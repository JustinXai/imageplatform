<?php

declare(strict_types=1);

/**
 * 一键优化提示词 API
 *
 * 将用户输入的原始提示词通过 AI 模型优化为更详细、更专业的提示词。
 * 后台可配置 API 地址、Key、模型和系统提示词。
 *
 * 请求方式：POST
 * 请求参数：
 *   - prompt (string, required): 原始提示词
 *   - csrf_token (string): CSRF 令牌
 *
 * 响应：
 *   {"ok":true,"prompt":"优化后的提示词"}
 *   {"ok":false,"message":"错误信息"}
 */

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// 仅接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '仅支持 POST 请求。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证登录
$user = require_login();

// 验证 CSRF
verify_csrf();

// 检查功能是否启用
if (!app_setting('prompt_optimize_enabled', '0')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '提示词优化功能未启用。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取原始提示词
$rawPrompt = trim((string) ($_POST['prompt'] ?? ''));
if ($rawPrompt === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => '提示词不能为空。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($rawPrompt) > 5000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => '提示词过长，请控制在 5000 字符以内。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取配置
$baseUrl = rtrim((string) app_setting('prompt_optimize_base_url', ''), '/');
$apiKey  = (string) app_setting('prompt_optimize_api_key', '');
$model   = (string) app_setting('prompt_optimize_model', 'gpt-4o-mini');

// 若未单独配置，尝试使用系统图片 API 配置
if ($baseUrl === '') {
    $baseUrl = rtrim((string) app_setting('image_base_url', ''), '/');
}
if ($apiKey === '') {
    $apiKey = (string) app_setting('image_api_key', '');
}

if ($baseUrl === '' || $apiKey === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '提示词优化功能未配置 API 地址或 Key。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取系统提示词（优先使用自定义，否则用默认）
$systemPrompt = (string) app_setting('prompt_optimize_system_prompt', '');
if ($systemPrompt === '') {
    $systemPrompt = '你是一个专业的AI绘画提示词优化专家。你的任务是将用户输入的中文或英文提示词，优化成详细、优美、适合AI绘画模型的英文提示词。要求：
1. 保持原意，增加细节描述（风格、光影、构图、色彩、质感、氛围等）
2. 适当加入艺术风格、视角、情绪等描述
3. 如果用户输入是中文，先理解含义，再输出英文优化版
4. 直接输出优化后的提示词内容，不要解释、不要评价、不要添加任何额外内容';
}

// 构建 chat completions 请求（baseUrl 不含 /v1，需手动添加）
$url = rtrim($baseUrl, '/') . '/v1/chat/completions';
$payload = [
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $rawPrompt],
    ],
    'max_tokens' => 2000,
    'temperature' => 0.7,
];

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
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => ssl_verify_enabled(),
    CURLOPT_SSL_VERIFYHOST => ssl_verify_enabled() ? 2 : 0,
]);

$raw   = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error   = curl_error($ch);
curl_close($ch);

if ($raw === false || $raw === '') {
    Logger::error('PROMPT_OPTIMIZE_CURL_ERROR', ['error' => $error]);
    http_response_code(502);
    echo json_encode(['ok' => false, 'message' => '调用优化接口失败：' . ($error ?: '无响应')], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    Logger::error('PROMPT_OPTIMIZE_INVALID_JSON', ['raw' => mb_substr((string) $raw, 0, 500)]);
    http_response_code(502);
    echo json_encode(['ok' => false, 'message' => '优化接口返回格式异常。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检查 API 错误
if (isset($data['error'])) {
    $errMsg = $data['error']['message'] ?? json_encode($data['error'], JSON_UNESCAPED_UNICODE);
    Logger::error('PROMPT_OPTIMIZE_API_ERROR', ['error' => $errMsg]);
    http_response_code(502);
    echo json_encode(['ok' => false, 'message' => '优化接口返回错误：' . $errMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

// 提取优化后的提示词
$optimizedPrompt = '';
if (isset($data['choices'][0]['message']['content'])) {
    $optimizedPrompt = trim((string) $data['choices'][0]['message']['content']);
}

if ($optimizedPrompt === '') {
    Logger::error('PROMPT_OPTIMIZE_EMPTY_RESULT', ['data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
    http_response_code(502);
    echo json_encode(['ok' => false, 'message' => '优化接口返回为空。'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'prompt' => $optimizedPrompt,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
