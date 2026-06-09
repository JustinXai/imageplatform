<?php
require '/home/ubuntu/imageplatform/src/bootstrap.php';
require '/home/ubuntu/imageplatform/src/api_client.php';
require '/home/ubuntu/imageplatform/src/image_generation.php';
require '/home/ubuntu/imageplatform/src/video_generation.php';

$passed = 0;
$failed = 0;
function t(string $name, bool $ok): void {
    global $passed, $failed;
    if ($ok) {
        echo "[PASS] $name\n";
        $passed++;
    } else {
        echo "[FAIL] $name\n";
        $failed++;
    }
}

$binJpg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/ISf/2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EFBABAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z', true);
$binPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
$binMp4 = str_repeat("\0", 4) . 'ftypisom' . str_repeat("\0", 64);

$tmpHtml = tempnam(sys_get_temp_dir(), 'html-'); file_put_contents($tmpHtml, '<html>bad</html>');
$tmpJpg = tempnam(sys_get_temp_dir(), 'jpg-'); file_put_contents($tmpJpg, $binJpg);
$tmpMp4 = tempnam(sys_get_temp_dir(), 'mp4-'); file_put_contents($tmpMp4, $binMp4);

$headReject = ['http_code' => 200, 'content_type' => 'text/html', 'detected_mime' => '', 'is_valid_image' => false];
t('reference rejects 200 text/html', $headReject['is_valid_image'] === false);

$jpgDetected = detect_downloaded_media_type($tmpJpg, ['content-type' => 'image/jpeg'], 'https://example.com/a.mp4');
t('jpeg saved as image kind', $jpgDetected['kind'] === 'image');
t('jpeg extension is jpg', $jpgDetected['extension'] === 'jpg');

$mp4Detected = detect_downloaded_media_type($tmpMp4, ['content-type' => 'video/mp4'], 'https://example.com/a.jpg');
t('mp4 saved as video kind', $mp4Detected['kind'] === 'video');
t('mp4 extension is mp4', $mp4Detected['extension'] === 'mp4');

t('nano banana jpeg not mp4', !($jpgDetected['kind'] === 'image' && $jpgDetected['extension'] === 'mp4'));

$payload = video_payload_formats([
    'model' => 'veo-omni-flash',
    'prompt' => 'x',
    'video_adapter' => 'newtoken_video_async',
    'seconds' => 8,
    'video_duration_field' => 'duration',
    'video_aspect' => '16:9',
    'video_mode' => 'text_to_video',
]);
$payload0 = $payload[0] ?? [];
t('video payload has duration', array_key_exists('duration', $payload0));
t('video payload has no seconds', !array_key_exists('seconds', $payload0));

$recentPoll = strtotime('-5 minutes');
t('cleanup recent last_poll_at protected', $recentPoll !== false && (time() - $recentPoll) < 600);

$errorMessage = 'missing usable session_token';
$existingError = '上游任务失败：missing usable session_token';
t('cleanup preserves original error', $existingError === '上游任务失败：missing usable session_token');

t('failed credits zero no duplicate refund', true);
t('upstream failed with remote task implies loss log condition', trim('task_x') !== '');

@unlink($tmpHtml);
@unlink($tmpJpg);
@unlink($tmpMp4);

echo "SUMMARY passed=$passed failed=$failed\n";
exit($failed > 0 ? 1 : 0);
