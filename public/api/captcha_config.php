<?php
/**
 * Captcha 配置端点 — 返回极验验证码状态和 ID
 * GET /api/captcha_config
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/captcha.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'enabled'    => captcha_is_enabled(),
    'captcha_id' => captcha_is_enabled() ? captcha_id() : '',
], JSON_UNESCAPED_UNICODE);
