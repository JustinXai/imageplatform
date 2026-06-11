<?php


class ImageEditTaskQueuedException extends Exception
{
    public string $taskId;
    public string $submitData;
    public string $baseUrl;
    public string $apiKey;
    public int $recordId;

    public function __construct(string $taskId, string $submitData, string $baseUrl, string $apiKey, int $recordId)
    {
        parent::__construct("Image edit task queued: {$taskId}");
        $this->taskId = $taskId;
        $this->submitData = $submitData;
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->recordId = $recordId;
    }
}


require_once __DIR__ . '/api_client.php';

/**
 * NewToken 异步模型规格矩阵。
 * 所有模型统一走 POST /v1/videos 和 GET /v1/videos/{task_id}。
 *
 * 字段说明：
 *   kind              image | video
 *   endpoint          提交端点
 *   prompt_field     prompt 参数名（固定 "prompt"）
 *   aspect_field     比例参数名
 *   duration_field   时长参数名（视频用）
 *   primary_image_field   单张主参考图参数名（video-pro: image_url, sora-2: image）
 *   reference_field  多张参考图参数名（nana: images, gpt-image-2: image_urls, veo: Ingredients_images）
 *   extra_images_field   额外参考图（video-pro: extra_images）
 *   extra_videos_field   额外参考视频（video-pro: extra_videos）
 *   extra_audios_field   额外参考音频（video-pro: extra_audios）
 *   video_field          参考视频参数名（veo-video-edit: video_url）
 *   result_fields    结果 URL 字段优先级列表
 *   supports_draw        是否支持纯文生图
 *   supports_reference   是否支持参考图模式
 *   supports_edit        是否支持编辑模式（需要参考图）
 *   max_reference_images 最多参考图数（0 = 无上限）
 *   max_reference_videos 最多参考视频数（0 = 无上限）
 *   resolution_options   可选分辨率（Nana 用，1k/2k）
 *   duration_options     可选时长列表（视频用）
 *   default_duration     默认时长（视频用，固定值时可不传）
 *   supports_sync        是否支持同步返回（目前全部 false）
 */
define('NEWTOKEN_MODEL_SPECS', [
    // =====================================================================
    // 图片模型
    // =====================================================================

    'gpt-image-2-1K' => [
        'kind' => 'image',
        'endpoint' => '/v1/videos',
        'prompt_field' => 'prompt',
        'aspect_field' => 'aspect_ratio',
        'reference_field' => 'image_urls',
        'result_fields' => ['image_url', 'url', 'metadata.result_urls'],
        'supports_draw' => true,
        'supports_reference' => true,
        'supports_edit' => false,          // 产品上叫"参考图生成"，不放编辑 tab
        'max_reference_images' => 10,
    ],
    'gpt-image-2-2K' => [
        'kind' => 'image',
        'endpoint' => '/v1/videos',
        'prompt_field' => 'prompt',
        'aspect_field' => 'aspect_ratio',
        'reference_field' => 'image_urls',
        'result_fields' => ['image_url', 'url', 'metadata.result_urls'],
        'supports_draw' => true,
        'supports_reference' => true,
        'supports_edit' => false,
        'max_reference_images' => 10,
    ],
    'gpt-image-2-4K' => [
        'kind' => 'image',
        'endpoint' => '/v1/videos',
        'prompt_field' => 'prompt',
        'aspect_field' => 'aspect_ratio',
        'reference_field' => 'image_urls',
        'result_fields' => ['image_url', 'url', 'metadata.result_urls'],
        'supports_draw' => true,
        'supports_reference' => true,
        'supports_edit' => false,
        'max_reference_images' => 10,
    ],

    'nana-banana-2' => [
        'kind' => 'image',
        'endpoint' => '/v1/videos',
        'prompt_field' => 'prompt',
        'aspect_field' => 'aspect_ratio',
        'reference_field' => 'images',
        'resolution_field' => 'resolution',
        'resolution_options' => ['1k', '2k'],
        'result_fields' => ['image_url', 'url', 'metadata.result_urls'],
        'supports_draw' => true,
        'supports_reference' => true,
        'supports_edit' => true,
        'max_reference_images' => 10,
    ],
    'nana-banana-pro' => [
        'kind' => 'image',
        'endpoint' => '/v1/videos',
        'prompt_field' => 'prompt',
        'aspect_field' => 'aspect_ratio',
        'reference_field' => 'images',
        'resolution_field' => 'resolution',
        'resolution_options' => ['1k', '2k'],
        'result_fields' => ['image_url', 'url', 'metadata.result_urls'],
        'supports_draw' => true,
        'supports_reference' => true,
        'supports_edit' => true,
        'max_reference_images' => 10,
    ],

    // =====================================================================
    // 视频模型
    // =====================================================================

    'video-pro-720p' => [
        'kind' => 'video',
        'endpoint' => '/v1/videos',
        'prompt_field' => 'prompt',
        'duration_field' => 'duration',
        'aspect_field' => 'aspect_ratio',
        'primary_image_field' => 'image_url',
        'extra_images_field' => 'extra_images',
        'extra_videos_field' => 'extra_videos',
        'extra_audios_field' => 'extra_audios',
        'result_fields' => ['video_url', 'url'],
        'supports_draw' => true,
        'supports_reference' => false,
        'supports_edit' => false,
        'duration_options' => [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
        'default_duration' => 6,
        'max_reference_images' => 0,
    ],

    'sora-2' => [
        'kind' => 'video',
        'endpoint' => '/v1/videos',
        'prompt_field' => 'prompt',
        'duration_field' => 'duration',
        'aspect_field' => 'aspect_ratio',
        'primary_image_field' => 'image',
        'result_fields' => ['video_url', 'url'],
        'supports_draw' => true,
        'supports_reference' => false,
        'supports_edit' => false,
        'duration_options' => [12],
        'default_duration' => 12,
        'max_reference_images' => 1,   // 单张主参考图用 primary_image_field
    ],

    'veo-omni-flash' => [
        'kind' => 'video',
        'endpoint' => '/v1/videos',
        'prompt_field' => 'prompt',
        'duration_field' => 'duration',
        'aspect_field' => 'aspect_ratio',
        'reference_field' => 'Ingredients_images',
        'result_fields' => ['video_url', 'url'],
        'supports_draw' => true,
        'supports_reference' => true,
        'supports_edit' => false,
        'duration_options' => [10],
        'default_duration' => 10,
        'max_reference_images' => 6,
    ],

    'veo-omni-flash-video-edit' => [
        'kind' => 'video',
        'endpoint' => '/v1/videos',
        'prompt_field' => 'prompt',
        'duration_field' => 'duration',
        'aspect_field' => 'aspect_ratio',
        'video_field' => 'video_url',
        'reference_field' => 'Ingredients_images',
        'result_fields' => ['video_url', 'url'],
        'supports_draw' => false,
        'supports_reference' => true,
        'supports_edit' => false,
        'duration_options' => [10],
        'default_duration' => 10,
        'max_reference_images' => 6,
        'max_reference_videos' => 1,
        'requires_video' => true,
    ],
]);

/**
 * 根据 model_id 查找 NEWTOKEN_MODEL_SPECS 规格。
 */
function newtoken_model_spec(string $modelId): ?array
{
    return NEWTOKEN_MODEL_SPECS[$modelId] ?? null;
}

/**
 * 判断是否为 NewToken 异步模型。
 */
function is_newtoken_async_model(string $modelId): bool
{
    return array_key_exists($modelId, NEWTOKEN_MODEL_SPECS);
}

/**
 * 获取图片类模型规格（kind === 'image'）。
 */
function newtoken_image_specs(): array
{
    return array_filter(NEWTOKEN_MODEL_SPECS, fn(array $s) => ($s['kind'] ?? '') === 'image');
}

/**
 * 获取视频类模型规格（kind === 'video'）。
 */
function newtoken_video_specs(): array
{
    return array_filter(NEWTOKEN_MODEL_SPECS, fn(array $s) => ($s['kind'] ?? '') === 'video');
}

/**
 * 判断模型是否支持指定模式。
 */
function newtoken_model_supports_mode(string $modelId, string $mode): bool
{
    $spec = newtoken_model_spec($modelId);
    if ($spec === null) {
        return false;
    }
    return match ($mode) {
        'draw' => ($spec['supports_draw'] ?? false),
        'reference' => ($spec['supports_reference'] ?? false),
        'edit' => ($spec['supports_edit'] ?? false),
        default => false,
    };
}

/**
 * 获取模型支持的最多参考图数。
 */
function newtoken_max_reference_images(string $modelId): int
{
    $spec = newtoken_model_spec($modelId);
    return (int) ($spec['max_reference_images'] ?? 0);
}

function build_generation_config_snapshot(int $modelId, string $mode, array $params = []): ?string
{
    $mode = strtolower(trim($mode));
    $snapshot = [
        'mode' => $mode,
        'captured_at' => date('c'),
    ];

    if ($modelId > 0) {
        $stmt = db()->prepare('SELECT * FROM ai_models WHERE id = ? LIMIT 1');
        $stmt->execute([$modelId]);
        $model = $stmt->fetch();
        if (is_array($model)) {
            $snapshot['ai_model_id'] = (int) $model['id'];
            $snapshot['model_type'] = (string) ($model['model_type'] ?? '');
            foreach ([
                'base_url', 'model_id', 'invoke_mode', 'auth_type', 'credits',
                'supports_edit', 'edit_adapter', 'edit_image_field', 'max_reference_images', 'edit_endpoint', 'edit_poll_endpoint',
                'supports_reference', 'reference_required', 'video_adapter', 'fixed_seconds', 'video_resolution', 'video_aspect_ratio', 'input_mode',
                'image_adapter',
            ] as $field) {
                if (array_key_exists($field, $model) && $model[$field] !== null) {
                    $snapshot[$field] = $model[$field];
                }
            }
        }
    }

    foreach (['mode', 'size', 'quality', 'format', 'seconds'] as $field) {
        if (array_key_exists($field, $params) && $params[$field] !== null && $params[$field] !== '') {
            $snapshot['request_' . $field] = $params[$field];
        }
    }

    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function apply_generation_config_snapshot(array $record, array $config): array
{
    $liveApiKey = (string) ($record['__live_api_key'] ?? $config['api_key'] ?? '');
    $snapshotRaw = (string) ($record['generation_config_snapshot'] ?? '');
    if ($snapshotRaw !== '') {
        $snapshot = json_decode($snapshotRaw, true);
        if (is_array($snapshot)) {
            foreach ($snapshot as $key => $value) {
                if ($key === 'api_key' || str_starts_with((string) $key, 'request_')) {
                    continue;
                }
                $config[$key] = $value;
            }
        }
    }
    $config['api_key'] = $liveApiKey;
    return $config;
}



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

    if ($mode !== 'video') {

        if ($modelId > 0) {

            $stmt = db()->prepare('SELECT credits FROM ai_models WHERE id = ? AND credits IS NOT NULL AND credits > 0');

            $stmt->execute([$modelId]);

            $credits = (int) $stmt->fetchColumn();

            if ($credits > 0) {

                return $credits;

            }

        }

        return 1;

    }



    if ($modelId > 0) {

        $stmt = db()->prepare('SELECT credits, fixed_seconds FROM ai_models WHERE id = ? AND is_active = 1');

        $stmt->execute([$modelId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {

            $perSecond = (int) ($row['credits'] ?? 0);

            $seconds = (int) ($row['fixed_seconds'] ?? 0);

            if ($perSecond > 0 && $seconds > 0) {

                return $perSecond * $seconds;

            }

        }

    }

    return 0;

}



/**

 * Get video model fixed seconds

 */

function video_fixed_seconds(int $modelId): int

{

    if ($modelId <= 0) {

        return 0;

    }

    $stmt = db()->prepare('SELECT fixed_seconds FROM ai_models WHERE id = ? AND is_active = 1');

    $stmt->execute([$modelId]);

    $seconds = (int) $stmt->fetchColumn();

    return max(0, $seconds);

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

        throw new InvalidArgumentException('请填写提示词。');

    }

    if (!generation_size_is_valid($size)

        || !in_array($mode, generation_allowed_modes(), true)) {

        throw new InvalidArgumentException('无效的生成参数，请刷新页面后重试。');

    }



    // 瑙嗛妯″紡锛氫笉闇€瑕?quality銆佷笉闇€瑕佷笂浼犲浘鐗?

    if ($mode === 'video') {

        $format = (string) ($input['output_format'] ?? 'mp4');

        if (!in_array($format, ['mp4', 'webm'], true)) {

            $format = 'mp4';

        }

        $resolution = normalize_video_resolution((string) ($input['resolution'] ?? 'auto'));

        if (!video_resolution_is_valid($resolution)) {

            throw new InvalidArgumentException('无效的视频分辨率。');

        }

        $videoMode = strtolower(trim((string) ($input['video_mode'] ?? 'text')));

        if (!in_array($videoMode, ['text', 'image'], true)) {

            $videoMode = 'text';

        }

        $modelId = (int) ($input['ai_model_id'] ?? 0);
        $modelSupportsRef = 0;
        $modelRefRequired = 0;
        $modelFixedSeconds = 0;
        $modelFixedResolution = 'auto';
        $modelFixedAspectRatio = 'auto';
        $maxRefImages = 1;

        if ($modelId > 0) {
            $stmt = db()->prepare('SELECT supports_reference, reference_required, max_reference_images, fixed_seconds, video_resolution, video_aspect_ratio FROM ai_models WHERE id = ? AND is_active = 1 AND model_type = ? LIMIT 1');
            $stmt->execute([$modelId, 'video']);
            $videoModelRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($videoModelRow)) {
                $modelSupportsRef = (int) ($videoModelRow['supports_reference'] ?? 0);
                $modelRefRequired = (int) ($videoModelRow['reference_required'] ?? 0);
                $maxRefImages = max(1, (int) ($videoModelRow['max_reference_images'] ?? 1));
                $modelFixedSeconds = max(0, (int) ($videoModelRow['fixed_seconds'] ?? 0));
                $modelFixedResolution = normalize_video_resolution((string) ($videoModelRow['video_resolution'] ?? 'auto'));
                $modelFixedAspectRatio = trim((string) ($videoModelRow['video_aspect_ratio'] ?? 'auto'));
            }
        }

        if ($modelSupportsRef === 0 && ($videoMode === 'image' || request_has_uploaded_edit_images($files))) {
            Logger::warning('VIDEO_REFERENCE_REJECTED', ['model_id' => $modelId, 'reason' => 'supports_reference_disabled']);
            throw new InvalidArgumentException('当前视频模型不支持参考图片上传。');
        }

        $inputImages = [];

        if ($videoMode === 'image' || request_has_uploaded_edit_images($files)) {

            $inputImages = generation_uploaded_images_from_files($files);

            if (!$inputImages) {
                if ($modelRefRequired === 1) {
                    throw new InvalidArgumentException('当前视频模型要求上传参考图片。');
                }
                throw new InvalidArgumentException('图生视频模式需要至少一张参考图片。');
            }

            if (count($inputImages) > $maxRefImages) {
                throw new InvalidArgumentException('当前视频模型最多允许 ' . $maxRefImages . ' 张参考图片。');
            }

        } elseif ($modelRefRequired === 1) {
            throw new InvalidArgumentException('当前视频模型要求上传参考图片。');
        }

        if ($modelFixedSeconds <= 0) {
            throw new InvalidArgumentException('当前视频模型尚未完成内测固定时长配置。');
        }

        $effectiveSeconds = $modelFixedSeconds;
        $effectiveResolution = $modelFixedResolution !== 'auto' ? $modelFixedResolution : $resolution;
        $effectiveSize = $modelFixedAspectRatio !== '' && $modelFixedAspectRatio !== 'auto' ? $modelFixedAspectRatio : $size;

        $model   = $modelId > 0 ? '' : (string) app_setting('video_model', '');

        return [

            'mode'        => $mode,

            'prompt'      => $prompt,

            'size'        => $effectiveSize,

            'quality'     => $effectiveResolution,

            'format'      => $format,

            'model'       => $model,

            'ai_model_id' => $modelId,

            'input_images' => $inputImages,

            'seconds'     => $effectiveSeconds,

        ];

    }



    $quality = normalize_generation_quality((string) ($input['quality'] ?? 'auto'));

    $format  = (string) ($input['output_format'] ?? 'png');

    if (!in_array($quality, generation_allowed_quality(), true)

        || !in_array($format, generation_allowed_formats(), true)) {

        throw new InvalidArgumentException('无效的生成参数，请刷新页面后重试。');

    }



    $hasEditUploads = request_has_uploaded_edit_images($files);

    if ($hasEditUploads) {

        $mode = 'edit';

    }



    $inputImages = [];

    if ($mode === 'edit') {

        $inputImages = generation_uploaded_images_from_files($files);

        if (!$inputImages) {

            throw new InvalidArgumentException('编辑模式需要至少一张参考图片。没有参考图时，请切换到绘画模式。');

        }

    }



    $modelId = (int) ($input['ai_model_id'] ?? 0);

    $model   = $modelId > 0 ? '' : image_model_id();

    if ($mode === 'edit' && $modelId > 0) {
        $snapshotRaw = trim((string) ($input['generation_config_snapshot'] ?? ''));
        $snapshotSupportsEdit = null;
        $snapshotEditAdapter = null;
        if ($snapshotRaw !== '') {
            $snap = @json_decode($snapshotRaw, true);
            if (is_array($snap)) {
                $snapshotSupportsEdit = (int) ($snap['supports_edit'] ?? 0);
                $snapshotEditAdapter = trim((string) ($snap['edit_adapter'] ?? ''));
            }
        }
        if ($snapshotSupportsEdit === null) {
            $pdo2 = db();
            $stmt2 = $pdo2->prepare('SELECT supports_edit, edit_adapter FROM ai_models WHERE id = ? AND is_active = 1 LIMIT 1');
            $stmt2->execute([$modelId]);
            $row = $stmt2->fetch();
            if (!$row) {
                throw new InvalidArgumentException('The selected model is unavailable. Please refresh the page and try again.');
            }
            $snapshotSupportsEdit = (int) ($row['supports_edit'] ?? 0);
            $snapshotEditAdapter = trim((string) ($row['edit_adapter'] ?? ''));
        }
        if ($snapshotEditAdapter === '') $snapshotEditAdapter = 'none';
        if ($snapshotSupportsEdit !== 1 || $snapshotEditAdapter === 'none') {
            throw new InvalidArgumentException('当前模型不支持图片编辑，请选择已开启编辑能力的模型，或切换到绘画模式。');
        }
    }



    return [

        'mode'        => $mode,

        'prompt'      => $prompt,

        'size'        => $size,

        'quality'     => $quality,

        'format'      => $format,

        'model'       => $model,

        'ai_model_id' => $modelId,

        'input_images' => $inputImages,

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

            throw new InvalidArgumentException('参考图片上传失败。');

        }

        if (count($images) >= $limit) {

            throw new InvalidArgumentException('每次最多上传 ' . $limit . ' 张参考图片。');

        }



        $tmpName = (string) ($tmpNames[$index] ?? '');

        $size    = (int) ($sizes[$index] ?? 0);

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {

            throw new InvalidArgumentException('参考图片无效。');

        }

        $maxBytes = max_edit_image_mb() * 1024 * 1024;

        if ($size <= 0 || $size > $maxBytes) {

            throw new InvalidArgumentException('每张参考图片大小不能超过 ' . max_edit_image_mb() . ' MB。');

        }



        $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {

            throw new InvalidArgumentException('参考图片格式必须为 PNG、JPG、JPEG 或 WEBP。');

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

            throw new InvalidArgumentException('参考图片 MIME 类型必须为 PNG、JPEG 或 WEBP。');

        }

        $extensionMimeMap = [

            'png'  => 'image/png',

            'jpg'  => 'image/jpeg',

            'jpeg' => 'image/jpeg',

            'webp' => 'image/webp',

        ];

        if (($extensionMimeMap[$extension] ?? '') !== $mime) {

            throw new InvalidArgumentException('参考图片扩展名与实际文件类型不匹配。');

        }



        $imageSize = @getimagesize($tmpName);

        if (!is_array($imageSize) || empty($imageSize[0]) || empty($imageSize[1])) {

            throw new InvalidArgumentException('参考图片不是有效的图片文件。');

        }

        $width        = (int) $imageSize[0];

        $height       = (int) $imageSize[1];

        $maxDimension = max_edit_image_dimension();

        if ($width > $maxDimension || $height > $maxDimension) {

            throw new InvalidArgumentException('参考图片尺寸不能超过 ' . $maxDimension . ' 像素。');

        }



        $content = file_get_contents($tmpName);

        if ($content === false) {

            throw new InvalidArgumentException('读取参考图片失败。');

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
 * 写入生成扣费账本记录（幂等，防止重复扣费）
 *
 * @param  PDO   $pdo         数据库连接（需在事务中）
 * @param  int   $recordId    generation_records.id
 * @param  int   $userId      用户 ID
 * @param  int   $cost        扣费点数（正数）
 * @param  int   $balanceBefore 扣费前余额
 * @param  int   $balanceAfter  扣费后余额
 * @param  string $modelName    模型名称（用于 reason）
 * @return void
 */
function record_generation_charge_log(PDO $pdo, int $recordId, int $userId, int $cost, int $balanceBefore, int $balanceAfter, string $modelName = ''): void
{
    if ($recordId < 1 || $userId < 1 || $cost <= 0) {
        return;
    }

    $check = $pdo->prepare(
        "SELECT id FROM credit_logs WHERE ref_type = 'generation_record' AND ref_id = ? LIMIT 1"
    );
    $check->execute([(string) $recordId]);
    if ($check->fetch()) {
        return;
    }

    $reason = '图片生成扣费';
    if ($modelName !== '') {
        $reason = mb_substr('图片生成扣费：' . $modelName, 0, 255, 'UTF-8');
    }

    $insert = $pdo->prepare(
        'INSERT INTO credit_logs (user_id, amount, balance_before, balance_after, type, source, ref_type, ref_id, admin_id, reason, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NOW())'
    );
    $insert->execute([
        $userId,
        -$cost,
        $balanceBefore,
        $balanceAfter,
        'generation_charge',
        'system',
        'generation_record',
        (string) $recordId,
        $reason,
        '127.0.0.1',
    ]);
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

        throw new InvalidArgumentException('无效的生成任务状态。');

    }



    ensure_generation_records_queue_status();

    ensure_generation_records_generation_options();



    $modelId = isset($params['ai_model_id']) ? (int) $params['ai_model_id'] : 0;

    $cost = generation_cost_for((string) $params['mode'], $modelId);

    $configSnapshot = build_generation_config_snapshot($modelId, (string) $params['mode'], $params);

    if ($cost === 0 && (string) $params['mode'] === 'video') {

        throw new RuntimeException('视频模型未配置固定秒数或每秒点数，请联系管理员。');

    }

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



        $balanceBefore = $credits;
        $balanceAfter = $credits - $cost;

        $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ?');

        $stmt->execute([$cost, $userId]);



        $stmt = $pdo->prepare(

            'INSERT INTO generation_records

             (user_id, status, mode, model, ai_model_id, prompt, size, quality, output_format, input_images_json, credits_cost, seconds, generation_config_snapshot)

             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'

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

            isset($params['seconds']) ? (int) $params['seconds'] : null,

            $configSnapshot,

        ]);

        $recordId = (int) $pdo->lastInsertId();
        $modelName = isset($params['model']) ? trim((string) $params['model']) : '';
        record_generation_charge_log($pdo, $recordId, $userId, $cost, $balanceBefore, $balanceAfter, $modelName);

        $pdo->commit();



        return ['id' => $recordId, 'credits_cost' => $cost, 'seconds' => isset($params['seconds']) ? (int) $params['seconds'] : null];

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }

        throw $e;

    }

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

        throw new RuntimeException('生成记录不存在。');

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
        'credits_cost'   => (int) $record['credits_cost'],
        'created_at'     => (string) $record['created_at'],
        'finished_at'    => $record['finished_at'] ?: '-',
        'error_message'  => (string) ($record['error_message'] ?: ''),
        'input_image_count' => (int) (is_array(json_decode((string) ($record['input_images_json'] ?? ''), true)) ? count(json_decode((string) ($record['input_images_json'] ?? ''), true)) : 0),
        'selected_aspect'       => (string) ($record['selected_aspect'] ?? ''),
        'selected_duration'     => (int) ($record['selected_duration'] ?? 0),
        'selected_video_mode'   => (string) ($record['selected_video_mode'] ?? ''),
    ];

    if ($isVideo) {
        $result['video_src']  = (!empty($record['video_url']))
        ? (string) $record['video_url']
        : (!empty($record['video_base64'])
            ? 'data:' . ($record['video_mime_type'] ?: 'video/mp4') . ';base64,' . $record['video_base64']
            : null);
        $result['video_url']  = (string) ($record['video_url'] ?? '');
        $result['media_url']  = $result['video_src'] ?: $result['video_url'];
        $result['media_type'] = 'video';
        $result['mime_type']  = (string) ($record['video_mime_type'] ?: 'video/mp4');
        $result['download_url'] = $result['video_src'] ?: $result['video_url'];
    } else {
        $thumbUrl = (!empty($record['thumb_url'])) ? (string) $record['thumb_url'] : null;
        $result['thumb_url'] = $thumbUrl ?: '';
        $result['image_src']  = (!empty($record['output_url']))
        ? (string) $record['output_url']
        : (!empty($record['output_base64'])
            ? 'data:' . ($record['mime_type'] ?: 'image/png') . ';base64,' . $record['output_base64']
            : null);
        $result['media_url']  = $thumbUrl ?: $result['image_src'] ?: '';
        $result['media_type'] = 'image';
        $result['mime_type']  = (string) ($record['mime_type'] ?? 'image/png');
        $result['download_url'] = $result['image_src'] ?: '';
    }

    return $result;
}


function image_edit_task_id(array $data): string
{
    $candidates = [
        $data['task_id'] ?? null,
        $data['taskId'] ?? null,
        $data['id'] ?? null,
        $data['generation_id'] ?? null,
        $data['data']['id'] ?? null,
        $data['data']['task_id'] ?? null,
        $data['data']['taskId'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }
    return '';
}

function image_edit_task_status(array $data): string
{
    $candidates = [
        $data['status'] ?? null,
        $data['data']['status'] ?? null,
        $data['task']['status'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return strtolower(trim($candidate));
        }
    }
    return '';
}

function image_edit_result_item(array $data): ?array
{
    $candidates = [
        $data['data']['images'][0] ?? null,
        $data['images'][0] ?? null,
        $data['video_url'] ?? null,
        $data['data']['video_url'] ?? null,
        ['url' => $data['url'] ?? null],
        ['url' => $data['image_url'] ?? null],
        ['url' => $data['output_url'] ?? null],
        ['url' => $data['result_url'] ?? null],
        ['url' => $data['data']['url'] ?? null],
        ['url' => $data['data']['image_url'] ?? null],
        ['url' => $data['data']['output_url'] ?? null],
        ['url' => $data['data']['result_url'] ?? null],
        $data['data']['output'][0] ?? null,
        $data['data']['outputs'][0] ?? null,
        $data['output'][0] ?? null,
        $data['outputs'][0] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_array($candidate)) {
            if (!empty($candidate['url']) || !empty($candidate['image_url']) || !empty($candidate['b64_json'])) {
                return $candidate;
            }
            if (count($candidate) === 1 && is_string(reset($candidate))) {
                return ['url' => (string) reset($candidate)];
            }
        } elseif (is_string($candidate) && trim($candidate) !== '') {
            return ['url' => trim($candidate)];
        }
    }
    return null;
}

function image_edit_task_is_running(string $status): bool
{
    return in_array($status, ['queued','pending','running','processing','in_progress','submitted'], true);
}

function image_edit_task_is_success(string $status): bool
{
    return in_array($status, ['succeeded','success','completed','done','finished'], true);
}

function image_edit_task_is_failed(string $status): bool
{
    return in_array($status, ['failed','error','cancelled','canceled','expired'], true);
}

function detect_binary_media_mime_from_string(string $binary): string
{
    if ($binary === '') {
        return '';
    }

    if (strncmp($binary, "\xFF\xD8\xFF", 3) === 0) {
        return 'image/jpeg';
    }
    if (strncmp($binary, "\x89PNG", 4) === 0) {
        return 'image/png';
    }
    if (strlen($binary) >= 12 && substr($binary, 0, 4) === 'RIFF' && substr($binary, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    if (strlen($binary) >= 8 && substr($binary, 4, 4) === 'ftyp') {
        return 'video/mp4';
    }

    return '';
}

function detect_downloaded_media_type(string $tmpFile, array $responseHeaders = [], string $url = ''): array
{
    $headerMime = '';
    foreach ($responseHeaders as $key => $value) {
        if (is_string($key) && strtolower($key) === 'content-type') {
            $headerMime = strtolower(trim(explode(';', (string) $value)[0] ?? ''));
            break;
        }
    }

    $finfoMime = '';
    if (is_file($tmpFile)) {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $finfoMime = (string) finfo_file($finfo, $tmpFile);
                finfo_close($finfo);
            }
        }
        if ($finfoMime === '' && function_exists('mime_content_type')) {
            $finfoMime = (string) @mime_content_type($tmpFile);
        }
    }

    $magicMime = '';
    $prefix = is_file($tmpFile) ? (string) @file_get_contents($tmpFile, false, null, 0, 64) : '';
    if ($prefix !== '') {
        $magicMime = detect_binary_media_mime_from_string($prefix);
    }

    $candidates = [$headerMime, $finfoMime, $magicMime];
    $mime = '';
    foreach ($candidates as $candidate) {
        $candidate = strtolower(trim((string) $candidate));
        if ($candidate !== '' && $candidate !== 'application/octet-stream' && $candidate !== 'text/html') {
            $mime = $candidate;
            break;
        }
    }

    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    return [
        'mime' => $mime,
        'extension' => $extensionMap[$mime] ?? '',
        'kind' => str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : 'unknown'),
        'header_mime' => $headerMime,
        'finfo_mime' => $finfoMime,
        'magic_mime' => $magicMime,
        'url' => $url,
    ];
}

function save_downloaded_media_file(string $binary, array $detected, string $bucket = 'generations'): string
{
    $extension = (string) ($detected['extension'] ?? '');
    if ($extension === '') {
        throw new RuntimeException('上游完成，但结果下载/保存失败：无法识别媒体类型。');
    }

    return api_save_binary_file($binary, $extension, $bucket);
}

function localize_remote_media_url(string $url, string $bucket = 'generations'): array
{
    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException('上游完成，但结果下载/保存失败：结果 URL 为空。');
    }

    if (!is_remote_url($url)) {
        $localPath = local_public_file_from_url($url);
        if ($localPath === null || !is_file($localPath)) {
            throw new RuntimeException('本地媒体文件不存在，无法完成结果校验。');
        }
        $detected = detect_downloaded_media_type($localPath, [], $url);
        if (($detected['kind'] ?? 'unknown') === 'unknown' || ($detected['extension'] ?? '') === '') {
            throw new RuntimeException('本地媒体文件类型无效。');
        }
        return [
            'path' => $url,
            'mime' => (string) ($detected['mime'] ?? ''),
            'kind' => (string) ($detected['kind'] ?? 'unknown'),
            'source_url' => $url,
            'already_local' => true,
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => ssl_verify_enabled(),
        CURLOPT_SSL_VERIFYHOST => ssl_verify_enabled() ? 2 : 0,
        CURLOPT_HTTPHEADER => ['Accept: image/*,video/*;q=0.9,*/*;q=0.1'],
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = (string) curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('上游完成，但结果下载/保存失败：未获取到文件内容。');
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('上游完成，但结果下载/保存失败：HTTP ' . $httpCode . '。');
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'gen-media-');
    if ($tmpFile === false) {
        throw new RuntimeException('上游完成，但结果下载/保存失败：无法创建临时文件。');
    }

    file_put_contents($tmpFile, $raw, LOCK_EX);
    try {
        $detected = detect_downloaded_media_type($tmpFile, ['content-type' => $contentType], $url);
        if (($detected['kind'] ?? 'unknown') === 'unknown' || ($detected['extension'] ?? '') === '') {
            throw new RuntimeException('上游完成，但结果下载/保存失败：结果不是有效图片或视频。');
        }
        if (($detected['mime'] ?? '') === 'text/html' || ($detected['header_mime'] ?? '') === 'text/html') {
            throw new RuntimeException('上游完成，但结果下载/保存失败：返回了 text/html。');
        }
        $relativePath = save_downloaded_media_file($raw, $detected, $bucket);
    } finally {
        @unlink($tmpFile);
    }

    return [
        'path' => $relativePath,
        'mime' => (string) ($detected['mime'] ?? ''),
        'kind' => (string) ($detected['kind'] ?? 'unknown'),
        'source_url' => $url,
        'already_local' => false,
        'http_code' => $httpCode,
        'content_type' => $contentType,
        'curl_error' => $curlError,
    ];
}

function image_reference_urls_from_record(array $record): array
{
    $images = json_decode((string) ($record['input_images_json'] ?? ''), true);
    if (!is_array($images) || !$images) {
        throw new RuntimeException('编辑任务缺少参考图片。');
    }
    $imageUrls = [];
    foreach ($images as $image) {
        if (!is_array($image)) continue;
        $content = null;
        $mime = (string) ($image['mime_type'] ?? 'image/png');
        if (!empty($image['url'])) {
            $localPath = local_public_file_from_url((string) $image['url']);
            if ($localPath && is_file($localPath)) {
                $content = @file_get_contents($localPath);
            }
        }
        if (($content === null || $content === false || $content === '') && !empty($image['base64'])) {
            $decoded = base64_decode((string) $image['base64'], true);
            if ($decoded !== false) {
                $content = $decoded;
            }
        }
        if (!is_string($content) || $content === '') continue;
        $fmt = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/webp' ? 'webp' : 'png');
        $relativePath = api_save_binary_file($content, $fmt, 'reference');
        $publicUrl = rtrim((string) config('app.base_url', ''), '/') . $relativePath;
        $head = remote_file_head($publicUrl, 20);
        if (!($head['is_valid_image'] ?? false)) {
            throw new RuntimeException('参考图公网地址不可访问或类型错误。');
        }
        $ct = strtolower((string) (($head['content_type'] ?: $head['detected_mime'] ?? '') ?? ''));
        Logger::info('IMAGE_EDIT_REFERENCE_URL_READY', [
            'record_id' => $record['id'] ?? 0,
            'url' => $publicUrl,
            'content_type' => $ct,
        ]);
        $imageUrls[] = $publicUrl;
    }
    if (!$imageUrls) {
        throw new RuntimeException('无法生成可访问的参考图片 URL，请重试。');
    }
    return $imageUrls;
}

function remote_file_head(string $url, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);
    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);

    $contentType = strtolower(trim(explode(';', $contentType)[0] ?? ''));
    $allowed = ['image/png', 'image/jpeg', 'image/webp'];
    $byMagic = '';

    if ($httpCode === 200 && ($contentType === '' || $contentType === 'text/html')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_RANGE => '0-511',
        ]);
        $body = curl_exec($ch);
        if (is_string($body) && $body !== '') {
            $byMagic = detect_binary_media_mime_from_string($body);
        }
        $rangeErr = curl_error($ch);
        curl_close($ch);
        if ($err === '' && $rangeErr !== '') {
            $err = $rangeErr;
        }
    }

    $isImage = in_array($contentType, $allowed, true) || in_array($byMagic, $allowed, true);

    return [
        'http_code' => $httpCode,
        'content_type' => $contentType,
        'detected_mime' => $byMagic,
        'is_valid_image' => $httpCode === 200 && $isImage,
        'error' => $err,
    ];
}

/**
 * Banana async image adapter — handles both draw and edit modes.
 * draw:   POST /v1/videos { model, prompt, input_mode: "text_to_image" }
 * edit:   POST /v1/videos { model, prompt, reference_images: [...] }
 * poll:   GET  /v1/videos/{task_id}
 *
 * @param string $baseUrl
 * @param string $apiKey
 * @param array  $record  must contain: model, prompt, mode (draw|edit)
 * @param int    $timeout
 * @return array  task_id and task details
 */

/**
 * Image2 chat-image adapter — POST /v1/chat/completions with messages format.
 *
 * Payload:
 *   { "model": "gpt-image-2-2K", "messages": [{"role":"user","content":"prompt text"}] }
 *
 * No prompt-only, no reference_images, no images endpoint.
 *
 * @param string $baseUrl
 * @param string $apiKey
 * @param array  $record  must contain: model, prompt
 * @param int    $timeout
 * @return array  api_curl_post_json compatible response
 */
/**
 * 从 Image2 chat 响应中提取图片 URL 或 base64。
 * 兼容多种字段路径。
 */
function image2_extract_result(array $data): ?array
{
    // 1. choices[0].message.content — 纯文本 URL
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (is_string($content) && $content !== '') {
        // markdown 图片: ![alt](url)
        if (preg_match('/!\[.*?\]\(((data:[^;]+;base64,[^\s)]+)|(https?:\/\/[^\s)]+))\)/', $content, $m)) {
            $matched = $m[1];
            if (str_starts_with($matched, 'data:')) {
                $parts = explode(',', $matched, 2);
                return ['b64_json' => ($parts[1] ?? '')];
            }
            return ['url' => $matched];
        }
        // 纯文本 URL
        if (str_starts_with($content, 'http') || str_starts_with($content, '/')) {
            return ['url' => $content];
        }
        // 纯 base64 字符串
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $content) && strlen($content) > 100) {
            return ['b64_json' => $content];
        }
    }

    // 2. choices[0].message.image_url
    $imgUrl = $data['choices'][0]['message']['image_url'] ?? null;
    if (is_string($imgUrl) && $imgUrl !== '') {
        return ['url' => $imgUrl];
    }

    // 3. choices[0].message.images[0].url
    $imgList = $data['choices'][0]['message']['images'] ?? null;
    if (is_array($imgList) && !empty($imgList)) {
        $first = $imgList[0];
        if (is_string($first)) {
            return ['url' => $first];
        }
        if (is_array($first) && !empty($first['url'])) {
            return ['url' => $first['url']];
        }
    }

    // 4. choices[0].message.content[i].image_url.url
    $contentItems = $data['choices'][0]['message']['content'] ?? null;
    if (is_array($contentItems)) {
        foreach ($contentItems as $item) {
            if (!is_array($item)) continue;
            $url = $item['image_url']['url'] ?? $item['url'] ?? null;
            if (is_string($url) && $url !== '') {
                return ['url' => $url];
            }
            $b64 = $item['image_url']['b64_json'] ?? $item['b64_json'] ?? null;
            if (is_string($b64) && $b64 !== '') {
                return ['b64_json' => $b64];
            }
        }
    }

    // 5. choices[0].message.b64_json (direct)
    $b64 = $data['choices'][0]['message']['b64_json'] ?? null;
    if (is_string($b64) && $b64 !== '') {
        return ['b64_json' => $b64];
    }

    // 6. Top-level data/images/output/files arrays
    $arrays = [
        $data['data'] ?? null,
        $data['images'] ?? null,
        $data['output'] ?? null,
        $data['files'] ?? null,
    ];
    foreach ($arrays as $arr) {
        if (is_array($arr) && !empty($arr)) {
            $first = is_array($arr[0]) ? $arr[0] : ['url' => $arr[0]];
            $url = $first['url'] ?? $first['image_url'] ?? $first['b64_json'] ?? null;
            if (is_string($url) && $url !== '') {
                if ($url !== $first['b64_json']) {
                    return ['url' => $url];
                }
                return ['b64_json' => $url];
            }
        }
    }

    // 7. Single URL fields
    $urlFields = ['url', 'image_url', 'output_url', 'result_url'];
    foreach ($urlFields as $field) {
        $v = $data[$field] ?? null;
        if (is_string($v) && (str_starts_with($v, 'http') || str_starts_with($v, '/'))) {
            return ['url' => $v];
        }
    }

    // 8. metadata.result_urls
    $metaUrls = $data['metadata']['result_urls'] ?? $data['metadata']['urls'] ?? null;
    if (is_array($metaUrls) && !empty($metaUrls) && is_string($metaUrls[0])) {
        return ['url' => $metaUrls[0]];
    }

    return null;
}

/**
 * 从 Image2 chat 响应中提取异步任务 ID（如果有）。
 */
function image2_extract_task_id(array $data): string
{
    $candidates = [
        $data['id'] ?? null,
        $data['task_id'] ?? null,
        $data['request_id'] ?? null,
        $data['job_id'] ?? null,
        $data['data']['id'] ?? null,
        $data['data']['task_id'] ?? null,
        $data['data']['request_id'] ?? null,
        $data['id'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }
    return '';
}

/**
 * Image2 适配器：POST /v1/chat/completions，messages 格式。
 *
 * 支持三类响应：
 * A. 同步 URL / base64：直接返回
 * B. 异步 task_id：throw ImageEditTaskQueuedException 进入轮询
 * C. cURL 超时：返回 curl 错误让 decode_response 抛异常（不扣费）
 */
function call_image2_chat_image(string $baseUrl, string $apiKey, array $record, int $timeout = 60): array
{
    $model = trim((string) ($record['model'] ?? ''));
    $prompt = trim((string) ($record['prompt'] ?? ''));

    if ($model === '' || $prompt === '') {
        throw new RuntimeException('图片生成失败：Image2 适配器参数不完整（模型或提示词为空）。请联系管理员。');
    }

    $endpoint = safe_join_api_url($baseUrl, '/v1/chat/completions');
    $payload = [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ];

    Logger::info('IMAGE2_CHAT_SUBMIT', [
        'url' => $endpoint,
        'model' => $model,
        'prompt_len' => mb_strlen($prompt, 'UTF-8'),
        'timeout' => $timeout,
    ]);

    $authType = strtolower(trim((string) ($record['auth_type'] ?? 'bearer')));
    $headers = ['Content-Type: application/json'];
    if ($authType === 'x-api-key') {
        $headers[] = 'x-api-key: ' . $apiKey;
    } else {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    // cURL 超时或网络错误：返回让 decode_response 抛异常（不扣费）
    if ($curlErr !== '') {
        Logger::warning('IMAGE2_CURL_ERROR', ['error' => $curlErr, 'http_code' => $httpCode]);
        return [
            'raw' => '{"error":{"message":"Image2 接口响应超时，请稍后重试。","code":"timeout"}}',
            'http_code' => $httpCode > 0 ? $httpCode : 0,
            'content_type' => 'application/json',
            'error' => $curlErr,
        ];
    }

    $data = json_decode((string) $raw, true);

    // HTTP 错误或 API 错误
    if ($httpCode < 200 || $httpCode >= 300 || (is_array($data) && isset($data['error']))) {
        if (is_array($data) && isset($data['error'])) {
            $err = $data['error'];
            $errMsg = is_array($err) ? ($err['message'] ?? '') : (string) $err;
            if (stripos($errMsg, 'messages') !== false && stripos($errMsg, 'required') !== false) {
                throw new RuntimeException('图片生成失败：Image2 接口配置错误，当前接口要求 messages 格式。请联系管理员检查模型配置。');
            }
            throw new RuntimeException('图片生成失败：' . $errMsg);
        }

        // 不重试 504/503：Image2 /v1/chat/completions 不保证幂等，
        //盲目重试可能创建重复任务并导致双重扣费。正确流程是：failed → refund。
        throw new RuntimeException('图片生成失败：Image2 接口请求失败（HTTP ' . $httpCode . '）。请稍后重试。');
    }

    if (!is_array($data)) {
        throw new RuntimeException('图片生成失败：Image2 接口返回格式异常。');
    }

    // 检查异步 task_id
    $taskId = image2_extract_task_id($data);
    if ($taskId !== '') {
        // 有 task_id 说明是异步任务，需要轮询
        Logger::info('IMAGE2_CHAT_TASK_ID', ['task_id' => $taskId, 'http_code' => $httpCode]);

        // 检查是否有 image2_poll_endpoint 配置
        $snapshotRaw = (string) ($record['generation_config_snapshot'] ?? '');
        $snapshot = is_string($snapshotRaw) && $snapshotRaw !== '' ? @json_decode($snapshotRaw, true) : [];
        $pollEndpoint = trim((string) ($snapshot['edit_poll_endpoint'] ?? config('image2.poll_endpoint', '')));
        if ($pollEndpoint === '') {
            throw new RuntimeException('图片生成失败：Image2 异步任务已提交（ID：' . $taskId . '），但系统未配置轮询端点，无法获取结果。请联系管理员配置 Image2 轮询端点。');
        }

        // 写入 remote_task_id，进入异步轮询流程
        $recordId = (int) ($record['id'] ?? 0);
        if ($recordId > 0) {
            $pdo = db();
            $stmt = $pdo->prepare(
                "UPDATE generation_records
                 SET remote_task_id = ?, status = 'running', started_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND status = 'queued'"
            );
            $stmt->execute([$taskId, $recordId]);
        }

        // 写入 edit_task_response 供轮询使用
        $responseJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($recordId > 0) {
            $pdo2 = db();
            $stmt2 = $pdo2->prepare(
                "UPDATE generation_records SET edit_task_response = ?, last_poll_at = NOW() WHERE id = ?"
            );
            $stmt2->execute([$responseJson, $recordId]);
        }

        throw new ImageEditTaskQueuedException($taskId, $responseJson, $baseUrl, $apiKey, $recordId);
    }

    // 同步响应：提取图片 URL 或 base64
    $result = image2_extract_result($data);
    if ($result === null) {
        $keys = is_array($data) ? implode(', ', array_keys($data)) : 'N/A';
        throw new RuntimeException('图片生成失败：Image2 接口返回数据格式无法解析，未找到图片内容。返回字段：' . $keys . '。');
    }

    if (isset($result['b64_json'])) {
        Logger::info('IMAGE2_CHAT_BASE64', ['len' => strlen($result['b64_json'])]);
        return [
            'raw' => json_encode(['b64_json' => $result['b64_json']], JSON_UNESCAPED_UNICODE),
            'http_code' => $httpCode,
            'content_type' => 'application/json',
            'error' => '',
        ];
    }

    // URL
    Logger::info('IMAGE2_CHAT_URL', ['url' => $result['url']]);
    return [
        'raw' => json_encode(['url' => $result['url']], JSON_UNESCAPED_UNICODE),
        'http_code' => $httpCode,
        'content_type' => 'application/json',
        'error' => '',
    ];
}


function call_banana_async_image_submit(string $baseUrl, string $apiKey, array $record, int $timeout = 60): array
{
    $isEdit = ($record['mode'] ?? 'draw') === 'edit';
    $imageUrls = $isEdit ? image_reference_urls_from_record($record) : [];
    $submitEndpoint = trim((string) ($record['edit_endpoint'] ?? '/v1/videos')) ?: '/v1/videos';
    $model = (string) ($record['model'] ?? '');
    $prompt = (string) ($record['prompt'] ?? '');

    $payload = ['model' => $model, 'prompt' => $prompt];
    if ($isEdit) {
        $payload['reference_images'] = $imageUrls;
    } else {
        $payload['input_mode'] = 'text_to_image';
    }

    $submitUrl = safe_join_api_url($baseUrl, $submitEndpoint);
    $headers = ['Content-Type: application/json'];
    $authType = strtolower(trim((string) ($record['auth_type'] ?? 'bearer')));
    if ($authType === 'x-api-key') {
        $headers[] = 'x-api-key: ' . $apiKey;
    } else {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    Logger::info('BANANA_ASYNC_SUBMIT', [
        'url' => $submitUrl,
        'model' => $model,
        'mode' => $isEdit ? 'edit' : 'draw',
        'has_reference' => $isEdit && !empty($imageUrls),
    ]);

    $ch = curl_init($submitUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        throw new RuntimeException('Banana 异步图片生成提交失败：网络错误。');
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Banana 异步图片生成返回格式异常，无法解析任务响应。');
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = api_error_message($data, generation_response_excerpt((string) $raw));
        throw new RuntimeException('Banana 异步图片生成任务创建失败（HTTP ' . $httpCode . '）：' . $msg);
    }
    $taskId = image_edit_task_id($data);
    if ($taskId === '') {
        Logger::warning('BANANA_ASYNC_TASK_ID_MISSING', ['keys' => array_keys($data)]);
        throw new RuntimeException('Banana 异步任务已响应，但未返回任务 ID。');
    }

    $recordId = (int) ($record['id'] ?? 0);
    if ($recordId > 0) {
        $pdo = db();
        $stmt = $pdo->prepare(
            "UPDATE generation_records
             SET remote_task_id = ?, status = 'running', started_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'queued'"
        );
        $stmt->execute([$taskId, $recordId]);
    }

    return [
        'task_id' => $taskId,
        'data' => $data,
        'http_code' => $httpCode,
    ];
}



function call_newtoken_async_reference_edit_api_submit(string $baseUrl, string $apiKey, array $record, int $timeout = 60): array
{
    $imageUrls = image_reference_urls_from_record($record);
    $editImageField = trim((string) ($record['edit_image_field'] ?? 'reference_images')) ?: 'reference_images';
    $submitEndpoint = trim((string) ($record['edit_endpoint'] ?? '/v1/videos')) ?: '/v1/videos';
    $payload = [
        'model' => (string) ($record['model'] ?? ''),
        'prompt' => (string) ($record['prompt'] ?? ''),
        $editImageField => $imageUrls,
    ];
    $submitUrl = safe_join_api_url($baseUrl, $submitEndpoint);
    $headers = ['Content-Type: application/json'];
    $authType = strtolower(trim((string) ($record['auth_type'] ?? 'bearer')));
    if ($authType === 'x-api-key') {
        $headers[] = 'x-api-key: ' . $apiKey;
    } else {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    Logger::info('IMAGE_EDIT_SUBMIT', [
        'url' => $submitUrl,
        'model' => $payload['model'],
        'edit_image_field' => $editImageField,
        'reference_count' => count($imageUrls),
    ]);
    $ch = curl_init($submitUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr !== '') {
        throw new RuntimeException('上游提交任务失败，请稍后重试。');
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('上游返回格式异常，无法解析任务响应。');
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = api_error_message($data, generation_response_excerpt((string) $raw));
        throw new RuntimeException('上游任务创建失败（HTTP ' . $httpCode . '）：' . $msg);
    }
    $taskId = image_edit_task_id($data);
    if ($taskId === '') {
        Logger::warning('IMAGE_EDIT_TASK_ID_MISSING', ['record_id' => $record['id'] ?? 0, 'keys' => array_keys($data)]);
        throw new RuntimeException('上游已响应，但未返回任务 ID。');
    }
    // 保存异步任务信息到记录
    $recordId = (int) ($record['id'] ?? 0);
    if ($recordId > 0) {
        $pdo = db();
        $stmt = $pdo->prepare(
            "UPDATE generation_records
             SET remote_task_id = ?, remote_status = ?, edit_task_id = ?, edit_task_status = ?, edit_task_response = ?, status = 'running', last_poll_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status IN ('queued','running')"
        );
        $stmt->execute([$taskId, 'queued', $taskId, 'queued', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $recordId]);
    }
    throw new ImageEditTaskQueuedException($taskId, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $baseUrl, $apiKey, $recordId);
}

/**
 * 轮询图片编辑异步任务，直到完成或超时
 *
 * @param string $baseUrl   API base URL
 * @param string $apiKey    API key
 * @param string $taskId    任务 ID
 * @param int    $recordId  本地记录 ID
 * @param int    $deadline  最大允许秒数（默认 1800，即 30 分钟）
 * @param int    $httpTimeout 单次 HTTP 请求超时（默认 60）
 */
function poll_image_edit_task(string $baseUrl, string $apiKey, string $taskId, int $recordId, int $deadline = 1800, int $httpTimeout = 60): void
{
    // 优先从记录的配置快照中读取轮询配置
    $snapshot = [];
    $recStmt = db()->prepare('SELECT generation_config_snapshot FROM generation_records WHERE id = ? LIMIT 1');
    $recStmt->execute([$recordId]);
    $rec = $recStmt->fetch();
    if (is_array($rec) && !empty($rec['generation_config_snapshot'])) {
        $snapshot = @json_decode((string) $rec['generation_config_snapshot'], true) ?: [];
    }

    $editPollEndpoint = trim((string) ($snapshot['edit_poll_endpoint'] ?? config('image.edit_poll_endpoint', '/v1/videos/{task_id}')));
    $authType = strtolower(trim((string) ($snapshot['auth_type'] ?? config('image.auth_type', 'bearer'))));
    if ($baseUrl === '') {
        $baseUrl = trim((string) ($snapshot['base_url'] ?? config('image.base_url', '')));
    }
    if ($apiKey === '') {
        $apiKey = trim((string) ($snapshot['api_key'] ?? config('image.api_key', '')));
    }
    $pollUrlTemplate = safe_join_api_url($baseUrl, $editPollEndpoint);
    $pollUrl = str_replace('{task_id}', rawurlencode($taskId), $pollUrlTemplate);
    $startTime = time();
    $interval = 5;
    $headers = ['Accept: application/json'];
    if ($authType === 'x-api-key') {
        $headers[] = 'x-api-key: ' . $apiKey;
    } else {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    Logger::info('IMAGE_EDIT_POLL_START', [
        'task_id' => $taskId,
        'poll_url' => $pollUrl,
        'deadline' => $deadline,
    ]);
    while (time() - $startTime < $deadline) {
        $ch = curl_init($pollUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => min($httpTimeout, $deadline - (time() - $startTime)),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $pollRaw = curl_exec($ch);
        $pollHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $pollErr = curl_error($ch);
        curl_close($ch);
        if ($pollErr !== '' || $pollRaw === false || $pollRaw === '') {
            Logger::info('IMAGE_EDIT_POLL_NETWORK_ERROR', ['error' => $pollErr, 'elapsed' => time() - $startTime]);
            sleep($interval);
            continue;
        }
        $pollData = json_decode((string) $pollRaw, true);
        if (!is_array($pollData)) {
            Logger::info('IMAGE_EDIT_POLL_INVALID_JSON', ['elapsed' => time() - $startTime]);
            sleep($interval);
            continue;
        }
        $status = image_edit_task_status($pollData);
        $remoteStatus = $status;
        // Save full poll response so retrieve_image_edit_task_result can access all fields including url/image_url/metadata
        $responseSummary = json_encode($pollData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo = db();
        $stmt = $pdo->prepare("UPDATE generation_records SET remote_status = ?, edit_task_id = ?, edit_task_status = ?, edit_task_response = ?, last_poll_at = NOW(), updated_at = NOW() WHERE id = ?");
        $stmt->execute([$remoteStatus, $taskId, $status, $responseSummary, $recordId]);
        Logger::info('IMAGE_EDIT_POLL_STATUS', [
            'task_id' => $taskId,
            'status' => $status,
            'elapsed' => time() - $startTime,
        ]);
        if (image_edit_task_is_running($status) || $status === '') {
            sleep($interval);
            continue;
        }
        if (image_edit_task_is_failed($status)) {
            $msg = api_error_message($pollData, '上游任务执行失败');
            $stmt = $pdo->prepare("UPDATE generation_records SET status = 'failed', credits_cost = 0, error_message = ?, finished_at = NOW(), updated_at = NOW() WHERE id = ?");
            $stmt->execute(['上游任务失败：' . $msg, $recordId]);
            throw new RuntimeException('上游任务失败：' . $msg);
        }
        if (image_edit_task_is_success($status)) {
            // completed/success status reached — but /v1/videos may return completed
            // before the URL field is populated. Grace period: poll up to 3 times (15s max).
            Logger::info('IMAGE_EDIT_POLL_SUCCESS_STATUS', [
                'task_id' => $taskId,
                'status' => $status,
                'pollData_keys' => array_keys($pollData),
                'has_result_url' => has_result_url($pollData),
            ]);

            $graceCount = 0;
            $maxGrace = 3;
            $graceDelay = 5;
            $gracePollData = $pollData;

            while ($graceCount < $maxGrace) {
                if (has_result_url($gracePollData)) {
                    Logger::info('IMAGE_EDIT_POLL_GRACE_URL_FOUND', [
                        'task_id' => $taskId,
                        'grace_attempt' => $graceCount,
                    ]);
                    break;
                }
                $graceCount++;
                if ($graceCount >= $maxGrace) {
                    Logger::warning('IMAGE_EDIT_POLL_GRACE_EXHAUSTED', [
                        'task_id' => $taskId,
                        'total_grace_attempts' => $maxGrace,
                        'final_pollData_keys' => array_keys($gracePollData),
                    ]);
                    throw new RuntimeException('上游任务已完成（status=' . $status . '），等待 ' . ($maxGrace * $graceDelay) . ' 秒后仍未返回可用结果 URL。请稍后重试，或联系管理员检查模型配置。');
                }
                Logger::info('IMAGE_EDIT_POLL_GRACE_WAIT', [
                    'task_id' => $taskId,
                    'grace_attempt' => $graceCount,
                    'waiting_seconds' => $graceDelay,
                ]);
                sleep($graceDelay);

                // Re-fetch latest state
                $pollUrlGrace = str_replace('{task_id}', rawurlencode($taskId), $pollUrlTemplate);
                $ch2 = curl_init($pollUrlGrace);
                curl_setopt_array($ch2, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_HTTPHEADER => $headers,
                ]);
                $pollRaw2 = curl_exec($ch2);
                $pollHttp2 = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);

                if ($pollHttp2 === 200 && $pollRaw2 !== '') {
                    $gracePollData = json_decode((string) $pollRaw2, true) ?: $gracePollData;
                    $responseSummary3 = json_encode($gracePollData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $stmt3 = $pdo->prepare("UPDATE generation_records SET edit_task_response = ?, last_poll_at = NOW(), updated_at = NOW() WHERE id = ?");
                    $stmt3->execute([$responseSummary3, $recordId]);
                }
            }

            Logger::info('IMAGE_EDIT_POLL_COMPLETED', ['task_id' => $taskId, 'elapsed' => time() - $startTime]);
            return;
        }
        sleep($interval);
    }
    throw new RuntimeException('图片编辑任务轮询超时（' . $deadline . ' 秒），上游任务可能仍在处理中，请稍后刷新页面查看。');
}

/**
 * 获取图片编辑任务结果（从已更新的记录中读取）
 *
 * @param int $recordId
 * @return array ['data' => [...], 'http_code' => 200, 'content_type' => '...', 'error' => '']
 * @throws RuntimeException
 */
function retrieve_image_edit_task_result(int $recordId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT edit_task_response, edit_task_status FROM generation_records WHERE id = ? LIMIT 1');
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    if (!is_array($row) || ($row['edit_task_response'] ?? '') === '') {
        throw new RuntimeException('图片编辑任务结果数据不存在。');
    }
    $data = json_decode((string) $row['edit_task_response'], true);
    if (!is_array($data)) {
        throw new RuntimeException('图片编辑任务结果解析失败。');
    }
    // 返回原始 poll 响应数据，由调用方决定如何存储
    // Nano Banana (newtoken_async_reference) 返回 {task_id, status, video_url, ...}
    return $data;
}




/**
 * 存储 Nano Banana 编辑结果（视频 URL 模式）。
 * newtoken_async_reference 返回 {task_id, status, video_url, ...}
 * 其中 video_url 是可直接访问的 CDN URL。
 */
function store_nano_banana_video_result(int $recordId, array $pollData, array $record): void
{
    // Try multiple common URL field names for both video and image results
    // Priority: top-level URL fields, then nested metadata/result_urls (used by /v1/images/generations)
    $videoUrl = $pollData['url']
        ?? $pollData['image_url']
        ?? $pollData['video_url']
        ?? $pollData['result_url']
        ?? $pollData['output_url']
        ?? ($pollData['metadata']['result_urls'][0] ?? null)
        ?? ($pollData['metadata']['url'] ?? null)
        ?? ($pollData['data']['url'] ?? null)
        ?? ($pollData['data']['image_url'] ?? null)
        ?? ($pollData['data']['video_url'] ?? null)
        ?? ($pollData['image']['url'] ?? null)
        ?? null;

    if (!is_string($videoUrl) || $videoUrl === '' || !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
        $status = $pollData['status'] ?? 'unknown';
        Logger::warning('NANO_BANANA_NO_URL', [
            'record_id' => $recordId,
            'status' => $status,
            'keys' => array_keys($pollData),
            'has_metadata' => isset($pollData['metadata']),
        ]);
        throw new RuntimeException("上游任务已完成（status={$status}），但未返回可用结果 URL。可能当前模型不支持该端点或返回格式不兼容。");
    }

    $saved = save_nano_banana_video_file($videoUrl, $recordId);
    $publicUrl = (string) ($saved['path'] ?? '');
    $mime = (string) ($saved['mime'] ?? '');
    $kind = (string) ($saved['kind'] ?? 'unknown');

    $pdo = db();
    if ($kind === 'video') {
        $stmt = $pdo->prepare(
            "UPDATE generation_records
             SET status = 'succeeded', output_url = NULL, output_base64 = NULL, mime_type = NULL,
                 video_url = ?, video_mime_type = ?, remote_status = ?, edit_task_status = 'completed',
                 finished_at = NOW(), error_message = NULL, updated_at = NOW()
             WHERE id = ? AND status IN ('queued','running')"
        );
        $stmt->execute([$publicUrl, $mime, (string) ($pollData['status'] ?? 'completed'), $recordId]);
    } else {
        $stmt = $pdo->prepare(
            "UPDATE generation_records
             SET status = 'succeeded', output_url = ?, output_base64 = NULL, mime_type = ?,
                 video_url = NULL, video_mime_type = NULL, remote_status = ?, edit_task_status = 'completed',
                 finished_at = NOW(), error_message = NULL, updated_at = NOW()
             WHERE id = ? AND status IN ('queued','running')"
        );
        $stmt->execute([$publicUrl, $mime, (string) ($pollData['status'] ?? 'completed'), $recordId]);
    }
    if ($stmt->rowCount() === 0) {
        Logger::warning('NANO_BANANA_STORE_SKIPPED', [
            'record_id' => $recordId,
            'reason' => 'record not in running status (may already be failed or succeeded)',
        ]);
        return;
    }

    Logger::info('NANO_BANANA_RESULT_STORED', [
        'record_id' => $recordId,
        'source_url' => $videoUrl,
        'local_path' => $publicUrl,
        'mime' => $mime,
        'kind' => $kind,
    ]);
}

function save_nano_banana_video_file(string $videoUrl, int $recordId): ?array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 60,
            'follow_location' => true,
            'user_agent' => 'Mozilla/5.0 (compatible; ImagePlatform/1.0)',
        ],
    ]);

    $raw = @file_get_contents($videoUrl, false, $context);
    if ($raw === false || strlen((string) $raw) < 32) {
        $error = error_get_last()['message'] ?? 'download failed';
        Logger::warning('NANO_BANANA_VIDEO_DOWNLOAD_FAILED', [
            'record_id' => $recordId,
            'video_url' => $videoUrl,
            'size' => $raw === false ? 0 : strlen((string) $raw),
            'error' => $error,
        ]);
        throw new RuntimeException('上游完成，但结果下载/保存失败：下载结果文件失败。');
    }

    $headers = [];
    foreach (($http_response_header ?? []) as $line) {
        if (strpos($line, ':') !== false) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'nb-media-');
    if ($tmpFile === false) {
        throw new RuntimeException('上游完成，但结果下载/保存失败：无法创建临时文件。');
    }

    file_put_contents($tmpFile, $raw, LOCK_EX);
    try {
        $detected = detect_downloaded_media_type($tmpFile, $headers, $videoUrl);
        if (($detected['kind'] ?? 'unknown') === 'unknown' || ($detected['extension'] ?? '') === '') {
            throw new RuntimeException('上游完成，但结果下载/保存失败：无法识别结果媒体类型。');
        }

        $relativePath = save_downloaded_media_file($raw, $detected, 'generations');
        return [
            'path' => $relativePath,
            'mime' => (string) $detected['mime'],
            'kind' => (string) $detected['kind'],
        ];
    } finally {
        @unlink($tmpFile);
    }
}

/**
 * 清理错误消息中的乱码字符，防止写入日志时出现不可读内容。
 * 移除：控制字符、上游错误中的 endpoint/format 乱码片段（如 绔偣、鏍煎紡）、
 *       原始 request id、以及其他非可读字符。
 */
function sanitize_error_message_for_log(string $message): string
{
    // Step 1: 移除不可见字符（控制字符、零宽字符等）
    $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message);

    // Step 2: 移除 Unicode 替换字符 � (U+FFFD) 及其周围乱码序列
    $message = preg_replace('/�+/u', '', $message);

    // Step 3: 移除常见的 endpoint/format 乱码片段（来自旧版 call_image_generation_api 错误）
    // 这些是从 NewToken 上游错误中被错误编码的 endpoint+format 标识符
    $garbled = [
        '绔偣',
        '鏍煎紡',
        '鍥剧墖鎺ユā',
        '鍥剧墖鎺ユā鏈嶅姟閿欒',
        '绔偣/鏍煎紡',
        '绔偣1/鏍煎紡3',
        '鍥剧墖鎺ュ彛杩斿洖 HTTP',
        '鍥剧墖鎺ュ彛杩斿洖涓氬姟',
        '鍥剧墖鎺ュ彛鏃犲搷搴',
    ];
    foreach ($garbled as $g) {
        $message = str_replace($g, '', $message);
    }

    // Step 4: 移除上游 request id（格式：40+ 字符的字母数字字符串）
    $message = preg_replace('/\b[a-zA-Z0-9]{30,}\b/u', '[request_id]', $message);

    // Step 5: 移除 JSON 内的敏感字段片段（保留简要内容）
    // 移除 request_id 值
    $message = preg_replace('/"request[_\s]?id"\s*:\s*"[^"]+"/i', '[request_id]', $message);

    // Step 6: 合并多余空格
    $message = preg_replace('/\s+/', ' ', $message);
    $message = trim($message);

    return $message;
}

/**
 * 生成 API 响应的可读摘要，用于日志和错误消息。
 * 移除不可见字符和乱码片段，只保留可读文本。
 */
function generation_response_excerpt(string $raw): string
{
    $text = preg_replace('/\s+/', ' ', trim(strip_tags($raw)));
    $text = is_string($text) ? $text : trim($raw);
    if ($text === '') {
        return 'empty content';
    }
    // 移除不可见字符和乱码片段
    $text = sanitize_error_message_for_log($text);
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

        return '图片模型 "' . $model . '" 在上游 API 上存在，但当前 API Key 没有该模型组的授权。如需使用，请更换为有权限的 API Key。';

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

    $invokeMode = normalize_image_invoke_mode($record['invoke_mode'] ?? 'relay');

    $modelId = trim((string) ($record['model'] ?? ''));

    Logger::info('CALL_IMAGE_API', [
        'mode' => $mode,
        'model' => $modelId,
        'invokeMode' => $invokeMode,
        'image_adapter' => ($record['image_adapter'] ?? 'N/A'),
        'edit_adapter' => ($record['edit_adapter'] ?? 'N/A'),
    ]);

    // curl 模式：走 relay 兼容模式（不变）
    if ($invokeMode === 'curl') {
        return $mode === 'edit'
            ? call_image_edit_api_curl_mode($baseUrl, $apiKey, $record, $timeout)
            : call_image_generation_api_curl_mode($baseUrl, $apiKey, $record, $timeout);
    }

    // NewToken 异步模型统一路由（image_adapter 决定路径）
    $imageAdapter = trim((string) ($record['image_adapter'] ?? ''));

    if ($imageAdapter === 'newtoken_image2_async' || $imageAdapter === 'newtoken_banana_async') {
        // 统一 NewToken 异步图片流程：
        //   - draw 模式：纯文生图，不传参考图
        //   - edit/reference 模式：传参考图字段（GPT Image 2 用 image_urls，Nana 用 images）
        // submit → task_id → ImageEditTaskQueuedException → polling in perform_generation_record
        return call_newtoken_image_async_submit($baseUrl, $apiKey, $record, $timeout, $imageAdapter);
    }

    // 未实现的 image_adapter
    if (!empty($imageAdapter) && !in_array($imageAdapter, ['seedream_image', 'grok_image', 'banana_async_image', 'image2_chat_image'], true)) {
        throw new RuntimeException('不受支持的图片适配器：' . $imageAdapter . '。请联系管理员。');
    }

    // 遗留 adapter：banana_async_image（兼容老配置）
    if ($imageAdapter === 'banana_async_image') {
        $submitResult = call_banana_async_image_submit($baseUrl, $apiKey, $record, $timeout);
        $taskId = (string) ($submitResult['task_id'] ?? '');
        if ($taskId === '') {
            throw new RuntimeException('Banana 异步图片任务已提交，但未返回任务 ID。');
        }
        $submitJson = json_encode($submitResult['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $recordId = (int) ($record['id'] ?? 0);
        if ($recordId > 0) {
            try {
                $pdoLocal = db();
                $stmtLocal = $pdoLocal->prepare(
                    "UPDATE generation_records SET remote_task_id = ?, edit_task_id = ?, edit_task_status = ?, edit_task_response = ?, last_poll_at = NOW(), updated_at = NOW() WHERE id = ? AND status IN ('queued','running')"
                );
                $stmtLocal->execute([$taskId, $taskId, 'submitted', $submitJson, $recordId]);
            } catch (Throwable $e) {
                Logger::warning('BANANA_ASYNC_SUBMIT_SAVE_FAIL', ['record_id' => $recordId, 'error' => $e->getMessage()]);
            }
        }
        throw new ImageEditTaskQueuedException($taskId, $submitJson, $baseUrl, $apiKey, $recordId);
    }

    // 遗留 adapter：image2_chat_image（已废弃，走 /v1/chat/completions）
    if ($imageAdapter === 'image2_chat_image') {
        return call_image2_chat_image($baseUrl, $apiKey, $record, $timeout);
    }

    // 编辑模式：edit_adapter 分流（仅限非 NewToken adapter 的遗留模型）
    $images = json_decode((string) ($record['input_images_json'] ?? ''), true);
    $isEdit = $mode === 'edit' || (is_array($images) && $images);
    if ($isEdit) {
        $editAdapter = trim((string) ($record['edit_adapter'] ?? ''));
        if ($editAdapter === 'newtoken_async_reference') {
            $submitResult = call_newtoken_async_reference_edit_api_submit($baseUrl, $apiKey, $record, $timeout);
            return [
                'raw' => json_encode(['task_id' => $submitResult['task_id'], 'status' => 'submitted'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'http_code' => 200,
                'content_type' => 'application/json',
                'error' => '',
            ];
        }
        if ($editAdapter === 'nano_banana_image_urls') {
            return call_nano_banana_edit_api($baseUrl, $apiKey, $record, $timeout);
        }
        if ($editAdapter === 'openai_edits_multipart') {
            return call_image_edit_api_curl_mode($baseUrl, $apiKey, $record, $timeout);
        }
        throw new RuntimeException('当前模型未配置受支持的编辑适配器。');
    }

    // 无适配器配置：relay 兼容模式
    return call_image_generation_api($baseUrl, $apiKey, $record, $timeout);

}

/**
 * 统一 NewToken 异步图片提交。
 * 全部走 POST /v1/videos，支持纯文生图和参考图模式。
 *
 * imageAdapter 值：
 *   newtoken_image2_async  → gpt-image-2-* 系列
 *   newtoken_banana_async  → nana-banana-2 / nana-banana-pro
 *
 * @throws ImageEditTaskQueuedException
 */
function call_newtoken_image_async_submit(string $baseUrl, string $apiKey, array $record, int $timeout, string $imageAdapter): array
{
    $modelId  = trim((string) ($record['model'] ?? ''));
    $mode     = (string) ($record['mode'] ?? 'draw');
    $spec     = newtoken_model_spec($modelId);

    if ($spec === null || ($spec['kind'] ?? '') !== 'image') {
        throw new RuntimeException('不支持的图片模型：' . $modelId . '。');
    }

    // 收集参考图 URL
    $refUrls = [];
    if ($mode === 'edit' || $mode === 'reference') {
        $refUrls = image_reference_urls_from_record($record);
    }

    $payload = [
        'model' => $modelId,
        'prompt' => trim((string) ($record['prompt'] ?? '')),
    ];

    // aspect_ratio
    $aspectField = $spec['aspect_field'] ?? 'aspect_ratio';
    $size = trim((string) ($record['size'] ?? 'auto'));
    if ($size !== '' && $size !== 'auto') {
        $payload[$aspectField] = $size;
    }

    // 分辨率（Nana 系列）
    if (!empty($spec['resolution_field'])) {
        $resField = $spec['resolution_field'];
        $resolution = trim((string) ($record['resolution'] ?? ''));
        if ($resolution !== '' && in_array($resolution, ($spec['resolution_options'] ?? []), true)) {
            $payload[$resField] = $resolution;
        } else {
            // 默认 1k
            $payload[$resField] = '1k';
        }
    }

    // 参考图字段
    if ($refUrls) {
        $refField = $spec['reference_field'] ?? 'images';
        $payload[$refField] = $refUrls;
    }

    $endpoint = safe_join_api_url($baseUrl, $spec['endpoint'] ?? '/v1/videos');
    $authType = strtolower(trim((string) ($record['auth_type'] ?? 'bearer')));
    $headers  = $authType === 'x-api-key'
        ? ['Content-Type: application/json', 'x-api-key: ' . $apiKey]
        : ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        return [
            'raw' => json_encode(['error' => '网络错误：' . $curlErr], JSON_UNESCAPED_UNICODE),
            'http_code' => 0,
            'content_type' => 'application/json',
            'error' => $curlErr,
        ];
    }

    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        return [
            'raw' => $raw,
            'http_code' => $httpCode,
            'content_type' => 'application/json',
            'error' => '响应 JSON 解析失败',
        ];
    }

    // 检查 API 错误
    $apiErr = $data['error']['message'] ?? $data['error'] ?? null;
    if ($apiErr !== null) {
        $msg = is_string($apiErr) ? $apiErr : 'API 返回错误';
        return [
            'raw' => json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE),
            'http_code' => $httpCode,
            'content_type' => 'application/json',
            'error' => $msg,
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $excerpt = generation_response_excerpt($data, $httpCode);
        return [
            'raw' => $raw,
            'http_code' => $httpCode,
            'content_type' => 'application/json',
            'error' => $excerpt,
        ];
    }

    // 提取 task_id
    $taskId = image2_extract_task_id($data);
    if ($taskId !== '') {
        Logger::info('NEwTOKEN_IMAGE_ASYNC_SUBMIT', [
            'model' => $modelId,
            'adapter' => $imageAdapter,
            'mode' => $mode,
            'task_id' => $taskId,
            'http_code' => $httpCode,
        ]);
        $submitJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // 保存 task_id 到 DB，防止 cleanup_stale_running_generation_records 在此函数抛出异常之前清理此记录
        $recordId = (int) ($record['id'] ?? 0);
        if ($recordId > 0) {
            try {
                $pdoLocal = db();
                $stmtLocal = $pdoLocal->prepare(
                    "UPDATE generation_records SET remote_task_id = ?, edit_task_id = ?, edit_task_status = ?, edit_task_response = ?, last_poll_at = NOW(), updated_at = NOW() WHERE id = ? AND status IN ('queued','running')"
                );
                $stmtLocal->execute([$taskId, $taskId, 'submitted', $submitJson, $recordId]);
            } catch (Throwable $e) {
                Logger::warning('NEwTOKEN_IMAGE_ASYNC_SUBMIT_SAVE_FAIL', ['record_id' => $recordId, 'error' => $e->getMessage()]);
            }
        }
        throw new ImageEditTaskQueuedException($taskId, $submitJson, $baseUrl, $apiKey, $recordId);
    }

    // 同步返回（极少情况）：提取结果
    $result = image2_extract_result($data);
    if ($result !== null) {
        return [
            'raw' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'http_code' => 200,
            'content_type' => 'application/json',
            'error' => '',
        ];
    }

    // 无 task_id 且无结果
    $excerpt = generation_response_excerpt($data, $httpCode);
    return [
        'raw' => $raw,
        'http_code' => $httpCode,
        'content_type' => 'application/json',
        'error' => '未返回任务 ID 且无图片结果：' . $excerpt,
    ];
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

        throw new RuntimeException('编辑任务缺少参考图片。');

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

            throw new RuntimeException('创建编辑图片临时文件失败。');

        }



        $extension = image_extension_from_mime($mimeType);

        $finalPath = $tmpPath . '.' . $extension;

        if (!@rename($tmpPath, $finalPath)) {

            $finalPath = $tmpPath;

        }



        if (file_put_contents($finalPath, $binary, LOCK_EX) === false) {

            @unlink($finalPath);

            throw new RuntimeException('写入临时编辑图片文件失败。');

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



    // Format 2: messages format (chat endpoint, no response_format)
    $formats[] = [
        'model'           => $model,
        'messages'        => [['role' => 'user', 'content' => $prompt]],
        'n'               => 1,
    ];



    // 鏍煎紡3锛歱rompt 鏍煎紡锛堥€傜敤浜?/images/generations 绔偣锛?

    $p1 = ['model' => $model, 'prompt' => $prompt, 'size' => $size, 'n' => 1];

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

            $attemptLabel = "endpoint{$ei}/format{$pi}";

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

                    Logger::info('IMAGE_API_SUCCESS', ['attempt' => $attemptLabel]);

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

                    $lastError = "endpoint {$ei}/format {$pi}，重试 {$retries} 次后：" . $errDetail;

                    Logger::info('IMAGE_API_ENDPOINT_FAILED', ['url' => $url, 'error' => $lastError]);

                    break; // 璺冲嚭閲嶈瘯寰幆锛屽皾璇曚笅涓€涓鐐?鏍煎紡

                }



                // 鍏朵粬閿欒锛氭崲鏍煎紡

                $errDetail = $httpCode >= 500

                    ? 'HTTP ' . $httpCode . ': 服务器错误'

                    : 'HTTP ' . $httpCode . ': 请求失败';

                $lastError = $attemptLabel . ': ' . $errDetail;

                Logger::info('IMAGE_API_FORMAT_FAILED', ['attempt' => $attemptLabel, 'error' => $errDetail]);

                break; // 璺冲嚭閲嶈瘯寰幆锛屽皾璇曚笅涓€涓牸寮?绔偣

            }

        }

    }



    throw new RuntimeException('图片接口请求失败：' . $lastError);

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


/**
 * 编辑模式，Nano Banana 适配器。使用 /v1/images/generations + image_urls
 *
 * @param  string $baseUrl
 * @param  string $apiKey
 * @param  array  $record
 * @param  int    $timeout
 *
 * @return array
 */
function call_nano_banana_edit_api(string $baseUrl, string $apiKey, array $record, int $timeout): array
{
    $images = json_decode((string) ($record['input_images_json'] ?? ''), true);
    if (!is_array($images) || !$images) {
        throw new RuntimeException('编辑任务缺少参考图片。');
    }

    // 将参考图保存为公网 HTTPS URL
    $imageUrls = [];
    foreach ($images as $image) {
        if (!is_array($image)) continue;

        if (!empty($image['url'])) {
            $localPath = local_public_file_from_url((string) $image['url']);
            if ($localPath && is_file($localPath)) {
                $content = @file_get_contents($localPath);
                if ($content !== false && $content !== '') {
                    $mime = (string) ($image['mime_type'] ?? 'image/png');
                    $fmt  = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/webp' ? 'webp' : 'png');
                    $relativePath = api_save_binary_file($content, $fmt, 'reference');
                    $baseUrl2 = rtrim((string) config('app.base_url', ''), '/');
                    $imageUrls[] = $baseUrl2 . $relativePath;
                    continue;
                }
            }
        }

        if (!empty($image['base64'])) {
            $mime = (string) ($image['mime_type'] ?? 'image/png');
            $fmt  = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/webp' ? 'webp' : 'png');
            $content = base64_decode($image['base64'], true);
            if ($content !== false) {
                $relativePath = api_save_binary_file($content, $fmt, 'reference');
                $baseUrl2 = rtrim((string) config('app.base_url', ''), '/');
                $imageUrls[] = $baseUrl2 . $relativePath;
            }
        }
    }

    if (!$imageUrls) {
        throw new RuntimeException('无法生成可访问的参考图片 URL，请重试。');
    }

    $model  = (string) ($record['model'] ?? '');
    $prompt = (string) ($record['prompt'] ?? '');
    $sizeRaw = strtolower(trim((string) ($record['size'] ?? 'auto')));
    $size = ($sizeRaw === 'auto') ? '1024x1024' : image_size_to_pixel($sizeRaw, $model);

    $editImageField = trim((string) ($record['edit_image_field'] ?? 'image_urls')) ?: 'image_urls';
    $payload = [
        'model'  => $model,
        'prompt' => $prompt,
        $editImageField => $imageUrls,
        'n'      => 1,
        'size'   => $size,
    ];

    $editEndpoint = trim((string) ($record['edit_endpoint'] ?? '/v1/images/generations')) ?: '/v1/images/generations';
    $url = safe_join_api_url($baseUrl, $editEndpoint);

    Logger::info('IMAGE_API_NANO_BANANA_EDIT', [
        'url'          => $url,
        'model'        => $payload['model'],
        'image_urls'   => $imageUrls,
        'ref_count'    => count($imageUrls),
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new RuntimeException('Nano Banana 编辑请求失败：' . $curlErr);
    }

    $decoded = json_decode($raw, true);
    if ($httpCode >= 400 || !is_array($decoded)) {
        $msg = generation_response_excerpt((string) $raw);
        throw new RuntimeException('Nano Banana 编辑失败 (HTTP ' . $httpCode . '): ' . $msg);
    }

    return [
        'raw'         => $raw,
        'http_code'   => $httpCode,
        'content_type' => 'application/json',
        'error'       => '',
    ];
}

function call_image_edit_api(string $baseUrl, string $apiKey, array $record, int $timeout): array

{

    $images = json_decode((string) ($record['input_images_json'] ?? ''), true);

    if (!is_array($images) || !$images) {

        throw new RuntimeException('编辑任务缺少参考图片。');

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

        throw new RuntimeException('编辑任务中没有有效的参考图片。');

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

        $errMsg = $errorMap[$httpCode] ?? ('图片生成接口服务错误（HTTP ' . $httpCode . '）：' . $excerpt);



        Logger::error('API_HTTP_' . $httpCode, [

            'http_code'        => $httpCode,

            'sanitized_url'    => '(URL已隐藏)',

            'content_type'      => $contentType,

            'body_excerpt'     => $excerpt,

        ]);

        throw new RuntimeException($errMsg);

    }



    if ($raw === false || $raw === '') {

        $errMsg = $curlError ?: '图片生成接口无响应（可能是接口超时或网络不可达）';

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

        throw new RuntimeException(image_api_error_message($data, '图片生成接口返回 HTTP ' . $httpCode . ' 错误'));

    }

    // 浠呭綋 HTTP 姝ｅ父浣嗕笟鍔?code 鏄庣‘涓洪敊璇椂鎵嶆姤閿欙紙code!=0 涓?code!=200锛?

    if (isset($data['code']) && !in_array((int) $data['code'], [0, 200], true)) {

        throw new RuntimeException(image_api_error_message($data, '图片生成接口返回业务错误'));

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

 * @return array{base_url: string, api_key: string, model: string, invoke_mode: string, supports_edit: bool}

 * @throws RuntimeException 妯″瀷涓嶅彲鐢ㄦ椂

 */

function resolve_image_generation_config(array $record): array

{

    $pdo = db();

    $modelId = (int) ($record['ai_model_id'] ?? 0);



    if ($modelId > 0) {

        $stmt = $pdo->prepare('SELECT * FROM ai_models WHERE id = ? AND is_active = 1 LIMIT 1');

        $stmt->execute([$modelId]);

        $modelConfig = $stmt->fetch();

        if (!$modelConfig) {

            throw new RuntimeException('所选模型不可用，请刷新页面后重试。');

        }

        if (trim((string) ($modelConfig['api_key'] ?? '')) === '') {
            throw new RuntimeException('管理员尚未配置当前图片模型的 API Key。');
        }
        $config = [
            'base_url' => rtrim((string) $modelConfig['base_url'], '/'),
            'api_key'  => (string) $modelConfig['api_key'],
            'model'    => (string) $modelConfig['model_id'],
            'invoke_mode' => normalize_image_invoke_mode($modelConfig['invoke_mode'] ?? 'relay'),
            'supports_edit' => ((int) ($modelConfig['supports_edit'] ?? 0)) === 1,
            'edit_adapter' => trim((string) ($modelConfig['edit_adapter'] ?? '')),
            'edit_image_field' => trim((string) ($modelConfig['edit_image_field'] ?? 'reference_images')) ?: 'reference_images',
            'edit_endpoint' => trim((string) ($modelConfig['edit_endpoint'] ?? '/v1/videos')) ?: '/v1/videos',
            'edit_poll_endpoint' => trim((string) ($modelConfig['edit_poll_endpoint'] ?? '/v1/videos/{task_id}')) ?: '/v1/videos/{task_id}',
            'image_adapter' => trim((string) ($modelConfig['image_adapter'] ?? '')),
            'max_reference_images' => max(1, (int) ($modelConfig['max_reference_images'] ?? max_edit_images())),
        ];
        $record['__live_api_key'] = (string) $modelConfig['api_key'];
        return apply_generation_config_snapshot($record, $config);

    }



    $baseUrl = rtrim((string) app_setting('image_base_url', 'https://api.kbl6.cn'), '/');

    $apiKey  = (string) app_setting('image_api_key', '');

    $model   = (string) app_setting('image_model', 'gpt-image-2');

    if ($model === '') {

        $model = 'gpt-image-2';

    }



    if ($baseUrl === '' || $apiKey === '') {

        throw new RuntimeException('管理员尚未配置图片 API，请稍后重试。');

    }



    $config = [
        'base_url' => $baseUrl,
        'api_key'  => $apiKey,
        'model'    => $model,
        'invoke_mode' => 'relay',
        'supports_edit' => false,
        'edit_adapter' => 'none',
        'edit_image_field' => 'reference_images',
        'edit_endpoint' => '/v1/videos',
        'edit_poll_endpoint' => '/v1/videos/{task_id}',
        'max_reference_images' => max_edit_images(),
    ];
    $record['__live_api_key'] = $apiKey;
    return apply_generation_config_snapshot($record, $config);

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

        throw new RuntimeException(($record['mode'] ?? 'draw') === 'edit' ? '图片编辑失败：参考图未被接口识别，或当前模型不支持图片编辑。请重新上传参考图，或切换到绘画模式。' : '图片生成接口未返回有效图片数据，请稍后重试或联系管理员。');

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

        try {

            $localized = localize_remote_media_url($imageUrl, 'generations');
            if (($localized['kind'] ?? '') !== 'image') {
                throw new RuntimeException('结果 URL 未返回有效图片。');
            }
            $storedUrl = (string) ($localized['path'] ?? '');
            $mime = (string) ($localized['mime'] ?? 'image/png');

            Logger::info('REMOTE_IMAGE_CACHED_LOCALLY', [

                'record_id' => $recordId,

                'remote_url' => $imageUrl,

                'local_url' => $storedUrl,

            ]);

        } catch (Throwable $e) {

            Logger::warning('REMOTE_IMAGE_CACHE_FAILED', [

                'record_id' => $recordId,

                'remote_url' => $imageUrl,

                'error' => $e->getMessage(),

            ]);

            throw $e;

        }

    } else {

        throw new RuntimeException(
            (($record['mode'] ?? 'draw') === 'edit' ? '图片编辑失败：参考图未被接口识别，或当前模型不支持图片编辑。请重新上传参考图，或切换到绘画模式。' : '图片生成接口未返回有效图片数据，请稍后重试或联系管理员。') . ' 可用字段：' . $availableKeys . '。'
        );

    }



    $pdo = db();

    // Generate thumbnail from stored local file (non-blocking)
    $thumbUrl = null;
    if ($storedUrl !== null && $storedUrl !== '' && str_starts_with($storedUrl, '/uploads/')) {
        $localPath = local_public_file_from_url($storedUrl);
        if ($localPath !== null && is_file($localPath) && is_readable($localPath)) {
            $thumbUrl = generate_thumbnail($localPath, $mime);
            if ($thumbUrl !== null) {
                Logger::info('THUMBNAIL_GENERATED', ['record_id' => $recordId, 'thumb_url' => $thumbUrl]);
            }
        }
    }

    $stmt = $pdo->prepare(

        "UPDATE generation_records

         SET status = 'succeeded', output_base64 = ?, output_url = ?, thumb_url = ?, mime_type = ?,

             finished_at = NOW(), error_message = NULL

         WHERE id = ? AND status = 'running'"

    );

    $stmt->execute([

        $storedBase64,

        $storedUrl,
        $thumbUrl,

        $mime,

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



    // 鍥剧墖/缂栬緫妯″紡锛氳В鏋愭ā鍨嬮厤缃?

    $config = resolve_image_generation_config($record);

    if (($record['mode'] ?? 'draw') === 'edit') {
        $supportsEdit = (int)($config['supports_edit'] ?? 0) === 1;
        $editAdapter  = trim((string) ($config['edit_adapter'] ?? ''));
        $maxReferenceImages = max(1, (int) ($config['max_reference_images'] ?? max_edit_images()));
        $inputImages = json_decode((string) ($record['input_images_json'] ?? ''), true);
        $inputCount = is_array($inputImages) ? count($inputImages) : 0;
        if (!$supportsEdit) {
            throw new RuntimeException('当前模型不支持图片编辑，请选择已开启编辑能力的模型，或切换到绘画模式。');
        }
        if ($editAdapter === '' || $editAdapter === 'none') {
            throw new RuntimeException('当前模型编辑接口配置不完整，请联系管理员。');
        }
        if ($inputCount <= 0) {
            throw new RuntimeException('编辑模式必须上传至少一张参考图。');
        }
        if ($inputCount > $maxReferenceImages) {
            throw new RuntimeException('当前模型最多允许 ' . $maxReferenceImages . ' 张参考图。');
        }
    }
    $record['model'] = $config['model'];

    $record['invoke_mode'] = $config['invoke_mode'];
    $record['edit_adapter'] = $config['edit_adapter'] ?? 'none';
    $record['edit_image_field'] = $config['edit_image_field'] ?? 'image_urls';
    $record['edit_endpoint'] = $config['edit_endpoint'] ?? '/v1/videos';
    $record['auth_type'] = $config['auth_type'] ?? 'bearer';
    $record['image_adapter'] = $config['image_adapter'] ?? '';


    // 统一调用 /images/generations（draw/edit 都走这条路）
    try {

        $apiResponse = call_image_api($config['base_url'], $config['api_key'], $record, $timeout);

        $data = image_api_decode_response($apiResponse);

        $apiError = image_api_error_message($data, '');

        if ($apiError !== '') {

            throw new RuntimeException('图片接口返回错误：' . $apiError);

        }


        store_image_generation_data($recordId, $data, $record);

        return generation_record_by_id($recordId);
    } catch (ImageEditTaskQueuedException $qe) {
        // Banana async 或 Image2 async 任务已排队，开始轮询
        $imageAdapter = $config['image_adapter'] ?? '';
        Logger::info('ASYNC_POLL_START', [
            'task_id' => $qe->taskId,
            'record_id' => $qe->recordId,
            'adapter' => $imageAdapter,
            'base_url' => $qe->baseUrl,
        ]);
        try {
            poll_image_edit_task($qe->baseUrl, $qe->apiKey, $qe->taskId, $qe->recordId, $timeout);
        } catch (RuntimeException $pollEx) {
            // 轮询失败（超时或任务失败）：更新记录状态为 failed
            $pdo2 = db();
            $errMsg = $pollEx->getMessage();
            $stmtFail = $pdo2->prepare(
                "UPDATE generation_records
                 SET status = 'failed', error_message = ?, updated_at = NOW()
                 WHERE id = ? AND status IN ('queued','running')"
            );
            $stmtFail->execute([$errMsg, $qe->recordId]);
            throw $pollEx;
        }
        // 轮询完成后，根据 adapter 类型存储结果
        $pollData = retrieve_image_edit_task_result($qe->recordId);
        Logger::info('ASYNC_POLL_COMPLETE', ['record_id' => $qe->recordId, 'adapter' => $imageAdapter, 'keys' => array_keys($pollData)]);

        if ($imageAdapter === 'banana_async_image') {
            // Banana：pollData 包含 URL，使用 image/video 存储逻辑
            try {
                store_nano_banana_video_result($qe->recordId, $pollData, $record);
                return generation_record_by_id($qe->recordId);
            } catch (RuntimeException $storeEx) {
                $pdo3 = db();
                $err3 = $storeEx->getMessage();
                $stmt3 = $pdo3->prepare("UPDATE generation_records SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ? AND status = 'running'");
                $stmt3->execute([$err3, $qe->recordId]);
                throw $storeEx;
            }
        } else {
            // Image2 async：pollData 包含标准 API 格式，使用图片存储逻辑
            try {
                store_image_generation_data($qe->recordId, $pollData, $record);
                return generation_record_by_id($qe->recordId);
            } catch (RuntimeException $storeEx) {
                $pdo3 = db();
                $err3 = $storeEx->getMessage();
                $stmt3 = $pdo3->prepare("UPDATE generation_records SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ? AND status = 'running'");
                $stmt3->execute([$err3, $qe->recordId]);
                throw $storeEx;
            }
        }
    } catch (Throwable $e) {

        refund_generation_failure($pdo, $recordId, $e->getMessage(), 'RECOVERY_FAILED');

        throw $e;

    }

}



/**

 * 鐢熸垚澶辫触鏃堕€€绉垎骞舵爣璁拌褰?

 *

 * @param  PDO    $pdo      鏁版嵁搴撹繛鎺?

 * @param  int    $recordId 璁板綍 ID

 * @param  string $errorMsg 閿欒娑堟伅

 * @param  string $logKey   Logger 鏍囪瘑閿?

 */

function record_generation_refund_log(PDO $pdo, array $record, int $refundAmount, string $message): void
{
    if ($refundAmount < 0) {
        $refundAmount = 0;
    }

    $recordId = (int) ($record['id'] ?? 0);
    $userId = (int) ($record['user_id'] ?? 0);
    if ($recordId < 1 || $userId < 1) {
        return;
    }

    $check = $pdo->prepare("SELECT id FROM credit_logs WHERE ref_type = 'generation_record_refund' AND ref_id = ? LIMIT 1");
    $check->execute([(string) $recordId]);
    if ($check->fetch()) {
        return;
    }

    $balanceStmt = $pdo->prepare('SELECT credits FROM users WHERE id = ? LIMIT 1');
    $balanceStmt->execute([$userId]);
    $balanceAfter = (int) $balanceStmt->fetchColumn();
    $balanceBefore = $balanceAfter - $refundAmount;

    $insert = $pdo->prepare(
        'INSERT INTO credit_logs (user_id, amount, balance_before, balance_after, type, source, ref_type, ref_id, admin_id, reason, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NOW())'
    );
        $cleanMsg = sanitize_error_message_for_log($message);
        $insert->execute([
            $userId,
            $refundAmount,
            $balanceBefore,
            $balanceAfter,
            'refund',
            'generation_failure',
            'generation_record_refund',
            (string) $recordId,
            mb_substr('生成失败退款：' . $cleanMsg, 0, 255, 'UTF-8'),
            '127.0.0.1',
        ]);
}

function record_upstream_cost_loss(PDO $pdo, array $record, string $message): void
{
    ensure_credit_tables();

    $recordId = (int) ($record['id'] ?? 0);
    $userId = (int) ($record['user_id'] ?? 0);
    $remoteTaskId = trim((string) ($record['remote_task_id'] ?? ''));
    if ($recordId < 1 || $userId < 1 || $remoteTaskId === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO upstream_cost_logs (record_id, user_id, provider, remote_task_id, remote_status, credits_refunded, note, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE remote_status = VALUES(remote_status), credits_refunded = VALUES(credits_refunded), note = VALUES(note)'
    );
    $stmt->execute([
        $recordId,
        $userId,
        'newtoken',
        $remoteTaskId,
        (string) ($record['remote_status'] ?? ''),
        (int) ($record['credits_cost'] ?? 0),
        mb_substr($message, 0, 255, 'UTF-8'),
    ]);
}

function refund_generation_failure(PDO $pdo, int $recordId, string $errorMsg, string $logKey = 'RECOVERY_FAILED'): void

{

    try {

        $recoveryPdo = db();

        $recoveryPdo->beginTransaction();

        $latest  = generation_record_by_id($recordId);

        $charged = (int) ($latest['credits_cost'] ?? 0);

        $userId  = (int) ($latest['user_id'] ?? 0);



        if ($charged > 0 && $userId > 0) {

            $stmt = $recoveryPdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');

            $stmt->execute([$charged, $userId]);

        }



        $stmt = $recoveryPdo->prepare(

            "UPDATE generation_records

             SET status = 'failed', credits_cost = 0, error_message = ?, finished_at = NOW()

             WHERE id = ? AND status IN ('queued','running')"

        );

        $stmt->execute([$errorMsg, $recordId]);
        $latest['credits_cost'] = 0;
        $latest['remote_status'] = (string) ($latest['remote_status'] ?? '');

        record_generation_refund_log($recoveryPdo, $latest, $charged, $errorMsg);
        if (trim((string) ($latest['remote_task_id'] ?? '')) !== '') {
            record_upstream_cost_loss($recoveryPdo, $latest, $errorMsg);
        }

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

             WHERE id = ? AND status IN ('queued','running')"

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

            'SELECT id, user_id, credits_cost, status, error_message

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



                // PRESERVE existing error_message — never overwrite with generic timeout
        $existingMsg = trim((string) ($record['error_message'] ?? ''));
        if ($existingMsg !== '') {
            $pdo->commit();
            return false;
        }

        $charged = (int) $record['credits_cost'];
        $refundAmount = $charged;

        if ($charged > 0) {

            $stmt = $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');

            $stmt->execute([$charged, (int) $record['user_id']]);

        }



        $stmt = $pdo->prepare(

            "UPDATE generation_records

             SET status = 'failed', credits_cost = 0, error_message = ?, finished_at = NOW()

             WHERE id = ? AND status IN ('queued','running')"

        );

        $stmt->execute([sanitize_error_message_for_log($message), $recordId]);

        $record['credits_cost'] = 0;
        record_generation_refund_log($pdo, $record, $refundAmount, $message);
        if (trim((string) ($record['remote_task_id'] ?? '')) !== '') {
            record_upstream_cost_loss($pdo, $record, $message);
        }

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
    $defaultTimeout     = max(30, (int) config('generation.timeout', 900));
    $defaultStaleAfter = max($defaultTimeout + 120, (int) config('generation.stale_running_after', $defaultTimeout + 120));

    $asyncSafetyBuffer = 300;
    $asyncImageStaleAfter = $asyncImageTimeout + $asyncSafetyBuffer;
    $asyncVideoStaleAfter = $asyncVideoTimeout + $asyncSafetyBuffer;

    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT id, mode, ai_model_id
         FROM generation_records
         WHERE status IN ('running', 'processing')
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
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $remoteStmt = $pdo->prepare(
        "SELECT id, remote_task_id, remote_status, last_poll_at, mode, ai_model_id
         FROM generation_records
         WHERE id IN ($placeholders)"
    );
    $remoteStmt->execute($ids);
    $remoteMap = [];
    foreach ($remoteStmt->fetchAll() as $row) {
        $remoteMap[(int) $row['id']] = $row;
    }

    $adapterStmt = $pdo->prepare(
        "SELECT id, video_adapter FROM ai_models WHERE model_type = 'video' AND video_adapter IN ('newtoken_async_reference','newtoken_video_async')"
    );
    $adapterStmt->execute();
    $videoAdapterSet = [];
    foreach ($adapterStmt->fetchAll() as $row) {
        $videoAdapterSet[(int) $row['id']] = $row['video_adapter'];
    }
    $adapterStmt2 = $pdo->prepare(
        "SELECT id, edit_adapter FROM ai_models WHERE edit_adapter IN ('newtoken_async_reference','newtoken_video_async')"
    );
    $adapterStmt2->execute();
    $editAdapterSet = [];
    foreach ($adapterStmt2->fetchAll() as $row) {
        $editAdapterSet[(int) $row['id']] = $row['edit_adapter'];
    }

    $count = 0;
    foreach ($candidates as $row) {
        $id = (int) $row['id'];
        $remote = $remoteMap[$id] ?? [];
        $hasRemoteTask = !empty($remote['remote_task_id']);
        $modelId = (int) ($row['ai_model_id'] ?? 0);
        $mode = trim((string) ($row['mode'] ?? ''));
        $adapter = $videoAdapterSet[$modelId] ?? $editAdapterSet[$modelId] ?? '';
        $isAsyncTask = $hasRemoteTask && in_array($adapter, ['newtoken_async_reference', 'newtoken_video_async'], true);

        if ($isAsyncTask && !empty($remote['last_poll_at'])) {
            $lastPoll = strtotime((string) $remote['last_poll_at']);
            if ($lastPoll && (time() - $lastPoll) < 600) {
                continue;
            }
        }

        $remoteStatus = strtolower(trim((string) ($remote['remote_status'] ?? '')));
        if ($isAsyncTask && in_array($remoteStatus, ['queued', 'pending', 'processing', 'running'], true)) {
            $lastPoll = !empty($remote['last_poll_at']) ? strtotime((string) $remote['last_poll_at']) : 0;
            if ($lastPoll && (time() - $lastPoll) < 600) {
                continue;
            }
        }

        if ($isAsyncTask) {
            $staleSeconds = ($mode === 'video') ? $asyncVideoStaleAfter : $asyncImageStaleAfter;
        } else {
            $staleSeconds = $defaultStaleAfter;
        }

        $stmtCheck = $pdo->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, COALESCE(last_poll_at, started_at), NOW()) AS elapsed
             FROM generation_records
             WHERE id = ? AND status IN ('running', 'processing') AND deleted_at IS NULL"
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

        // Only write timeout message if error_message is currently empty.
        // If worker already set a real error (e.g. "当前模型不支持图片编辑"),
        // do NOT overwrite it with a generic timeout message.
        $pdoChk = db();
        $stmtChk = $pdoChk->prepare('SELECT error_message FROM generation_records WHERE id = ? LIMIT 1');
        $stmtChk->execute([$id]);
        $rowChk = $stmtChk->fetch();
        $existingErr = is_array($rowChk) ? trim((string) ($rowChk['error_message'] ?? '')) : '';
        if ($existingErr === '') {
            if ($isAsyncTask) {
                $msg = ($mode === 'video')
                    ? '视频生成等待超时，上游长时间未返回结果，余额已自动退回。请稍后重试。'
                    : '生成任务等待超时，上游长时间未返回结果，余额已自动退回。请稍后重试。';
            } else {
                $msg = '生成任务等待超时，余额已自动退回。请稍后重试。';
            }
            if (fail_generation_record_with_refund($id, $msg)) {
                $count++;
            }
        }
    }

    return $count;
}