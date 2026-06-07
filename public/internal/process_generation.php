<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/image_generation.php';

$recordId = (int) ($_GET['record_id'] ?? 0);
$timestamp = (int) ($_GET['ts'] ?? 0);
$signature = (string) ($_GET['sig'] ?? '');

if (!verify_generation_worker_signature($recordId, $timestamp, $signature)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

ignore_user_abort(true);
set_time_limit(max(60, (int) config('generation.timeout', 300) + 30));

header('Content-Type: application/json; charset=utf-8');

try {
    if (!claim_generation_record_by_id($recordId)) {
        echo json_encode([
            'ok' => true,
            'message' => 'already claimed',
            'record_id' => $recordId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $record = perform_generation_record($recordId);
    echo json_encode([
        'ok' => true,
        'record_id' => $recordId,
        'status' => $record['status'] ?? 'succeeded',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Logger::error('HTTP_QUEUE_PROCESS_FAILED', [
        'record_id' => $recordId,
        'error' => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'record_id' => $recordId,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
