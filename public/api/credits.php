<?php

/**
 * API 端点 — 查询用户积分
 *
 * GET /api/credits
 * Authorization: Bearer <token>
 *
 * 返回：
 * {
 *   "ok": true,
 *   "credits": 100,
 *   "username": "xxx"
 * }
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/api_token.php';
require_once __DIR__ . '/../../src/api_middleware.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $token = require_api_token();
    require_api_permission('check_credits');

    echo json_encode([
        'ok' => true,
        'credits' => $token['user_info']['credits'],
        'username' => $token['user_info']['username'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '服务器内部错误。'], JSON_UNESCAPED_UNICODE);
}
