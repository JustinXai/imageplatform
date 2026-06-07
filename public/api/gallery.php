<?php
/**
 * 图片广场 API
 *
 * GET  /api/gallery?page=1&limit=20&mode=draw        → 列表
 * POST /api/gallery  {action: share, record_id: 123}  → 分享
 * POST /api/gallery  {action: unshare, id: 5}          → 取消分享
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/image_generation.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function api_ok(array $data = [], string $message = 'ok'): void {
    echo json_encode(['ok' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function api_error(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── GET: 列表 ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ensure_gallery_table();

    $page  = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
    $mode  = (string) ($_GET['mode'] ?? '');

    $where = '';
    $params = [];
    if (in_array($mode, ['draw', 'edit', 'video'], true)) {
        $where = 'WHERE mode = ?';
        $params[] = $mode;
    }

    $stmt = db()->prepare("SELECT COUNT(*) FROM gallery $where");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $limit));
    $offset = ($page - 1) * $limit;

    $stmt = db()->prepare(
        "SELECT id, user_id, record_id, username, prompt, image_url, mime_type,
                model, mode, size, likes, created_at
         FROM gallery $where
         ORDER BY created_at DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    api_ok([
        'items' => $stmt->fetchAll(),
        'page' => $page,
        'total_pages' => $totalPages,
        'total' => $total,
    ]);
}

// ── POST ──
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = (string) ($input['action'] ?? '');

// 分享图片到广场
if ($action === 'share') {
    try {
        $user = require_login();
        ensure_gallery_table();

        $recordId = (int) ($input['record_id'] ?? 0);
        if ($recordId < 1) api_error('参数不完整。');

        $stmt = db()->prepare('SELECT * FROM generation_records WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$recordId, $user['id']]);
        $record = $stmt->fetch();
        if (!$record) api_error('记录不存在。');
        if ($record['status'] !== 'succeeded') api_error('只能分享成功的作品。');

        $src = record_image_src($record);
        if (!$src) api_error('该记录没有图片。');

        $stmt = db()->prepare('SELECT id FROM gallery WHERE record_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$recordId, $user['id']]);
        if ($stmt->fetch()) api_error('该作品已分享过。');

        db()->prepare(
            'INSERT INTO gallery (user_id, record_id, username, prompt, image_url, mime_type, model, mode, size)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'], $recordId,
            $user['username'],
            $record['prompt'] ?? '',
            $src,
            $record['mime_type'] ?? 'image/png',
            $record['model'] ?? '',
            $record['mode'] ?? 'draw',
            $record['size'] ?? 'auto',
        ]);

        api_ok([], '已分享到图片广场');
    } catch (PDOException $e) {
        api_error('数据库错误：' . $e->getMessage(), 500);
    } catch (RuntimeException $e) {
        api_error($e->getMessage(), 400);
    } catch (Throwable $e) {
        api_error('系统错误：' . $e->getMessage(), 500);
    }
}

// 取消分享（支持 id 或 record_id）
if ($action === 'unshare') {
    $user = require_login();
    $galleryId = (int) ($input['id'] ?? 0);
    $recordId  = (int) ($input['record_id'] ?? 0);
    if ($recordId > 0) {
        db()->prepare('DELETE FROM gallery WHERE record_id = ? AND user_id = ?')
            ->execute([$recordId, $user['id']]);
    } elseif ($galleryId > 0) {
        db()->prepare('DELETE FROM gallery WHERE id = ? AND user_id = ?')
            ->execute([$galleryId, $user['id']]);
    } else {
        api_error('参数不完整。');
    }
    api_ok([], '已取消分享');
}

// 检查是否已分享
if ($action === 'check_share') {
    $user = require_login();
    $recordId = (int) ($input['record_id'] ?? 0);
    if ($recordId < 1) api_error('参数不完整。');
    $stmt = db()->prepare('SELECT id FROM gallery WHERE record_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$recordId, $user['id']]);
    api_ok(['shared' => (bool) $stmt->fetch()]);
}

// 我的分享列表
if ($action === 'my_shares') {
    $user = require_login();
    $stmt = db()->prepare('SELECT id, record_id, prompt, image_url, model, mode, created_at FROM gallery WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->execute([$user['id']]);
    api_ok(['items' => $stmt->fetchAll()]);
}

api_error('未知操作。');
