<?php

/**
 * 在线生成 — 秒回模式
 *
 * 创建队列记录后立即返回，由 CLI Worker 或队列系统处理。
 * 前端通过轮询 /check_record 获取最终结果。
 * 不调 API、不异步、不 exec，纯粹秒回。
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/prompt_moderation.php';
require_once __DIR__ . '/../src/image_generation.php';
require_once __DIR__ . '/../src/migration.php';

$user = require_login();
ensure_generation_records_soft_delete();
ensure_generation_records_queue_status();

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
    $recordId = (int) $created['id'];

    json_response([
        'ok' => true,
        'record_id' => $recordId,
        'status' => 'queued',
        'credits' => current_user_credits((int) $user['id']),
    ]);
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    $status = $e->getCode() === 402 ? 402 : 500;
    json_response(['ok' => false, 'message' => $e->getMessage()], $status);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => '创建生成任务失败。'], 500);
}
