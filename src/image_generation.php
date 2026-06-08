<?php

declare(strict_types=1);

require_once __DIR__ . '/api_client.php';

/**
 * 鏀寔鐨勭敾闈㈡瘮渚嬪垪琛?
 * @return string[]
 */
function generation_allowed_sizes(): array
{
    return ['auto', '1:1', '3:2', '2:3', '4:3', '3:4', '5:4', '4:5', '16:9', '9:16', '2:1', '1:2', '21:9', '9:21'];
}

/**
 * 鏀寔鐨勭敾璐ㄥ垪琛?
 * @return string[]
 */
function generation_allowed_quality(): array
{
    return ['auto', 'low', 'medium', 'high'];
}

function video_allowed_resolutions(): array
{
    return ['auto', '720p', '1080p'];
}

function normalize_generation_size(string $size): string
{
    $size = strtolower(trim($size));

    $map = [
        '1024x1024' => '1:1',
        '1024x1536' => '9:16',
        '1024x1792' => '9:16',
        '1536x1024' => '16:9',
        '1792x1024' => '16:9',
    ];

    return $map[$size] ?? $size;
}

function normalize_generation_quality(string $quality): string
{
    $quality = strtolower(trim($quality));

    $map = [
        '' => 'auto',
        'standard' => 'medium',
        'hd' => 'high',
    ];

    return $map[$quality] ?? $quality;
}

function normalize_video_resolution(string $resolution): string
{
    $resolution = strtolower(trim($resolution));
    $map = [
        '' => 'auto',
        'default' => 'auto',
    ];

    return $map[$resolution] ?? $resolution;
}

function video_resolution_is_valid(string $resolution): bool
{
    return in_array($resolution, video_allowed_resolutions(), true);
}

function generation_size_is_valid(string $size): bool
{
    return in_array($size, generation_allowed_sizes(), true)
        || preg_match('/^\d+x\d+$/i', $size) === 1;
}

/**
 * 鏀寔鐨勫浘鐗囨牸寮忓垪琛?
 * @return string[]
 */
function generation_allowed_formats(): array
{
    return ['png', 'jpeg', 'webp'];
}

/**
 * 鏀寔鐨勭敓鎴愭ā寮忓垪琛?
 * @return string[]
 */
function generation_allowed_modes(): array
{
    return ['draw', 'edit', 'video'];
}

/**
 * 鏈€澶х紪杈戝弬鑰冨浘鐗囨暟
 * @return int
 */
function max_edit_images(): int
{
    return max(1, min(16, (int) app_setting('max_edit_images', '4')));
}

/**
 * 鍗曞紶缂栬緫鍙傝€冨浘鐗囨渶澶?MB
 * @return int
 */
function max_edit_image_mb(): int
{
    return max(1, min(50, (int) app_setting('max_edit_image_mb', '10')));
}

/**
 * 缂栬緫鍙傝€冨浘鐗囨渶澶у儚绱?
 * @return int
 */
function max_edit_image_dimension(): int
{
    return max(512, min(12000, (int) app_setting('max_edit_image_dimension', '8000')));
}

/**
 * 鑾峰彇鐢熸垚妯″紡鐨勫崟娆℃秷鑰?
 * @param  string $mode     鐢熸垚妯″紡锛坉raw/edit/video锛?
 * @param  int    $modelId  鍙€夛紝AI 妯″瀷 ID锛堟湁鑷畾涔夌偣鏁板垯瑕嗙洊榛樿锛?
 * @return int
 */
function generation_cost_for(string $mode, int $modelId = 0): int
{
    // 浠?AI 妯″瀷鑾峰彇娑堣€楃偣鏁?
    if ($modelId > 0) {
        $stmt = db()->prepare('SELECT credits FROM ai_models WHERE id = ? AND credits IS NOT NULL AND credits > 0');
        $stmt->execute([$modelId]);
        $modelCredits = (int) $stmt->fetchColumn();
        if ($modelCredits > 0) {
            return $modelCredits;
        }
    }
    // 妯″瀷鏈厤缃偣鏁版椂榛樿 1
    return 1;
}

/**
 * 浠庤姹備腑鎻愬彇骞舵牎楠岀敓鎴愬弬鏁?
 * @param  array $input  
 * @param  array $files  
 * @return array
 */
function generation_input_from_request(array $input, array $files): array
{
    $prompt = trim((string) ($input['prompt'] ?? ''));
    $size   = normalize_generation_size((string) ($input['size'] ?? 'auto'));
    $mode   = (string) ($input['mode'] ?? 'draw');

    if ($prompt === '') {
        throw new InvalidArgumentException('Prompt is required.');
    }
    if (!generation_size_is_valid($size)
        || !in_array($mode, generation_allowed_modes(), true)) {
        throw new InvalidArgumentException('Invalid generation parameters.');
    }

    if ($mode === 'video') {
        $format = (string) ($input['output_format'] ?? 'mp4');
        if (!in_array($format, ['mp4', 'webm'], true)) {
            $format = 'mp4';
        }
        $modelId = (int) ($input['ai_model_id'] ?? 0);

        // Load video model capabilities from DB
        $modelSupportsRef = 0;
        $modelRefRequired = 0;
        $modelMaxRefImages = 1;
        $modelFixedSeconds = 0;
        $modelDurationOptions = [];
        $modelDefaultDuration = null;
        $modelAspectOptions = [];
        $modelDefaultAspect = '16:9';
        $modelSizeOptions = [];
        $modelDefaultSize = 'auto';
        $modelModeOptions = [];
        $modelDefaultMode = 'text_to_video';
        $modelRefField = 'reference_images';
        $modelDurationField = 'duration';
        $modelAspectField = 'aspect_ratio';
        $modelSizeField = 'size';
        $modelInputModeField = 'input_mode';
        $modelCredits = 0;

        if ($modelId > 0) {
            $stmt = db()->prepare(
                'SELECT supports_reference, reference_required, max_reference_images, fixed_seconds, '
                . 'video_duration_options_json, video_default_duration, '
                . 'video_aspect_options_json, video_default_aspect, '
                . 'video_size_options_json, video_default_size, '
                . 'video_mode_options_json, video_default_mode, '
                . 'video_reference_field, video_duration_field, video_aspect_field, '
                . 'video_size_field, video_input_mode_field, credits '
                . 'FROM ai_models WHERE id = ? AND is_active = 1 AND model_type = ? LIMIT 1'
            );
            $stmt->execute([$modelId, 'video']);
            $vm = $stmt->fetch();
            if ($vm) {
                $modelSupportsRef = (int) ($vm['supports_reference'] ?? 0);
                $modelRefRequired = (int) ($vm['reference_required'] ?? 0);
                $modelMaxRefImages = max(1, (int) ($vm['max_reference_images'] ?? 1));
                $modelFixedSeconds = max(0, (int) ($vm['fixed_seconds'] ?? 0));
                $modelCredits = max(0, (int) ($vm['credits'] ?? 0));

                $dOpts = json_decode((string) ($vm['video_duration_options_json'] ?? ''), true);
                if (is_array($dOpts) && count($dOpts) > 0) {
                    $modelDurationOptions = array_filter(array_map('intval', $dOpts), fn($v) => $v > 0);
                }
                $modelDefaultDuration = (int) ($vm['video_default_duration'] ?? 0);

                $aOpts = json_decode((string) ($vm['video_aspect_options_json'] ?? ''), true);
                if (is_array($aOpts)) {
                    $modelAspectOptions = array_values(array_filter($aOpts, fn($v) => is_string($v) && $v !== ''));
                }
                $modelDefaultAspect = trim((string) ($vm['video_default_aspect'] ?? '16:9'));

                $sOpts = json_decode((string) ($vm['video_size_options_json'] ?? ''), true);
                if (is_array($sOpts)) {
                    $modelSizeOptions = array_values(array_filter($sOpts, fn($v) => is_string($v) && $v !== ''));
                }
                $modelDefaultSize = trim((string) ($vm['video_default_size'] ?? 'auto'));

                $mOpts = json_decode((string) ($vm['video_mode_options_json'] ?? ''), true);
                if (is_array($mOpts)) {
                    $modelModeOptions = array_values(array_filter($mOpts, fn($v) => is_string($v) && $v !== ''));
                }
                $modelDefaultMode = trim((string) ($vm['video_default_mode'] ?? 'text_to_video'));

                $modelRefField = trim((string) ($vm['video_reference_field'] ?? 'reference_images'));
                if ($modelRefField === '') $modelRefField = 'reference_images';
                $modelDurationField = trim((string) ($vm['video_duration_field'] ?? 'duration'));
                if ($modelDurationField === '') $modelDurationField = 'duration';
                $modelAspectField = trim((string) ($vm['video_aspect_field'] ?? 'aspect_ratio'));
                if ($modelAspectField === '') $modelAspectField = 'aspect_ratio';
                $modelSizeField = trim((string) ($vm['video_size_field'] ?? 'size'));
                if ($modelSizeField === '') $modelSizeField = 'size';
                $modelInputModeField = trim((string) ($vm['video_input_mode_field'] ?? 'input_mode'));
                if ($modelInputModeField === '') $modelInputModeField = 'input_mode';
            }
        }

        // Read user-submitted values (will be validated against model config)
        $rawVideoMode = strtolower(trim((string) ($input['video_mode'] ?? $modelDefaultMode)));
        $effectiveMode = in_array($rawVideoMode, $modelModeOptions, true) ? $rawVideoMode : $modelDefaultMode;

        $rawDuration = (int) ($input['video_duration'] ?? 0);
        if ($rawDuration <= 0) $rawDuration = $modelDefaultDuration;
        if (count($modelDurationOptions) > 0 && !in_array($rawDuration, $modelDurationOptions, true)) {
            $rawDuration = $modelDefaultDuration;
        }
        $effectiveDuration = max(1, $rawDuration);

        $rawAspect = trim((string) ($input['video_aspect'] ?? $modelDefaultAspect));
        if (count($modelAspectOptions) > 0 && !in_array($rawAspect, $modelAspectOptions, true)) {
            $rawAspect = $modelDefaultAspect;
        }
        $effectiveAspect = $rawAspect;

        $rawSize = trim((string) ($input['video_size'] ?? $modelDefaultSize));
        if (count($modelSizeOptions) > 0 && !in_array($rawSize, $modelSizeOptions, true)) {
            $rawSize = $modelDefaultSize;
        }
        $effectiveSize = $rawSize;

        // Reference image validation based on mode
        $inputImages = [];
        $hasUploads = request_has_uploaded_edit_images($files);
        $requiredImages = 0;
        if ($effectiveMode === 'first_frame' || $effectiveMode === 'first_last_frame' || $effectiveMode === 'multi_reference') {
            $requiredImages = $effectiveMode === 'first_last_frame' ? 2 : 1;
        }

        if ($hasUploads || $effectiveMode !== 'text_to_video') {
            $inputImages = generation_uploaded_images_from_files($files);
            $uploadCount = count($inputImages);
            if ($uploadCount === 0) {
                if ($requiredImages > 0) {
                    throw new InvalidArgumentException("当前模式「{$effectiveMode}」至少需要 {$requiredImages} 张参考图片。");
                }
            } else {
                if ($effectiveMode === 'text_to_video' && $uploadCount > 0) {
                    throw new InvalidArgumentException('文生视频模式不需要参考图片，请切换到参考模式或去掉图片。');
                }
                if ($effectiveMode === 'first_frame' && $uploadCount > 1) {
                    throw new InvalidArgumentException('首帧参考模式最多上传 1 张参考图片。');
                }
                if ($effectiveMode === 'first_last_frame' && ($uploadCount < 2 || $uploadCount > 2)) {
                    throw new InvalidArgumentException('首尾帧模式必须上传恰好 2 张参考图片（首帧 / 尾帧）。');
                }
                if ($effectiveMode === 'multi_reference' && $uploadCount > $modelMaxRefImages) {
                    throw new InvalidArgumentException("当前模型最多允许 {$modelMaxRefImages} 张参考图片。");
                }
            }
        } elseif ($requiredImages > 0) {
            throw new InvalidArgumentException("当前模式「{$effectiveMode}」至少需要 {$requiredImages} 张参考图片。");
        }

        // Credits: credits × duration (backend enforced, not from frontend)
        $effectiveCredits = $modelCredits > 0 ? $modelCredits * $effectiveDuration : 0;

        $model = $modelId > 0 ? '' : (string) app_setting('video_model', '');
        return [
            'mode'              => $mode,
            'prompt'           => $prompt,
            'size'             => $effectiveSize,
            'quality'          => $effectiveAspect,
            'format'           => $format,
            'seconds'          => $effectiveDuration,
            'model'            => $model,
            'ai_model_id'      => $modelId,
            'input_images'     => $inputImages,
            // New fields
            'video_mode'       => $effectiveMode,
            'video_duration'   => $effectiveDuration,
            'video_aspect'    => $effectiveAspect,
            'video_size'      => $effectiveSize,
            'credits_charged'  => $effectiveCredits,
            // Payload field mappings
            'video_ref_field'     => $modelRefField,
            'video_duration_field' => $modelDurationField,
            'video_aspect_field'  => $modelAspectField,
            'video_size_field'    => $modelSizeField,
            'video_input_mode_field' => $modelInputModeField,
            'video_adapter'       => 'newtoken_video_async', // always set for video mode
        ];
    }


    $quality = normalize_generation_quality((string) ($input['quality'] ?? 'auto'));
    $format  = (string) ($input['output_format'] ?? 'png');
    if (!in_array($quality, generation_allowed_quality(), true)
        || !in_array($format, generation_allowed_formats(), true)) {
        throw new InvalidArgumentException('Invalid generation parameters.');
    }

    $hasEditUploads = request_has_uploaded_edit_images($files);
    if ($hasEditUploads) {
        $mode = 'edit';
    }

    $inputImages = [];
    if ($mode === 'edit') {
        $inputImages = generation_uploaded_images_from_files($files);
        if (!$inputImages) {
            throw new InvalidArgumentException('Edit mode requires at least one reference image.');
        }
    }

    $modelId = (int) ($input['ai_model_id'] ?? 0);
    $model   = $modelId > 0 ? '' : image_model_id();

    // Validate edit mode is supported when ai_model_id is provided
    if ($mode === 'edit' && $modelId > 0) {
        $stmt = db()->prepare('SELECT supports_edit, edit_adapter FROM ai_models WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$modelId]);
        $modelRow = $stmt->fetch();
        if (!$modelRow) {
            throw new InvalidArgumentException('The selected model is unavailable. Please refresh the page and try again.');
        }
        $supportsEdit = (int) ($modelRow['supports_edit'] ?? 0);
        $editAdapter = trim((string) ($modelRow['edit_adapter'] ?? ''));
        if ($editAdapter === '') {
            $editAdapter = 'none';
        }
        if ($supportsEdit === 0 || $editAdapter === 'none') {
            throw new InvalidArgumentException('The selected model does not support edit mode. Please choose a model with edit capability, or switch to draw mode.');
        }
    }

    // Validate aspect and size against model config
    $rawAspect = trim((string) ($input['image_aspect'] ?? 'auto'));
    $rawSize = trim((string) ($input['image_size'] ?? $size));
    $aspectOptions = ['auto'];
    $defaultAspect = 'auto';
    $sizeOptions = ['auto'];
    $defaultSize = 'auto';
    if ($modelId > 0) {
        $stmt2 = db()->prepare(
            'SELECT image_aspect_options_json, image_default_aspect, image_size_options_json, image_default_size '
            . 'FROM ai_models WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt2->execute([$modelId]);
        $imgModelRow = $stmt2->fetch();
        if ($imgModelRow) {
            $aOpts = json_decode((string) ($imgModelRow['image_aspect_options_json'] ?? ''), true);
            if (is_array($aOpts) && count($aOpts) > 0) {
                $aspectOptions = array_values(array_filter($aOpts, fn($v) => is_string($v)));
            }
            $defaultAspect = trim((string) ($imgModelRow['image_default_aspect'] ?? 'auto'));
            $sOpts = json_decode((string) ($imgModelRow['image_size_options_json'] ?? ''), true);
            if (is_array($sOpts) && count($sOpts) > 0) {
                $sizeOptions = array_values(array_filter($sOpts, fn($v) => is_string($v)));
            }
            $defaultSize = trim((string) ($imgModelRow['image_default_size'] ?? 'auto'));
        }
    }
    $effectiveAspect = in_array($rawAspect, $aspectOptions, true) ? $rawAspect : $defaultAspect;
    $effectiveSize2 = in_array($rawSize, $sizeOptions, true) ? $rawSize : $defaultSize;

    return [
        'mode'        => $mode,
        'prompt'      => $prompt,
        'size'        => $effectiveSize2,
        'quality'     => $quality,
        'format'      => $format,
        'model'       => $model,
        'ai_model_id' => $modelId,
        'input_images' => $inputImages,
        'image_aspect' => $effectiveAspect,
    ];
}

/**
 * 妫€鏌ヨ姹傛槸鍚﹀寘鍚紪杈戝浘鐗囦笂浼?
 * @param  array $files  
 * @return bool
 */
function request_has_uploaded_edit_images(array $files): bool
{
    if (empty($files['edit_images'])) {
        return false;
    }

    $file   = $files['edit_images'];
    $errors = is_array($file['error'] ?? null) ? $file['error'] : [($file['error'] ?? UPLOAD_ERR_NO_FILE)];
    foreach ($errors as $error) {
        if ((int) $error !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
    }
    return false;
}

/**
 * 浠庢枃浠朵笂浼犱腑鎻愬彇骞舵牎楠岀紪杈戝浘鐗?
 * @param  array $files  
 * @return array
 */
function generation_uploaded_images_from_files(array $files): array
{

    // DIAGNOSTIC: log what we received without exposing sensitive data
    $fileKeys = array_keys($files);
    $diagSummary = [
        'files_keys' => $fileKeys,
        'has_edit_images' => isset($files['edit_images']),
        'files_counts' => [],
    ];
    if (isset($files['edit_images'])) {
        $ef = $files['edit_images'];
        $diagSummary['edit_images_type'] = gettype($ef);
        if (is_array($ef)) {
            $diagSummary['edit_images_keys'] = array_keys($ef);
            $diagSummary['edit_images_name'] = is_string($ef['name'] ?? null) ? basename($ef['name']) : (is_array($ef['name'] ?? null) ? 'array(' . count($ef['name']) . ')' : gettype($ef['name'] ?? null));
            $diagSummary['edit_images_size'] = is_array($ef['size'] ?? null) ? array_sum(array_filter($ef['size'] ?? [], 'is_int')) : ($ef['size'] ?? null);
            $diagSummary['edit_images_error'] = $ef['error'] ?? null;
        }
    }
    Logger::info('GENERATION_EDIT_IMAGES_UPLOAD', $diagSummary);
    if (empty($files['edit_images'])) {
        return [];
    }

    $file = $files['edit_images'];
    $names    = is_array($file['name']) ? $file['name'] : [$file['name']];
    $tmpNames = is_array($file['tmp_name']) ? $file['tmp_name'] : [$file['tmp_name']];
    $errors   = is_array($file['error']) ? $file['error'] : [$file['error']];
    $sizes    = is_array($file['size']) ? $file['size'] : [$file['size']];
    $limit    = max_edit_images();
    $images   = [];

    foreach ($names as $index => $name) {
        $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Reference image upload failed.');
        }
        if (count($images) >= $limit) {
            throw new InvalidArgumentException('You can upload at most ' . $limit . ' reference images at one time.');
        }

        $tmpName = (string) ($tmpNames[$index] ?? '');
        $size    = (int) ($sizes[$index] ?? 0);
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('Reference image is invalid.');
        }
        $maxBytes = max_edit_image_mb() * 1024 * 1024;
        if ($size <= 0 || $size > $maxBytes) {
            throw new InvalidArgumentException('Each reference image must be no larger than ' . max_edit_image_mb() . 'MB.');
        }

        $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            throw new InvalidArgumentException('Reference image extension must be png, jpg, jpeg, or webp.');
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string) finfo_file($finfo, $tmpName);
                finfo_close($finfo);
            }
        }
        if ($mime === '') {
            $mime = (string) ($file['type'][$index] ?? '');
        }
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new InvalidArgumentException('Reference image MIME type must be png, jpeg, or webp.');
        }
        $extensionMimeMap = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        ];
        if (($extensionMimeMap[$extension] ?? '') !== $mime) {
            throw new InvalidArgumentException('Reference image extension does not match the actual MIME type.');
        }

        $imageSize = @getimagesize($tmpName);
        if (!is_array($imageSize) || empty($imageSize[0]) || empty($imageSize[1])) {
            throw new InvalidArgumentException('Reference image is not a valid image file.');
        }
        $width        = (int) $imageSize[0];
        $height       = (int) $imageSize[1];
        $maxDimension = max_edit_image_dimension();
        if ($width > $maxDimension || $height > $maxDimension) {
            throw new InvalidArgumentException('Reference image dimensions must be no larger than ' . $maxDimension . 'px.');
        }

        $content = file_get_contents($tmpName);
        if ($content === false) {
            throw new InvalidArgumentException('Failed to read the reference image.');
        }

        $inputImage = [
            'name'     => basename((string) $name) ?: ('image-' . (count($images) + 1)),
            'mime_type' => $mime,
        ];
        if (image_storage_mode() === 'base64') {
            $inputImage['base64'] = base64_encode($content);
        } else {
            $inputImage['url'] = save_input_image_file($content, $mime);
        }

        $images[] = $inputImage;
    }

    return $images;
}

/**
 * 鑾峰彇鍥剧墖妯″瀷 ID锛堥粯璁?gpt-image-2锛?
 * @return string
 */
function image_model_id(): string
{
    $model = trim((string) app_setting('image_model', 'gpt-image-2'));
    return $model !== '' ? $model : 'gpt-image-2';
}

function resolve_active_image_model_id(string $requestedModel = ''): int
{
    if (function_exists('ensure_ai_models_table')) {
        ensure_ai_models_table();
    }
    if (function_exists('ensure_ai_models_type_column')) {
        ensure_ai_models_type_column();
    }

    $requestedModel = trim($requestedModel);
    $pdo = db();

    if ($requestedModel !== '') {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM ai_models
             WHERE is_active = 1
               AND model_type = 'image'
               AND (model_id = ? OR name = ?)
             ORDER BY sort_order ASC, id ASC
             LIMIT 1"
        );
        $stmt->execute([$requestedModel, $requestedModel]);
        $matchedId = (int) $stmt->fetchColumn();
        if ($matchedId > 0) {
            return $matchedId;
        }
    }

    $stmt = $pdo->query(
        "SELECT id
         FROM ai_models
         WHERE is_active = 1 AND model_type = 'image'
         ORDER BY sort_order ASC, id ASC
         LIMIT 1"
    );

    return (int) $stmt->fetchColumn();
}

/**
 * 鑾峰彇鍥剧墖瀛樺偍妯″紡锛坒ile/base64锛?
 * @return string
 */
function image_storage_mode(): string
{
    $mode = trim((string) app_setting('image_storage_mode', 'file'));
    return $mode === 'base64' ? 'base64' : 'file';
}

/**
 * 鍒涘缓鐢熸垚璁板綍骞舵墸鍑忕Н鍒嗭紙浜嬪姟淇濇姢锛?
 * @param  int $userId  
 * @param  array $params  
 * @param  string $status  
 * @return array
 */
function create_generation_record(int $userId, array $params, string $status = 'running'): array
{
    if (!in_array($status, ['queued', 'running'], true)) {
        throw new InvalidArgumentException('Invalid generation task status.');
    }

    ensure_generation_records_queue_status();
    ensure_generation_records_generation_options();

    $modelId = isset($params['ai_model_id']) ? (int) $params['ai_model_id'] : 0;
    $isVideo = (string) ($params['mode'] ?? '') === 'video';
    // For video: credits may already be computed as credits × duration in generation_input_from_request
    $cost = isset($params['credits_charged']) && $params['credits_charged'] > 0
        ? (int) $params['credits_charged']
        : generation_cost_for((string) $params['mode'], $modelId);

    // 构建配置快照（使用创建任务当时的配置，不受后续管理员修改影响）
    $configSnapshot = build_generation_config_snapshot($modelId, (string) $params['mode'], $params);

    $pdo  = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT credits FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $credits = (int) $stmt->fetchColumn();
        if ($credits < $cost) {
            $pdo->rollBack();
            throw new RuntimeException(balance_label() . ' insufficient, please recharge first.', 402);
        }

        $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ?');
        $stmt->execute([$cost, $userId]);

        $stmt = $pdo->prepare(
            'INSERT INTO generation_records
             (user_id, status, mode, model, ai_model_id, prompt, size, quality, output_format, input_images_json, credits_charged, generation_config_snapshot, selected_aspect, selected_size, selected_duration, selected_video_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $status,
            $params['mode'],
            $params['model'],
            isset($params['ai_model_id']) ? (int) $params['ai_model_id'] : null,
            $params['prompt'],
            $params['size'],
            $params['quality'],
            $params['format'],
            !empty($params['input_images']) ? json_encode($params['input_images'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $cost,
            $configSnapshot,
            $isVideo ? ($params['video_aspect'] ?? null) : ($params['image_aspect'] ?? null),
            $params['size'] ?? null,
            $isVideo ? ($params['video_duration'] ?? null) : null,
            $isVideo ? ($params['video_mode'] ?? null) : null,
        ]);
        $recordId = (int) $pdo->lastInsertId();
        $pdo->commit();

        return ['id' => $recordId, 'credits_charged' => $cost];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 构建生成任务的配置快照
 * 保存创建任务当时的模型配置，避免后续管理员修改影响已排队任务
 *
 * @param  int    $modelId  模型 ID
 * @param  string $mode     生成模式 draw/edit/video
 * @param  array $params    请求参数
 * @return string           JSON 快照
 */
function build_generation_config_snapshot(int $modelId, string $mode, array $params): string
{
    $snapshot = [
        'snapshot_at' => date('c'),
    ];

    if ($modelId > 0) {
        $stmt = db()->prepare('SELECT * FROM ai_models WHERE id = ? LIMIT 1');
        $stmt->execute([$modelId]);
        $modelConfig = $stmt->fetch();
        if ($modelConfig) {
            $snapshot['model_id'] = (int) $modelConfig['id'];
            $snapshot['model'] = (string) $modelConfig['model_id'];
            $snapshot['base_url'] = rtrim((string) $modelConfig['base_url'], '/');
            $snapshot['invoke_mode'] = (string) ($modelConfig['invoke_mode'] ?? 'relay');
            $snapshot['supports_edit'] = (int) ($modelConfig['supports_edit'] ?? 0);
            $snapshot['edit_adapter'] = trim((string) ($modelConfig['edit_adapter'] ?? 'none')) ?: 'none';
            $snapshot['edit_image_field'] = trim((string) ($modelConfig['edit_image_field'] ?? 'image_urls')) ?: 'image_urls';

            // 视频模型专用字段
            $snapshot['supports_reference'] = (int) ($modelConfig['supports_reference'] ?? 0);
            $snapshot['reference_required'] = (int) ($modelConfig['reference_required'] ?? 0);
            $snapshot['max_reference_images'] = max(1, (int) ($modelConfig['max_reference_images'] ?? 1));
            $snapshot['video_adapter'] = trim((string) ($modelConfig['video_adapter'] ?? 'none')) ?: 'none';
            $snapshot['fixed_seconds'] = max(0, (int) ($modelConfig['fixed_seconds'] ?? 0));
            $snapshot['video_resolution'] = trim((string) ($modelConfig['video_resolution'] ?? 'auto'));
            $snapshot['video_aspect_ratio'] = trim((string) ($modelConfig['video_aspect_ratio'] ?? 'auto'));
            // New capability fields
            $snapshot['video_duration_options_json'] = $modelConfig['video_duration_options_json'] ?? null;
            $snapshot['video_default_duration'] = (int) ($modelConfig['video_default_duration'] ?? 0);
            $snapshot['video_aspect_options_json'] = $modelConfig['video_aspect_options_json'] ?? null;
            $snapshot['video_default_aspect'] = trim((string) ($modelConfig['video_default_aspect'] ?? '16:9'));
            $snapshot['video_size_options_json'] = $modelConfig['video_size_options_json'] ?? null;
            $snapshot['video_default_size'] = trim((string) ($modelConfig['video_default_size'] ?? 'auto'));
            $snapshot['video_mode_options_json'] = $modelConfig['video_mode_options_json'] ?? null;
            $snapshot['video_default_mode'] = trim((string) ($modelConfig['video_default_mode'] ?? 'text_to_video'));
            $snapshot['video_reference_field'] = trim((string) ($modelConfig['video_reference_field'] ?? 'reference_images'));
            $snapshot['video_duration_field'] = trim((string) ($modelConfig['video_duration_field'] ?? 'duration'));
            $snapshot['video_aspect_field'] = trim((string) ($modelConfig['video_aspect_field'] ?? 'aspect_ratio'));
            $snapshot['video_size_field'] = trim((string) ($modelConfig['video_size_field'] ?? 'size'));
            $snapshot['video_input_mode_field'] = trim((string) ($modelConfig['video_input_mode_field'] ?? 'input_mode'));
            $snapshot['image_aspect_options_json'] = $modelConfig['image_aspect_options_json'] ?? null;
            $snapshot['image_default_aspect'] = trim((string) ($modelConfig['image_default_aspect'] ?? 'auto'));
            $snapshot['image_size_options_json'] = $modelConfig['image_size_options_json'] ?? null;
            $snapshot['image_default_size'] = trim((string) ($modelConfig['image_default_size'] ?? 'auto'));
        }
    } else {
        // 使用全局设置
        $snapshot['model_id'] = 0;
        $snapshot['model'] = (string) ($params['model'] ?? '');
        $snapshot['base_url'] = rtrim((string) app_setting('image_base_url', 'https://api.kbl6.cn'), '/');
        $snapshot['invoke_mode'] = 'relay';
        $snapshot['supports_edit'] = 0;
        $snapshot['edit_adapter'] = 'none';
        $snapshot['edit_image_field'] = 'image_urls';
        $snapshot['supports_reference'] = 0;
        $snapshot['reference_required'] = 0;
        $snapshot['max_reference_images'] = 1;
        $snapshot['video_adapter'] = 'none';
        $snapshot['fixed_seconds'] = 0;
        $snapshot['video_resolution'] = 'auto';
        $snapshot['video_aspect_ratio'] = 'auto';
        // New capability fields (defaults for global settings)
        $snapshot['video_duration_options_json'] = null;
        $snapshot['video_default_duration'] = 0;
        $snapshot['video_aspect_options_json'] = null;
        $snapshot['video_default_aspect'] = '16:9';
        $snapshot['video_size_options_json'] = null;
        $snapshot['video_default_size'] = 'auto';
        $snapshot['video_mode_options_json'] = null;
        $snapshot['video_default_mode'] = 'text_to_video';
        $snapshot['video_reference_field'] = 'reference_images';
        $snapshot['video_duration_field'] = 'duration';
        $snapshot['video_aspect_field'] = 'aspect_ratio';
        $snapshot['video_size_field'] = 'size';
        $snapshot['video_input_mode_field'] = 'input_mode';
        $snapshot['image_aspect_options_json'] = null;
        $snapshot['image_default_aspect'] = 'auto';
        $snapshot['image_size_options_json'] = null;
        $snapshot['image_default_size'] = 'auto';
    }

    return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * 鑾峰彇鐢ㄦ埛褰撳墠绉垎
 * @param  int $userId  
 * @return int
 */
function current_user_credits(int $userId): int
{
    $stmt = db()->prepare('SELECT credits FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * 鏍规嵁 ID 鑾峰彇鐢熸垚璁板綍
 * @param  int $recordId  
 * @return array
 */
function generation_record_by_id(int $recordId): array
{
    $stmt = db()->prepare('SELECT * FROM generation_records WHERE id = ?');
    $stmt->execute([$recordId]);
    $record = $stmt->fetch();
    if (!is_array($record)) {
        throw new RuntimeException('Generation record does not exist.');
    }
    return $record;
}

/**
 * 鏋勫缓 API 鍝嶅簲鐢ㄧ殑璁板綍鏁版嵁
 * @param  array $record  
 * @return array
 */
function generation_response_record(array $record): array
{
    $isVideo = ($record['mode'] ?? '') === 'video';

    $result = [
        'id'             => (int) $record['id'],
        'status'         => (string) $record['status'],
        'mode'           => (string) ($record['mode'] ?? 'draw'),
        'prompt'         => (string) $record['prompt'],
        'size'           => (string) $record['size'],
        'quality'        => (string) $record['quality'],
        'format'         => (string) $record['output_format'],
        'credits_charged' => (int) $record['credits_charged'],
        'created_at'     => (string) $record['created_at'],
        'finished_at'    => $record['finished_at'] ?: '-',
        'error_message'  => (string) ($record['error_message'] ?: ''),
        'input_image_count' => generation_input_image_count($record),
    ];

    if ($isVideo) {
        $result['video_src'] = generation_record_video_src($record);
    } else {
        $result['image_src'] = generation_record_image_src($record);
    }

    return $result;
}

/**
 * 鑾峰彇鐢熸垚璁板綍鐨勫浘鐗?URL
 * @param  array $record  
 * @return ?string
 */
function generation_record_image_src(array $record): ?string
{
    if (!empty($record['image_url'])) {
        $url = (string) $record['image_url'];
        if (is_remote_url($url)) {
            $recordId = (int) ($record['id'] ?? 0);
            $url = ensure_local_image_url($url, $recordId);
        }
        return $url;
    }
    if (!empty($record['image_base64'])) {
        $mime = $record['mime_type'] ?: 'image/png';
        return 'data:' . $mime . ';base64,' . $record['image_base64'];
    }
    if (!empty($record['has_image_base64']) && !empty($record['id'])) {
        return '/record_image?id=' . (int) $record['id'];
    }
    return null;
}

/**
 * 鑾峰彇鐢熸垚璁板綍鐨勮棰?URL
 * @param  array $record  
 * @return ?string
 */
function generation_record_video_src(array $record): ?string
{
    if (!empty($record['video_url'])) {
        $url = (string) $record['video_url'];
        if (is_remote_url($url)) {
            $recordId = (int) ($record['id'] ?? 0);
            $url = ensure_local_image_url($url, $recordId, 'video_url');
        }
        return $url;
    }
    if (!empty($record['video_base64'])) {
        $mime = $record['video_mime_type'] ?: 'video/mp4';
        return 'data:' . $mime . ';base64,' . $record['video_base64'];
    }
    return null;
}

/**
 * 鑾峰彇缂栬緫妯″紡鍙傝€冨浘鐗囨暟閲?
 * @param  array $record  
 * @return int
 */
function generation_input_image_count(array $record): int
{
    $images = json_decode((string) ($record['input_images_json'] ?? ''), true);
    return is_array($images) ? count($images) : 0;
}

function save_generated_image_file(string $base64, string $format): string
{
    $format = strtolower($format);
    if (!in_array($format, generation_allowed_formats(), true)) {
        $format = 'png';
    }

    $binary = base64_decode($base64, true);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('Failed to decode generated image base64.');
    }

    return api_save_binary_file($binary, $format, 'generations');
}

function save_input_image_file(string $binary, string $mime): string
{
    $extensionMap = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    $format = $extensionMap[$mime] ?? 'png';
    return api_save_binary_file($binary, $format, 'input-images');
}

function save_image_binary_file(string $binary, string $format, string $bucket): string
{
    return api_save_binary_file($binary, $format, $bucket);
}

/**
 * 灏嗘湰鍦?URL 杞崲涓烘枃浠剁郴缁熻矾寰?
 * @param  string $url  
 * @return ?string
 */
function local_public_file_from_url(string $url): ?string
{
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }
    $path = '/' . ltrim($path, '/');
    if (strpos($path, '/uploads/') !== 0) {
        return null;
    }
    $fullPath = realpath(ROOT_PATH . '/public' . $path);
    $uploadsRoot = realpath(ROOT_PATH . '/public/uploads');
    if ($fullPath === false || $uploadsRoot === false || strpos($fullPath, $uploadsRoot) !== 0 || !is_file($fullPath)) {
        return null;
    }
    return $fullPath;
}

function is_remote_url(string $url): bool
{
    return strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0;
}

function download_remote_image(string $url, string $bucket = 'generations'): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => ssl_verify_enabled(),
        CURLOPT_SSL_VERIFYHOST => ssl_verify_enabled() ? 2 : 0,
        CURLOPT_USERAGENT => 'AI-Image-Generator/1.0',
    ]);

    $binary = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($binary === false || $binary === '') {
        throw new RuntimeException('Failed to download remote media: ' . ($curlError ?: 'empty response'));
    }

    if ($httpCode < 200 || $httpCode >= 400) {
        throw new RuntimeException('Failed to download remote media: HTTP ' . $httpCode);
    }

    $mime = strtolower(trim(explode(';', $contentType)[0] ?? ''));
    $extensionMap = [
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'application/octet-stream' => 'bin',
    ];

    $format = $extensionMap[$mime] ?? '';
    if ($format === '') {
        $path = parse_url($url, PHP_URL_PATH);
        $ext = strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));
        $format = $ext !== '' ? $ext : 'bin';
    }

    return api_save_binary_file((string) $binary, $format, $bucket);
}

function ensure_local_image_url(string $url, int $recordId = 0, string $column = 'image_url'): string
{
    if (!is_remote_url($url)) {
        return $url;
    }

    try {
        $bucket = $column === 'video_url' ? 'videos' : 'generations';
        $localPath = download_remote_image($url, $bucket);
    } catch (Throwable $e) {
        Logger::warning('REMOTE_MEDIA_CACHE_FAILED', [
            'record_id' => $recordId,
            'column' => $column,
            'url' => $url,
            'error' => $e->getMessage(),
        ]);
        return $url;
    }

    if ($recordId > 0 && in_array($column, ['image_url', 'video_url'], true)) {
        try {
            $stmt = db()->prepare("UPDATE generation_records SET {$column} = ? WHERE id = ?");
            $stmt->execute([$localPath, $recordId]);
        } catch (Throwable $e) {
            Logger::warning('REMOTE_MEDIA_CACHE_DB_UPDATE_FAILED', [
                'record_id' => $recordId,
                'column' => $column,
                'local_path' => $localPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    return $localPath;
}

/**
 * 鎴彇 API 鍝嶅簲鎽樿锛堢敤浜庨敊璇棩蹇楋級
 * @param  string $raw  
 * @return string
 */
function generation_response_excerpt(string $raw): string
{
    $text = preg_replace('/\s+/', ' ', trim(strip_tags($raw)));
    $text = is_string($text) ? $text : trim($raw);
    if ($text === '') {
        return 'empty content';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, 300, 'UTF-8');
    }
    return substr($text, 0, 300);
}

function image_api_error_code_from_response(array $response): string
{
    $raw = $response['raw'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return '';
    }

    $data = api_response_json($raw);
    if (!is_array($data)) {
        return '';
    }

    return api_error_code($data);
}

function image_api_error_message_from_response(array $response): string
{
    $raw = $response['raw'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return '';
    }

    $data = api_response_json($raw);
    if (!is_array($data)) {
        return '';
    }

    return api_error_message($data, '');
}

function image_model_group_unavailable_message(string $model, string $apiMessage): string
{
    $model = trim($model);
    $group = '';
    if (preg_match('/under group\s+([^\s()]+)/i', $apiMessage, $matches)) {
        $group = trim((string) ($matches[1] ?? ''));
    }

    if ($group !== '') {
        return 'The image model "' . $model . '" exists on the upstream API, but your current API key is not authorized for group "' . $group . '". For api.kbl6.cn, move this key to the correct pricing group such as 按次调用, or replace it with a key that has access to that group.';
    }

    return image_model_not_found_message($model);
}

function image_model_not_found_message(string $model): string
{
    $model = trim($model);
    if ($model === '') {
        return 'The current image model is unavailable on the upstream API. Please switch to an available model in admin AI models or check the Base URL / API Key.';
    }

    return 'The current image model "' . $model . '" is unavailable on the upstream API. Please switch to an available model in admin AI models or check the Base URL / API Key.';
}

function image_model_fallback_candidates(string $model): array
{
    $model = strtolower(trim($model));
    if ($model === '') {
        return [];
    }

    $fallbacks = [];
    if ($model === 'gpt-image-2-4k') {
        $fallbacks[] = 'gpt-image-2';
    }
    if ($model === 'gpt-image-1-4k') {
        $fallbacks[] = 'gpt-image-1';
    }
    if (str_starts_with($model, 'gpt-image-2') && $model !== 'gpt-image-2') {
        $fallbacks[] = 'gpt-image-2';
    }

    return array_values(array_unique($fallbacks));
}

function trigger_generation_worker(): bool
{
    $workerScript = ROOT_PATH . '/worker/generate_worker.php';
    if (!is_file($workerScript)) {
        return false;
    }

    $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $binaryName = strtolower(basename($phpBinary));
    if ($binaryName === 'php-cgi.exe') {
        $phpBinary = 'php';
    }
    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerScript) . ' --once';

    try {
        if (DIRECTORY_SEPARATOR === '\\') {
            $process = @popen('start /B "" ' . $cmd . ' >NUL 2>&1', 'r');
            if (is_resource($process)) {
                @pclose($process);
                return true;
            }
            return false;
        }

        exec($cmd . ' > /dev/null 2>&1 &');
        return true;
    } catch (Throwable $e) {
        Logger::warning('TRIGGER_WORKER_FAILED', ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * 鏍规嵁妯″紡璋冪敤鍥剧墖鐢熸垚/缂栬緫 API
 * @param  string $baseUrl  
 * @param  string $apiKey  
 * @param  array $record  
 * @param  int $timeout  
 * @return array
 */
function normalize_image_invoke_mode(?string $value): string
{
    return strtolower(trim((string) $value)) === 'curl' ? 'curl' : 'relay';
}

function call_image_api(string $baseUrl, string $apiKey, array $record, int $timeout): array
{
    $mode = (string) ($record['mode'] ?? 'draw');
    $images = json_decode((string) ($record['input_images_json'] ?? ''), true);
    $isEdit = $mode === 'edit' || (is_array($images) && $images);
    $invokeMode = normalize_image_invoke_mode($record['invoke_mode'] ?? 'relay');

    // For edit mode, check edit_adapter for nano_banana dispatch
    if ($isEdit) {
        $editAdapter = trim((string) ($record['edit_adapter'] ?? 'none'));
        if ($editAdapter === '') {
            $editAdapter = 'none';
        }

        if ($editAdapter === 'nano_banana_image_urls') {
            return call_nano_banana_edit_api($baseUrl, $apiKey, $record, $timeout);
        }
    }

    if ($invokeMode === 'curl') {
        // 缂栬緫妯″紡锛氫笂浼犲弬鑰冨浘鐗囷紙multipart锛夛紝浣嗕娇鐢?/images/generations 绔偣
        return $isEdit
            ? call_image_edit_api_curl_mode($baseUrl, $apiKey, $record, $timeout)
            : call_image_generation_api_curl_mode($baseUrl, $apiKey, $record, $timeout);
    }
    return $isEdit
        ? call_image_edit_api($baseUrl, $apiKey, $record, $timeout)
        : call_image_generation_api($baseUrl, $apiKey, $record, $timeout);
}

/**
 * 灏嗗墠绔紶鍏ョ殑姣斾緥瀛楃涓叉槧灏勪负鏀寔鐨勫儚绱犲昂瀵?
 * 鑻ュ凡鏄儚绱犳牸寮忓垯鍘熸牱杩斿洖
 * 鑻ュ€间负 auto锛屽師鏍疯繑鍥烇紙gpt-image-2 鏀寔 auto锛?
 *
 * 褰撳墠浠呮敮鎸?gpt-image-2 妯″瀷锛堢敤鎴锋寚瀹氾級銆?
 */
function image_size_to_pixel(string $size, string $model = ''): string
{
    $size = strtolower(trim($size));

    // auto 鍘熸牱杩斿洖
    if ($size === 'auto') {
        return 'auto';
    }

    // 宸叉槸鍍忕礌鏍煎紡鍒欏師鏍疯繑鍥?
    if (preg_match('/^\d+x\d+$/i', $size)) {
        return $size;
    }

    // 姣斾緥 鈫?鍍忕礌鏄犲皠锛坓pt-image-2 鍏煎灏哄锛?
    $map = [
        '1:1'   => '1024x1024',
        '3:2'   => '1024x1024',
        '2:3'   => '1024x1024',
        '4:3'   => '1024x1024',
        '3:4'   => '1024x1024',
        '5:4'   => '1024x1024',
        '4:5'   => '1024x1024',
        '16:9'  => '1536x1024',  // gpt-image-2 妯悜
        '9:16'  => '1024x1536',  // gpt-image-2 绾靛悜
        '2:1'   => '1536x1024',
        '1:2'   => '1024x1536',
        '21:9'  => '1536x1024',
        '9:21'  => '1024x1536',
    ];
    return $map[$size] ?? '1024x1024';
}

/**
 * 灏?quality 瀛楁杞负 OpenAI 鏀寔鐨勫悎娉曞€?
 * dall-e-2: 涓嶆敮鎸?quality 鍙傛暟
 * dall-e-3: standard / hd
 * gpt-image-1: standard / hd / auto
 */
function image_quality_to_valid(string $quality, string $model = ''): string
{
    $quality = strtolower(trim($quality));
    $model   = strtolower(trim($model));

    // dall-e-2 涓嶆敮鎸?quality 鍙傛暟锛岃繑鍥炵┖瀛楃涓茶〃绀轰笉浼?
    if ($model === 'dall-e-2') {
        return '';
    }

    $map = ['low' => 'standard', 'medium' => 'standard', 'high' => 'hd'];
    $result = $map[$quality] ?? 'standard';

    // gpt-image-1 鏀寔 auto
    if ($model === 'gpt-image-1' && $quality === 'auto') {
        return 'auto';
    }
    return $result;
}

function image_curl_mode_payload(array $record): array
{
    $format = strtolower(trim((string) ($record['output_format'] ?? 'png')));
    if (!in_array($format, generation_allowed_formats(), true)) {
        $format = 'png';
    }

    $size = strtolower(trim((string) ($record['size'] ?? 'auto')));
    if ($size === '') {
        $size = 'auto';
    }

    $quality = strtolower(trim((string) ($record['quality'] ?? 'auto')));
    if ($quality === '') {
        $quality = 'auto';
    }

    return [
        'model' => (string) ($record['model'] ?? 'gpt-image-2'),
        'prompt' => (string) ($record['prompt'] ?? ''),
        'size' => $size,
        'quality' => $quality,
        'output_format' => $format,
        'response_format' => 'b64_json',
    ];
}

function image_extension_from_mime(string $mimeType): string
{
    $map = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    return $map[strtolower(trim($mimeType))] ?? 'png';
}

function image_edit_source_files_from_record(array $record): array
{
    $images = json_decode((string) ($record['input_images_json'] ?? ''), true);
    if (!is_array($images) || !$images) {
        throw new RuntimeException('Edit task is missing reference images.');
    }

    $files = [];
    foreach ($images as $index => $image) {
        if (!is_array($image)) {
            continue;
        }

        $mimeType = (string) ($image['mime_type'] ?? 'image/png');
        $name = trim((string) ($image['name'] ?? ''));
        if ($name === '') {
            $name = 'image-' . ($index + 1) . '.' . image_extension_from_mime($mimeType);
        }

        if (!empty($image['url'])) {
            $path = local_public_file_from_url((string) $image['url']);
            if ($path && is_file($path)) {
                $files[] = [
                    'path' => $path,
                    'mime_type' => $mimeType,
                    'name' => $name,
                    'temporary' => false,
                ];
                continue;
            }
        }

        if (empty($image['base64'])) {
            continue;
        }

        $binary = base64_decode((string) $image['base64'], true);
        if ($binary === false || $binary === '') {
            continue;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'img-edit-');
        if ($tmpPath === false) {
            throw new RuntimeException('Failed to create temporary file for edit image.');
        }

        $extension = image_extension_from_mime($mimeType);
        $finalPath = $tmpPath . '.' . $extension;
        if (!@rename($tmpPath, $finalPath)) {
            $finalPath = $tmpPath;
        }

        if (file_put_contents($finalPath, $binary, LOCK_EX) === false) {
            @unlink($finalPath);
            throw new RuntimeException('Failed to write temporary edit image file.');
        }

        $files[] = [
            'path' => $finalPath,
            'mime_type' => $mimeType,
            'name' => $name,
            'temporary' => true,
        ];
    }

    if (!$files) {
        throw new RuntimeException('No valid reference images were found for edit mode.');
    }

    return $files;
}

function cleanup_image_edit_source_files(array $files): void
{
    foreach ($files as $file) {
        if (!is_array($file) || empty($file['temporary']) || empty($file['path'])) {
            continue;
        }
        @unlink((string) $file['path']);
    }
}

/**
 * 鏋勫缓鍥剧墖 API 璇锋眰浣擄紝鍏煎澶氱绔偣鏍煎紡
 *
 * 鏀寔 prompt 鏍煎紡锛?images/generations锛夊拰 messages 鏍煎紡锛?v1/chat/completions锛夈€?
 *
 * @param  array  $record
 * @return array[] 姣忎釜鍏冪礌鏄竴涓畬鏁寸殑璇锋眰浣?
 */
function image_payload_formats(array $record): array
{
    $model   = (string) $record['model'];
    $prompt  = (string) $record['prompt'];
    $size    = image_size_to_pixel((string) ($record['size'] ?? 'auto'), $model);
    $fmt     = ['type' => 'b64_json']; // NewAPI 瑕佹眰瀵硅薄鏍煎紡 {"type":"b64_json"}
    $quality = image_quality_to_valid((string) ($record['quality'] ?? 'auto'), $model);

    $formats = [];

    // 鏍煎紡1锛歮essages 绾枃鏈紙閫傜敤浜?/v1/chat/completions 榛樿绔偣锛?
    //       chat completions 涓嶆帴鍙?size / response_format / quality
    $formats[] = [
        'model'    => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'n'        => 1,
    ];

    // 鏍煎紡2锛歮essages + response_format锛堥儴鍒?chat 绔偣鏀寔锛?
    $formats[] = [
        'model'           => $model,
        'messages'        => [['role' => 'user', 'content' => $prompt]],
        'n'               => 1,
        'response_format' => $fmt,
    ];

    // 鏍煎紡3锛歱rompt 鏍煎紡锛堥€傜敤浜?/images/generations 绔偣锛?
    $p1 = ['model' => $model, 'prompt' => $prompt, 'size' => $size, 'n' => 1, 'response_format' => $fmt];
    if ($quality !== '') {
        $p1['quality'] = $quality;
    }
    $formats[] = $p1;

    // 鏍煎紡4锛歱rompt 绮剧畝鐗堬紙鏃?size/response_format/quality锛?
    $formats[] = ['model' => $model, 'prompt' => $prompt, 'n' => 1];

    return $formats;
}

/**
 * 璋冪敤鍥剧墖鐢熸垚 API锛坉raw 妯″紡锛夛紝鑷姩灏濊瘯澶氱璇锋眰鏍煎紡
 * @param  string $baseUrl  
 * @param  string $apiKey  
 * @param  array $record  
 * @param  int $timeout  
 * @return array
 */
function call_image_generation_api(string $baseUrl, string $apiKey, array $record, int $timeout): array
{
    // 灏濊瘯澶氫釜绔偣锛氬厛鍦?settings 閲岄厤缃殑鑷畾涔夎矾寰勶紝鐒跺悗 /v1/images/generations锛屾渶鍚?/v1/chat/completions
    $endpointCandidates = [];

    // 1. 绠＄悊鍛樿嚜瀹氫箟璺緞锛堥€氳繃 settings 閰嶇疆锛?
    $customPath = trim((string) app_setting('image_generate_path', ''));
    if ($customPath !== '') {
        $endpointCandidates[] = api_build_url($baseUrl, $customPath);
    }

    // 2. /v1/images/generations锛堟爣鍑?OpenAI 鍥剧墖鐢熸垚绔偣锛?
    $endpointCandidates[] = api_build_url($baseUrl, 'v1/images/generations');

    // 3. /v1/chat/completions锛堝吋瀹归儴鍒?NewAPI 妯″瀷锛?
    $endpointCandidates[] = api_build_url($baseUrl, 'v1/chat/completions');

    $payloads = image_payload_formats($record);
    $lastError = '';

    Logger::info('鍥剧墖API璋冭瘯', [
        'model'       => $record['model'] ?? '(not set)',
        'base_url'    => $baseUrl,
        'endpoints'   => $endpointCandidates,
        'payload_cnt' => count($payloads),
    ]);

    // 瀵规瘡涓鐐瑰皾璇曟墍鏈?payload 鏍煎紡
    foreach ($endpointCandidates as $ei => $url) {
        foreach ($payloads as $pi => $payload) {
            $attemptLabel = "绔偣{$ei}/鏍煎紡{$pi}";
            Logger::info('鍥剧墖API璇锋眰', [
                'url'     => $url,
                'attempt' => $attemptLabel,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);

            // 502/503 閿欒閲嶈瘯锛堟渶澶?娆★紝闂撮殧2绉掞級
            $retries = 3;
            for ($try = 1; $try <= $retries; $try++) {
                if ($try > 1) {
                    Logger::info('鍥剧墖API閲嶈瘯', ['attempt' => $attemptLabel, 'retry' => $try]);
                    sleep(2); // 绛夊緟2绉掑悗閲嶈瘯
                }

                $response = api_curl_post_json($url, $apiKey, $payload, $timeout);
                $httpCode = (int) $response['http_code'];
                $raw      = $response['raw'];
                $errorCode = image_api_error_code_from_response($response);

                if ($httpCode >= 200 && $httpCode < 300) {
                    Logger::info('鍥剧墖API鎴愬姛', ['attempt' => $attemptLabel]);
                    return $response;
                }

                if ($errorCode === 'model_not_found') {
                    $fallbackModels = image_model_fallback_candidates((string) ($record['model'] ?? ''));
                    if ($fallbackModels !== []) {
                        $retryRecord = $record;
                        $retryRecord['model'] = $fallbackModels[0];
                        Logger::warning('IMAGE_MODEL_FALLBACK_RETRY', [
                            'attempt' => $attemptLabel,
                            'from_model' => (string) ($record['model'] ?? ''),
                            'to_model' => $retryRecord['model'],
                            'url' => $url,
                        ]);
                        return call_image_generation_api($baseUrl, $apiKey, $retryRecord, $timeout);
                    }
                    $apiMessage = image_api_error_message_from_response($response);
                    $message = image_model_group_unavailable_message((string) ($record['model'] ?? ''), $apiMessage);
                    Logger::warning('IMAGE_MODEL_NOT_FOUND', [
                        'attempt' => $attemptLabel,
                        'model' => (string) ($record['model'] ?? ''),
                        'url' => $url,
                        'api_message' => $apiMessage,
                        'detail' => generation_response_excerpt((string) $raw),
                    ]);
                    throw new RuntimeException($message);
                }

                // 502/503锛氭湇鍔℃殏鏃朵笉鍙敤锛岀瓑2绉掑悗閲嶈瘯
                if ($httpCode === 502 || $httpCode === 503) {
                    $errDetail = 'HTTP ' . $httpCode . ': ' . generation_response_excerpt((string) $raw);
                    if ($try < $retries) {
                        Logger::info('IMAGE_API_RETRY_PENDING', ['attempt' => $attemptLabel, 'retry' => $try, 'detail' => $errDetail]);
                        continue;
                    }
                    // 鎵€鏈夐噸璇曢兘鐢ㄥ畬浜嗭紝璁板綍鏈€鍚庨敊璇户缁皾璇曚笅涓€涓鐐?
                    $lastError = "{$attemptLabel}锛坽$retries}娆￠噸璇曞悗锛夛細" . $errDetail;
                    Logger::info('鍥剧墖API绔偣澶辫触', ['url' => $url, 'error' => $lastError]);
                    break; // 璺冲嚭閲嶈瘯寰幆锛屽皾璇曚笅涓€涓鐐?鏍煎紡
                }

                // 鍏朵粬閿欒锛氭崲鏍煎紡
                $errDetail = $httpCode >= 500
                    ? 'HTTP ' . $httpCode . ': ' . generation_response_excerpt((string) $raw)
                    : 'HTTP ' . $httpCode . ': ' . (is_string($raw) ? substr(strip_tags($raw), 0, 200) : '');
                $lastError = $attemptLabel . ': ' . $errDetail;
                Logger::info('鍥剧墖API鏍煎紡澶辫触', ['attempt' => $attemptLabel, 'error' => $errDetail]);
                break; // 璺冲嚭閲嶈瘯寰幆锛屽皾璇曚笅涓€涓牸寮?绔偣
            }
        }
    }

    throw new RuntimeException('Image API request failed after trying all configured endpoint formats: ' . $lastError);
}

/**
 * 璋冪敤鍥剧墖缂栬緫 API锛坋dit 妯″紡锛夛紝閫氳繃 multipart 涓婁紶鍙傝€冨浘鐗?
 *
 * 涓婁紶鍙傝€冨浘鐗囧埌 /images/edits锛堟垨绠＄悊鍛樿嚜瀹氫箟璺緞锛夛紝
 * 妯″瀷鍚嶅凡瑙勮寖鍖栵紝鑻?NewAPI 鏈夊搴旂紪杈戦€氶亾鍒欐甯稿伐浣溿€?
 *
 * @param  string $baseUrl
 * @param  string $apiKey
 * @param  array $record
 * @param  int $timeout
 * @return array
 * @throws RuntimeException 鎵€鏈夋牸寮忓潎澶辫触鏃?
 */
function call_image_edit_api(string $baseUrl, string $apiKey, array $record, int $timeout): array
{
    $images = json_decode((string) ($record['input_images_json'] ?? ''), true);
    if (!is_array($images) || !$images) {
        throw new RuntimeException('Edit task is missing reference images.');
    }
    $imageDataUris = [];
    foreach ($images as $image) {
        if (!is_array($image)) continue;
        $mime = (string) ($image['mime_type'] ?? 'image/png');
        // base64 妯″紡
        if (!empty($image['base64'])) {
            $imageDataUris[] = 'data:' . $mime . ';base64,' . (string) $image['base64'];
            continue;
        }
        // file 妯″紡锛氫粠鏈湴鏂囦欢璇诲彇骞剁紪鐮?
        if (!empty($image['url'])) {
            $filePath = local_public_file_from_url((string) $image['url']);
            if ($filePath && is_file($filePath)) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $imageDataUris[] = 'data:' . $mime . ';base64,' . base64_encode($content);
                }
            }
        }
    }
    if (!$imageDataUris) {
        throw new RuntimeException('Edit task does not contain any valid reference images.');
    }

    $model  = (string) $record['model'];
    $prompt = (string) $record['prompt'];
    $model  = $model ?: 'gpt-image-2';
    $sizeRaw = strtolower(trim((string) ($record['size'] ?? 'auto')));
    $size = ($sizeRaw === 'auto') ? '1024x1024' : image_size_to_pixel($sizeRaw, $model);

    $payloads = [];
    $fmt = 'b64_json';
    $baseData = ['model' => $model, 'n' => 1, 'response_format' => $fmt];
    $payloads[] = array_merge($baseData, ['image' => $imageDataUris, 'prompt' => $prompt, 'size' => $size]);
    $payloads[] = array_merge($baseData, ['image' => $imageDataUris, 'prompt' => $prompt]);
    $msgContent = [];
    foreach ($imageDataUris as $uri) {
        $msgContent[] = ['type' => 'image_url', 'image_url' => ['url' => $uri]];
    }
    $msgContent[] = ['type' => 'text', 'text' => $prompt];
    $payloads[] = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $msgContent]], 'max_tokens' => 4096];
    $payloads[] = array_merge($baseData, ['prompt' => $prompt, 'size' => $size, 'image' => $imageDataUris[0]]);

    $endpointCandidates = [];
    $customPath = trim((string) app_setting('image_generate_path', ''));
    if ($customPath !== '') {
        $endpointCandidates[] = api_build_url($baseUrl, $customPath);
    }
    $endpointCandidates[] = api_build_url($baseUrl, 'v1/images/generations');
    $endpointCandidates[] = api_build_url($baseUrl, 'v1/chat/completions');

    $errorList = [];
    foreach ($endpointCandidates as $ei => $url) {
        foreach ($payloads as $pi => $payload) {
            $ch = curl_init($url);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER   => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS    => $json,
                CURLOPT_CONNECTTIMEOUT => min(30, $timeout),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => ssl_verify_enabled(),
                CURLOPT_SSL_VERIFYHOST => ssl_verify_enabled() ? 2 : 0,
            ]);
            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $errorCode = image_api_error_code_from_response([
                'raw' => $raw,
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'error' => $curlError,
            ]);

            if ($errorCode === 'model_not_found') {
                $fallbackModels = image_model_fallback_candidates($model);
                if ($fallbackModels !== []) {
                    $retryRecord = $record;
                    $retryRecord['model'] = $fallbackModels[0];
                    Logger::warning('IMAGE_MODEL_FALLBACK_RETRY', [
                        'context' => 'edit',
                        'from_model' => $model,
                        'to_model' => $retryRecord['model'],
                        'url' => $url,
                    ]);
                    return call_image_edit_api($baseUrl, $apiKey, $retryRecord, $timeout);
                }
                $apiMessage = image_api_error_message_from_response([
                    'raw' => $raw,
                    'http_code' => $httpCode,
                    'content_type' => $contentType,
                    'error' => $curlError,
                ]);
                throw new RuntimeException(image_model_group_unavailable_message($model, $apiMessage));
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return ['raw' => $raw, 'http_code' => $httpCode, 'content_type' => $contentType, 'error' => $curlError];
            }
            $errDetail = $httpCode >= 500
                ? 'HTTP ' . $httpCode . ': ' . generation_response_excerpt((string) $raw)
                : 'HTTP ' . $httpCode . ': ' . (is_string($raw) ? substr(strip_tags((string) $raw), 0, 200) : '');
            $errorList[] = 'endpoint ' . ($ei + 1) . ' / payload ' . ($pi + 1) . ': ' . $errDetail;
        }
    }
    throw new RuntimeException('Image edit API request failed for all endpoint formats: ' . implode(' | ', $errorList));
}

/**
 * Call nano_banana edit API with image URLs
 * Saves uploaded reference images as public HTTPS URLs and sends them to the API
 *
 * @param  string $baseUrl
 * @param  string $apiKey
 * @param  array  $record
 * @param  int    $timeout
 * @return array
 */
function call_nano_banana_edit_api(string $baseUrl, string $apiKey, array $record, int $timeout): array
{
    $images = json_decode((string) ($record['input_images_json'] ?? ''), true);
    if (!is_array($images) || !$images) {
        throw new RuntimeException('Edit task is missing reference images.');
    }

    $editImageField = trim((string) ($record['edit_image_field'] ?? 'image_urls'));
    if ($editImageField === '') {
        $editImageField = 'image_urls';
    }

    $imageUrls = [];
    foreach ($images as $image) {
        if (!is_array($image)) continue;

        // If already a URL, use it directly
        if (!empty($image['url']) && is_remote_url((string) $image['url'])) {
            $imageUrls[] = (string) $image['url'];
            continue;
        }

        // If base64, save to public/uploads/reference/ and use the public URL
        if (!empty($image['base64'])) {
            $mime = (string) ($image['mime_type'] ?? 'image/png');
            $extensionMap = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
            ];
            $extension = $extensionMap[$mime] ?? 'png';
            $binary = base64_decode((string) $image['base64'], true);
            if ($binary === false || $binary === '') {
                continue;
            }

            $filename = 'ref_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $uploadDir = PUBLIC_PATH . '/uploads/reference';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $uploadPath = $uploadDir . '/' . $filename;
            if (file_put_contents($uploadPath, $binary, LOCK_EX) === false) {
                continue;
            }

            // Build public URL
            $baseUrlForUpload = rtrim((string) config('app.base_url', ''), '/');
            if ($baseUrlForUpload === '') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
                $baseUrlForUpload = $scheme . '://' . $host;
            }
            $imageUrls[] = $baseUrlForUpload . '/uploads/reference/' . $filename;
            continue;
        }

        // If local file URL, convert to absolute URL
        if (!empty($image['url'])) {
            $filePath = local_public_file_from_url((string) $image['url']);
            if ($filePath && is_file($filePath)) {
                $baseUrlForUpload = rtrim((string) config('app.base_url', ''), '/');
                if ($baseUrlForUpload === '') {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
                    $baseUrlForUpload = $scheme . '://' . $host;
                }
                $relativePath = '/uploads/' . basename($filePath);
                $imageUrls[] = $baseUrlForUpload . $relativePath;
            }
        }
    }

    if (!$imageUrls) {
        throw new RuntimeException('Edit task does not contain any valid reference images.');
    }

    $model = (string) ($record['model'] ?? 'gpt-image-2');
    $prompt = (string) ($record['prompt'] ?? '');
    $sizeRaw = strtolower(trim((string) ($record['size'] ?? 'auto')));
    $size = ($sizeRaw === 'auto') ? 'auto' : image_size_to_pixel($sizeRaw, $model);
    $format = strtolower(trim((string) ($record['output_format'] ?? 'png')));
    if (!in_array($format, generation_allowed_formats(), true)) {
        $format = 'png';
    }

    $payload = [
        'model' => $model,
        'prompt' => $prompt,
        $editImageField => $imageUrls,
        'size' => $size,
        'response_format' => 'b64_json',
    ];

    $url = api_build_url($baseUrl, 'v1/images/generations');

    Logger::info('NANO_BANANA_EDIT_API', [
        'url' => $url,
        'model' => $model,
        'edit_image_field' => $editImageField,
        'image_count' => count($imageUrls),
        'payload_keys' => array_keys($payload),
    ]);

    $response = api_curl_post_json($url, $apiKey, $payload, $timeout);

    if (image_api_error_code_from_response($response) === 'model_not_found') {
        $fallbackModels = image_model_fallback_candidates($model);
        if ($fallbackModels !== []) {
            $retryRecord = $record;
            $retryRecord['model'] = $fallbackModels[0];
            Logger::warning('IMAGE_MODEL_FALLBACK_RETRY', [
                'context' => 'nano-banana-edit',
                'from_model' => $model,
                'to_model' => $retryRecord['model'],
                'url' => $url,
            ]);
            return call_nano_banana_edit_api($baseUrl, $apiKey, $retryRecord, $timeout);
        }
        throw new RuntimeException(image_model_group_unavailable_message(
            $model,
            image_api_error_message_from_response($response)
        ));
    }

    return $response;
}




/**
 * 鏋勫缓缂栬緫 API 鐨?multipart 琛ㄥ崟瀛楁
 * @param  array $payload  
 * @param  array $files  
 * @return array
 */
function image_edit_post_fields(array $payload, array $files): array
{
    $fields = [];
    foreach ($payload as $key => $value) {
        if ($value !== null && $value !== '') {
            $fields[$key] = (string) $value;
        }
    }

    foreach ($files as $index => $file) {
        $fieldName = count($files) === 1 ? 'image' : 'image[' . $index . ']';
        $fields[$fieldName] = new CURLFile(
            (string) $file['path'],
            (string) $file['mime_type'],
            (string) $file['name']
        );
    }
    return $fields;
}

/**
 * 瀹屾垚 cURL 璇锋眰骞惰繑鍥炴爣鍑嗗寲鍝嶅簲
 * @param  CurlHandle $ch  
 * @return array
 */
function image_api_curl_request(string $url, string $apiKey, array $postFields, int $timeout): array
{
    $doRequest = function (bool $verify) use ($url, $apiKey, $postFields, $timeout): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_CONNECTTIMEOUT => min(30, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);

        return finish_image_api_curl($ch);
    };

    $result = $doRequest(ssl_verify_enabled());
    if (
        ssl_verify_enabled()
        && is_string($result['error'])
        && $result['error'] !== ''
        && (
            stripos($result['error'], 'SSL certificate problem') !== false
            || stripos($result['error'], 'unable to get local issuer certificate') !== false
        )
    ) {
        Logger::warning('IMAGE_API_SSL_VERIFY_FALLBACK', [
            'url' => $url,
            'error' => $result['error'],
        ]);
        $result = $doRequest(false);
    }

    return $result;
}

function call_image_generation_api_curl_mode(string $baseUrl, string $apiKey, array $record, int $timeout): array
{
    $payload = image_curl_mode_payload($record);
    $url = api_build_url($baseUrl, 'v1/images/generations');

    Logger::info('IMAGE_API_CURL_GENERATE', [
        'url' => $url,
        'model' => $payload['model'],
        'invoke_mode' => 'curl',
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $response = api_curl_post_json($url, $apiKey, $payload, $timeout);
    if (image_api_error_code_from_response($response) === 'model_not_found') {
        $fallbackModels = image_model_fallback_candidates((string) $payload['model']);
        if ($fallbackModels !== []) {
            $retryRecord = $record;
            $retryRecord['model'] = $fallbackModels[0];
            Logger::warning('IMAGE_MODEL_FALLBACK_RETRY', [
                'context' => 'curl-generation',
                'from_model' => (string) $payload['model'],
                'to_model' => $retryRecord['model'],
                'url' => $url,
            ]);
            return call_image_generation_api_curl_mode($baseUrl, $apiKey, $retryRecord, $timeout);
        }
        throw new RuntimeException(image_model_group_unavailable_message(
            (string) $payload['model'],
            image_api_error_message_from_response($response)
        ));
    }

    return $response;
}

function call_image_edit_api_curl_mode(string $baseUrl, string $apiKey, array $record, int $timeout): array
{
    $payload = image_curl_mode_payload($record);
    $url = api_build_url($baseUrl, 'v1/images/edits');
    $files = image_edit_source_files_from_record($record);

    try {
        $postFields = image_edit_post_fields($payload, $files);

        Logger::info('IMAGE_API_CURL_EDIT', [
            'url' => $url,
            'model' => $payload['model'],
            'invoke_mode' => 'curl',
            'file_count' => count($files),
            'payload_keys' => array_keys($payload),
        ]);

        $response = image_api_curl_request($url, $apiKey, $postFields, $timeout);
        if (image_api_error_code_from_response($response) === 'model_not_found') {
            $fallbackModels = image_model_fallback_candidates((string) $payload['model']);
            if ($fallbackModels !== []) {
                $retryRecord = $record;
                $retryRecord['model'] = $fallbackModels[0];
                Logger::warning('IMAGE_MODEL_FALLBACK_RETRY', [
                    'context' => 'curl-edit',
                    'from_model' => (string) $payload['model'],
                    'to_model' => $retryRecord['model'],
                    'url' => $url,
                ]);
                return call_image_edit_api_curl_mode($baseUrl, $apiKey, $retryRecord, $timeout);
            }
            throw new RuntimeException(image_model_group_unavailable_message(
                (string) $payload['model'],
                image_api_error_message_from_response($response)
            ));
        }

        return $response;
    } finally {
        cleanup_image_edit_source_files($files);
    }
}

function finish_image_api_curl($ch): array
{
    $raw        = curl_exec($ch);
    $httpCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    return [
        'raw'         => $raw,
        'http_code'   => $httpCode,
        'content_type' => $contentType,
        'error'       => $curlError,
    ];
}

/**
 * 瑙ｇ爜鍥剧墖 API 鍝嶅簲骞舵牎楠?HTTP 鐘舵€?
 * @param  array $apiResponse  
 * @return array
 */
function image_api_decode_response(array $apiResponse): array
{
    $raw        = $apiResponse['raw'];
    $httpCode   = (int) $apiResponse['http_code'];
    $contentType = (string) $apiResponse['content_type'];
    $curlError  = (string) ($apiResponse['error'] ?? '');

    if ($httpCode >= 500) {
        $excerpt = generation_response_excerpt((string) $raw);
        $errorMap = [
            502 => 'Image API gateway error (502 Bad Gateway). Please check whether the Base URL is correct.',
            503 => 'Image API service is temporarily unavailable (503 Service Unavailable). Please retry later or check the Base URL and API Key.',
            504 => 'Image API timed out (504 Gateway Time-out). Please retry later or use queued generation mode.',
        ];
        $errMsg = $errorMap[$httpCode] ?? ('鍥剧墖鎺ュ彛鏈嶅姟閿欒锛圚TTP ' . $httpCode . '锛夛細' . $excerpt);

        Logger::error('API_HTTP_' . $httpCode, [
            'http_code'        => $httpCode,
            'sanitized_url'    => '(URL 宸茶劚鏁?',
            'content_type'      => $contentType,
            'body_excerpt'     => $excerpt,
        ]);
        throw new RuntimeException($errMsg);
    }

    if ($raw === false || $raw === '') {
        $errMsg = $curlError ?: '鍥剧墖鎺ュ彛鏃犲搷搴旓紙鍙兘鏄帴鍙ｈ秴鏃舵垨缃戠粶涓嶅彲杈撅級';
        Logger::error('API_CURL_ERROR', [
            'curl_error'    => $errMsg,
            'http_code'     => $httpCode,
            'content_type'  => $contentType,
        ]);
        throw new RuntimeException($errMsg);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $excerpt = generation_response_excerpt((string) $raw);
        Logger::error('API_INVALID_JSON', [
            'http_code'    => $httpCode,
            'content_type'  => $contentType,
            'body_excerpt' => $excerpt,
        ]);
        throw new RuntimeException(
            'Image API returned invalid JSON (HTTP ' . $httpCode . '): ' . $excerpt
        );
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(image_api_error_message($data, '鍥剧墖鎺ュ彛杩斿洖 HTTP ' . $httpCode));
    }
    // 浠呭綋 HTTP 姝ｅ父浣嗕笟鍔?code 鏄庣‘涓洪敊璇椂鎵嶆姤閿欙紙code!=0 涓?code!=200锛?
    if (isset($data['code']) && !in_array((int) $data['code'], [0, 200], true)) {
        throw new RuntimeException(image_api_error_message($data, '鍥剧墖鎺ュ彛杩斿洖涓氬姟閿欒'));
    }

    return $data;
}

function image_api_error_message(array $data, string $fallback): string
{
    return api_error_message($data, $fallback);
}

function image_api_first_data_item(array $data): ?array
{
    return api_first_data_item($data);
}

/**
 * 瑙ｆ瀽鍥剧墖鐢熸垚妯″瀷閰嶇疆
 *
 * 鏍规嵁璁板綍涓殑 ai_model_id 鏌ユ壘瀵瑰簲鐨勬ā鍨嬮厤缃紝鎴栦粠鍏ㄥ眬璁剧疆璇诲彇銆?
 *
 * @param  array $record 鐢熸垚璁板綍
 * @return array{base_url: string, api_key: string, model: string, invoke_mode: string}
 * @throws RuntimeException 妯″瀷涓嶅彲鐢ㄦ椂
 */
function resolve_image_generation_config(array $record): array
{
    // 优先使用配置快照锛堝垱寤轰娇鍓嶇殑閰嶇疆锛?
    $snapshotJson = (string) ($record['generation_config_snapshot'] ?? '');
    if ($snapshotJson !== '') {
        $snapshot = json_decode($snapshotJson, true);
        if (is_array($snapshot) && !empty($snapshot['model'])) {
            $baseUrl = (string) ($snapshot['base_url'] ?? '');
            if ($baseUrl !== '') {
                $snapshotModelId = (int) ($snapshot['model_id'] ?? 0);
                if ($snapshotModelId > 0) {
                    $stmt = db()->prepare('SELECT api_key FROM ai_models WHERE id = ? AND is_active = 1 LIMIT 1');
                    $stmt->execute([$snapshotModelId]);
                    $apiKey = (string) $stmt->fetchColumn();
                    if ($apiKey !== '') {
                        return [
                            'base_url' => rtrim($baseUrl, '/'),
                            'api_key'  => $apiKey,
                            'model'    => (string) $snapshot['model'],
                            'invoke_mode' => (string) ($snapshot['invoke_mode'] ?? 'relay'),
                            'supports_edit' => (int) ($snapshot['supports_edit'] ?? 0),
                            'edit_adapter' => trim((string) ($snapshot['edit_adapter'] ?? 'none')) ?: 'none',
                            'edit_image_field' => trim((string) ($snapshot['edit_image_field'] ?? 'image_urls')) ?: 'image_urls',
                        ];
                    }
                }
            }
        }
    }

    $pdo = db();
    $modelId = (int) ($record['ai_model_id'] ?? 0);

    if ($modelId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM ai_models WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$modelId]);
        $modelConfig = $stmt->fetch();
        if (!$modelConfig) {
            throw new RuntimeException('The selected model is unavailable. Please refresh the page and try again.');
        }
        $editAdapter = trim((string) ($modelConfig['edit_adapter'] ?? ''));
        if ($editAdapter === '') {
            $editAdapter = 'none';
        }
        $editImageField = trim((string) ($modelConfig['edit_image_field'] ?? ''));
        if ($editImageField === '') {
            $editImageField = 'image_urls';
        }
        return [
            'base_url' => rtrim((string) $modelConfig['base_url'], '/'),
            'api_key'  => (string) $modelConfig['api_key'],
            'model'    => (string) $modelConfig['model_id'],
            'invoke_mode' => normalize_image_invoke_mode($modelConfig['invoke_mode'] ?? 'relay'),
            'supports_edit' => (int) ($modelConfig['supports_edit'] ?? 0),
            'edit_adapter' => $editAdapter,
            'edit_image_field' => $editImageField,
        ];
    }

    $baseUrl = rtrim((string) app_setting('image_base_url', 'https://api.kbl6.cn'), '/');
    $apiKey  = (string) app_setting('image_api_key', '');
    $model   = (string) app_setting('image_model', 'gpt-image-2');
    if ($model === '') {
        $model = 'gpt-image-2';
    }

    if ($baseUrl === '' || $apiKey === '') {
        throw new RuntimeException('The administrator has not configured the image API yet.');
    }

    return [
        'base_url' => $baseUrl,
        'api_key'  => $apiKey,
        'model'    => $model,
        'invoke_mode' => 'relay',
        'supports_edit' => 0,
        'edit_adapter' => 'none',
        'edit_image_field' => 'image_urls',
    ];
}

/**
 * 浠?chat 瀹屾垚鏍煎紡鍝嶅簲涓彁鍙栧浘鐗囨暟鎹?
 */
function image_chat_completion_item(array $data): ?array
{
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (is_string($content) && $content !== '') {
        // markdown 鍥剧墖: ![alt](data:.../https:...)
        if (preg_match('/!\[.*?\]\(((data:[^;]+;base64,[^\s)]+)|(https?:\/\/[^\s)]+))\)/', $content, $m)) {
            $matched = $m[1];
            if (strpos($matched, 'data:') === 0) {
                // data:image/png;base64,xxxx 鈫?鎻愬彇 base64 閮ㄥ垎
                $parts = explode(',', $matched, 2);
                return ['b64_json' => ($parts[1] ?? '')];
            }
            return ['url' => $matched];
        }
        // 绾枃鏈?URL
        if (strpos($content, 'http') === 0) {
            return ['url' => $content];
        }
        // 绾?base64 鏂囨湰锛堟棤 markdown锛屾棤 URL锛?
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $content) && strlen($content) > 100) {
            return ['b64_json' => $content];
        }
    }
    $imgUrl = $data['choices'][0]['message']['image_url'] ?? null;
    if (is_string($imgUrl) && $imgUrl !== '') {
        return ['url' => $imgUrl];
    }
    $videoUrl = $data['choices'][0]['video']['url'] ?? null;
    if (is_string($videoUrl) && $videoUrl !== '') {
        return ['url' => $videoUrl];
    }
    return null;
}

/**
 * 瀛樺偍鍥剧墖鐢熸垚鎴愬姛缁撴灉鍒拌褰?
 *
 * @param  int   $recordId 璁板綍 ID
 * @param  array $data     API 瑙ｇ爜鍚庣殑鍝嶅簲鏁版嵁
 * @param  array $record   鍘熷鐢熸垚璁板綍
 * @throws RuntimeException 鏁版嵁缂哄け鏃?
 */
function store_image_generation_data(int $recordId, array $data, array $record): void
{
    $item = image_api_first_data_item($data);

    // 鍏煎 chat 瀹屾垚鏍煎紡锛歝hoices[0].message 涓惡甯﹀浘鐗?
    if (!is_array($item)) {
        $item = image_chat_completion_item($data);
    }

    if (!is_array($item)) {
        Logger::info('IMAGE_API_NO_DATA', ['raw_keys' => array_keys($data), 'sample' => substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 500)]);
        throw new RuntimeException('Image API response does not contain image data.');
    }

    $imageBase64  = $item['b64_json'] ?? null;
    $imageUrl     = $item['url'] ?? null;
    $mime         = 'image/' . ($record['output_format'] ?? 'png');
    $storedBase64 = null;
    $storedUrl    = null;

    if (is_string($imageBase64) && $imageBase64 !== '') {
        if (image_storage_mode() === 'base64') {
            $storedBase64 = $imageBase64;
        } else {
            try {
                $storedUrl = save_generated_image_file($imageBase64, (string) ($record['output_format'] ?? 'png'));
            } catch (Throwable $e) {
                Logger::warning('GENERATED_IMAGE_STORE_FALLBACK_BASE64', [
                    'record_id' => $recordId,
                    'error' => $e->getMessage(),
                ]);
                $storedBase64 = $imageBase64;
                $storedUrl = null;
            }
        }
    } elseif (is_string($imageUrl) && $imageUrl !== '') {
        if (is_remote_url($imageUrl)) {
            try {
                $storedUrl = download_remote_image($imageUrl, 'generations');
                Logger::info('REMOTE_IMAGE_CACHED_LOCALLY', [
                    'record_id' => $recordId,
                    'remote_url' => $imageUrl,
                    'local_url' => $storedUrl,
                ]);
            } catch (Throwable $e) {
                Logger::warning('REMOTE_IMAGE_CACHE_FAILED_KEEP_ORIGINAL', [
                    'record_id' => $recordId,
                    'remote_url' => $imageUrl,
                    'error' => $e->getMessage(),
                ]);
                $storedUrl = $imageUrl;
            }
        } else {
            $storedUrl = $imageUrl;
        }
        $mime      = 'image/png';
    } else {
        $availableKeys = 'b64_json' . (array_key_exists('url', $item ?? []) ? ', url' : '');
        throw new RuntimeException(
            'Image API response does not contain image data. Available keys: ' . $availableKeys . '.'

        );
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "UPDATE generation_records
         SET status = 'succeeded', image_base64 = ?, image_url = ?, mime_type = ?,
             usage_json = ?, finished_at = NOW(), error_message = NULL
         WHERE id = ?"
    );
    $stmt->execute([
        $storedBase64,
        $storedUrl,
        $mime,
        isset($data['usage']) ? json_encode($data['usage'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $recordId,
    ]);
}

/**
 * 鎵ц鍥剧墖/瑙嗛鐢熸垚璁板綍
 *
 * 澶勭悊 draw/edit/video 涓夌妯″紡鐨勫畬鏁寸敓鎴愭祦绋嬨€?
 *
 * @param  int  $recordId 璁板綍 ID
 * @param  int|null $timeout 瓒呮椂绉掓暟锛堥粯璁や粠閰嶇疆璇诲彇锛?
 * @return array 鏇存柊鍚庣殑鐢熸垚璁板綍
 * @throws Throwable
 */
function perform_generation_record(int $recordId, ?int $timeout = null): array
{
    ensure_generation_records_queue_status();
    ensure_generation_records_generation_options();
    ensure_generation_records_video_columns();

    $pdo     = db();
    $record  = generation_record_by_id($recordId);
    $timeout = $timeout ?? max(30, (int) config('generation.timeout', 300));

    // 瑙嗛妯″紡锛氳蛋鐙珛鐨勮棰戠敓鎴愭ā鍧?
    if (($record['mode'] ?? '') === 'video') {
        require_once __DIR__ . '/video_generation.php';
        return perform_video_generation_record($recordId, $timeout);
    }

    // 优先使用配置快照（创建任务当时的配置），避免管理员后续修改影响已排队任务
    $record = apply_generation_config_snapshot($record);

    // 图片/编辑模式：解析模型配置
    $config = resolve_image_generation_config($record);
    $record['model'] = $config['model'];
    $record['invoke_mode'] = $config['invoke_mode'];
    $record['supports_edit'] = $config['supports_edit'];
    $record['edit_adapter'] = $config['edit_adapter'];
    $record['edit_image_field'] = $config['edit_image_field'];

    // Validate edit mode is supported
    if (($record['mode'] ?? '') === 'edit') {
        $supportsEdit = (int) ($config['supports_edit'] ?? 0);
        $editAdapter = trim((string) ($config['edit_adapter'] ?? 'none'));
        if ($editAdapter === '') {
            $editAdapter = 'none';
        }
        if ($supportsEdit === 0 || $editAdapter === 'none') {
            throw new RuntimeException('The selected model does not support edit mode. Please choose a model with edit capability, or switch to draw mode.');
        }
    }

    // 统一调用 /images/generations（draw/edit 都走这条路）
    try {
        $apiResponse = call_image_api($config['base_url'], $config['api_key'], $record, $timeout);
        $data = image_api_decode_response($apiResponse);

        $apiError = image_api_error_message($data, '');
        if ($apiError !== '') {
            throw new RuntimeException('Image API returned an error: ' . $apiError);
        }

        store_image_generation_data($recordId, $data, $record);
        return generation_record_by_id($recordId);
    } catch (Throwable $e) {
        refund_generation_failure($pdo, $recordId, $e->getMessage(), 'RECOVERY_FAILED');
        throw $e;
    }
}

/**
 * 应用配置快照到记录
 * 如果记录有 generation_config_snapshot，优先使用快照中的配置
 * 否则从当前数据库模型配置读取
 *
 * @param  array $record
 * @return array
 */
function apply_generation_config_snapshot(array $record): array
{
    $snapshotJson = (string) ($record['generation_config_snapshot'] ?? '');
    if ($snapshotJson === '') {
        return $record;
    }

    $snapshot = json_decode($snapshotJson, true);
    if (!is_array($snapshot) || empty($snapshot['model'])) {
        return $record;
    }

    // 覆盖 record 中的模型相关字段
    $record['model'] = $snapshot['model'];
    $record['snapshot_base_url'] = $snapshot['base_url'] ?? '';
    $record['snapshot_invoke_mode'] = $snapshot['invoke_mode'] ?? 'relay';
    $record['snapshot_supports_edit'] = (int) ($snapshot['supports_edit'] ?? 0);
    $record['snapshot_edit_adapter'] = trim((string) ($snapshot['edit_adapter'] ?? 'none')) ?: 'none';
    $record['snapshot_edit_image_field'] = trim((string) ($snapshot['edit_image_field'] ?? 'image_urls')) ?: 'image_urls';

    // 视频模型专用快照字段
    $record['snapshot_supports_reference'] = (int) ($snapshot['supports_reference'] ?? 0);
    $record['snapshot_reference_required'] = (int) ($snapshot['reference_required'] ?? 0);
    $record['snapshot_max_reference_images'] = max(1, (int) ($snapshot['max_reference_images'] ?? 1));
    $record['snapshot_video_adapter'] = (string) ($snapshot['video_adapter'] ?? 'none');
    $record['snapshot_fixed_seconds'] = max(0, (int) ($snapshot['fixed_seconds'] ?? 0));
    $record['snapshot_video_resolution'] = (string) ($snapshot['video_resolution'] ?? 'auto');
    $record['snapshot_video_aspect_ratio'] = (string) ($snapshot['video_aspect_ratio'] ?? 'auto');

    return $record;
}

/**
 * 鐢熸垚澶辫触鏃堕€€绉垎骞舵爣璁拌褰?
 *
 * @param  PDO    $pdo      鏁版嵁搴撹繛鎺?
 * @param  int    $recordId 璁板綍 ID
 * @param  string $errorMsg 閿欒娑堟伅
 * @param  string $logKey   Logger 鏍囪瘑閿?
 */
function refund_generation_failure(PDO $pdo, int $recordId, string $errorMsg, string $logKey = 'RECOVERY_FAILED'): void
{
    try {
        $recoveryPdo = db();
        $recoveryPdo->beginTransaction();
        $latest  = generation_record_by_id($recordId);
        $charged = (int) ($latest['credits_charged'] ?? 0);
        $userId  = (int) ($latest['user_id'] ?? 0);

        if ($charged > 0 && $userId > 0) {
            $stmt = $recoveryPdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
            $stmt->execute([$charged, $userId]);
        }

        $stmt = $recoveryPdo->prepare(
            "UPDATE generation_records
             SET status = 'failed', credits_charged = 0, error_message = ?, finished_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$errorMsg, $recordId]);
        $recoveryPdo->commit();
    } catch (Throwable $recoveryError) {
        try {
            $rollbackPdo = isset($recoveryPdo) && $recoveryPdo instanceof PDO ? $recoveryPdo : db();
            if ($rollbackPdo->inTransaction()) {
                $rollbackPdo->rollBack();
            }
        } catch (Throwable $_) {
        }
        Logger::error($logKey, [
            'record_id'      => $recordId,
            'error'          => $errorMsg,
            'recovery_error' => $recoveryError->getMessage(),
        ]);
    }
}

/**
 * 鑾峰彇涓嬩竴涓帓闃熶腑鐨勭敓鎴愯褰曪紙琛岄攣锛?
 * @return ?int
 */
function claim_next_generation_record(): ?int
{
    ensure_generation_records_queue_status();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query(
            "SELECT id
             FROM generation_records
             WHERE status = 'queued' AND deleted_at IS NULL
             ORDER BY created_at ASC, id ASC
             LIMIT 1
             FOR UPDATE"
        );
        $recordId = (int) $stmt->fetchColumn();
        if ($recordId < 1) {
            $pdo->commit();
            return null;
        }

        $stmt = $pdo->prepare(
            "UPDATE generation_records
             SET status = 'running', started_at = NOW(), error_message = NULL
             WHERE id = ?"
        );
        $stmt->execute([$recordId]);
        $pdo->commit();
        return $recordId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 鏍囪璁板綍澶辫触骞堕€€绉垎锛堣閿佷繚鎶わ級
 * @param  int $recordId  
 * @param  string $message  
 * @return bool
 */
function claim_generation_record_by_id(int $recordId): bool
{
    ensure_generation_records_queue_status();

    if ($recordId < 1) {
        return false;
    }

    $stmt = db()->prepare(
        "UPDATE generation_records
         SET status = 'running', started_at = NOW(), error_message = NULL
         WHERE id = ? AND status = 'queued' AND deleted_at IS NULL"
    );
    $stmt->execute([$recordId]);

    return $stmt->rowCount() > 0;
}

function generation_worker_signature(int $recordId, int $timestamp): string
{
    $secret = implode('|', [
        (string) config('db.password', ''),
        (string) config('app.session_name', 'image_platform_session'),
        (string) config('app.name', 'image-platform'),
        ROOT_PATH,
    ]);

    return hash_hmac('sha256', $recordId . '|' . $timestamp, $secret);
}

function verify_generation_worker_signature(int $recordId, int $timestamp, string $signature): bool
{
    if ($recordId < 1 || $timestamp < 1 || $signature === '') {
        return false;
    }

    if (abs(time() - $timestamp) > 300) {
        return false;
    }

    return hash_equals(generation_worker_signature($recordId, $timestamp), $signature);
}

function generation_worker_dispatch_url(int $recordId): ?string
{
    if ($recordId < 1) {
        return null;
    }

    $baseUrl = rtrim((string) config('app.base_url', ''), '/');
    if ($baseUrl === '') {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $scheme . '://' . $host;
        }
    }

    if ($baseUrl === '') {
        return null;
    }

    $timestamp = time();
    $query = http_build_query([
        'record_id' => $recordId,
        'ts' => $timestamp,
        'sig' => generation_worker_signature($recordId, $timestamp),
    ]);

    return $baseUrl . '/internal/process_generation.php?' . $query;
}

function trigger_generation_worker_http(int $recordId): bool
{
    $url = generation_worker_dispatch_url($recordId);
    if ($url === null) {
        return false;
    }

    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $host = (string) ($parts['host'] ?? '');
    if ($host === '') {
        return false;
    }

    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $path = (string) (($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''));
    $transport = $scheme === 'https' ? 'ssl://' : '';
    $socket = @fsockopen($transport . $host, $port, $errno, $errstr, 1.5);
    if (!is_resource($socket)) {
        Logger::warning('HTTP_QUEUE_TRIGGER_FAILED', [
            'record_id' => $recordId,
            'url' => $url,
            'errno' => $errno,
            'error' => $errstr,
        ]);
        return false;
    }

    stream_set_timeout($socket, 1);
    $request = "GET {$path} HTTP/1.1\r\n"
        . "Host: {$host}\r\n"
        . "Connection: Close\r\n\r\n";
    fwrite($socket, $request);
    fclose($socket);

    return true;
}

function continue_generation_record_after_response(int $recordId, ?int $timeout = null): void
{
    if ($recordId < 1 || PHP_SAPI === 'cli') {
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    ignore_user_abort(true);

    if (!function_exists('fastcgi_finish_request')) {
        return;
    }

    fastcgi_finish_request();
    $timeout = $timeout ?? max(30, (int) config('generation.timeout', 300));
    @set_time_limit(max(60, $timeout + 30));

    try {
        if (!claim_generation_record_by_id($recordId)) {
            return;
        }

        perform_generation_record($recordId, $timeout);
    } catch (Throwable $e) {
        Logger::error('HTTP_QUEUE_FALLBACK_FAILED', [
            'record_id' => $recordId,
            'error' => $e->getMessage(),
        ]);
    }
}

function fail_generation_record_with_refund(int $recordId, string $message): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, credits_charged, status
             FROM generation_records
             WHERE id = ?
             FOR UPDATE'
        );
        $stmt->execute([$recordId]);
        $record = $stmt->fetch();
        if (!is_array($record) || $record['status'] !== 'running') {
            $pdo->commit();
            return false;
        }

        $charged = (int) $record['credits_charged'];
        if ($charged > 0) {
            $stmt = $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
            $stmt->execute([$charged, (int) $record['user_id']]);
        }

        $stmt = $pdo->prepare(
            "UPDATE generation_records
             SET status = 'failed', credits_charged = 0, error_message = ?, finished_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$message, $recordId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 娓呯悊瓒呮椂鐨?running 浠诲姟
 * @return int
 */
function cleanup_stale_running_generation_records(): int
{
    $httpTimeout     = max(30, (int) config('generation.http_timeout', 60));
    $asyncImageTimeout  = max(300, (int) config('generation.async_image_timeout', 1800));
    $asyncVideoTimeout  = max(300, (int) config('generation.async_video_timeout', 3600));
    $defaultTimeout     = max(30, (int) config('generation.timeout', 300));
    $defaultStaleAfter = max($defaultTimeout + 120, (int) config('generation.stale_running_after', $defaultTimeout + 120));
    $asyncSafetyBuffer = 300;
    $asyncImageStaleAfter = $asyncImageTimeout + $asyncSafetyBuffer;
    $asyncVideoStaleAfter = $asyncVideoTimeout + $asyncSafetyBuffer;

    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT id, mode, ai_model_id
         FROM generation_records
         WHERE status = 'running'
           AND deleted_at IS NULL
         ORDER BY started_at ASC
         LIMIT 50"
    );
    $stmt->execute();
    $candidates = $stmt->fetchAll();
    if (!$candidates) {
        return 0;
    }

    $ids = array_column($candidates, 'id');
    if (!$ids) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $remoteStmt = $pdo->prepare(
        "SELECT id, remote_task_id, last_poll_at, mode, ai_model_id
         FROM generation_records
         WHERE id IN ($placeholders)"
    );
    $remoteStmt->execute($ids);
    $remoteMap = [];
    foreach ($remoteStmt->fetchAll() as $row) {
        $remoteMap[(int) $row['id']] = $row;
    }

    $count = 0;
    foreach ($candidates as $row) {
        $id = (int) $row['id'];
        $remote = $remoteMap[$id] ?? [];
        $hasRemoteTask = !empty($remote['remote_task_id']);
        $modelId = (int) ($row['ai_model_id'] ?? 0);
        $mode = trim((string) ($row['mode'] ?? ''));
        $isAsyncTask = $hasRemoteTask;

        // æè¿ 10 åéåæ pollï¼ä¸æ¸ç
        if ($isAsyncTask && !empty($remote['last_poll_at'])) {
            $lastPoll = strtotime((string) $remote['last_poll_at']);
            if ($lastPoll && (time() - $lastPoll) < 600) {
                continue;
            }
        }

        if ($isAsyncTask) {
            $staleSeconds = ($mode === 'video') ? $asyncVideoStaleAfter : $asyncImageStaleAfter;
            $msg = ($mode === 'video')
                ? "视频生成等待超时，上游长时间未返回结果，余额已自动退回。请稍后重试。"
                : "生成任务等待超时，上游长时间未返回结果，余额已自动退回。请稍后重试。";
        } else {
            $staleSeconds = $defaultStaleAfter;
            $msg = "生成任务等待超时，余额已自动退回。请稍后重试。";
        }

        $stmtCheck = $pdo->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed
             FROM generation_records
             WHERE id = ? AND status = 'running' AND deleted_at IS NULL"
        );
        $stmtCheck->execute([$id]);
        $elapsedRow = $stmtCheck->fetch();
        if (!$elapsedRow) {
            continue;
        }
        $elapsed = (int) $elapsedRow['elapsed'];
        if ($elapsed <= $staleSeconds) {
            continue;
        }

        if (fail_generation_record_with_refund($id, $msg)) {
            $count++;
        }
    }

    return $count;
}

