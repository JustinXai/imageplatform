<?php

/**
 * 检查生成记录状态（前端轮询用）
 *
 * GET /check_record?id=123
 * 返回：{ ok, status, image_src, record, credits }
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/image_generation.php';

$user = require_login();
ensure_generation_records_soft_delete();

$recordId = (int) ($_GET['id'] ?? 0);
if ($recordId < 1) {
    json_response(['ok' => false, 'message' => '参数不合法'], 400);
}

try {
    $record = generation_record_by_id($recordId);

    // 只能查看自己的记录
    if ((int) $record['user_id'] !== (int) $user['id'] && $user['role'] !== 'admin') {
        json_response(['ok' => false, 'message' => '无权访问'], 403);
    }

    $status = (string) $record['status'];
    $responseRecord = generation_response_record($record);

    json_response([
        'ok' => true,
        'status' => $status,
        'record_id' => $recordId,
        'image_src' => $responseRecord['image_src'],
        'credits' => current_user_credits((int) $user['id']),
        'record' => $responseRecord,
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => '记录不存在'], 404);
}
