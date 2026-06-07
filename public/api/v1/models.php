<?php

/**
 * OpenAI 兼容 — 模型列表端点
 *
 * GET /api/v1/models
 * Authorization: Bearer <api_token>  （可选）
 *
 * 响应（OpenAI 标准格式）：
 * {
 *   "object": "list",
 *   "data": [
 *     {"id": "gpt-4", "object": "model", "created": 1686582000, "owned_by": "system"},
 *     {"id": "dall-e-3", "object": "model", "created": 1686582000, "owned_by": "system"}
 *   ]
 * }
 */

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';
require_once dirname(__DIR__, 3) . '/src/migration.php';
require_once dirname(__DIR__, 3) . '/src/api_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_openai_error('Method not allowed. Use GET.', 'invalid_request_error', 405);
}

try {
    ensure_ai_models_table();
    ensure_ai_models_type_column();

    $stmt = db()->query('SELECT * FROM ai_models WHERE is_active = 1 ORDER BY model_type ASC, sort_order ASC, id ASC');
    $models = $stmt->fetchAll();

    $data = [];
    foreach ($models as $m) {
        // 映射内部 model_id 到 OpenAI 可识别的 model 名称
        $modelId = (string) ($m['model_id'] ?: $m['name']);
        $typeLabel = match ((string) ($m['model_type'] ?? 'image')) {
            'chat'  => '聊天',
            'video' => '视频',
            default => '图片',
        };

        $data[] = [
            'id'        => $modelId,
            'object'    => 'model',
            'created'   => strtotime((string) ($m['created_at'] ?? 'now')) ?: time(),
            'owned_by'  => 'system',
            'type'      => (string) ($m['model_type'] ?? 'image'),
            'type_label' => $typeLabel,
            'name'      => (string) $m['name'],
        ];
    }

    api_openai_response([
        'object' => 'list',
        'data'   => $data,
    ]);

} catch (Throwable $e) {
    api_openai_error('服务器内部错误。', 'server_error', 500);
}
