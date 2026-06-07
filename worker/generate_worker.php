<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/image_generation.php';

if (PHP_SAPI !== 'cli') {
    exit("This worker must run from CLI.\n");
}

ensure_generation_records_soft_delete();
ensure_generation_records_queue_status();
set_time_limit(0);

$runOnce = in_array('--once', $argv, true);
$sleepSeconds = max(1, (int) config('generation.worker_sleep', 3));
echo '[' . date('Y-m-d H:i:s') . '] image storage mode: ' . image_storage_mode() . "\n";

do {
    try {
        $cleaned = cleanup_stale_running_generation_records();
        if ($cleaned > 0) {
            echo '[' . date('Y-m-d H:i:s') . '] cleaned stale running tasks: ' . $cleaned . "\n";
        }

        $recordId = claim_next_generation_record();
        if ($recordId === null) {
            if ($runOnce) {
                echo '[' . date('Y-m-d H:i:s') . "] no queued task\n";
                break;
            }
            sleep($sleepSeconds);
            continue;
        }

        echo '[' . date('Y-m-d H:i:s') . '] processing record #' . $recordId . "\n";
        try {
            perform_generation_record($recordId);
            echo '[' . date('Y-m-d H:i:s') . '] succeeded record #' . $recordId . "\n";
        } catch (Throwable $e) {
            echo '[' . date('Y-m-d H:i:s') . '] failed record #' . $recordId . ': ' . $e->getMessage() . "\n";
        }
    } catch (Throwable $e) {
        echo '[' . date('Y-m-d H:i:s') . '] worker error: ' . $e->getMessage() . "\n";
        if ($runOnce) {
            exit(1);
        }
        sleep($sleepSeconds);
    }
} while (!$runOnce);
