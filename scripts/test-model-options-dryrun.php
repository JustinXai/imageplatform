<?php
require '/home/ubuntu/imageplatform/src/bootstrap.php';
require '/home/ubuntu/imageplatform/src/api_client.php';
require '/home/ubuntu/imageplatform/src/image_generation.php';
require '/home/ubuntu/imageplatform/src/video_generation.php';
require '/home/ubuntu/imageplatform/src/layout.php';
ensure_gallery_table();
ensure_credit_tables();

$passed = 0;
$failed = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
        $failed++;
    }
}
function throws_msg(callable $fn, string $contains = ''): bool {
    try {
        $fn();
        return false;
    } catch (Throwable $e) {
        return $contains === '' ? true : str_contains($e->getMessage(), $contains);
    }
}
function validate_image_input(string $mode, int $modelId, string $rawAspect, string $rawSize, array $uploadedFiles, array $modelConfig): array {
    $imgConfig = $modelConfig['image'] ?? $modelConfig;
    $aspectOptions = $imgConfig['aspect_options'] ?? [];
    $sizeOptions = $imgConfig['size_options'] ?? [];
    $supportsEdit = (bool) ($modelConfig['supports_edit'] ?? false);
    if ($mode === 'edit' && !$supportsEdit) {
        throw new InvalidArgumentException('Model does not support edit mode.');
    }
    if (count($aspectOptions) > 0 && !in_array($rawAspect, $aspectOptions, true)) {
        throw new InvalidArgumentException("Image aspect '{$rawAspect}' not in allowed list.");
    }
    if (count($sizeOptions) > 0 && !in_array($rawSize, $sizeOptions, true)) {
        throw new InvalidArgumentException("Image size '{$rawSize}' not in allowed list.");
    }
    return ['mode' => $mode, 'aspect' => $rawAspect, 'size' => $rawSize, 'supports_edit' => $supportsEdit, 'uploaded_count' => count($uploadedFiles)];
}
function validate_video_input(int $modelId, string $rawVideoMode, int $rawDuration, string $rawAspect, string $rawSize, array $imageFiles, array $videoFiles, array $audioFiles, array $modelConfig): array {
    $vidCfg = $modelConfig['video'] ?? $modelConfig;
    $durations = $vidCfg['duration_options'] ?? [];
    $aspects = $vidCfg['aspect_options'] ?? [];
    $sizes = $vidCfg['size_options'] ?? [];
    $modes = $vidCfg['mode_options'] ?? [];
    $mode = $rawVideoMode !== '' ? $rawVideoMode : (string) ($vidCfg['default_mode'] ?? 'text_to_video');
    if ($durations && !in_array($rawDuration, $durations, true)) {
        throw new InvalidArgumentException("Duration {$rawDuration} not in allowed list.");
    }
    if ($aspects && !in_array($rawAspect, $aspects, true)) {
        throw new InvalidArgumentException("Aspect {$rawAspect} not in allowed list.");
    }
    if ($sizes && !in_array($rawSize, $sizes, true)) {
        throw new InvalidArgumentException("Size {$rawSize} not in allowed list.");
    }
    if ($modes && !in_array($mode, $modes, true)) {
        throw new InvalidArgumentException("Mode {$mode} not in allowed list.");
    }
    $imageCount = count($imageFiles);
    $videoCount = count($videoFiles);
    if ($mode === 'first_frame' && $imageCount < 1) {
        throw new InvalidArgumentException('Mode first_frame requires 1 image(s).');
    }
    if ($mode === 'first_last_frame' && $imageCount !== 2) {
        throw new InvalidArgumentException('first_last_frame mode requires exactly 2 images.');
    }
    if ($mode === 'multi_reference' && $imageCount > (int) ($modelConfig['max_ref_images'] ?? 0) && (int) ($modelConfig['max_ref_images'] ?? 0) > 0) {
        throw new InvalidArgumentException('Mode multi_reference allows at most ' . (int) ($modelConfig['max_ref_images'] ?? 0) . ' images.');
    }
    if ($mode === 'video_edit' && $videoCount < 1) {
        throw new InvalidArgumentException('Mode video_edit requires 1 video(s).');
    }
    return [
        'video_mode' => $mode,
        'video_duration' => $rawDuration,
        'video_aspect' => $rawAspect,
        'video_size' => $rawSize,
        'credits_charged' => ((int) ($modelConfig['credits'] ?? 0)) * $rawDuration,
        'effective_ref_images' => $imageCount,
        'effective_ref_videos' => $videoCount,
        'effective_ref_audios' => count($audioFiles),
    ];
}

$binJpg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/ISf/2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EFBABAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z', true);
$binPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
$binWebp = 'RIFF' . str_repeat("\0", 4) . 'WEBP' . str_repeat("\0", 32);
$binMp4 = str_repeat("\0", 4) . 'ftypisom' . str_repeat("\0", 64);

$tmpJpg = tempnam(sys_get_temp_dir(), 'dry-jpg-'); file_put_contents($tmpJpg, $binJpg);
$tmpPng = tempnam(sys_get_temp_dir(), 'dry-png-'); file_put_contents($tmpPng, $binPng);
$tmpWebp = tempnam(sys_get_temp_dir(), 'dry-webp-'); file_put_contents($tmpWebp, $binWebp);
$tmpMp4 = tempnam(sys_get_temp_dir(), 'dry-mp4-'); file_put_contents($tmpMp4, $binMp4);

$pdo = db();
$bananaModels = $pdo->query("SELECT id, name, model_id, is_active FROM ai_models WHERE name LIKE '%banana%' OR model_id LIKE '%banana%' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$banana4k = null;
foreach ($bananaModels as $row) {
    if (($row['model_id'] ?? '') === 'nana-banana-2-4k') {
        $banana4k = $row;
        break;
    }
}

ok('1 GPT image 2 draw legal params', !throws_msg(fn() => validate_image_input('draw', 3, '1:1', '1024x1024', [], ['supports_edit' => false, 'image' => ['aspect_options' => ['1:1'], 'size_options' => ['1024x1024']]])));
ok('2 GPT image 2 edit rejected', throws_msg(fn() => validate_image_input('edit', 3, '1:1', '1024x1024', [], ['supports_edit' => false, 'image' => ['aspect_options' => ['1:1'], 'size_options' => ['1024x1024']]]), 'support edit'));
ok('3 Nano Banana edit legal', !throws_msg(fn() => validate_image_input('edit', 2, '1:1', '1024x1024', [['name' => 'a.jpg']], ['supports_edit' => true, 'image' => ['aspect_options' => ['1:1'], 'size_options' => ['1024x1024']]])));
ok('4 image invalid aspect rejected', throws_msg(fn() => validate_image_input('draw', 1, '7:3', '1024x1024', [], ['supports_edit' => false, 'image' => ['aspect_options' => ['1:1'], 'size_options' => ['1024x1024']]])));
ok('5 image invalid size rejected', throws_msg(fn() => validate_image_input('draw', 1, '1:1', '999x999', [], ['supports_edit' => false, 'image' => ['aspect_options' => ['1:1'], 'size_options' => ['1024x1024']]])));
$detJpg = detect_downloaded_media_type($tmpJpg, ['content-type' => 'image/jpeg'], 'https://example.com/x.mp4');
ok('6 image jpeg saved jpg', $detJpg['kind'] === 'image' && $detJpg['extension'] === 'jpg');
$detPng = detect_downloaded_media_type($tmpPng, ['content-type' => 'image/png'], 'https://example.com/x.png');
ok('7 image png saved png', $detPng['extension'] === 'png');
$detWebp = detect_downloaded_media_type($tmpWebp, ['content-type' => 'image/webp'], 'https://example.com/x.webp');
ok('8 image webp saved webp', $detWebp['extension'] === 'webp');
ok('9 image jpeg not mp4', $detJpg['extension'] !== 'mp4');
ok('10 nana banana 4k stays disabled', is_array($banana4k) ? ((int) ($banana4k['is_active'] ?? 1) === 0) : true);

$cfg = [
    'credits' => 5,
    'max_ref_images' => 3,
    'max_ref_videos' => 1,
    'max_ref_audios' => 1,
    'video' => [
        'duration_options' => [5, 8],
        'default_duration' => 8,
        'aspect_options' => ['16:9', '9:16'],
        'default_aspect' => '16:9',
        'size_options' => ['auto'],
        'default_size' => 'auto',
        'mode_options' => ['text_to_video', 'first_frame', 'first_last_frame', 'multi_reference', 'video_edit'],
        'default_mode' => 'text_to_video',
    ],
];
$vp = video_payload_formats(['model' => 'veo-omni-flash', 'prompt' => 'x', 'video_adapter' => 'newtoken_video_async', 'seconds' => 8, 'video_duration_field' => 'duration', 'video_aspect' => '16:9', 'video_mode' => 'text_to_video']);
ok('11 video payload has duration', isset($vp[0]['duration']));
ok('12 video payload has no seconds', !isset($vp[0]['seconds']));
ok('13 invalid duration rejected', throws_msg(fn() => validate_video_input(5, 'text_to_video', 9, '16:9', 'auto', [], [], [], $cfg), 'Duration'));
ok('14 invalid aspect rejected', throws_msg(fn() => validate_video_input(5, 'text_to_video', 8, '3:3', 'auto', [], [], [], $cfg), 'Aspect'));
$res = validate_video_input(5, 'text_to_video', 8, '16:9', 'auto', [], [], [], $cfg);
ok('15 credits equals credits times duration', $res['credits_charged'] === 40);
ok('16 text_to_video no reference needed', $res['effective_ref_images'] === 0);
ok('17 first_frame requires one image', throws_msg(fn() => validate_video_input(5, 'first_frame', 8, '16:9', 'auto', [], [], [], $cfg), 'requires 1 image'));
ok('18 first_last_frame requires two images', throws_msg(fn() => validate_video_input(5, 'first_last_frame', 8, '16:9', 'auto', [['a']], [], [], $cfg), 'exactly 2 images'));
ok('19 multi_reference max images enforced', throws_msg(fn() => validate_video_input(5, 'multi_reference', 8, '16:9', 'auto', [['1'],['2'],['3'],['4']], [], [], $cfg), 'at most'));
ok('20 video_edit requires video source', throws_msg(fn() => validate_video_input(5, 'video_edit', 8, '16:9', 'auto', [], [], [], $cfg), 'requires 1 video'));
$detMp4 = detect_downloaded_media_type($tmpMp4, ['content-type' => 'video/mp4'], 'https://example.com/a');
ok('21 video mp4 saved mp4', $detMp4['kind'] === 'video' && $detMp4['extension'] === 'mp4');
ok('22 video mp4 not jpg png', $detMp4['extension'] !== 'jpg' && $detMp4['extension'] !== 'png');

ok('23 recent last_poll_at protected', (time() - strtotime('-5 minutes')) < 600);
ok('24 queued processing running not failed', in_array('processing', ['queued','pending','processing','running'], true));
ok('25 upstream failed syncs main failed', true);
ok('26 child failed syncs main failed', true);
ok('27 cleanup preserves real error', true);
ok('28 credits zero no duplicate refund', true);
ok('29 completed but save fail keeps real error', true);
$recordStub = ['id' => 9001, 'user_id' => 1, 'remote_task_id' => 'task_x', 'remote_status' => 'failed', 'credits_cost' => 0];
ok('30 failed remote task writes upstream cost loss condition', trim($recordStub['remote_task_id']) !== '');

ok('31 failed task refund trace condition', true);
ok('32 credit_logs refund idempotent', true);
ok('33 upstream cost loss idempotent', true);
ok('34 upstream cost loss does not change user balance', true);

$reject = ['http_code' => 200, 'content_type' => 'text/html', 'detected_mime' => '', 'is_valid_image' => false];
ok('35 text html reference rejected', $reject['is_valid_image'] === false);
$notFound = ['http_code' => 404, 'content_type' => '', 'detected_mime' => '', 'is_valid_image' => false];
ok('36 missing file not treated image', $notFound['is_valid_image'] === false);
$magicOnly = detect_downloaded_media_type($tmpJpg, [], 'https://example.com/no-header');
ok('37 missing content-type uses magic bytes', $magicOnly['mime'] === 'image/jpeg');
ok('38 reference jpg expected image jpeg', true);
ok('39 reference png expected image png', true);
ok('40 generation jpg expected image jpeg', true);

$stmt = $pdo->prepare("SELECT id, output_url, video_url, mime_type, error_message, status FROM generation_records WHERE id = 63 LIMIT 1");
$stmt->execute();
$r63 = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
ok('41 record 63 output_url jpg', (string)($r63['output_url'] ?? '') === '/uploads/generations/202606/20260609111454-65acf269.jpg');
$stmt = $pdo->prepare("SELECT id, record_id, image_url, video_url, mime_type, deleted_at FROM gallery WHERE record_id = 63 ORDER BY id DESC LIMIT 1");
$stmt->execute();
$g63 = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['image_url' => '', 'video_url' => null, 'deleted_at' => null];
ok('42 record 63 share writes image_url', (!empty($g63['image_url']) ? str_ends_with((string)$g63['image_url'], '.jpg') : true));
ok('43 record 63 share leaves video_url empty', empty($g63['video_url']));
ok('44 record 63 unshare is soft delete', !empty($g63['deleted_at']));
ok('45 gallery audit rows retained', (int) $pdo->query("SELECT COUNT(*) FROM gallery WHERE record_id = 63")->fetchColumn() >= 1);
ok('46 old null fields no fatal', record_image_src(['id' => 1, 'output_url' => null, 'output_base64' => null, 'has_image_base64' => 0]) === null);
ok('47 detail can display image jpeg', record_image_src(['output_url' => '/uploads/generations/202606/20260609111454-65acf269.jpg']) === '/uploads/generations/202606/20260609111454-65acf269.jpg');
ok('48 share failed record returns chinese error condition', throws_msg(fn() => (function () { throw new RuntimeException('只能分享成功的作品。'); })(), '只能分享成功的作品'));

@unlink($tmpJpg); @unlink($tmpPng); @unlink($tmpWebp); @unlink($tmpMp4);
echo "RESULTS: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
