<?php

/**
 * OpenAI-compatible image generations endpoint.
 */

require_once dirname(__DIR__, 4) . '/src/bootstrap.php';
require_once dirname(__DIR__, 4) . '/src/api_token.php';
require_once dirname(__DIR__, 4) . '/src/api_middleware.php';
require_once dirname(__DIR__, 4) . '/src/image_generation.php';
require_once dirname(__DIR__, 4) . '/src/migration.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_openai_error('Method not allowed. Use POST.', 'invalid_request_error', 405);
}

try {
    $token = require_api_token();
    require_api_permission('generate_image');

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        api_openai_error('请求体必须为 JSON 格式。', 'invalid_request_error', 400);
    }

    $prompt = trim((string) ($input['prompt'] ?? ''));
    if ($prompt === '') {
        api_openai_error('prompt 字段不能为空。', 'invalid_request_error', 400);
    }

    $requestedModel = trim((string) ($input['model'] ?? ''));
    $aiModelId = resolve_active_image_model_id($requestedModel);

    $sizeMap = [
        '1024x1024' => '1:1',
        '1024x1792' => '9:16',
        '1792x1024' => '16:9',
    ];

    $size = (string) ($input['size'] ?? '1024x1024');
    $quality = (string) ($input['quality'] ?? 'standard');
    $responseFormat = (string) ($input['response_format'] ?? 'url');
    if (!in_array($responseFormat, ['url', 'b64_json'], true)) {
        $responseFormat = 'url';
    }

    $mode = 'draw';
    $_POST = [
        'prompt' => $prompt,
        'mode' => $mode,
        'size' => $sizeMap[$size] ?? 'auto',
        'quality' => $quality === 'hd' ? 'high' : 'medium',
        'output_format' => 'png',
        'ai_model_id' => $aiModelId,
        'csrf_token' => '',
    ];

    $params = generation_input_from_request($_POST, []);
    $credits = generation_cost_for($mode, $aiModelId);
    if ((int) $token['user_info']['credits'] < $credits) {
        api_openai_error('积分不足，需要 ' . $credits . ' 积分。', 'insufficient_quota', 402);
    }

    $created = create_generation_record((int) $token['user_id'], $params, 'queued');
    $recordId = (int) $created['id'];
    trigger_generation_worker();

    $baseUrl = rtrim((string) config('app.base_url', ''), '/');
    if ($baseUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $baseUrl = $scheme . '://' . $host;
    }
    $statusUrl = $baseUrl . '/api/check_record?id=' . $recordId;

    api_openai_response([
        'created' => time(),
        'data' => [[
            'url' => $statusUrl,
            'revised_prompt' => $prompt,
        ]],
        'record_id' => $recordId,
        'status_url' => $statusUrl,
    ]);
} catch (RuntimeException $e) {
    api_openai_error($e->getMessage(), 'invalid_request_error', 400);
} catch (Throwable $e) {
    api_openai_error('服务器内部错误。', 'server_error', 500);
}
