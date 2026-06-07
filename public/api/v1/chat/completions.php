<?php

/**
 * OpenAI-compatible chat completions endpoint.
 */

require_once dirname(__DIR__, 4) . '/src/bootstrap.php';
require_once dirname(__DIR__, 4) . '/src/api_client.php';
require_once dirname(__DIR__, 4) . '/src/api_token.php';
require_once dirname(__DIR__, 4) . '/src/api_middleware.php';
require_once dirname(__DIR__, 4) . '/src/migration.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

function openai_chat_reply_content(array $data): string
{
    $candidates = [
        $data['choices'][0]['message']['content'] ?? null,
        $data['choices'][0]['text'] ?? null,
        $data['output_text'] ?? null,
        $data['message']['content'] ?? null,
        $data['content'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return $candidate;
        }
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_openai_error('Method not allowed. Use POST.', 'invalid_request_error', 405);
}

try {
    ensure_chat_conversations_table();
    ensure_chat_records_table();
    ensure_chat_records_conversation_column();

    $auth = require_dual_auth();
    $userId = (int) $auth['user_id'];
    $userInfo = $auth['user_info'];

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        api_openai_error('请求体必须为 JSON 格式。', 'invalid_request_error', 400);
    }

    $messages = $input['messages'] ?? [];
    if (!is_array($messages) || $messages === []) {
        api_openai_error('messages 字段不能为空。', 'invalid_request_error', 400);
    }

    $userMessage = '';
    foreach (array_reverse($messages) as $message) {
        if (($message['role'] ?? '') === 'user') {
            $userMessage = trim((string) ($message['content'] ?? ''));
            break;
        }
    }
    if ($userMessage === '') {
        api_openai_error('messages 中必须包含 user 角色的消息。', 'invalid_request_error', 400);
    }

    $requestModel = trim((string) ($input['model'] ?? ''));
    $modelConfig = null;
    if ($requestModel !== '') {
        $stmt = db()->prepare(
            "SELECT * FROM ai_models
             WHERE (model_id = ? OR name = ?)
               AND is_active = 1
               AND model_type = 'chat'
             LIMIT 1"
        );
        $stmt->execute([$requestModel, $requestModel]);
        $modelConfig = $stmt->fetch();
    }
    if (!$modelConfig) {
        $models = active_chat_ai_models();
        $modelConfig = $models[0] ?? null;
    }
    if (!$modelConfig) {
        api_openai_error('管理员尚未配置对话模型。', 'server_error', 503);
    }

    $chatCost = ((int) ($modelConfig['credits'] ?? 0) > 0) ? (int) $modelConfig['credits'] : 1;
    $credits = (int) ($userInfo['credits'] ?? 0);
    if ($credits < $chatCost) {
        api_openai_error('积分不足，每次对话需要 ' . $chatCost . ' 积分。', 'insufficient_quota', 402);
    }

    $chatModel = (string) $modelConfig['model_id'];
    $chatBaseUrl = rtrim((string) $modelConfig['base_url'], '/');
    $chatApiKey = (string) $modelConfig['api_key'];
    $maxTokens = min(4096, max(1, (int) ($input['max_tokens'] ?? 2048)));
    $temperature = (float) ($input['temperature'] ?? 0.7);

    $pdo = db();
    $pdo->beginTransaction();
    $creditsDeducted = false;
    try {
        $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
        $stmt->execute([$chatCost, $userId, $chatCost]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            api_openai_error('积分不足。', 'insufficient_quota', 402);
        }
        $pdo->commit();
        $creditsDeducted = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        api_openai_error('扣减积分失败。', 'server_error', 500);
    }

    $url = api_build_url($chatBaseUrl, 'v1/chat/completions');
    $payload = [
        'model' => $chatModel,
        'messages' => $messages,
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
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

        $reply = openai_chat_reply_content($data);
        if ($reply === '') {
            throw new RuntimeException('对话接口未返回有效回复。');
        }

        $tokens = (int) ($data['usage']['total_tokens'] ?? 0);
        $userIp = (string) (
            $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? ''
        );

        $stmtConv = $pdo->prepare('INSERT INTO chat_conversations (user_id, title, model_id) VALUES (?, ?, ?)');
        $conversationTitle = mb_strlen($userMessage) > 50 ? mb_substr($userMessage, 0, 50) . '…' : $userMessage;
        $stmtConv->execute([$userId, $conversationTitle, (int) $modelConfig['id']]);
        $conversationId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO chat_records (user_id, conversation_id, prompt, reply, tokens, model, credits_cost, user_ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $conversationId, $userMessage, $reply, $tokens, $chatModel, $chatCost, $userIp]);

        $pdo->prepare('UPDATE chat_conversations SET message_count = message_count + 1 WHERE id = ?')
            ->execute([$conversationId]);

        api_openai_response([
            'id' => 'chatcmpl-' . bin2hex(random_bytes(12)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $chatModel,
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $reply,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => (int) ($data['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
                'total_tokens' => $tokens,
            ],
        ]);
    } catch (Throwable $e) {
        if ($creditsDeducted) {
            $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
                ->execute([$chatCost, $userId]);
        }
        api_openai_error($e->getMessage(), 'api_error', 500);
    }
} catch (RuntimeException $e) {
    api_openai_error($e->getMessage(), 'invalid_request_error', 400);
} catch (Throwable $e) {
    api_openai_error('服务器内部错误。', 'server_error', 500);
}
