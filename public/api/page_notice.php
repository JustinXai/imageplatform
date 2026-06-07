<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

header('Content-Type: application/json; charset=utf-8');

$page = trim((string) ($_GET['page'] ?? ''));
$allowed = ['login', 'register', 'forgot', 'index'];
if (!in_array($page, $allowed, true)) {
    echo json_encode(['ok' => false, 'message' => '未知页面。']);
    exit;
}

$enabled = app_setting("page_notice_{$page}_enabled", 'off');
if ($enabled !== 'on') {
    echo json_encode(['ok' => false, 'enabled' => false]);
    exit;
}

$content = trim((string) app_setting("page_notice_{$page}_content", ''));
if ($content === '') {
    echo json_encode(['ok' => false, 'enabled' => false]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'enabled' => true,
    'content' => $content,
]);
