#!/usr/bin/env php
<?php
/**
 * test-model-options-dryrun.php
 *
 * Dry-run tests for NexoAPI model capability system.
 * Uses sudo mysql to read actual DB model configs.
 * NO real upstream calls, NO balance deduction.
 */

declare(strict_types=1);

$passed = 0;
$failed = 0;

function test(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "\033[32m[PASS]\033[0m $name\n";
        $passed++;
    } else {
        echo "\033[31m[FAIL]\033[0m $name";
        if ($detail) echo " — $detail";
        echo "\n";
        $failed++;
    }
}

function throws(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (Throwable $e) {
        return true;
    }
}

function notThrows(callable $fn): bool
{
    try {
        $fn();
        return true;
    } catch (Throwable $e) {
        echo "    \033[33m[WARN]\033[0m {$e->getMessage()}\n";
        return false;
    }
}

/**
 * Simulate backend video validation (mirrors generation_input_from_request).
 * Supports video_edit, video_reference, audio_reference modes.
 */
function validate_video_input(
    int $modelId,
    string $rawVideoMode,
    int $rawDuration,
    string $rawAspect,
    string $rawSize,
    array $imageFiles,
    array $videoFiles,
    array $audioFiles,
    array $modelConfig
): array {
    $vidCfg = $modelConfig['video'] ?? $modelConfig;
    $modelDurationOptions = $vidCfg['duration_options'] ?? [];
    $modelDefaultDuration = (int) ($vidCfg['default_duration'] ?? 0);
    $modelAspectOptions = $vidCfg['aspect_options'] ?? [];
    $modelDefaultAspect = trim($vidCfg['default_aspect'] ?? '16:9');
    $modelSizeOptions = $vidCfg['size_options'] ?? [];
    $modelDefaultSize = trim($vidCfg['default_size'] ?? 'auto');
    $modelModeOptions = $vidCfg['mode_options'] ?? [];
    $modelDefaultMode = trim($vidCfg['default_mode'] ?? 'text_to_video');
    $modelMaxRefImages = max(0, (int) ($modelConfig['max_ref_images'] ?? 0));
    $modelMaxRefVideos = max(0, (int) ($modelConfig['max_ref_videos'] ?? 0));
    $modelMaxRefAudios = max(0, (int) ($modelConfig['max_ref_audios'] ?? 0));
    $modelCredits = max(0, (int) ($modelConfig['credits'] ?? 0));

    // Validate video mode
    $userRequestedMode = $rawVideoMode !== '' ? $rawVideoMode : $modelDefaultMode;
    if ($rawVideoMode !== '' && count($modelModeOptions) > 0 && !in_array($rawVideoMode, $modelModeOptions, true)) {
        throw new InvalidArgumentException("Mode $rawVideoMode not in allowed list.");
    }
    $effectiveMode = in_array($userRequestedMode, $modelModeOptions, true) ? $userRequestedMode : $modelDefaultMode;

    // Validate duration
    if (count($modelDurationOptions) > 0 && !in_array($rawDuration, $modelDurationOptions, true)) {
        throw new InvalidArgumentException("Duration $rawDuration not in allowed list.");
    }
    $effectiveDuration = max(1, $rawDuration > 0 ? $rawDuration : $modelDefaultDuration);

    // Validate aspect
    if (count($modelAspectOptions) > 0 && !in_array($rawAspect, $modelAspectOptions, true)) {
        throw new InvalidArgumentException("Aspect $rawAspect not in allowed list.");
    }
    $effectiveAspect = $rawAspect;

    // Validate size
    if (count($modelSizeOptions) > 0 && !in_array($rawSize, $modelSizeOptions, true)) {
        throw new InvalidArgumentException("Size $rawSize not in allowed list.");
    }
    $effectiveSize = $rawSize;

    // Reference validation based on mode
    $requiredImages = 0;
    $requiredVideos = 0;
    $requiredAudios = 0;
    if ($effectiveMode === 'first_frame') $requiredImages = 1;
    elseif ($effectiveMode === 'first_last_frame') $requiredImages = 2;
    elseif ($effectiveMode === 'multi_reference') $requiredImages = 1;
    elseif ($effectiveMode === 'video_edit') $requiredVideos = 1;
    elseif ($effectiveMode === 'video_reference') $requiredVideos = 1;
    elseif ($effectiveMode === 'audio_reference') $requiredAudios = 1;

    $imageCount = count($imageFiles);
    $videoCount = count($videoFiles);
    $audioCount = count($audioFiles);

    // Image uploads
    if ($imageCount > 0 || $requiredImages > 0) {
        if ($requiredImages > 0 && $imageCount < $requiredImages) {
            throw new InvalidArgumentException("Mode $effectiveMode requires $requiredImages image(s).");
        }
        if ($effectiveMode === 'text_to_video' && $imageCount > 0) {
            throw new InvalidArgumentException('text_to_video mode does not accept reference images.');
        }
        if ($effectiveMode === 'first_frame' && $imageCount > 1) {
            throw new InvalidArgumentException('first_frame mode allows at most 1 image.');
        }
        if ($effectiveMode === 'first_last_frame' && ($imageCount < 2 || $imageCount > 2)) {
            throw new InvalidArgumentException('first_last_frame mode requires exactly 2 images.');
        }
        if (($effectiveMode === 'multi_reference' || $effectiveMode === 'video_edit' || $effectiveMode === 'video_reference')
            && $imageCount > $modelMaxRefImages && $modelMaxRefImages > 0) {
            throw new InvalidArgumentException("Mode $effectiveMode allows at most $modelMaxRefImages images.");
        }
    }

    // Video uploads
    if ($videoCount > 0 || $requiredVideos > 0) {
        if ($requiredVideos > 0 && $videoCount < $requiredVideos) {
            throw new InvalidArgumentException("Mode $effectiveMode requires $requiredVideos video(s).");
        }
        if ($videoCount > 0 && $modelMaxRefVideos > 0 && $videoCount > $modelMaxRefVideos) {
            throw new InvalidArgumentException("Mode $effectiveMode allows at most $modelMaxRefVideos videos.");
        }
    }

    // Audio uploads
    if ($audioCount > 0 || $requiredAudios > 0) {
        if ($requiredAudios > 0 && $audioCount < $requiredAudios) {
            throw new InvalidArgumentException("Mode $effectiveMode requires $requiredAudios audio(s).");
        }
        if ($audioCount > 0 && $modelMaxRefAudios > 0 && $audioCount > $modelMaxRefAudios) {
            throw new InvalidArgumentException("Mode $effectiveMode allows at most $modelMaxRefAudios audios.");
        }
    }

    // Credits
    $effectiveCredits = $modelCredits > 0 ? $modelCredits * $effectiveDuration : 0;

    return [
        'video_mode' => $effectiveMode,
        'video_duration' => $effectiveDuration,
        'video_aspect' => $effectiveAspect,
        'video_size' => $effectiveSize,
        'credits_charged' => $effectiveCredits,
        'effective_ref_images' => $imageCount,
        'effective_ref_videos' => $videoCount,
        'effective_ref_audios' => $audioCount,
    ];
}

/**
 * Simulate backend image validation.
 * Invalid aspect/size is REJECTED (not silently replaced).
 */
function validate_image_input(
    string $mode,
    int $modelId,
    string $rawAspect,
    string $rawSize,
    array $uploadedFiles,
    array $modelConfig
): array {
    $imgConfig = $modelConfig['image'] ?? $modelConfig;
    $aspectOptions = $imgConfig['aspect_options'] ?? [];
    $defaultAspect = trim($imgConfig['default_aspect'] ?? 'auto');
    $sizeOptions = $imgConfig['size_options'] ?? [];
    $defaultSize = trim($imgConfig['default_size'] ?? 'auto');
    $supportsEdit = (bool) ($modelConfig['supports_edit'] ?? false);

    if ($mode === 'edit' && !$supportsEdit) {
        throw new InvalidArgumentException("Model does not support edit mode.");
    }

    $ALL_IMAGE_ASPECTS = ['auto','1:1','3:2','2:3','4:3','3:4','5:4','4:5','16:9','9:16','2:1','1:2','21:9','9:21'];
    $ALL_IMAGE_SIZES = ['auto','1024x1024','1536x1024','1024x1536','2048x2048'];
    $allowedAspects = count($aspectOptions) > 0 ? $aspectOptions : $ALL_IMAGE_ASPECTS;
    $allowedSizes = count($sizeOptions) > 0 ? $sizeOptions : $ALL_IMAGE_SIZES;

    if (count($aspectOptions) > 0 && !in_array($rawAspect, $aspectOptions, true)) {
        throw new InvalidArgumentException("Image aspect '$rawAspect' not in allowed list.");
    }
    if (count($aspectOptions) === 0 && !in_array($rawAspect, $ALL_IMAGE_ASPECTS, true)) {
        throw new InvalidArgumentException("Image aspect '$rawAspect' is not a valid value.");
    }
    if (count($sizeOptions) > 0 && !in_array($rawSize, $sizeOptions, true)) {
        throw new InvalidArgumentException("Image size '$rawSize' not in allowed list.");
    }
    if (count($sizeOptions) === 0 && !in_array($rawSize, $ALL_IMAGE_SIZES, true)) {
        throw new InvalidArgumentException("Image size '$rawSize' is not a valid value.");
    }

    return [
        'size' => $rawAspect !== '' ? $rawAspect : $defaultAspect,
        'aspect' => $rawSize !== '' ? $rawSize : $defaultSize,
        'mode' => $mode,
        'supports_edit' => $supportsEdit,
    ];
}

/**
 * Read model configs from DB (with new video_edit fields).
 */
function get_model_configs(): array
{
    $cmd = 'sudo mysql -N -e "SELECT id, name, model_id, model_type, credits, max_reference_images, '
        . 'max_reference_videos, max_reference_audios, video_adapter, '
        . 'image_aspect_options_json, image_default_aspect, image_size_options_json, image_default_size, '
        . 'video_duration_options_json, video_default_duration, '
        . 'video_aspect_options_json, video_default_aspect, '
        . 'video_size_options_json, video_default_size, '
        . 'video_mode_options_json, video_default_mode, '
        . 'supports_edit, edit_adapter '
        . 'FROM aio222.ai_models ORDER BY id;" 2>&1';

    $output = shell_exec($cmd);
    if (!$output || strpos($output, 'ERROR') !== false) {
        throw new RuntimeException("Cannot read DB: " . substr($output, 0, 200));
    }

    $configs = [];
    foreach (explode("\n", trim($output)) as $line) {
        if ($line === '') continue;
        $fields = explode("\t", $line);
        if (count($fields) < 22) continue;

        [$id, $name, $modelId, $type, $credits,
         $maxRefImg, $maxRefVid, $maxRefAud, $videoAdapter,
         $imgAspJson, $imgDefAsp, $imgSzJson, $imgDefSz,
         $vidDurJson, $vidDefDur,
         $vidAspJson, $vidDefAsp,
         $vidSzJson, $vidDefSz,
         $vidModeJson, $vidDefMode,
         $supportsEdit, $editAdapter] = $fields;

        $configs[(int)$id] = [
            'id' => (int)$id,
            'name' => $name,
            'model_id' => $modelId,
            'type' => $type,
            'credits' => (int)$credits,
            'max_ref_images' => (int)$maxRefImg,
            'max_ref_videos' => (int)$maxRefVid,
            'max_ref_audios' => (int)$maxRefAud,
            'video_adapter' => $videoAdapter,
            'supports_edit' => (int)$supportsEdit,
            'edit_adapter' => $editAdapter,
            'image' => [
                'aspect_options' => json_decode($imgAspJson, true) ?: [],
                'default_aspect' => $imgDefAsp,
                'size_options' => json_decode($imgSzJson, true) ?: [],
                'default_size' => $imgDefSz,
            ],
            'video' => [
                'duration_options' => array_map('intval', json_decode($vidDurJson, true) ?: []),
                'default_duration' => (int)$vidDefDur,
                'aspect_options' => json_decode($vidAspJson, true) ?: [],
                'default_aspect' => $vidDefAsp,
                'size_options' => json_decode($vidSzJson, true) ?: [],
                'default_size' => $vidDefSz,
                'mode_options' => json_decode($vidModeJson, true) ?: [],
                'default_mode' => $vidDefMode,
            ],
        ];
    }
    return $configs;
}

/**
 * Classify a model using the same logic as admin/ai_models.php classify_model().
 */
function classify_model(array $cfg): string
{
    $type = strtolower(trim($cfg['type'] ?? 'image'));
    $name = strtolower(trim($cfg['name'] ?? ''));
    $mid  = strtolower(trim($cfg['model_id'] ?? ''));

    if ($type === 'image') return 'image';
    if ($type === 'video') return 'video';

    if ($type === 'chat') {
        if (preg_match('/\b(gpt|grok|claude|chat|llama|qwen|yi|deepseek|gemini|o1|o3|o4)\b/', $name)
            && !preg_match('/\b(gpt-image|banana|nana|veo|seedance|video|sora)\b/', $name)) {
            return 'chat';
        }
        return 'other';
    }

    foreach (['banana', 'nana', 'gpt-image'] as $kw) {
        if (strpos($name, $kw) !== false) return 'image';
    }
    foreach (['veo', 'seedance', 'video-pro', 'sora'] as $kw) {
        if (strpos($name, $kw) !== false) return 'video';
    }

    return 'other';
}

echo "\033[1;36mLoading model configs from database...\033[0m\n";
$configs = get_model_configs();
echo "Loaded " . count($configs) . " models.\n";

// ============================================================
// ADMIN PARTITION TESTS
// ============================================================
echo "\n\033[1;33m=== ADMIN PARTITION TESTS ===\033[0m\n\n";

// GPT image 2 (id=1) should be in image area
{
    $cfg = $configs[1] ?? null;
    test('Model 1 (GPT image 2) exists', $cfg !== null);
    test('Model 1 is classified as image', classify_model($cfg ?? []) === 'image');
    test('Model 1 supports_edit=0', ($cfg['supports_edit'] ?? -1) === 0);
}

// Nano Banana (id=2) should be in image area
{
    $cfg = $configs[2] ?? null;
    test('Model 2 (Nano Banana) exists', $cfg !== null);
    test('Model 2 is classified as image', classify_model($cfg ?? []) === 'image');
    test('Model 2 supports_edit=1', ($cfg['supports_edit'] ?? -1) === 1);
}

// veo/seedance should be in video area
foreach ([5, 6, 9] as $id) {
    $cfg = $configs[$id] ?? null;
    test("Model $id (video) is classified as video", classify_model($cfg ?? []) === 'video');
}

// ============================================================
// IMAGE MODEL TESTS
// ============================================================
echo "\n\033[1;33m=== IMAGE MODEL TESTS ===\033[0m\n\n";

// T1: GPT image 2 draw with auto
{
    $cfg = $configs[1] ?? null;
    $ok = notThrows(fn() => validate_image_input('draw', 1, 'auto', 'auto', [], $cfg));
    test('T1: GPT image 2 draw with auto aspect passes', $ok);
}

// T2: GPT image 2 edit 被拒绝
{
    $cfg = $configs[1] ?? null;
    $ok = throws(fn() => validate_image_input('edit', 1, 'auto', 'auto', [], $cfg));
    test('T2: GPT image 2 edit is rejected', $ok);
}

// T3: Nano Banana edit 合法
{
    $cfg = $configs[2] ?? null;
    $ok = notThrows(fn() => validate_image_input('edit', 2, '1:1', 'auto', ['fake.jpg'], $cfg));
    test('T3: Nano Banana edit passes', $ok);
}

// T4: 非允许图片比例被拒绝
{
    $cfg = $configs[1] ?? null;
    $ok = throws(fn() => validate_image_input('draw', 1, '99:99', 'auto', [], $cfg));
    test('T4: Invalid image aspect (99:99) rejected', $ok);
}

// T5: 非允许图片尺寸被拒绝
{
    $cfg = $configs[2] ?? null;
    $ok = throws(fn() => validate_image_input('draw', 2, 'auto', 'invalid-size', [], $cfg));
    test('T5: Invalid image size rejected', $ok);
}

// ============================================================
// VIDEO MODEL TESTS
// ============================================================
echo "\n\033[1;33m=== VIDEO MODEL TESTS ===\033[0m\n\n";

// T6: veo-omni-flash (id=5) multi_reference + 1 image passes
{
    $cfg = $configs[5] ?? null;
    $ok = notThrows(fn() => validate_video_input(5, 'multi_reference', 10, '16:9', 'auto', ['ref1.jpg'], [], [], $cfg));
    test('T6: veo-omni-flash multi_reference+1 image passes', $ok);
}

// T7: veo-omni-flash duration=15 被拒绝
{
    $cfg = $configs[5] ?? null;
    $ok = throws(fn() => validate_video_input(5, 'multi_reference', 15, '16:9', 'auto', [], [], [], $cfg));
    test('T7: veo-omni-flash duration=15 rejected', $ok);
}

// T8: veo-3-1 (id=6) multi_reference + 1 image passes
{
    $cfg = $configs[6] ?? null;
    $ok = notThrows(fn() => validate_video_input(6, 'multi_reference', 8, '16:9', 'auto', ['ref1.jpg'], [], [], $cfg));
    test('T8: veo-3-1 multi_reference+1 image passes', $ok);
}

// T9: veo-3-1 duration=20 被拒绝
{
    $cfg = $configs[6] ?? null;
    $ok = throws(fn() => validate_video_input(6, 'multi_reference', 20, '16:9', 'auto', [], [], [], $cfg));
    test('T9: veo-3-1 duration=20 rejected', $ok);
}

// T10: 非允许 video aspect 被拒绝
{
    $cfg = $configs[5] ?? null;
    $ok = throws(fn() => validate_video_input(5, 'multi_reference', 10, '99:99', 'auto', [], [], [], $cfg));
    test('T10: Invalid video aspect (99:99) rejected', $ok);
}

// T11: 非允许 video size 被拒绝
{
    $cfg = $configs[5] ?? null;
    $ok = throws(fn() => validate_video_input(5, 'multi_reference', 10, '16:9', 'invalid-size', [], [], [], $cfg));
    test('T11: Invalid video size rejected', $ok);
}

// T12: first_frame 0 张被拒绝
{
    $cfg = $configs[5] ?? null;
    $ok = throws(fn() => validate_video_input(5, 'first_frame', 10, '16:9', 'auto', [], [], [], $cfg));
    test('T12: first_frame with 0 images rejected', $ok);
}

// T13: first_frame 1 张通过
{
    $cfg = $configs[5] ?? null;
    $ok = notThrows(fn() => validate_video_input(5, 'first_frame', 10, '16:9', 'auto', ['img1.jpg'], [], [], $cfg));
    test('T13: first_frame with 1 image passes', $ok);
}

// T14: first_last_frame 1 张被拒绝
{
    $cfg = $configs[5] ?? null;
    $ok = throws(fn() => validate_video_input(5, 'first_last_frame', 10, '16:9', 'auto', ['img1.jpg'], [], [], $cfg));
    test('T14: first_last_frame with 1 image rejected', $ok);
}

// T15: first_last_frame 2 张通过
{
    $cfg = $configs[9] ?? null;
    $ok = notThrows(fn() => validate_video_input(9, 'first_last_frame', 8, '16:9', 'auto', ['img1.jpg','img2.jpg'], [], [], $cfg));
    test('T15: first_last_frame with 2 images passes', $ok);
}

// T16: multi_reference 超过 max_reference_images 被拒绝
{
    $cfg = $configs[5] ?? null;
    $imgs = array_map(fn($i) => "img$i.jpg", range(1, 10));
    $ok = throws(fn() => validate_video_input(5, 'multi_reference', 10, '16:9', 'auto', $imgs, [], [], $cfg));
    test('T16: multi_reference with 10 images (>max 9) rejected', $ok);
}

// T17: credits_cost = credits × selected_duration
{
    $cfg = $configs[5] ?? null;
    $result = null;
    try {
        $result = validate_video_input(5, 'multi_reference', 10, '16:9', 'auto', ['ref1.jpg'], [], [], $cfg);
    } catch (Throwable $e) { echo "    \033[33m[WARN]\033[0m {$e->getMessage()}\n"; }
    $expected = 6 * 10;
    $actual = $result !== null ? ($result['credits_charged'] ?? -1) : -1;
    test("T17: credits_charged = 6×10=60 (got $actual)", $actual === $expected);
}

// T18: newtoken_video_async adapter
{
    $cfg = $configs[5] ?? null;
    test('T18: Model 5 uses newtoken_video_async adapter', ($cfg['video_adapter'] ?? '') === 'newtoken_video_async');
}

// T19: newtoken uses duration (not seconds)
{
    $cfg = $configs[5] ?? null;
    $hasDuration = in_array(10, $cfg['video']['duration_options'] ?? [], true);
    test('T19: Model 5 duration options include 10', $hasDuration);
}

// T20: Invalid duration throws
{
    $cfg = $configs[5] ?? null;
    $ok = throws(fn() => validate_video_input(5, 'multi_reference', 999, '16:9', 'auto', [], [], [], $cfg));
    test('T20: Invalid duration 999 throws exception', $ok);
}

// T21: video payload result structure
{
    $cfg = $configs[5] ?? null;
    $result = null;
    try {
        $result = validate_video_input(5, 'multi_reference', 10, '16:9', 'auto', ['ref1.jpg'], [], [], $cfg);
    } catch (Throwable $e) { echo "    \033[33m[WARN]\033[0m {$e->getMessage()}\n"; }
    if ($result !== null) {
        test('T21a: video_mode = multi_reference', ($result['video_mode'] ?? '') === 'multi_reference');
        test('T21b: video_duration = 10', ($result['video_duration'] ?? 0) === 10);
        test('T21c: video_aspect = 16:9', ($result['video_aspect'] ?? '') === '16:9');
        test('T21d: video_size = auto', ($result['video_size'] ?? '') === 'auto');
        test('T21e: credits_charged = 60', ($result['credits_charged'] ?? 0) === 60);
    } else {
        test('T21: validate returned non-null', false, 'result was null');
    }
}

// ============================================================
// VIDEO EDIT TESTS (new)
// ============================================================
echo "\n\033[1;33m=== VIDEO EDIT CAPABILITY TESTS ===\033[0m\n\n";

// T22: Model 9 supports video_edit mode
{
    $cfg = $configs[9] ?? null;
    $modes = $cfg['video']['mode_options'] ?? [];
    test('T22: Model 9 (veo-omni-flash-edit) supports video_edit mode', in_array('video_edit', $modes, true));
}

// T23: Model 9 max_ref_videos = 1
{
    $cfg = $configs[9] ?? null;
    test('T23: Model 9 max_ref_videos = 1', ($cfg['max_ref_videos'] ?? -1) === 1);
}

// T24: video_edit mode with 0 videos rejected
{
    $cfg = $configs[9] ?? null;
    $ok = throws(fn() => validate_video_input(9, 'video_edit', 8, '16:9', 'auto', [], [], [], $cfg));
    test('T24: video_edit with 0 videos rejected', $ok);
}

// T25: video_edit mode with 1 video passes
{
    $cfg = $configs[9] ?? null;
    $ok = notThrows(fn() => validate_video_input(9, 'video_edit', 8, '16:9', 'auto', [], ['vid1.mp4'], [], $cfg));
    test('T25: video_edit with 1 video passes', $ok);
}

// T26: video_edit mode with >max_ref_videos videos rejected
{
    $cfg = $configs[9] ?? null;
    $ok = throws(fn() => validate_video_input(9, 'video_edit', 8, '16:9', 'auto', [], ['v1.mp4','v2.mp4'], [], $cfg));
    test('T26: video_edit with 2 videos (>max 1) rejected', $ok);
}

// T27: Model 9 does NOT support video_reference (not configured yet)
{
    $cfg = $configs[9] ?? null;
    $modes = $cfg['video']['mode_options'] ?? [];
    test('T27: Model 9 does NOT support video_reference (not configured)', !in_array('video_reference', $modes, true));
}

// T28: Models 5,6 don't support video_edit
{
    foreach ([5, 6] as $id) {
        $cfg = $configs[$id] ?? null;
        $modes = $cfg['video']['mode_options'] ?? [];
        test("T28-$id: Model $id does NOT support video_edit", !in_array('video_edit', $modes, true));
    }
}

// T29: video_edit has max_ref_images
{
    $cfg = $configs[9] ?? null;
    test('T29: Model 9 max_ref_images >= 9', ($cfg['max_ref_images'] ?? 0) >= 9);
}

// T30: Model 9 max_ref_audios = 0
{
    $cfg = $configs[9] ?? null;
    test('T30: Model 9 max_ref_audios = 0', ($cfg['max_ref_audios'] ?? -1) === 0);
}

// ============================================================
// SUMMARY
// ============================================================
echo "\n" . str_repeat('=', 52) . "\n";
echo "\033[1mRESULTS:\033[0m \033[32m$passed passed\033[0m, \033[31m$failed failed\033[0m\n";
echo str_repeat('=', 52) . "\n";

exit($failed > 0 ? 1 : 0);
