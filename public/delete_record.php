<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/image_generation.php';

$user = require_login();
ensure_generation_records_soft_delete();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

verify_csrf();

$recordId = max(0, (int) ($_POST['record_id'] ?? 0));
$redirectTo = (string) ($_POST['redirect_to'] ?? '/index');
if ($redirectTo === '' || $redirectTo[0] !== '/') {
    $redirectTo = '/index';
}

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

/**
 * 辅助函数：根据请求类型返回 JSON 错误或者带 Flash 跳转
 */
function delete_error(string $message, int $httpCode): never
{
    global $isAjax, $redirectTo;
    if ($isAjax) {
        json_response(['ok' => false, 'message' => $message], $httpCode);
    }
    flash('error', $message);
    redirect($redirectTo);
}

// ============================================================
// 1. 参数完整性检查
// ============================================================
if ($recordId < 1) {
    delete_error('记录不存在。', 422);
}

// ============================================================
// 2. 查询记录，获取图片文件路径（物理删除文件）
// ============================================================
$stmt = db()->prepare('SELECT id, user_id, image_url, input_images_json FROM generation_records WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$recordId]);
$record = $stmt->fetch();

if (!is_array($record)) {
    delete_error('记录不存在或已被删除。', 422);
}

// 权限检查：必须是记录所有者或是管理员
if ($user['role'] !== 'admin' && (int) $record['user_id'] !== (int) $user['id']) {
    delete_error('无权限删除此记录。', 403);
}

// ============================================================
// 3. 物理删除图片文件
// ============================================================

// 3a. 删除生成的图片文件
if (!empty($record['image_url'])) {
    $filePath = local_public_file_from_url((string) $record['image_url']);
    if ($filePath !== null && is_file($filePath)) {
        @unlink($filePath);
    }
}

// 3b. 删除输入的参考图片文件
if (!empty($record['input_images_json'])) {
    $inputImages = json_decode((string) $record['input_images_json'], true);
    if (is_array($inputImages)) {
        foreach ($inputImages as $inputImage) {
            if (!empty($inputImage['url']) && is_string($inputImage['url'])) {
                $filePath = local_public_file_from_url($inputImage['url']);
                if ($filePath !== null && is_file($filePath)) {
                    @unlink($filePath);
                }
            }
        }
    }
}

// ============================================================
// 4. 软删除数据库记录（标记 deleted_at）
// ============================================================
$stmt = db()->prepare('UPDATE generation_records SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$recordId]);

if ($isAjax) {
    json_response(['ok' => $stmt->rowCount() > 0]);
}

flash($stmt->rowCount() > 0 ? 'success' : 'error', $stmt->rowCount() > 0 ? '记录已删除。' : '记录不存在或无权限删除。');
redirect($redirectTo);
