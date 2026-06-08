<?php

/**
 * 检查用户是否有运行中的生成记录（前端页面加载时用）
 *
 * GET /check_running_records?mode=video
 * 返回：{ ok, records: [...], record, credits }
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/image_generation.php';

$user = require_login();

$mode = (string) ($_GET['mode'] ?? '');
$modeWhere = $mode !== '' ? ' AND mode = ' . db()->quote($mode) : '';

$stmt = db()->prepare(
    "SELECT id, status, mode, prompt, size, output_format, credits_cost,
            error_message, started_at, finished_at, created_at,
            output_base64 IS NOT NULL AS has_image_base64
     FROM generation_records
     WHERE user_id = ? AND deleted_at IS NULL AND status IN ('running', 'queued'){$modeWhere}
     ORDER BY created_at DESC
     LIMIT 5"
);
$stmt->execute([(int) $user['id']]);
$records = $stmt->fetchAll();

if (!$records) {
    json_response(['ok' => true, 'records' => []]);
}

$result = [];
foreach ($records as $record) {
    $result[] = generation_response_record($record);
}

json_response([
    'ok' => true,
    'records' => $result,
    'record' => $result[0] ?? null,
    'credits' => current_user_credits((int) $user['id']),
]);
