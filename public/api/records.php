<?php

/**
 * API 端点 — 生成记录列表
 *
 * GET /api/records?mode=draw&limit=10&offset=0
 * Authorization: Bearer <token>
 *
 * 返回：
 * {
 *   "ok": true,
 *   "records": [...],
 *   "total": 50
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

    $mode   = (string) ($_GET['mode'] ?? '');
    $limit  = min(50, max(1, (int) ($_GET['limit'] ?? 10)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $where  = 'WHERE user_id = ? AND deleted_at IS NULL';
    $params = [$token['user_id']];

    if ($mode !== '' && in_array($mode, ['draw', 'edit', 'video'], true)) {
        $where .= ' AND mode = ?';
        $params[] = $mode;
    }

    // 总数
    $countStmt = db()->prepare("SELECT COUNT(*) FROM generation_records $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // 列表
    $stmt = db()->prepare(
        "SELECT id, user_id, status, mode, model, prompt, size, quality, output_format,
                output_url, mime_type, credits_cost, error_message, started_at, finished_at,
                deleted_at, created_at,
                video_url, video_base64, video_mime_type
         FROM generation_records
         $where
         ORDER BY created_at DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $records = [];
    foreach ($rows as $row) {
        $resp = generation_response_record($row);
        $records[] = $resp;
    }

    echo json_encode([
        'ok' => true,
        'records' => $records,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '服务器内部错误。'], JSON_UNESCAPED_UNICODE);
}
