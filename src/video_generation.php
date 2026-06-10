<?php

declare(strict_types=1);

/**
 * Exception thrown when a video generation task is queued and awaiting polling.
 */
class VideoTaskQueuedException extends Exception
{
    public string $taskId;
    public string $taskStatus;

    public function __construct(string $taskId, string $taskStatus)
    {
        parent::__construct("Video task queued: {$taskId} ({$taskStatus})");
        $this->taskId = $taskId;
        $this->taskStatus = $taskStatus;
    }
}

/**
 * 视频生成模块
 *
 * 与 image_generation.php 平级，处理视频生成相关的 API 调用与记录更新。
 * 共享的 cURL / 错误处理 / 文件存储能力在 api_client.php 中定义。
 *
 * @author Senior Developer
 */

require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/image_generation.php';

/**
 * 支持的视频格式列表
 *
 * @return string[]
 */
function video_allowed_formats(): array
{
    return ['mp4', 'webm'];
}

/**
 * 构建视频 API 请求体
 *
 * @param  array $record 生成记录
 * @return array
 */
/**
 * 生成视频 API 请求体（按 adapter 类型构建）
 *
 * newtoken_video_async: 使用 /v1/videos 接口，按 NEWTOKEN_MODEL_SPECS 的字段映射。
 * kaiyuncode/relay: 使用原有多格式探测逻辑。
 *
 * @param  array $record 生成记录
 * @return array         payload 数组
 */
function video_payload_formats(array $record): array
{
    $model       = (string) ($record['model'] ?? '');
    $prompt      = (string) ($record['prompt'] ?? '');
    $adapter     = (string) ($record['video_adapter'] ?? ($record['snapshot_video_adapter'] ?? ''));
    $duration    = max(1, (int) ($record['seconds'] ?? ($record['video_duration'] ?? 8)));
    $aspect      = trim((string) ($record['video_aspect'] ?? ($record['size'] ?? '16:9')));
    $videoSize   = trim((string) ($record['video_size'] ?? 'auto'));
    $videoMode   = trim((string) ($record['video_mode'] ?? 'text_to_video'));
    $refUrls     = $record['input_images'] ?? [];
    $refVideoUrls = $record['input_videos'] ?? [];
    $refAudioUrls = $record['input_audios'] ?? [];

    // newtoken_video_async: 使用 NEWTOKEN_MODEL_SPECS 字段映射
    if ($adapter === 'newtoken_video_async') {
        $spec = newtoken_model_spec($model);

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
        ];

        // Duration（固定值可不传，默认由 spec 定义）
        $durationField = $spec['duration_field'] ?? 'duration';
        $specDefaultDuration = $spec['default_duration'] ?? null;
        if ($duration !== $specDefaultDuration || $specDefaultDuration === null) {
            $payload[$durationField] = $duration;
        }

        // Aspect ratio（仅当非 auto 时传参）
        $aspectField = $spec['aspect_field'] ?? 'aspect_ratio';
        if ($aspect !== '' && $aspect !== 'auto') {
            $payload[$aspectField] = $aspect;
        }

        // Size field（video-pro-720p 用 image_url 作为 primary_image_field，非 video_size）
        if (!empty($spec['primary_image_field'])) {
            // video-pro-720p: primary_image_field = image_url
            // sora-2: primary_image_field = image
            $primaryField = $spec['primary_image_field'];
            // 单张主参考图：从 refUrls 取第一张
            if (!empty($refUrls)) {
                $first = is_string($refUrls[0]) ? $refUrls[0] : ($refUrls[0]['url'] ?? '');
                if ($first !== '') {
                    $payload[$primaryField] = $first;
                }
            }
        }

        // 多参考图（veo-omni-flash: Ingredients_images，注意大小写）
        if (!empty($spec['reference_field'])) {
            $refField = $spec['reference_field'];
            $refs = [];
            foreach ($refUrls as $img) {
                $url = is_string($img) ? $img : ($img['url'] ?? '');
                if ($url !== '') $refs[] = $url;
            }
            if (!empty($refs)) {
                $payload[$refField] = $refs;
            }
        }

        // 额外参考图（video-pro-720p: extra_images）
        if (!empty($spec['extra_images_field'])) {
            $extraField = $spec['extra_images_field'];
            $extraImgs = [];
            foreach (array_slice($refUrls, 1) as $img) {
                $url = is_string($img) ? $img : ($img['url'] ?? '');
                if ($url !== '') $extraImgs[] = $url;
            }
            if (!empty($extraImgs)) {
                $payload[$extraField] = $extraImgs;
            }
        }

        // 额外参考视频（video-pro-720p: extra_videos）
        if (!empty($spec['extra_videos_field'])) {
            $vidField = $spec['extra_videos_field'];
            $vids = [];
            foreach ($refVideoUrls as $v) {
                $url = is_string($v) ? $v : ($v['url'] ?? '');
                if ($url !== '') $vids[] = $url;
            }
            if (!empty($vids)) {
                $payload[$vidField] = $vids;
            }
        }

        // 额外参考音频（video-pro-720p: extra_audios）
        if (!empty($spec['extra_audios_field'])) {
            $audField = $spec['extra_audios_field'];
            $auds = [];
            foreach ($refAudioUrls as $a) {
                $url = is_string($a) ? $a : ($a['url'] ?? '');
                if ($url !== '') $auds[] = $url;
            }
            if (!empty($auds)) {
                $payload[$audField] = $auds;
            }
        }

        // 参考视频（veo-omni-flash-video-edit: video_url）
        if (!empty($spec['video_field'])) {
            $vidF = $spec['video_field'];
            $vidUrl = '';
            foreach ($refVideoUrls as $v) {
                $url = is_string($v) ? $v : ($v['url'] ?? '');
                if ($url !== '') { $vidUrl = $url; break; }
            }
            if ($vidUrl !== '') {
                $payload[$vidF] = $vidUrl;
            }
        }

        // input_mode（仅当非 text_to_video 且有该字段时）
        if ($videoMode !== 'text_to_video') {
            $inputModeField = $spec['input_mode_field'] ?? null;
            if ($inputModeField !== null) {
                $payload[$inputModeField] = $videoMode;
            }
        }

        return [$payload];
    }

    // kaiyuncode/relay: 原有多格式探测逻辑
    $formats = [];

    // 格式1：messages 格式（适用于 /v1/chat/completions）
    $formats[] = [
        'model'    => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'seconds'  => (string) $duration,
    ];

    // 格式2：messages 格式无 seconds
    $formats[] = [
        'model'    => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ];

    // 格式3：标准 prompt 格式（Sora 标准）
    $formats[] = [
        'model'   => $model,
        'prompt'  => $prompt,
        'seconds' => (string) $duration,
    ];

    // 格式4：prompt 格式无 seconds
    $formats[] = ['model' => $model, 'prompt' => $prompt];

    // 格式5：带 duration 参数（部分非 Sora 模型用 duration）
    $formats[] = [
        'model'    => $model,
        'prompt'   => $prompt,
        'duration' => $duration,
    ];

    return $formats;
}

/**
 * 调用视频生成 API（自动尝试多种请求格式）
 *
 * @param  string $baseUrl 基础地址
 * @param  string $apiKey  API Key
 * @param  array  $record  生成记录
 * @param  int    $timeout 超时秒数
 * @return array{raw: string|false, http_code: int, content_type: string, error: string}
 */
function call_video_generation_api(string $baseUrl, string $apiKey, array $record, int $timeout): array
{
    if (empty($apiKey)) {
        throw new RuntimeException('API Key 为空，请检查视频模型配置。');
    }

    $adapter = trim((string) ($record['video_adapter'] ?? ''));

    // newtoken_video_async 必须走 /v1/videos，单一 payload，无重试
    if ($adapter === 'newtoken_video_async') {
        $payloads = video_payload_formats($record);
        $payload = $payloads[0] ?? null;
        if ($payload === null) {
            throw new RuntimeException('newtoken_video_async 模型未能构建有效 payload。');
        }
        $url = api_build_url($baseUrl, 'v1/videos');
        Logger::info('视频API请求[newtoken_async]', [
            'url' => $url,
            'payload_keys' => array_keys($payload),
            'full_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        return api_curl_post_json($url, $apiKey, $payload, $timeout);
    }

    // relay / kaiyuncode: 原有逻辑，遍历多个 endpoint
    $url = api_build_url_from_setting($baseUrl, 'video_generate_path', 'v1/chat/completions');
    $payloads = video_payload_formats($record);
    $lastError = '';

    foreach ($payloads as $index => $payload) {
        Logger::info('视频API请求', [
            'url' => $url,
            'attempt' => $index + 1,
            'total' => count($payloads),
            'full_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $response = api_curl_post_json($url, $apiKey, $payload, $timeout);
        $raw      = $response['raw'];
        $httpCode = (int) $response['http_code'];

        // 成功（HTTP 2xx）直接返回
        if ($httpCode >= 200 && $httpCode < 300) {
            Logger::info('视频API成功', ['attempt' => $index + 1, 'http_code' => $httpCode]);
            return $response;
        }

        // 服务器错误（5xx）→ 重试下一个格式
        if ($httpCode >= 500) {
            $excerpt = is_string($raw) ? substr(strip_tags($raw), 0, 200) : '空响应';
            $lastError = "HTTP {$httpCode}：" . $excerpt;
            Logger::info('视频API重试', ['attempt' => $index + 1, 'reason' => $lastError]);
            continue;
        }

        // 客户端错误（4xx）→ 格式不对，继续下一种
        $lastError = is_string($raw) ? substr(strip_tags($raw), 0, 200) : "HTTP {$httpCode}";
        Logger::info('视频API重试', ['attempt' => $index + 1, 'reason' => $lastError]);
    }

    // 所有格式都失败
    throw new RuntimeException('视频接口所有请求均失败（已尝试 ' . count($payloads) . ' 种格式）：' . $lastError);
}

/**
 * 解码视频 API 响应并校验 HTTP 状态
 * 解码视频 API 响应并校验 HTTP 状态
 *
 * @param  array $apiResponse 原始 cURL 响应
 * @return array              解码后的 JSON 数据
 * @throws RuntimeException
 */
function video_api_decode_response(array $apiResponse): array
{
    $raw         = $apiResponse['raw'];
    $httpCode    = (int) $apiResponse['http_code'];
    $contentType = (string) $apiResponse['content_type'];
    $curlError   = (string) ($apiResponse['error'] ?? '');

    Logger::info('视频API响应', [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'raw_preview' => is_string($raw) ? substr($raw, 0, 500) : '非字符串',
    ]);

    if ($httpCode >= 500) {
        $excerpt = is_string($raw) ? substr(strip_tags($raw), 0, 300) : '空响应';
        $errorMap = [
            502 => '视频接口网关错误（502 Bad Gateway），上游服务可能不可达。',
            503 => '视频接口服务暂不可用（503 Service Unavailable）。请稍后重试。',
            504 => '视频接口响应超时（504 Gateway Time-out），请稍后重试或联系管理员。',
        ];
        throw new RuntimeException($errorMap[$httpCode] ?? ('视频接口服务错误（HTTP ' . $httpCode . '）：' . $excerpt));
    }

    if ($raw === false || $raw === '') {
        throw new RuntimeException($curlError ?: '视频接口无响应（可能是接口超时或网络不可达）');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $excerpt = substr(strip_tags((string) $raw), 0, 300);
        throw new RuntimeException('视频接口返回格式异常（HTTP ' . $httpCode . '），返回内容：' . $excerpt);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $apiError = api_error_message($data, '');
        if ($apiError !== '') {
            if (isset($data['code']) && $data['code'] === 'insufficient_user_quota') {
                throw new RuntimeException('AI 接口账户余额不足，请联系管理员充值。');
            }
            throw new RuntimeException('视频接口返回错误：' . $apiError);
        }
        throw new RuntimeException('视频接口返回 HTTP ' . $httpCode);
    }
    // 仅当 HTTP 正常但业务 code 明确为错误时才报错
    if (isset($data['code']) && !in_array((int) $data['code'], [0, 200], true)) {
        throw new RuntimeException('视频接口返回业务错误：' . api_error_message($data, '未知错误'));
    }

    return $data;
}

/**
 * 从视频 API 响应中提取错误消息
 *
 * @deprecated 使用 api_client.php 中的 api_error_message()
 * @see api_error_message()
 */
function video_api_error_message(array $data, string $fallback): string
{
    return api_error_message($data, $fallback);
}

/**
 * 从视频 API 响应中提取第一条数据
 *
 * @deprecated 使用 api_client.php 中的 api_first_data_item()
 * @see api_first_data_item()
 */
function video_api_first_data_item(array $data): ?array
{
    return api_first_data_item($data);
}

/**
 * 保存生成的视频文件
 *
 * @param  string $binary 视频二进制内容
 * @param  string $format 格式（mp4/webm）
 * @return string         相对路径
 * @throws RuntimeException
 */
function save_generated_video_file(string $binary, string $format): string
{
    $format = strtolower($format);
    if (!in_array($format, video_allowed_formats(), true)) {
        $format = 'mp4';
    }
    return api_save_binary_file($binary, $format, 'videos');
}

function normalize_video_invoke_mode(?string $value): string
{
    return strtolower(trim((string) $value)) === 'kaiyuncode' ? 'kaiyuncode' : 'relay';
}

function kaiyuncode_video_duration(array $record): int
{
    return max(1, min(60, (int) ($record['seconds'] ?? 8)));
}

function kaiyuncode_video_aspect_ratio(array $record): string
{
    $size = strtolower(trim((string) ($record['size'] ?? 'auto')));
    $supported = ['1:1', '4:3', '3:4', '16:9', '9:16', '21:9', '9:21'];

    return in_array($size, $supported, true) ? $size : '16:9';
}

function kaiyuncode_video_payload(array $record): array
{
    $payload = [
        'model' => (string) $record['model'],
        'prompt' => (string) $record['prompt'],
        'duration' => kaiyuncode_video_duration($record),
        'aspect_ratio' => kaiyuncode_video_aspect_ratio($record),
    ];

    $resolution = normalize_video_resolution((string) ($record['quality'] ?? 'auto'));
    if ($resolution !== 'auto' && video_resolution_is_valid($resolution)) {
        $payload['resolution'] = $resolution;
    }

    $inputImages = json_decode((string) ($record['input_images_json'] ?? ''), true);
    if (is_array($inputImages) && $inputImages !== []) {
        $firstImage = $inputImages[0];
        if (is_array($firstImage)) {
            $resolvedImage = '';
            if (!empty($firstImage['base64']) && is_string($firstImage['base64'])) {
                $mime = (string) ($firstImage['mime_type'] ?? 'image/png');
                $resolvedImage = 'data:' . $mime . ';base64,' . $firstImage['base64'];
            } elseif (!empty($firstImage['url']) && is_string($firstImage['url'])) {
                $localPath = local_public_file_from_url($firstImage['url']);
                if ($localPath && is_file($localPath)) {
                    $binary = @file_get_contents($localPath);
                    if ($binary !== false && $binary !== '') {
                        $mime = (string) ($firstImage['mime_type'] ?? 'image/png');
                        $resolvedImage = 'data:' . $mime . ';base64,' . base64_encode($binary);
                    }
                }

                if ($resolvedImage === '') {
                    $publicBaseUrl = rtrim((string) config('app.base_url', ''), '/');
                    if ($publicBaseUrl !== '' && str_starts_with($firstImage['url'], '/')) {
                        $resolvedImage = $publicBaseUrl . $firstImage['url'];
                    } else {
                        $resolvedImage = $firstImage['url'];
                    }
                }
            }

            if ($resolvedImage !== '') {
                $payload['image'] = $resolvedImage;
                $payload['image_url'] = $resolvedImage;
                $payload['input_image'] = $resolvedImage;
                $payload['first_frame_image'] = $resolvedImage;
            }
        }
    }

    return $payload;
}

function call_kaiyuncode_video_generation_api(string $baseUrl, string $apiKey, array $record, int $timeout): array
{
    if ($apiKey === '') {
        throw new RuntimeException('API Key 为空，请检查视频模型配置。');
    }

    $url = rtrim($baseUrl, '/') . '/v1/videos';
    $payload = kaiyuncode_video_payload($record);

    Logger::info('KAIYUNCODE_VIDEO_SUBMIT', [
        'url' => $url,
        'model' => (string) ($record['model'] ?? ''),
        'has_input_images' => !empty($record['input_images_json']),
        'image_field_mode' => isset($payload['image']) ? (str_starts_with((string) $payload['image'], 'data:') ? 'data-url' : 'url') : 'none',
        'image_field_preview' => isset($payload['image']) ? substr((string) $payload['image'], 0, 120) : '',
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return api_curl_post_json($url, $apiKey, $payload, $timeout);
}

/**
 * 解析视频生成模型配置
 *
 * @param  array $record 生成记录
 * @return array{base_url: string, api_key: string, model: string, invoke_mode: string}
 * @throws RuntimeException
 */
function resolve_video_generation_config(array $record): array
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
                    $stmt = db()->prepare("SELECT api_key FROM ai_models WHERE id = ? AND is_active = 1 AND model_type = 'video' LIMIT 1");
                    $stmt->execute([$snapshotModelId]);
                    $apiKey = (string) $stmt->fetchColumn();
                    if ($apiKey !== '') {
                        return [
                            'base_url' => rtrim($baseUrl, '/'),
                            'api_key'  => $apiKey,
                            'model'    => (string) $snapshot['model'],
                            'invoke_mode' => (string) ($snapshot['invoke_mode'] ?? 'relay'),
                            'supports_reference' => (int) ($snapshot['supports_reference'] ?? 0),
                            'reference_required' => (int) ($snapshot['reference_required'] ?? 0),
                            'max_reference_images' => max(1, (int) ($snapshot['max_reference_images'] ?? 1)),
                            'video_adapter' => (string) ($snapshot['video_adapter'] ?? 'none'),
                            'fixed_seconds' => max(0, (int) ($snapshot['fixed_seconds'] ?? 0)),
                            'video_resolution' => (string) ($snapshot['video_resolution'] ?? 'auto'),
                            'video_aspect_ratio' => (string) ($snapshot['video_aspect_ratio'] ?? 'auto'),
                        ];
                    }
                }
            }
        }
    }

    $pdo = db();
    $modelId = (int) ($record['ai_model_id'] ?? 0);

    if ($modelId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM ai_models WHERE id = ? AND is_active = 1 AND model_type = 'video' LIMIT 1");
        $stmt->execute([$modelId]);
        $modelConfig = $stmt->fetch();
        if (!$modelConfig) {
            throw new RuntimeException('鎵€閫夎嗛槧妯″瀷涓嶅彲鐢ㄣ€佹�楠岃法椤甸潰閲嶈瘯銆?');
        }
        return [
            'base_url' => rtrim((string) $modelConfig['base_url'], '/'),
            'api_key'  => (string) $modelConfig['api_key'],
            'model'    => (string) $modelConfig['model_id'],
            'invoke_mode' => normalize_video_invoke_mode($modelConfig['invoke_mode'] ?? 'relay'),
            'supports_reference' => (int) ($modelConfig['supports_reference'] ?? 0),
            'reference_required' => (int) ($modelConfig['reference_required'] ?? 0),
            'max_reference_images' => max(1, (int) ($modelConfig['max_reference_images'] ?? 1)),
            'video_adapter' => (string) ($modelConfig['video_adapter'] ?? 'none'),
            'fixed_seconds' => max(0, (int) ($modelConfig['fixed_seconds'] ?? 0)),
            'video_resolution' => (string) ($modelConfig['video_resolution'] ?? 'auto'),
            'video_aspect_ratio' => (string) ($modelConfig['video_aspect_ratio'] ?? 'auto'),
        ];
    }

    $baseUrl = rtrim((string) app_setting('video_base_url', ''), '/');
    $apiKey  = (string) app_setting('video_api_key', '');
    $model   = (string) app_setting('video_model', '');

    if ($baseUrl === '' || $apiKey === '') {
        throw new RuntimeException('绠\$ admin 鍚庤韩鍙浠呰剧疆鑰嗛槧鐢熸垚鎺ュ彛銆?');
    }

    return [
        'base_url' => $baseUrl,
        'api_key'  => $apiKey,
        'model'    => $model,
        'invoke_mode' => 'relay',
        'supports_reference' => 0,
        'reference_required' => 0,
        'max_reference_images' => 1,
        'video_adapter' => 'none',
        'fixed_seconds' => 0,
        'video_resolution' => 'auto',
        'video_aspect_ratio' => 'auto',
    ];
}

/**
 * 检查 API 响应是否为异步视频任务格式
 * NewAPI Sora 格式返回：{ "id": "...", "status": "...", ... }
 */
function video_task_id(array $data): string
{
    return trim((string) ($data['task_id'] ?? $data['id'] ?? ''));
}

function video_task_video_url(array $data): string
{
    $candidates = [
        $data['video_url'] ?? null,
        $data['data']['video_url'] ?? null,
        $data['video']['url'] ?? null,
        $data['output']['video_url'] ?? null,
    ];

    $item = api_first_data_item($data);
    if (is_array($item)) {
        $candidates[] = $item['url'] ?? null;
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    return '';
}

function video_api_response_is_task(array $data): bool
{
    if (!isset($data['status'])) {
        return false;
    }

    if (video_task_video_url($data) !== '') {
        return false;
    }

    return isset($data['task_id']) || (isset($data['id']) && isset($data['object']));
}

/**
 * 保存视频异步任务信息到记录（先存任务ID，等待轮询完成）
 */
function save_video_task_record(int $recordId, array $data): void
{
    $pdo = db();
    $taskId    = video_task_id($data);
    $status    = (string) ($data['status'] ?? '');
    $recordStatus = video_record_status_from_task($status);
    $stmt = $pdo->prepare(
        'UPDATE generation_records
            SET status = ?, remote_task_id = ?, remote_status = ?, video_task_id = ?, video_task_status = ?, video_task_response = ?, last_poll_at = NOW(), updated_at = NOW()
            WHERE id = ?'
    );
    $stmt->execute([$recordStatus, $taskId, $status, $taskId, $status, json_encode($data, JSON_UNESCAPED_UNICODE), $recordId]);
    Logger::info('视频异步任务已保存', [
        'record_id' => $recordId,
        'task_id' => $taskId,
        'status' => $status,
        'record_status' => $recordStatus,
    ]);
}

/**
 * 存储视频生成成功结果到记录
 *
 * NewAPI 两种格式均兼容：
 * 1. 同步格式：{ "data": [{ "url": "..." }] }
 * 2. 异步格式：{ "id": "...", "status": "succeeded", "video": {...} }
 *
 * @param  int   $recordId 记录 ID
 * @param  array $data     API 解码后的响应数据
 * @param  array $record   原始生成记录
 * @throws RuntimeException
 */
function store_video_generation_data(int $recordId, array $data, array $record): void
{
    // DEBUG
    error_log("DEBUG store_video_generation_data: video_api_response_is_task=" . (video_api_response_is_task($data) ? 'true' : 'false'));
    // 异步任务格式（含 id + status，不含 data）→ 保存任务信息，开始轮询
    if (video_api_response_is_task($data)) {
        save_video_task_record($recordId, $data);
        $taskId = video_task_id($data);
        $taskStatus = (string) ($data['status'] ?? '');
        error_log("DEBUG: throwing VideoTaskQueuedException taskId=$taskId status=$taskStatus");
        throw new VideoTaskQueuedException($taskId, $taskStatus);
    }

    // 同步格式：优先从标准 data[0] 提取
    $item = api_first_data_item($data);
    $videoUrl    = null;
    $videoBase64 = null;

    if (is_array($item)) {
        $videoUrl    = $item['url'] ?? null;
        $videoBase64 = $item['b64_json'] ?? null;
    }

    // 兼容 /v1/chat/completions 返回格式：顶层 video 字段
    if (!$videoUrl && !$videoBase64 && isset($data['video']['url'])) {
        $videoUrl = $data['video']['url'];
    }

    // 兼容 choices[0] 中携带视频信息
    if (!$videoUrl && !$videoBase64 && isset($data['choices'][0]['video']['url'])) {
        $videoUrl = $data['choices'][0]['video']['url'];
    }

    // 兼容 choices[0].message.content 中嵌入的 markdown 视频/图片
    if (!$videoUrl && !$videoBase64) {
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (is_string($content) && $content !== '') {
            // 检测错误消息：内容包含"失败"、"敏感"等关键词
            if (preg_match('/失败|敏感|不允许|拒绝|error|denied|blocked/i', $content) && !preg_match('/!\[.*?\]\(/', $content)) {
                throw new RuntimeException('视频生成失败：' . mb_substr(trim($content), 0, 300));
            }
            // markdown 图片/视频: ![alt](url)
            if (preg_match('/!\[.*?\]\(((data:[^;]+;base64,[^\s)]+)|(https?:\/\/[^\s)]+))\)/', $content, $m)) {
                $matched = $m[1];
                if (strpos($matched, 'data:') === 0) {
                    $parts = explode(',', $matched, 2);
                    $videoBase64 = $parts[1] ?? '';
                } else {
                    $videoUrl = $matched;
                }
            }
        }
    }

    $mime        = 'video/mp4';
    $storedBase64 = null;
    $storedUrl    = null;

    if (is_string($videoBase64) && $videoBase64 !== '') {
        $format = (string) ($record['output_format'] ?? 'mp4');
        if (video_storage_mode() === 'base64') {
            $storedBase64 = $videoBase64;
        } else {
            $storedUrl = save_generated_video_file($videoBase64, $format);
        }
        $mime = 'video/' . $format;
    } elseif (is_string($videoUrl) && $videoUrl !== '') {
        $storedUrl = $videoUrl;
        if (str_ends_with($videoUrl, '.webm')) {
            $mime = 'video/webm';
        }
    } else {
        Logger::info('视频API无数据', ['raw_keys' => array_keys($data), 'sample' => substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 600)]);
        throw new RuntimeException('视频接口返回中没有可用的视频数据，请确认接口配置正确。');
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "UPDATE generation_records
         SET status = 'succeeded', video_base64 = ?, video_url = ?, video_mime_type = ?,
             usage_json = ?, finished_at = NOW(), error_message = NULL, video_task_id = NULL, video_task_status = NULL
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

function store_video_generation_remote_url(int $recordId, string $videoUrl, array $data): void
{
    $path = parse_url($videoUrl, PHP_URL_PATH);
    $extension = strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));
    $mime = $extension === 'webm' ? 'video/webm' : 'video/mp4';

    $pdo = db();
    $stmt = $pdo->prepare(
        "UPDATE generation_records
         SET status = 'succeeded', video_base64 = NULL, video_url = ?, video_mime_type = ?,
             usage_json = ?, finished_at = NOW(), error_message = NULL,
             video_task_id = NULL, video_task_status = 'succeeded', video_task_response = ?
         WHERE id = ?"
    );
    $stmt->execute([
        $videoUrl,
        $mime,
        isset($data['usage']) ? json_encode($data['usage'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $recordId,
    ]);
}

function video_curl_get_json_response(string $url, string $apiKey, int $timeout): array
{
    $doRequest = function (bool $verify) use ($url, $apiKey, $timeout): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => min(30, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'raw' => $raw,
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'error' => $curlError,
        ];
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
        Logger::warning('VIDEO_SSL_VERIFY_FALLBACK', [
            'url' => $url,
            'error' => $result['error'],
        ]);
        $result = $doRequest(false);
    }

    return $result;
}

function video_task_is_completed(string $status): bool
{
    return in_array($status, ['succeeded', 'completed', 'success'], true);
}

function video_task_is_failed(string $status): bool
{
    return in_array($status, ['failed', 'error', 'cancelled'], true);
}

function video_record_status_from_task(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'queued') {
        return 'queued';
    }
    if (video_task_is_failed($status)) {
        return 'failed';
    }
    if (video_task_is_completed($status)) {
        return 'running';
    }

    return 'running';
}

function poll_kaiyuncode_video_task(string $baseUrl, string $apiKey, string $taskId, int $recordId, int $timeout = 3600): void
{
    $startTime = time();
    $interval = 5;

    while (time() - $startTime < $timeout) {
        $url = rtrim($baseUrl, '/') . '/v1/videos/' . $taskId;
        Logger::info('KAIYUNCODE_VIDEO_POLL', ['task_id' => $taskId, 'url' => $url]);

        $response = video_curl_get_json_response($url, $apiKey, min($timeout, 30));
        $raw = $response['raw'];
        $httpCode = (int) ($response['http_code'] ?? 0);
        $error = (string) ($response['error'] ?? '');

        if ($raw === false || $raw === '') {
            Logger::info('KAIYUNCODE_VIDEO_POLL_RETRY', ['task_id' => $taskId, 'reason' => $error]);
            sleep($interval);
            continue;
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            Logger::info('KAIYUNCODE_VIDEO_POLL_INVALID', ['task_id' => $taskId, 'raw' => substr((string) $raw, 0, 300)]);
            sleep($interval);
            continue;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $apiError = api_error_message($data, '视频任务查询失败');
            throw new RuntimeException($apiError . '（HTTP ' . $httpCode . '）');
        }

        save_video_task_record($recordId, $data);

        $status = strtolower((string) ($data['status'] ?? ''));
        if (video_task_is_completed($status)) {
            $videoUrl = video_task_video_url($data);
            if ($videoUrl === '') {
                throw new RuntimeException('KaiyunCode 视频任务已完成，但返回中缺少 video_url。');
            }

            store_video_generation_remote_url($recordId, $videoUrl, $data);
            return;
        }

        if (video_task_is_failed($status)) {
            $errorMessage = api_error_message($data, '视频生成任务失败');
            throw new RuntimeException('视频生成失败：' . $errorMessage);
        }

        sleep($interval);
    }

    throw new RuntimeException('视频生成等待超时，上游长时间未返回结果，余额已自动退回。请稍后重试。');
}

/**
 * 轮询视频任务状态，直到完成或超时
 * NewAPI Sora 格式：GET /v1/videos/{task_id}
 *
 * @param string $baseUrl 基础地址
 * @param string $apiKey  API Key
 * @param string $taskId  任务 ID
 * @param int    $recordId 本地记录 ID
 * @param int    $timeout  超时秒数（默认 300 秒）
 * @return void
 * @throws RuntimeException
 */
function poll_video_task(string $baseUrl, string $apiKey, string $taskId, int $recordId, int $timeout = 3600): void
{
    $startTime = time();
    $interval  = 5; // 每 5 秒轮询一次

    while (time() - $startTime < $timeout) {
        $url = rtrim($baseUrl, '/') . '/v1/videos/' . $taskId;

        Logger::info('视频任务轮询', ['task_id' => $taskId, 'url' => $url]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => ssl_verify_enabled(),
            CURLOPT_SSL_VERIFYHOST => ssl_verify_enabled() ? 2 : 0,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            Logger::info('视频任务轮询网络错误', ['error' => $error]);
            sleep($interval);
            continue;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            Logger::info('视频任务轮询响应格式异常', ['raw' => substr($raw, 0, 300)]);
            sleep($interval);
            continue;
        }

        save_video_task_record($recordId, $data);

        $status = strtolower((string) ($data['status'] ?? ''));
        Logger::info('视频任务轮询状态', ['task_id' => $taskId, 'status' => $status, 'progress' => $data['progress'] ?? null]);

        if (video_task_is_completed($status)) {
            // 任务完成，下载视频
            download_video_task_result($baseUrl, $apiKey, $taskId, $recordId, $data);
            return;
        }

        if (video_task_is_failed($status)) {
            $errorMsg = $data['error']['message'] ?? ($data['error'] ?? '视频生成任务失败');
            throw new RuntimeException('视频生成失败：' . $errorMsg);
        }

        // 任务仍在进行中，继续轮询
        sleep($interval);
    }

    throw new RuntimeException('视频生成等待超时，上游长时间未返回结果，余额已自动退回。请稍后重试。');
}

/**
 * 下载已完成的视频任务结果
 * NewAPI Sora 格式：GET /v1/videos/{task_id}/content
 */
function download_video_task_result(string $baseUrl, string $apiKey, string $taskId, int $recordId, array $taskData): void
{
    // 优先使用 poll 响应中已有的 video_url（newtoken.club 直接返回 CDN URL）
    $videoUrl = $taskData['video_url'] ?? $taskData['url'] ?? '';
    if (is_string($videoUrl) && $videoUrl !== '' && filter_var($videoUrl, FILTER_VALIDATE_URL)) {
        Logger::info('下载视频任务结果（使用响应URL）', ['task_id' => $taskId, 'url' => $videoUrl]);

        $ch = curl_init($videoUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => ssl_verify_enabled(),
            CURLOPT_SSL_VERIFYHOST => ssl_verify_enabled() ? 2 : 0,
            CURLOPT_USERAGENT => 'AI-Image-Generator/1.0',
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if (($raw === false || $raw === '') && $error !== '') {
            Logger::info('响应URL下载失败，尝试/content端点', ['error' => $error]);
        } elseif ($httpCode === 200 && $raw !== false && $raw !== '') {
            $tmpFile = tempnam(sys_get_temp_dir(), 'video-media-');
            if ($tmpFile === false) {
                throw new RuntimeException('上游完成，但结果下载/保存失败：无法创建临时文件。');
            }
            file_put_contents($tmpFile, $raw, LOCK_EX);
            try {
                $detected = detect_downloaded_media_type($tmpFile, ['content-type' => $contentType], $videoUrl);
                if (($detected['kind'] ?? '') !== 'video' || ($detected['extension'] ?? '') === '') {
                    throw new RuntimeException('上游完成，但结果下载/保存失败：结果不是有效视频。');
                }
                $relativePath = save_downloaded_media_file($raw, $detected, 'generations');
            } finally {
                @unlink($tmpFile);
            }
            $mime = (string) $detected['mime'];
            $pdo = db();
            $stmt = $pdo->prepare(
                "UPDATE generation_records
                 SET status = 'succeeded', video_url = ?, video_mime_type = ?, remote_status = ?,
                     video_task_response = ?, finished_at = NOW(), error_message = NULL
                 WHERE id = ?"
            );
            $stmt->execute([$relativePath, $mime, (string) ($taskData['status'] ?? 'completed'), json_encode($taskData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $recordId]);
            Logger::info('视频任务结果已保存（响应URL）', ['record_id' => $recordId, 'path' => $relativePath, 'mime' => $mime]);
            return;
        } else {
            Logger::info('响应URL下载失败，尝试/content端点', ['http_code' => $httpCode, 'content_length' => strlen((string) $raw)]);
        }
    }

    // 兜底：使用 /v1/videos/{taskId}/content 端点
    $url = rtrim($baseUrl, '/') . '/v1/videos/' . $taskId . '/content';
    Logger::info('下载视频任务结果（/content端点）', ['task_id' => $taskId, 'url' => $url]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => ssl_verify_enabled(),
        CURLOPT_SSL_VERIFYHOST => ssl_verify_enabled() ? 2 : 0,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        throw new RuntimeException('下载视频失败：' . $error);
    }

    if ($httpCode !== 200) {
        throw new RuntimeException('下载视频失败，HTTP ' . $httpCode . '：' . substr(strip_tags((string) $raw), 0, 200));
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'video-media-');
    if ($tmpFile === false) {
        throw new RuntimeException('上游完成，但结果下载/保存失败：无法创建临时文件。');
    }
    file_put_contents($tmpFile, $raw, LOCK_EX);
    try {
        $detected = detect_downloaded_media_type($tmpFile, ['content-type' => $contentType], $url);
        if (($detected['kind'] ?? '') !== 'video' || ($detected['extension'] ?? '') === '') {
            throw new RuntimeException('上游完成，但结果下载/保存失败：结果不是有效视频。');
        }
        $relativePath = save_downloaded_media_file($raw, $detected, 'generations');
    } finally {
        @unlink($tmpFile);
    }

    // 更新数据库记录
    $pdo = db();
    $stmt = $pdo->prepare(
        "UPDATE generation_records
            SET status = 'succeeded', video_url = ?, video_mime_type = ?, video_base64 = NULL,
                remote_status = ?, video_task_id = NULL, video_task_status = 'succeeded', video_task_response = ?, finished_at = NOW(), error_message = NULL
            WHERE id = ?"
    );
    $stmt->execute([
        $relativePath,
        (string) $detected['mime'],
        (string) ($taskData['status'] ?? 'completed'),
        json_encode($taskData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $recordId,
    ]);

    Logger::info('视频任务结果已保存', ['record_id' => $recordId, 'path' => $relativePath, 'mime' => $detected['mime']]);
}
function perform_video_generation_record(int $recordId, ?int $timeout = null): array
{
    ensure_generation_records_video_columns();

    $pdo     = db();
    $record  = generation_record_by_id($recordId);
    $timeout = $timeout ?? max(60, (int) config('generation.timeout', 600));

    $config = resolve_video_generation_config($record);
    $config = apply_generation_config_snapshot($record, $config);
    $record['model'] = $config['model'];
    $record['invoke_mode'] = $config['invoke_mode'];
    $record['video_adapter'] = $config['video_adapter'] ?? '';

    if (!empty($config['fixed_seconds'])) {
        $record['seconds'] = $config['fixed_seconds'];
    }
    if (!empty($config['video_resolution']) && $config['video_resolution'] !== 'auto') {
        $record['quality'] = $config['video_resolution'];
    }
    if (!empty($config['video_aspect_ratio']) && $config['video_aspect_ratio'] !== 'auto') {
        $record['size'] = $config['video_aspect_ratio'];
    }

    try {
        if ($config['invoke_mode'] === 'kaiyuncode') {
            $apiResponse = call_kaiyuncode_video_generation_api($config['base_url'], $config['api_key'], $record, $timeout);
            $data = video_api_decode_response($apiResponse);

            save_video_task_record($recordId, $data);

            $taskId = video_task_id($data);
            if ($taskId === '') {
                throw new RuntimeException('KaiyunCode 视频接口未返回 task_id。');
            }

            poll_kaiyuncode_video_task($config['base_url'], $config['api_key'], $taskId, $recordId, $timeout);
        } else {
            $apiResponse = call_video_generation_api($config['base_url'], $config['api_key'], $record, $timeout);
            $data = video_api_decode_response($apiResponse);

            $apiError = api_error_message($data, '');
            if ($apiError !== '') {
                throw new RuntimeException('视频接口返回错误：' . $apiError);
            }

            try {
                store_video_generation_data($recordId, $data, $record);
            } catch (VideoTaskQueuedException $qe) {
                poll_video_task($config['base_url'], $config['api_key'], $qe->taskId, $recordId, $timeout);
                return generation_record_by_id($recordId);
            } catch (Throwable $e) {
                throw $e;
            }

            return generation_record_by_id($recordId);
        }
    } catch (VideoTaskQueuedException $qe) {
        throw $qe;
    } catch (Throwable $e) {
        refund_generation_failure($pdo, $recordId, $e->getMessage(), 'VIDEO_RECOVERY_FAILED');
        throw $e;
    }

    return generation_record_by_id($recordId);
}
