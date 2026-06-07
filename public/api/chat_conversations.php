<?php

/**
 * 获取用户的对话列表
 *
 * GET /api/chat_conversations
 * 返回：{ok, conversations: [{id, title, message_count, created_at, updated_at}]}
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/migration.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();
ensure_chat_conversations_table();

$stmt = db()->prepare(
    'SELECT c.id, c.title, c.message_count, c.created_at, c.updated_at
     FROM chat_conversations c
     WHERE c.user_id = ?
     ORDER BY c.updated_at DESC
     LIMIT 50'
);
$stmt->execute([(int) $user['id']]);
$conversations = $stmt->fetchAll();

// 格式化数据
$list = [];
foreach ($conversations as $c) {
    $list[] = [
        'id'            => (int) $c['id'],
        'title'         => (string) $c['title'],
        'message_count' => (int) $c['message_count'],
        'created_at'    => (string) $c['created_at'],
        'updated_at'    => (string) $c['updated_at'],
    ];
}

json_response([
    'ok' => true,
    'conversations' => $list,
]);
