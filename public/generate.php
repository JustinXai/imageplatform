<?php

/**
 * 图片生成 API 端点
 *
 * 注意：绝对不能使用 ob_start()，它会与 PHP 输出压缩冲突
 * 导致 exit 时 JSON 响应丢失（图片已生成但前端收到空响应）。
 *
 * 错误处理策略：
 * 1. register_shutdown_function → 捕获致命错误后 ob_clean 再输出 JSON
 * 2. 全局 try-catch → 所有异常都走 json_response
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/prompt_moderation.php';
require_once __DIR__ . '/../src/image_generation.php';
require_once __DIR__ . '/../src/migration.php';

// ========== 致命错误捕获 ==========
register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    // 确保日志目录存在
    $logDir = dirname(__DIR__) . '/public/uploads';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logLine = '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . "\n";
    @file_put_contents($logDir . '/generate_error.log', $logLine, FILE_APPEND | LOCK_EX);

    // 清空已输出的任何内容
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => '服务器内部错误，请查看错误日志或联系管理员。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

// ========== 全局异常兜底 ==========
try {
    $user = require_login();
    ensure_generation_records_soft_delete();
    ensure_generation_records_queue_status();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    verify_csrf();
    // If no enabled AI model is present, fall back to the global image config.
    $hasActiveImageModel = !empty(active_ai_models());
    $hasGlobalImageConfig = trim((string) app_setting('image_base_url', '')) !== '' && trim((string) app_setting('image_api_key', '')) !== '';
    if (!$hasActiveImageModel && !$hasGlobalImageConfig) {
        json_response(['ok' => false, 'message' => '无可用 AI 模型，请前往后台配置。'], 503);
    }

    // 同步生成使用较短超时（45秒），避免长时间占用 PHP-FPM 进程
    // 长时间生成请使用后台队列
    $responseTimeout = 45;
    @set_time_limit($responseTimeout + 15);

    // ========== 创建生成任务（异步Worker队列模式） ==========
    try {
        $params = generation_input_from_request($_POST, $_FILES);
        $blockedCategories = blocked_prompt_categories($params['prompt']);
        if ($blockedCategories) {
            json_response(['ok' => false, 'message' => prompt_violation_message($blockedCategories)], 422);
        }

        $created = create_generation_record((int) $user['id'], $params, 'queued');
        $recordId = (int) $created['id'];
        $credits = current_user_credits((int) $user['id']);
        trigger_generation_worker();
        $httpTriggered = trigger_generation_worker_http($recordId);

        // 立即返回排队状态，由 Worker 异步处理
        $payload = [
            'ok' => true,
            'record_id' => $recordId,
            'status' => 'queued',
            'credits' => $credits,
            'message' => '已加入生成队列，完成后将自动出现在图库中。',
            'record' => [
                'id' => $recordId,
                'status' => 'queued',
                'image_src' => '',
                'mode' => $params['mode'] ?? 'draw',
                'prompt' => $params['prompt'] ?? '',
                'size' => $params['size'] ?? 'auto',
                'quality' => $params['quality'] ?? 'auto',
                'format' => $params['format'] ?? 'png',
                'credits_charged' => 0,
                'created_at' => $created['created_at'] ?? date('Y-m-d H:i:s'),
                'finished_at' => '',
            ],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode generate response.');
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
        echo $json;

        if (!$httpTriggered) {
            continue_generation_record_after_response($recordId);
        }
        exit;
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (RuntimeException $e) {
        $status = $e->getCode() === 402 ? 402 : 500;
        json_response(['ok' => false, 'message' => $e->getMessage()], $status);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => '创建生成任务失败：' . $e->getMessage()], 500);
    }
} catch (Throwable $e) {
    // ========== 最终兜底 ==========
    if (ob_get_level() > 0) {
        ob_clean();
    }

    $logLine = '[' . date('Y-m-d H:i:s') . '] UNCAUGHT: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
    @file_put_contents(dirname(__DIR__) . '/public/uploads/generate_error.log', $logLine, FILE_APPEND | LOCK_EX);

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => '服务器处理请求时发生意外错误，请重试。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
