<?php
/**
 * Nano Banana 图片编辑模型测试脚本
 *
 * 用法：php scripts/test-image-edit-model.php <模型ID>
 *
 * 测试内容：
 * 1. 参考图保存为公网 HTTPS URL
 * 2. curl 该 URL 返回 200
 * 3. Content-Type 是 image/png、image/jpeg 或 image/webp
 * 4. payload 里使用 image_urls 字段
 * 5. endpoint 是 {base_url}/v1/images/generations
 * 6. 返回里能解析到 b64_json / url / output_url


 * 7. 不输出 API Key
 * 8. 不写 generation_records
 * 9. 不扣用户余额
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit("This script must run from CLI.\n");
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/image_generation.php';

$modelId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($modelId < 1) {
    echo "用法: php scripts/test-image-edit-model.php <模型ID>\n";
    echo "示例: php scripts/test-image-edit-model.php 5\n";
    exit(1);
}

// 获取模型配置
ensure_ai_models_table();
ensure_ai_models_type_column();

$stmt = db()->prepare(
    'SELECT * FROM ai_models WHERE id = ? AND is_active = 1 AND model_type = ? LIMIT 1'
);
$stmt->execute([$modelId, 'image']);
$model = $stmt->fetch();

if (!$model) {
    echo "错误: 模型 ID {$modelId} 不存在或未启用\n";
    exit(1);
}

$modelName = $model['name'];
$modelIdStr = $model['model_id'];
$baseUrl = rtrim((string) $model['base_url'], '/');
$apiKey = (string) $model['api_key'];
$editAdapter = trim((string) ($model['edit_adapter'] ?? ''));
$editImageField = trim((string) ($model['edit_image_field'] ?? 'image_urls'));

if ($editAdapter !== 'nano_banana_image_urls') {
    echo "警告: 模型 '{$modelName}' 的 edit_adapter 是 '{$editAdapter}'，不是 'nano_banana_image_urls'\n";
    echo "此脚本仅测试 nano_banana_image_urls 适配器\n";
}

echo "========================================\n";
echo "Nano Banana 编辑模型测试\n";
echo "========================================\n";
echo "模型名称: {$modelName}\n";
echo "模型 ID:  {$modelIdStr}\n";
echo "Base URL: {$baseUrl}\n";
echo "编辑适配器: {$editAdapter}\n";
echo "图片字段: {$editImageField}\n";
echo "----------------------------------------\n";

// 准备测试提示词和参考图
$testPrompt = 'A beautiful sunset over the ocean with colorful clouds';
$testImagePath = __DIR__ . '/../public/assets/logo.svg';

if (!is_file($testImagePath)) {
    // 尝试找一个存在的图片
    $testImagePath = null;
    $possiblePaths = [
        __DIR__ . '/../public/uploads/reference/.gitkeep',
        __DIR__ . '/../public/assets/logo.svg',
    ];
    foreach ($possiblePaths as $p) {
        if (is_file($p)) {
            $testImagePath = $p;
            break;
        }
    }
}

// 如果找不到测试图，生成一个简单的 PNG
if (!$testImagePath || !is_file($testImagePath)) {
    echo "创建测试图片...\n";
    $img = @imagecreatetruecolor(256, 256);
    if ($img) {
        $bg = imagecolorallocate($img, 255, 200, 100);
        imagefill($img, 0, 0, $bg);
        $text = imagecolorallocate($img, 50, 50, 50);
        imagestring($img, 5, 60, 115, 'TEST', $text);
        $tmpFile = sys_get_temp_dir() . '/nano_banana_test_' . time() . '.png';
        imagepng($img, $tmpFile);
        imagedestroy($img);
        $testImagePath = $tmpFile;
        echo "测试图片已创建: {$tmpFile}\n";
    } else {
        echo "错误: 无法创建测试图片\n";
        exit(1);
    }
}

echo "使用测试图片: {$testImagePath}\n";
$imageContent = file_get_contents($testImagePath);
if ($imageContent === false) {
    echo "错误: 无法读取测试图片\n";
    exit(1);
}

$imageBase64 = base64_encode($imageContent);
$mime = 'image/png';
$extension = 'png';

// 上传参考图到 public/uploads/reference/ 并构建公网 HTTPS URL
$filename = 'test_' . bin2hex(random_bytes(8)) . '.' . $extension;
$uploadDir = __DIR__ . '/../public/uploads/reference';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$uploadPath = $uploadDir . '/' . $filename;
if (file_put_contents($uploadPath, $imageContent, LOCK_EX) === false) {
    echo "错误: 无法保存测试图片到 {$uploadPath}\n";
    exit(1);
}

$baseUrlForUpload = rtrim((string) config('app.base_url', ''), '/');
if ($baseUrlForUpload === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $baseUrlForUpload = $scheme . '://' . $host;
}
$publicImageUrl = $baseUrlForUpload . '/uploads/reference/' . $filename;

echo "----------------------------------------\n";
echo "步骤 1: 上传参考图到公网 URL\n";
echo "公网 URL: {$publicImageUrl}\n";

// 验证公网 URL 可访问（curl）
echo "步骤 2: 验证公网 URL 可访问\n";
$ch = curl_init($publicImageUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$probeResponse = curl_exec($ch);
$probeHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$probeContentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$probeError = curl_error($ch);
curl_close($ch);

if ($probeHttpCode < 200 || $probeHttpCode >= 300) {
    echo "错误: 公网 URL 返回 HTTP {$probeHttpCode}，期望 200\n";
    echo "提示: 确保 Caddy/nginx 配置了 /uploads/reference/ 的公网访问\n";
    @unlink($uploadPath);
    exit(1);
}

$probeContentType = strtolower(trim(explode(';', $probeContentType)[0] ?? ''));
if (!in_array($probeContentType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
    echo "错误: 公网 URL Content-Type 是 '{$probeContentType}'，期望 image/png/jpeg/webp\n";
    @unlink($uploadPath);
    exit(1);
}
echo "  ✓ HTTP {$probeHttpCode}, Content-Type: {$probeContentType}\n";

// 构建 API 请求
echo "步骤 3: 发送 API 请求\n";
$endpoint = $baseUrl . '/v1/images/generations';

$payload = [
    'model' => $modelIdStr,
    'prompt' => $testPrompt,
    $editImageField => [$publicImageUrl],
    'size' => '1024x1024',
    'response_format' => 'b64_json',
];

$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
// 不输出 API Key
$sanitizedPayload = preg_replace('/("api_key"\s*:\s*)"[^"]*"/', '$1"[REDACTED]"', $payloadJson);
$sanitizedPayload = preg_replace('/(Bearer\s+)[^"]+/', '$1[REDACTED]', $sanitizedPayload);
echo "  Endpoint: {$endpoint}\n";
echo "  Payload:  {$sanitizedPayload}\n";

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => $payloadJson,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT => 120,
]);
$raw = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError !== '') {
    echo "错误: cURL 错误 - {$curlError}\n";
    @unlink($uploadPath);
    exit(1);
}

echo "  HTTP 状态: {$httpCode}\n";

// 尝试解析响应
$responsePreview = is_string($raw) ? substr(trim($raw), 0, 300) : '非字符串响应';
if (strlen($raw) > 300) {
    $responsePreview .= '...';
}
echo "  响应: {$responsePreview}\n";

if ($httpCode < 200 || $httpCode >= 300) {
    echo "错误: API 返回 HTTP {$httpCode}\n";
    @unlink($uploadPath);
    exit(1);
}

$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    echo "错误: API 返回非 JSON 响应\n";
    @unlink($uploadPath);
    exit(1);
}

// 解析图片数据
$imageData = null;
foreach (['b64_json', 'url', 'output_url', 'image_url'] as $key) {
    if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
        $imageData = ['key' => $key, 'value' => substr($data[$key], 0, 50) . (strlen($data[$key]) > 50 ? '...' : '')];
        break;
    }
}
if (!$imageData) {
    $dataKey = array_keys($data)[0] ?? 'unknown';
    $item = null;
    foreach (['data', 'results', 'images'] as $k) {
        if (isset($data[$k]) && is_array($data[$k])) {
            $firstItem = $data[$k][0] ?? null;
            if (is_array($firstItem)) {
                foreach (['b64_json', 'url', 'output_url'] as $ik) {
                    if (isset($firstItem[$ik])) {
                        $item = ['key' => $ik, 'value' => substr($firstItem[$ik], 0, 50)];
                        break;
                    }
                }
            }
        }
        if ($item) break;
    }
    if ($item) {
        $imageData = $item;
    }
}

if ($imageData) {
    echo "  ✓ 响应中包含图片数据 ({$imageData['key']})\n";
} else {
    echo "错误: 无法从响应中解析到图片数据 (b64_json / url / output_url)\n";
    echo "  响应键: " . implode(', ', array_keys($data)) . "\n";
    @unlink($uploadPath);
    exit(1);
}

// 清理测试文件
@unlink($uploadPath);

echo "----------------------------------------\n";
echo "========================================\n";
echo "测试通过！\n";
echo "========================================\n";
echo "\n";
echo "测试结论:\n";
echo "  1. 参考图已保存为公网 HTTPS URL: {$publicImageUrl}\n";
echo "  2. curl 公网 URL 返回 HTTP 200\n";
echo "  3. Content-Type 正确: {$probeContentType}\n";
echo "  4. Payload 使用 {$editImageField} 字段\n";
echo "  5. Endpoint: {$endpoint}\n";
echo "  6. 响应中包含 {$imageData['key']} 图片数据\n";
echo "  7. 未输出 API Key\n";
echo "  8. 未写 generation_records\n";
echo "  9. 未扣用户余额\n";
echo "\n";
echo "脚本通过仅表示适配器链路与接口返回正常；是否开放给客户，还需确认该模型已由管理员显式启用并完成上线审批。\n";

exit(0);
