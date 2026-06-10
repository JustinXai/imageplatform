<?php

declare(strict_types=1);

/**
 * 共享 API 客户端工具
 *
 * 提供 cURL 请求、响应解析、文件存储等通用能力，
 * 从 image_generation.php 和 video_generation.php 中提取重复代码。
 *
 * @author Senior Developer
 */

// ============================================================================
// cURL 客户端
// ============================================================================

/**
 * 执行 cURL POST JSON 请求
 *
 * @param  string $url     完整请求地址
 * @param  string $apiKey  Bearer Token
 * @param  array  $payload JSON 请求体（自动编码）
 * @param  int    $timeout 超时秒数
 * @return array{raw: string|false, http_code: int, content_type: string, error: string}
 */
function api_curl_post_json(string $url, string $apiKey, array $payload, int $timeout): array
{
    $doRequest = function (bool $verify) use ($url, $apiKey, $payload, $timeout): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            'raw'          => $raw,
            'http_code'    => $httpCode,
            'content_type' => $contentType,
            'error'        => $curlError,
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
        Logger::warning('API_SSL_VERIFY_FALLBACK', [
            'url' => $url,
            'error' => $result['error'],
        ]);
        $result = $doRequest(false);
    }

    return $result;
}

/**
 * 从 API 响应中提取错误消息
 *
 * 遍历常见错误字段名，返回第一个非空字符串。
 *
 * @param  array  $data     解码后的 JSON 响应
 * @param  string $fallback 兜底消息
 * @return string
 */
function api_error_message(array $data, string $fallback): string
{
    $candidates = [
        $data['error']['message'] ?? null,
        $data['data']['error']['message'] ?? null,
        $data['data']['message'] ?? null,
        $data['message'] ?? null,
        $data['error'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return $candidate;
        }
    }
    return $fallback;
}

/**
 * 解码 API 响应 JSON
 *
 * @param  string $raw
 * @return ?array
 */
function api_response_json(string $raw): ?array
{
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * 提取 API 错误码
 *
 * @param  array $data
 * @return string
 */
function api_error_code(array $data): string
{
    $candidates = [
        $data['error']['code'] ?? null,
        $data['data']['error']['code'] ?? null,
        $data['error_code'] ?? null,
        $data['code'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
        if (is_int($candidate) || is_float($candidate)) {
            return (string) $candidate;
        }
    }

    return '';
}

/**
 * 从 API 响应中提取第一条数据
 *
 * 兼容 OpenAI 标准格式 { data: [ { url: ..., b64_json: ... } ] }、
 * 字符串数组 { data: [ "url1" ] }、{ images: [...] }、{ output: [...] } 等格式。
 *
 * @param  array      $data 解码后的 JSON 响应
 * @return array|null       数据数组，或 null
 */
function api_first_data_item(array $data): ?array
{
    // 标准格式: { "data": [ { "url": "...", "b64_json": "..." } ] }
    if (isset($data['data'][0]) && is_array($data['data'][0])) {
        return $data['data'][0];
    }
    // 字符串数组: { "data": [ "url1", "url2" ] }
    if (isset($data['data'][0]) && is_string($data['data'][0])) {
        return ['url' => $data['data'][0]];
    }
    // 嵌套格式: { "data": { "data": [ { "url": "...", "b64_json": "..." } ] } }
    // 某些 NewAPI 搭建站用此格式
    if (isset($data['data']['data'][0]) && is_array($data['data']['data'][0])) {
        return $data['data']['data'][0];
    }
    // 关联数组: { "data": { "url": "...", "b64_json": "..." } }
    if (isset($data['data']) && is_array($data['data'])) {
        $keys = array_keys($data['data']);
        if ($keys !== range(0, count($data['data']) - 1)) {
            // 检查是否还有标准 data[0] 子结构
            if (isset($data['data']['data'][0]) && is_array($data['data']['data'][0])) {
                return $data['data']['data'][0];
            }
            return $data['data'];
        }
    }
    // 其他平台兼容
    if (isset($data['images'][0]) && is_array($data['images'][0])) {
        return $data['images'][0];
    }
    if (isset($data['output'][0]) && is_string($data['output'][0])) {
        return ['b64_json' => $data['output'][0]];
    }
    // NewToken /v1/videos 响应格式: metadata.result_urls[0]
    if (!empty($data['metadata']['result_urls'][0])) {
        return ['url' => (string) $data['metadata']['result_urls'][0]];
    }
    // NewToken /v1/videos 响应格式: top-level url / image_url
    if (!empty($data['url']) && is_string($data['url'])) {
        return ['url' => $data['url']];
    }
    if (!empty($data['image_url']) && is_string($data['image_url'])) {
        return ['url' => $data['image_url']];
    }
    return null;
}

// ============================================================================
// 文件存储
// ============================================================================

/**
 * 支持的图片格式列表
 *
 * @return string[]
 */
function api_allowed_image_formats(): array
{
    return ['png', 'jpeg', 'webp'];
}

/**
 * 支持的视频格式列表
 *
 * @return string[]
 */
function api_allowed_video_formats(): array
{
    return ['mp4', 'webm'];
}

/**
 * 保存二进制文件到存储目录
 *
 * @param  string $binary   文件二进制内容
 * @param  string $format   扩展名（png/jpeg/webp/mp4/webm）
 * @param  string $bucket   子目录（generations / input-images / videos）
 * @return string           相对路径（如 /uploads/generations/202605/xxx.png）
 * @throws RuntimeException
 */
function api_save_binary_file(string $binary, string $format, string $bucket): string
{
    if ($binary === '') {
        throw new RuntimeException('文件内容为空。');
    }

    $format = strtolower($format);
    if ($format === 'jpg') {
        $format = 'jpeg';
    }

    $allFormats = array_merge(api_allowed_image_formats(), api_allowed_video_formats());
    if (!in_array($format, $allFormats, true)) {
        $format = 'png';
    }

    $extension = $format === 'jpeg' ? 'jpg' : $format;
    $bucket    = preg_replace('/[^a-z0-9-]/', '', strtolower($bucket));
    $bucket    = $bucket ?: 'files';
    $relativeDir = '/uploads/' . $bucket . '/' . date('Ym');
    $targetDir   = ROOT_PATH . '/public' . $relativeDir;

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('创建保存目录失败：' . $targetDir);
    }

    $filename   = date('YmdHis') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $filename;

    if (file_put_contents($targetPath, $binary, LOCK_EX) === false) {
        throw new RuntimeException('写入文件失败：' . $targetPath);
    }

    return $relativeDir . '/' . $filename;
}

/**
 * Generate a thumbnail from a local image file and save it.
 *
 * Falls back gracefully if GD is unavailable or thumbnail creation fails.
 * Does NOT throw — caller should continue with original file if thumbnail fails.
 *
 * @param  string $originalPath  Local filesystem path to the original image.
 * @param  string $mime         Detected MIME type (image/jpeg, image/png, image/webp).
 * @return string|null         Relative URL path to the thumbnail, or null on failure.
 */
function generate_thumbnail(string $originalPath, string $mime): ?string
{
    if (!is_file($originalPath) || !is_readable($originalPath)) {
        return null;
    }

    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    $targetWidth = 480;
    $targetHeight = 480;

    $src = null;
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $src = @imagecreatefromjpeg($originalPath);
            break;
        case 'image/png':
            $src = @imagecreatefrompng($originalPath);
            break;
        case 'image/webp':
            $src = @imagecreatefromwebp($originalPath);
            break;
    }

    if ($src === false || $src === null) {
        return null;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= 0 || $srcH <= 0) {
        imagedestroy($src);
        return null;
    }

    // Square crop: take the smaller dimension as the crop size
    $cropSize = min($srcW, $srcH);
    $srcX = (int) (($srcW - $cropSize) / 2);
    $srcY = (int) (($srcH - $cropSize) / 2);

    $thumb = @imagecreatetruecolor($targetWidth, $targetHeight);
    if ($thumb === false) {
        imagedestroy($src);
        return null;
    }

    // Fill with white background (transparency for PNG)
    imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
    imagecopyresampled(
        $thumb, $src,
        0, 0, $srcX, $srcY,
        $targetWidth, $targetHeight,
        $cropSize, $cropSize
    );

    $thumbDir = dirname($originalPath);
    $thumbFilename = 'thumb_' . basename($originalPath);
    // Preserve format: PNG > JPEG for alpha; JPEG > PNG for size
    $usePng = ($mime === 'image/png' || $mime === 'image/webp');
    $thumbPath = $thumbDir . '/' . $thumbFilename;
    $saved = false;
    if ($usePng) {
        $saved = @imagepng($thumb, $thumbPath, 8);
        if (!$saved) {
            $thumbFilename = preg_replace('/\.\w+$/', '.jpg', $thumbFilename);
            $thumbPath = $thumbDir . '/' . $thumbFilename;
            $saved = @imagejpeg($thumb, $thumbPath, 80);
        }
    } else {
        $saved = @imagejpeg($thumb, $thumbPath, 82);
        if (!$saved) {
            $thumbFilename = preg_replace('/\.\w+$/', '.png', $thumbFilename);
            $thumbPath = $thumbDir . '/' . $thumbFilename;
            $saved = @imagepng($thumb, $thumbPath, 8);
        }
    }

    imagedestroy($src);
    imagedestroy($thumb);

    if (!$saved || !is_file($thumbPath)) {
        return null;
    }

    // Return relative path from public/
    $publicDir = realpath(dirname($originalPath) . '/../..');
    if ($publicDir !== false && str_starts_with($thumbPath, $publicDir)) {
        return '/uploads/' . substr($thumbPath, strlen($publicDir) + 1);
    }
    return null;
}

/**
 * 构建 API URL（避免 /v1/v1/ 重复路径问题）
 *
 * @param  string $baseUrl 基础地址（可能带 /v1）
 * @param  string $path    路径（可能以 / 开头）
 * @return string          完整 URL
 */
function api_build_url(string $baseUrl, string $path): string
{
    // 移除 baseUrl 末尾的 /v1，避免与 path 中的 v1 重复
    $baseUrl = rtrim($baseUrl, '/');
    if (substr($baseUrl, -3) === '/v1') {
        $baseUrl = substr($baseUrl, 0, -3);
    }
    return $baseUrl . '/' . ltrim($path, '/');
}

/**
 * 快速生成 API URL：默认路径模板
 *
 * @param  string $baseUrl      基础地址
 * @param  string $settingKey   配置键（如 image_generate_path）
 * @param  string $defaultPath  默认路径（如 images/generations）
 * @return string
 */
function api_build_url_from_setting(string $baseUrl, string $settingKey, string $defaultPath): string
{
    $path = trim((string) app_setting($settingKey, ''));
    if ($path === '') {
        $path = $defaultPath;
    }
    return api_build_url($baseUrl, $path);
}

// ============================================================================
// OpenAI 兼容响应工具
// ============================================================================

/**
 * 发送 OpenAI 格式的错误响应并退出
 *
 * @param  string $message 错误描述
 * @param  string $type    错误类型
 * @param  int    $status  HTTP 状态码
 */
function api_openai_error(string $message, string $type = 'invalid_request_error', int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'message' => $message,
            'type'    => $type,
            'code'    => $status,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 发送 OpenAI 格式的成功响应
 *
 * @param  array $payload 响应体
 * @param  int   $status  HTTP 状态码
 */
function api_openai_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ============================================================================
// URL / File path helpers
// ============================================================================

/**
 * Check if a URL is a remote (HTTP/HTTPS) URL.
 *
 * @param  string $url
 * @return bool
 */
function is_remote_url(string $url): bool
{
    $url = trim($url);
    return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
}

/**
 * Build an API URL by joining base URL and path, avoiding duplicate path segments.
 *
 * Examples:
 *   safe_join_api_url('https://api.example.com/v1', '/v1/images/generations')
 *   → 'https://api.example.com/v1/images/generations'
 *
 *   safe_join_api_url('https://api.example.com', '/v1/images/generations')
 *   → 'https://api.example.com/v1/images/generations'
 *
 * @param  string $baseUrl
 * @param  string $path
 * @return string
 */
function safe_join_api_url(string $baseUrl, string $path): string
{
    $baseUrl = rtrim(trim($baseUrl), '/');
    $path = ltrim(trim($path), '/');
    if ($baseUrl === '') {
        return '/' . $path;
    }
    // Avoid /v1/v1/ duplication when baseUrl ends with /v1 and path starts with /v1/
    $baseSegments = explode('/', $baseUrl);
    $pathSegments = explode('/', $path);
    // If the last segment of baseUrl equals the first segment of path, skip it
    if (!empty($baseSegments) && !empty($pathSegments) && end($baseSegments) === $pathSegments[0]) {
        array_shift($pathSegments);
    }
    return $baseUrl . '/' . implode('/', $pathSegments);
}

/**
 * Convert a public-relative URL path to a local filesystem path.
 *
 * Examples:
 *   local_public_file_from_url('/uploads/generations/202605/abc.png')
 *   → '/path/to/project/public/uploads/generations/202605/abc.png'
 *
 *   local_public_file_from_url('https://example.com/uploads/reference/abc.png')
 *   → '/path/to/project/public/uploads/reference/abc.png'
 *   (if the domain matches the app's base_url, it's treated as a local file)
 *
 * Handles:
 *   - Absolute paths starting with /
 *   - URLs with the app base_url prepended
 *   - Full HTTPS URLs whose host matches the app's base_url (treated as local)
 *   - Returns null for truly remote URLs (different domain)
 *
 * @param  string $url  A public-relative path or full URL
 * @return string|null  Local filesystem path, or null if not resolvable
 */
function local_public_file_from_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    // Determine if this URL is local (same origin as the app) or remote
    $baseUrl = rtrim(trim((string) config('app.base_url', '')), '/');
    $isLocalUrl = false;

    if (is_remote_url($url)) {
        // If it's a remote URL, check if the host matches the app's base_url
        if ($baseUrl !== '') {
            $parsed = parse_url($url);
            $appParsed = parse_url($baseUrl);
            $urlHost = strtolower($parsed['host'] ?? '');
            $appHost = strtolower($appParsed['host'] ?? '');
            if ($urlHost !== '' && $urlHost === $appHost) {
                $isLocalUrl = true;
            }
        }
        if (!$isLocalUrl) {
            return null; // Truly remote URL — not a local file
        }
        // It's a full URL with matching host — strip the origin
        if ($baseUrl !== '' && str_starts_with($url, $baseUrl)) {
            $url = substr($url, strlen($baseUrl));
        }
    }

    // Remove leading slash for path operations
    $url = ltrim($url, '/');

    // Must start with 'public/' or 'uploads/' to be within the public directory
    if (!str_starts_with($url, 'public/') && !str_starts_with($url, 'uploads/')) {
        return null;
    }

    // Normalise uploads/* → public/uploads/*
    if (str_starts_with($url, 'uploads/')) {
        $url = 'public/' . $url;
    }

    $localPath = ROOT_PATH . '/' . $url;

    // Security: ensure the resolved path is actually within ROOT_PATH
    $realPath = realpath($localPath);
    if ($realPath === false) {
        // File doesn't exist yet — check the directory exists
        $dir = dirname($localPath);
        if (is_dir($dir)) {
            return $localPath;
        }
        return null;
    }

    // Ensure real path is under ROOT_PATH/public
    $publicDir = realpath(ROOT_PATH . '/public');
    if ($publicDir !== false && str_starts_with($realPath, $publicDir . '/')) {
        return $realPath;
    }

    return null;
}

/**
 * Save a reference/input image and return the public-relative URL path.
 *
 * Used by generation_uploaded_images_from_files() to store uploaded reference
 * images before they are written to the database as input_images_json.
 *
 * @param  string $content  Binary image content
 * @param  string $mime     MIME type (image/png, image/jpeg, image/webp)
 * @return string           Public-relative URL path (e.g. /uploads/reference/202606/xxx.png)
 * @throws RuntimeException
 */
function save_input_image_file(string $content, string $mime): string
{
    $mime = trim(strtolower($mime));
    $formatMap = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/webp' => 'webp',
    ];
    $format = $formatMap[$mime] ?? 'png';
    return api_save_binary_file($content, $format, 'reference');
}

/**
 * GET a JSON URL with Bearer auth. Returns ['data' => string, 'http_code' => int, 'error' => string].
 *
 * @param string $url
 * @param string $apiKey
 * @param int    $timeout
 * @return array
 */
function http_get_json(string $url, string $apiKey, int $timeout = 30): array
{
    $ch = curl_init($url);
    $authType = strtolower(trim((string) (defined('AUTH_TYPE') ? AUTH_TYPE : 'bearer')));
    $headers = [];
    if ($authType === 'x-api-key') {
        $headers[] = 'x-api-key: ' . $apiKey;
    } else {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $data = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    return [
        'data' => $data,
        'http_code' => $httpCode,
        'error' => $curlErr,
    ];
}

/**
 * Check whether poll data contains a usable result URL (image or video).
 * Tries the same field set as store_nano_banana_video_result().
 *
 * @param array $pollData
 * @return bool
 */
function has_result_url(array $pollData): bool
{
    $url = $pollData['url']
        ?? $pollData['image_url']
        ?? $pollData['video_url']
        ?? $pollData['result_url']
        ?? $pollData['output_url']
        ?? ($pollData['metadata']['result_urls'][0] ?? null)
        ?? ($pollData['metadata']['urls'][0] ?? null)
        ?? ($pollData['metadata']['url'] ?? null)
        ?? ($pollData['data']['url'] ?? null)
        ?? ($pollData['data']['image_url'] ?? null)
        ?? ($pollData['data']['video_url'] ?? null)
        ?? ($pollData['image']['url'] ?? null)
        ?? null;
    return is_string($url) && $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * 判断轮询结果是否为图片（而非视频）。
 */
function is_image_result(array $data): bool
{
    $mime = $data['mime_type'] ?? $data['content_type'] ?? $data['file_type'] ?? null;
    if (is_string($mime) && str_starts_with($mime, 'image/')) {
        return true;
    }
    if (is_string($mime) && str_starts_with($mime, 'video/')) {
        return false;
    }
    foreach (['url', 'image_url', 'output_url', 'video_url', 'result_url'] as $field) {
        $v = $data[$field] ?? null;
        if (is_string($v)) {
            if (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)\?/i', $v)) return true;
            if (preg_match('/\.(mp4|mov|avi|mkv|webm)\?/i', $v)) return false;
        }
    }
    return true;
}
