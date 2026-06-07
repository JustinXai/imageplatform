<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/prompt_moderation.php';
require_once __DIR__ . '/../src/image_generation.php';
require_once __DIR__ . '/../src/migration.php';

$user = require_login();
ensure_generation_records_soft_delete();
ensure_generation_records_queue_status();

// 检查是否有已启用的 AI 模型
if (!active_ai_models()) {
    json_response(['ok' => false, 'message' => '管理员尚未配置可用的 AI 模型，请稍后重试。'], 503);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

verify_csrf();

try {
    $params = generation_input_from_request($_POST, $_FILES);
    $blockedCategories = blocked_prompt_categories($params['prompt']);
    if ($blockedCategories) {
        json_response(['ok' => false, 'message' => prompt_violation_message($blockedCategories)], 422);
    }

    $created = create_generation_record((int) $user['id'], $params, 'queued');
    $record = generation_record_by_id((int) $created['id']);
    trigger_generation_worker();

    json_response([
        'ok' => true,
        'message' => '已加入生成队列，请在生成记录中查看进度。',
        'record_id' => (int) $created['id'],
        'credits' => current_user_credits((int) $user['id']),
        'record' => generation_response_record($record),
    ]);
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    $status = $e->getCode() === 402 ? 402 : 500;
    json_response(['ok' => false, 'message' => $e->getMessage()], $status);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => '创建队列任务失败。'], 500);
}
