<?php
/**
 * Final acceptance test: submit 1 Banana draw task and 1 Image2 draw task.
 * Uses normal user (Shepherd, id=5) through the proper web flow.
 * No API key / cookie / session / raw response / base64 output.
 */

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/prompt_moderation.php';
require __DIR__ . '/src/image_generation.php';

$pdo = db();

echo "=== Pre-test state ===" . PHP_EOL;
$user = $pdo->prepare("SELECT id, username, credits, role FROM users WHERE id=5")->execute([5]) ? $pdo->query("SELECT id, username, credits, role FROM users WHERE id=5")->fetch(PDO::FETCH_ASSOC) : null;
echo "User: " . json_encode($user, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$balance_before = (int) ($user['credits'] ?? 0);

echo PHP_EOL . "=== Test A: Banana draw (nana-banana-2, model id=2) ===" . PHP_EOL;
$ts_a = time();
$params_a = [
    'mode' => 'draw',
    'ai_model_id' => 2,
    'model' => 'nana-banana-2',
    'prompt' => "qa_final_banana_billing_{$ts_a} red apple",
    'size' => '1024x1024',
    'quality' => 'auto',
    'format' => 'png',
    'image_aspect' => '1:1',
];

try {
    $record_a = create_generation_record((int) $user['id'], $params_a, 'queued');
    $id_a = (int) $record_a['id'];
    echo "Record A created: id={$id_a}, credits_cost={$record_a['credits_cost']}" . PHP_EOL;

    $httpTriggered = trigger_generation_worker_http($id_a);
    echo "Worker HTTP triggered: " . ($httpTriggered ? 'yes' : 'no (CLI fallback)') . PHP_EOL;

    if (!$httpTriggered) {
        perform_generation_record($id_a, 120);
    }

    $after = $pdo->prepare("SELECT id, status, credits_cost, output_url, video_url, mime_type, error_message, created_at, finished_at FROM generation_records WHERE id=?")->execute([$id_a]) ? $pdo->query("SELECT id, status, credits_cost, output_url, video_url, mime_type, error_message, created_at, finished_at FROM generation_records WHERE id={$id_a}")->fetch(PDO::FETCH_ASSOC) : null;
    echo "Record A after processing: " . PHP_EOL;
    echo "  status: " . ($after['status'] ?? 'N/A') . PHP_EOL;
    echo "  credits_cost: " . ($after['credits_cost'] ?? 'N/A') . PHP_EOL;
    echo "  output_url: " . (($after['output_url'] ?? '') !== '' ? '(set)' : '(empty)') . PHP_EOL;
    echo "  video_url: " . (($after['video_url'] ?? '') !== '' ? '(set)' : '(empty)') . PHP_EOL;
    echo "  mime_type: " . ($after['mime_type'] ?? 'N/A') . PHP_EOL;
    echo "  error_message: " . (($after['error_message'] ?? '') !== '' ? substr($after['error_message'], 0, 100) . '...' : '(none)') . PHP_EOL;

    // credit_logs for this record
    $logs_a = $pdo->prepare("SELECT id, amount, type, reason, created_at FROM credit_logs WHERE ref_id=? ORDER BY id DESC")->execute([(string)$id_a]) ? $pdo->query("SELECT id, amount, type, reason, created_at FROM credit_logs WHERE ref_id='" . (string)$id_a . "' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) : [];
    echo "credit_logs for record {$id_a}:" . PHP_EOL;
    foreach ($logs_a as $l) { echo "  " . json_encode($l, JSON_UNESCAPED_UNICODE) . PHP_EOL; }

} catch (Throwable $e) {
    echo "Record A exception: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "=== Test B: Image2 draw (gpt-image-2-2K, model id=8) ===" . PHP_EOL;
$ts_b = time();
$params_b = [
    'mode' => 'draw',
    'ai_model_id' => 8,
    'model' => 'gpt-image-2-2K',
    'prompt' => "qa_final_image2_timeout_{$ts_b} red apple",
    'size' => '1024x1024',
    'quality' => 'auto',
    'format' => 'png',
    'image_aspect' => '1:1',
];

try {
    $record_b = create_generation_record((int) $user['id'], $params_b, 'queued');
    $id_b = (int) $record_b['id'];
    echo "Record B created: id={$id_b}, credits_cost={$record_b['credits_cost']}" . PHP_EOL;

    $httpTriggered_b = trigger_generation_worker_http($id_b);
    echo "Worker HTTP triggered: " . ($httpTriggered_b ? 'yes' : 'no (CLI fallback)') . PHP_EOL;

    if (!$httpTriggered_b) {
        perform_generation_record($id_b, 150);
    }

    $after_b = $pdo->prepare("SELECT id, status, credits_cost, output_url, mime_type, error_message, created_at, finished_at FROM generation_records WHERE id=?")->execute([$id_b]) ? $pdo->query("SELECT id, status, credits_cost, output_url, mime_type, error_message, created_at, finished_at FROM generation_records WHERE id={$id_b}")->fetch(PDO::FETCH_ASSOC) : null;
    echo "Record B after processing: " . PHP_EOL;
    echo "  status: " . ($after_b['status'] ?? 'N/A') . PHP_EOL;
    echo "  credits_cost: " . ($after_b['credits_cost'] ?? 'N/A') . PHP_EOL;
    echo "  output_url: " . (($after_b['output_url'] ?? '') !== '' ? '(set)' : '(empty)') . PHP_EOL;
    echo "  mime_type: " . ($after_b['mime_type'] ?? 'N/A') . PHP_EOL;
    echo "  error_message: " . (($after_b['error_message'] ?? '') !== '' ? substr($after_b['error_message'], 0, 200) . '...' : '(none)') . PHP_EOL;

    // credit_logs for this record
    $logs_b = $pdo->prepare("SELECT id, amount, type, reason, created_at FROM credit_logs WHERE ref_id=? ORDER BY id DESC")->execute([(string)$id_b]) ? $pdo->query("SELECT id, amount, type, reason, created_at FROM credit_logs WHERE ref_id='" . (string)$id_b . "' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) : [];
    echo "credit_logs for record {$id_b}:" . PHP_EOL;
    foreach ($logs_b as $l) { echo "  " . json_encode($l, JSON_UNESCAPED_UNICODE) . PHP_EOL; }

} catch (Throwable $e) {
    echo "Record B exception: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "=== Final user balance ===" . PHP_EOL;
$balance_after = $pdo->query("SELECT credits FROM users WHERE id=5")->fetchColumn();
echo "Balance before: {$balance_before}, after: {$balance_after}" . PHP_EOL;
echo "Delta: " . ($balance_before - $balance_after) . PHP_EOL;
