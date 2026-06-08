<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("This script must run from CLI.\n");
}

require_once __DIR__ . '/../src/bootstrap.php';

ensure_generation_records_generation_options();

$stmt = db()->query("SELECT id, generation_config_snapshot FROM generation_records WHERE generation_config_snapshot LIKE '%api_key%'");
$rows = $stmt->fetchAll();
$updated = 0;

foreach ($rows as $row) {
    $id = (int) ($row['id'] ?? 0);
    $snapshot = json_decode((string) ($row['generation_config_snapshot'] ?? ''), true);
    if (!is_array($snapshot) || !array_key_exists('api_key', $snapshot)) {
        continue;
    }

    unset($snapshot['api_key']);

    $update = db()->prepare('UPDATE generation_records SET generation_config_snapshot = ? WHERE id = ?');
    $update->execute([
        json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $id,
    ]);
    $updated++;
}

echo "Cleaned snapshots: {$updated}\n";
