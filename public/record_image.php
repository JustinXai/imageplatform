<?php

require_once __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$recordId = max(0, (int) ($_GET['id'] ?? 0));
if ($recordId < 1) {
    http_response_code(404);
    exit;
}

$stmt = db()->prepare(
    'SELECT id, user_id, output_base64, output_url, mime_type
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
if (!empty($record['output_url'])) {
    redirect((string) $record['output_url']);
}
if (empty($record['output_base64'])) {
    http_response_code(404);
    exit;
}

$binary = base64_decode((string) $record['output_base64'], true);
if ($binary === false) {
    http_response_code(404);
    exit;
}

$mime = (string) ($record['mime_type'] ?: 'image/png');
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . strlen($binary));
echo $binary;
