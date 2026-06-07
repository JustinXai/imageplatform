<?php

require_once __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$recordId = max(0, (int) ($_GET['id'] ?? 0));
$index = max(0, (int) ($_GET['index'] ?? 0));
if ($recordId < 1) {
    http_response_code(404);
    exit;
}

$stmt = db()->prepare(
    'SELECT id, user_id, input_images_json
     FROM generation_records
     WHERE id = ?
     LIMIT 1'
);
$stmt->execute([$recordId]);
$record = $stmt->fetch();

if (!is_array($record)) {
    http_response_code(404);
    exit;
}
if ($user['role'] !== 'admin' && (int) $record['user_id'] !== (int) $user['id']) {
    http_response_code(403);
    exit;
}

$images = json_decode((string) ($record['input_images_json'] ?? ''), true);
if (!is_array($images) || empty($images[$index]) || !is_array($images[$index])) {
    http_response_code(404);
    exit;
}

$image = $images[$index];
if (!empty($image['url'])) {
    redirect((string) $image['url']);
}
if (empty($image['base64'])) {
    http_response_code(404);
    exit;
}

$binary = base64_decode((string) $image['base64'], true);
if ($binary === false) {
    http_response_code(404);
    exit;
}

$mime = (string) ($image['mime_type'] ?? 'image/png');
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . strlen($binary));
echo $binary;
