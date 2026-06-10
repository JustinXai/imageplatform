#!/usr/bin/env php
<?php
/**
 * One-time backfill script: generate thumbnails for existing succeeded image records.
 *
 * This script:
 * - Queries all succeeded image records with local output_url but missing thumb_url
 * - Generates a thumbnail for each using GD
 * - Updates the thumb_url field in the database
 * - Reports progress and summary
 *
 * Safe: reads from DB, writes to local filesystem only.
 * Idempotent: can be re-run; already-processed records are skipped.
 * No NewToken calls, no generation, no user data modification.
 *
 * Usage: php scripts/backfill_thumbnails.php [--dry-run]
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/api_client.php';

$dryRun = in_array('--dry-run', $argv, true);
$verbose = in_array('--verbose', $argv, true) || $dryRun;

if ($dryRun) {
    echo "[DRY-RUN MODE — no changes will be written]\n\n";
}

$pdo = db();

// Find records needing backfill
$stmt = $pdo->query("
    SELECT id, output_url, mime_type, created_at
    FROM generation_records
    WHERE status = 'succeeded'
    AND mode IN ('draw', 'edit')
    AND output_url IS NOT NULL
    AND output_url != ''
    AND output_url NOT LIKE 'http%'
    AND (thumb_url IS NULL OR thumb_url = '')
    ORDER BY id ASC
");
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($records);

echo "Found {$total} record(s) needing thumbnail backfill.\n\n";

if ($total === 0) {
    echo "Nothing to do. Exiting.\n";
    exit(0);
}

$success = 0;
$skipped = 0;
$failed = 0;
$errors = [];

foreach ($records as $r) {
    $id = (int) $r['id'];
    $url = (string) $r['output_url'];
    $mime = (string) ($r['mime_type'] ?? 'image/png');

    echo "Processing record #{$id} ... ";

    // Resolve local file path
    $localPath = local_public_file_from_url($url);
    if ($localPath === null || !is_file($localPath)) {
        echo "SKIP (file not found: {$url})\n";
        $skipped++;
        continue;
    }

    if (!is_readable($localPath)) {
        echo "SKIP (not readable: {$localPath})\n";
        $skipped++;
        continue;
    }

    // Generate thumbnail
    $thumbUrl = generate_thumbnail($localPath, $mime);
    if ($thumbUrl === null) {
        echo "FAIL (generate_thumbnail returned null)\n";
        $failed++;
        $errors[] = "record #{$id}: generate_thumbnail failed for {$localPath}";
        continue;
    }

    // Verify thumbnail file actually exists
    $thumbPath = local_public_file_from_url($thumbUrl);
    if ($thumbPath === null || !is_file($thumbPath)) {
        echo "FAIL (thumb file not created: {$thumbUrl})\n";
        $failed++;
        $errors[] = "record #{$id}: thumb file missing at {$thumbUrl}";
        continue;
    }

    if ($dryRun) {
        echo "DRY-RUN: would update thumb_url = '{$thumbUrl}' for record #{$id}\n";
        $success++;
        continue;
    }

    // Write to database
    $upd = $pdo->prepare("UPDATE generation_records SET thumb_url = ? WHERE id = ?");
    $upd->execute([$thumbUrl, $id]);

    // Verify
    $verify = $pdo->prepare("SELECT thumb_url FROM generation_records WHERE id = ?");
    $verify->execute([$id]);
    $saved = $verify->fetchColumn();
    if ($saved === $thumbUrl) {
        echo "OK (thumb_url = {$thumbUrl})\n";
        $success++;
    } else {
        echo "FAIL (DB verify mismatch: saved={$saved}, expected={$thumbUrl})\n";
        $failed++;
        $errors[] = "record #{$id}: DB verify failed (saved={$saved})";
    }
}

// Summary
echo "\n";
echo "========================================\n";
echo "Backfill Summary:\n";
echo "  Total processed: {$total}\n";
echo "  Success:         {$success}\n";
echo "  Skipped:         {$skipped}\n";
echo "  Failed:          {$failed}\n";
echo "========================================\n";

if ($failed > 0) {
    echo "\nErrors:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

if ($dryRun) {
    echo "\nRe-run without --dry-run to apply changes.\n";
}

exit($failed > 0 ? 1 : 0);
