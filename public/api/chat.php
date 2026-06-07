<?php

/**
 * AI 对话 API
 *
 * POST /api/chat
 * Body: {"prompt":"用户消息","model_id":1,"conversation_id":0}
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/api_client.php';
require_once __DIR__ . '/../../src/image_generation.php';
require_once __DIR__ . '/../../src/migration.php';

error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

function chat_completion_content(array $data): string
{
    $candidates = [
        $data['choices'][0]['message']['content'] ?? null,
        $data['choices'][0]['text'] ?? null,
        $data['output_text'] ?? null,
        $data['content'] ?? null,
        $data['message']['content'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return $candidate;
        }
    }

    return '';
}

try {
    $user = require_login();
    ensure_chat_conversations_table();
    ensure_chat_records_table();
    ensure_chat_records_conversation_column();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode((string) $rawInput, true);
    if (!is_array($input)) {
        json_response(['ok' => false, 'message' => '请求体必须为 JSON 格式。'], 400);
    }

    if (isset($input['csrf_token'])) {
        $_POST['csrf_token'] = (string) $input['csrf_token'];
    }
    verify_csrf();

    $prompt = trim((string) ($input['prompt'] ?? ''));
    if ($prompt === '') {
        json_response(['ok' => false, 'message' => '请输入消息内容。'], 400);
    }

    $modelId = (int) ($input['model_id'] ?? 0);
    $modelConfig = null;
    if ($modelId > 0) {
        $stmt = db()->prepare('SELECT * FROM ai_models WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$modelId]);
        $modelConfig = $stmt->fetch();
    }
    if (!$modelConfig) {
        $models = active_chat_ai_models();
        $modelConfig = $models[0] ?? null;
    }
    if (!$modelConfig) {
        json_response(['ok' => false, 'message' => '管理员尚未配置对话模型。'], 503);
    }

    $chatCost = ((int) ($modelConfig['credits'] ?? 0) > 0) ? (int) $modelConfig['credits'] : 1;
    $credits = (int) $user['credits'];
    if ($credits < $chatCost) {
        json_response(['ok' => false, 'message' => '积分不足，每次对话需要 ' . $chatCost . ' 积分。'], 402);
    }

    $chatModel = (string) $modelConfig['model_id'];
    $chatBaseUrl = rtrim((string) $modelConfig['base_url'], '/');
    $chatApiKey = (string) $modelConfig['api_key'];

    $pdo = db();
    $conversationId = (int) ($input['conversation_id'] ?? 0);
    $conv = ['title' => '新对话'];

    if ($conversationId > 0) {
        $stmt = $pdo->prepare('SELECT id, title FROM chat_conversations WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$conversationId, $user['id']]);
        $existingConversation = $stmt->fetch();
        if ($existingConversation) {
            $conv = $existingConversation;
        } else {
            $conversationId = 0;
        }
    }

    if ($conversationId < 1) {
        $title = mb_strlen($prompt) > 50 ? mb_substr($prompt, 0, 50) . '…' : $prompt;
        $stmt = $pdo->prepare('INSERT INTO chat_conversations (user_id, title, model_id) VALUES (?, ?, ?)');
        $stmt->execute([$user['id'], $title, (int) $modelConfig['id']]);
        $conversationId = (int) $pdo->lastInsertId();
        $conv = ['title' => $title];
    }

    $stmt = $pdo->prepare('SELECT prompt, reply FROM chat_records WHERE conversation_id = ? ORDER BY id ASC');
    $stmt->execute([$conversationId]);
    $history = $stmt->fetchAll();

    $messages = [];
    foreach ($history as $row) {
        $messages[] = ['role' => 'user', 'content' => (string) $row['prompt']];
        $messages[] = ['role' => 'assistant', 'content' => (string) $row['reply']];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $pdo->beginTransaction();
    $creditsDeducted = false;
    try {
        $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
        $stmt->execute([$chatCost, $user['id'], $chatCost]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => '积分不足。'], 402);
        }
        $pdo->commit();
        $creditsDeducted = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['ok' => false, 'message' => '扣减积分失败。'], 500);
    }

    $url = api_build_url($chatBaseUrl, 'v1/chat/completions');
    $payload = [
        'model' => $chatModel,
        'messages' => $messages,
        'max_tokens' => 2048,
    ];

    try {
        $response = api_curl_post_json($url, $chatApiKey, $payload, 60);
        $httpCode = (int) $response['http_code'];
        $raw = (string) $response['raw'];

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('对话接口返回格式异常。');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(api_error_message($data, '对话接口返回 HTTP ' . $httpCode));
        }

        $reply = chat_completion_content($data);
        if ($reply === '') {
            throw new RuntimeException('对话接口未返回有效回复。');
        }

        $tokens = (int) ($data['usage']['total_tokens'] ?? 0);

        $pdo->prepare('UPDATE chat_conversations SET message_count = message_count + 1 WHERE id = ?')
            ->execute([$conversationId]);

        $userIp = (string) (
            $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? ''
        );

        $stmt = $pdo->prepare(
            'INSERT INTO chat_records (user_id, conversation_id, prompt, reply, tokens, model, credits_cost, user_ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user['id'], $conversationId, $prompt, $reply, $tokens, $chatModel, $chatCost, $userIp]);

        json_response([
            'ok' => true,
            'reply' => $reply,
            'credits' => $credits - $chatCost,
            'tokens' => $tokens,
            'model' => $chatModel,
            'conversation_id' => $conversationId,
            'title' => $conv['title'],
        ]);
    } catch (Throwable $e) {
        if ($creditsDeducted) {
            $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
                ->execute([$chatCost, $user['id']]);
        }
        json_response(['ok' => false, 'message' => $e->getMessage()], 500);
    }
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => '系统错误：' . $e->getMessage()], 500);
}
