<?php

/**
 * API 端点 — 查询生成记录
 *
 * GET /api/check?id=123
 * Authorization: Bearer <token>
 *
 * 返回：
 * {
 *   "ok": true,
 *   "status": "succeeded|failed|running|queued",
 *   "record": { id, status, image_src, video_src, prompt, ... }
 * }
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/api_token.php';
require_once __DIR__ . '/../../src/api_middleware.php';
require_once __DIR__ . '/../../src/image_generation.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $token = require_api_token();
    require_api_permission('check_record');

    $recordId = (int) ($_GET['id'] ?? 0);
    if ($recordId < 1) {
        throw new RuntimeException('缺少有效的 id 参数。');
    }

    $record = generation_record_by_id($recordId);
    if (!$record) {
        throw new RuntimeException('记录不存在。');
    }

    // 只能查看自己用户下的记录
    if ((int) $record['user_id'] !== $token['user_id'] && $token['user_info']['role'] !== 'admin') {
        throw new RuntimeException('无权访问该记录。');
    }

    $responseRecord = generation_response_record($record);

    echo json_encode([
        'ok' => true,
        'status' => (string) $record['status'],
        'record_id' => $recordId,
        'image_src' => $responseRecord['image_src'],
        'record' => $responseRecord,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '服务器内部错误。'], JSON_UNESCAPED_UNICODE);
}
