<?php

/**
 * JSON API for image/video generation tasks.
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/api_token.php';
require_once __DIR__ . '/../../src/api_middleware.php';
require_once __DIR__ . '/../../src/prompt_moderation.php';
require_once __DIR__ . '/../../src/image_generation.php';
require_once __DIR__ . '/../../src/migration.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $token = require_api_token();

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new RuntimeException('请求体必须为 JSON 格式。');
    }

    $mode = (string) ($input['mode'] ?? 'draw');
    if ($mode === 'video') {
        require_api_permission('generate_video');
        $requestedModelId = (int) ($input['ai_model_id'] ?? 0);
        if ($requestedModelId <= 0) {
            $stmt = db()->prepare('SELECT id FROM ai_models WHERE is_active = 1 AND model_type = ? AND fixed_seconds > 0 ORDER BY sort_order ASC LIMIT 1');
            $stmt->execute(['video']);
            $aiModelId = (int) $stmt->fetchColumn();
            if ($aiModelId <= 0) {
                throw new RuntimeException('没有可用的视频模型。');
            }
        } else {
            $aiModelId = $requestedModelId;
        }
    } else {
        require_api_permission('generate_image');
        $requestedModel = trim((string) ($input['model'] ?? ''));
        $aiModelId = resolve_active_image_model_id($requestedModel);
    }

    $_POST = [
        'prompt' => (string) ($input['prompt'] ?? ''),
        'mode' => $mode,
        'size' => (string) ($input['size'] ?? 'auto'),
        'quality' => (string) ($input['quality'] ?? 'auto'),
        'output_format' => (string) ($input['output_format'] ?? ($mode === 'video' ? 'mp4' : 'png')),
        'ai_model_id' => $aiModelId,
        'csrf_token' => '',
    ];

    $params = generation_input_from_request($_POST, []);
    $created = create_generation_record((int) $token['user_id'], $params, 'queued');
    $recordId = (int) $created['id'];
    trigger_generation_worker();

    echo json_encode([
        'ok' => true,
        'record_id' => $recordId,
        'status' => 'queued',
        'credits' => current_user_credits((int) $token['user_id']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '服务器内部错误。'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
