<?php

/**
 * 获取对话历史消息
 *
 * GET /api/chat_history?conversation_id=1
 * 返回：{ok, title, messages: [{role, content}]}
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/migration.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();
ensure_chat_conversations_table();
ensure_chat_records_table();
ensure_chat_records_conversation_column();

$conversationId = (int) ($_GET['conversation_id'] ?? 0);
if ($conversationId < 1) {
    json_response(['ok' => false, 'message' => '缺少 conversation_id'], 400);
}

// 验证归属
$stmt = db()->prepare('SELECT id, title FROM chat_conversations WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$conversationId, $user['id']]);
$conv = $stmt->fetch();

if (!$conv) {
    json_response(['ok' => false, 'message' => '对话不存在'], 404);
}

// 查询消息
$stmt = db()->prepare(
    'SELECT prompt, reply, created_at FROM chat_records WHERE conversation_id = ? ORDER BY id ASC'
);
$stmt->execute([$conversationId]);
$records = $stmt->fetchAll();

$messages = [];
foreach ($records as $r) {
    $messages[] = ['role' => 'user', 'content' => (string) $r['prompt']];
    $messages[] = ['role' => 'assistant', 'content' => (string) $r['reply']];
}

json_response([
    'ok' => true,
    'title' => (string) $conv['title'],
    'messages' => $messages,
]);
