<?php
/**
 * 系统安装状态检测
 * GET /api/system_status → {installed: true/false}
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$root = dirname(__DIR__, 2);
$installed = is_file($root . '/storage/.installed') || is_file($root . '/config.php');

echo json_encode(['installed' => $installed], JSON_UNESCAPED_UNICODE);
