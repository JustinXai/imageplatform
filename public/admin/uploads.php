<?php

/**
 * 图片管理后台 — 查看用户上传的参考图片 & 清理孤立文件
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/image_generation.php';

require_admin();
ensure_generation_records_soft_delete();

$activeTab = (string) ($_GET['tab'] ?? 'input');
$message = '';
$messageType = 'success';

// ============================================================
// 处理删除操作
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();

    if ($_POST['action'] === 'delete_input_image') {
        // 从 input_images_json 中移除指定路径的图片
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $imageUrl = (string) ($_POST['image_url'] ?? '');

        if ($recordId > 0 && $imageUrl !== '') {
            $stmt = db()->prepare('SELECT input_images_json FROM generation_records WHERE id = ?');
            $stmt->execute([$recordId]);
            $json = $stmt->fetchColumn();
            $images = json_decode((string) $json, true);

            if (is_array($images)) {
                $filtered = array_values(array_filter($images, fn($img) =>
                    !(is_array($img) && ($img['url'] ?? '') === $imageUrl)
                ));

                $stmt = db()->prepare('UPDATE generation_records SET input_images_json = ? WHERE id = ?');
                $stmt->execute([
                    !empty($filtered) ? json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    $recordId,
                ]);

                // 物理删除文件
                $filePath = local_public_file_from_url($imageUrl);
                if ($filePath !== null && is_file($filePath)) {
                    @unlink($filePath);
                }

                $message = '参考图片已删除。';
            }
        }
    } elseif ($_POST['action'] === 'delete_orphan') {
        // 删除孤立文件
        $filePath = (string) ($_POST['file_path'] ?? '');
        $absPath = dirname(__DIR__, 2) . '/public' . $filePath;
        if (is_file($absPath)) {
            if (unlink($absPath)) {
                $message = '已删除：' . basename($filePath);
            } else {
                $message = '删除失败（权限不足或文件被占用）：' . basename($filePath);
                $messageType = 'error';
            }
        } else {
            $message = '文件不存在：' . basename($filePath);
            $messageType = 'warning';
        }
    } elseif ($_POST['action'] === 'delete_all_orphans') {
        // 批量删除所有孤立文件
        $confirmed = (string) ($_POST['confirmed'] ?? '');
        if ($confirmed === 'yes') {
            // 收集数据库引用的文件路径（统一小写用于 Windows 比较）
            $referenced = collect_referenced_upload_paths();
            $referencedLower = array_map('strtolower', $referenced);

            // 扫描 uploads 目录
            $uploadsDir = dirname(__DIR__, 2) . '/public/uploads';
            $deletedCount = 0;
            $failCount = 0;

            foreach (['generations', 'input-images'] as $bucket) {
                $bucketDir = $uploadsDir . '/' . $bucket;
                if (!is_dir($bucketDir)) continue;

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($bucketDir, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (!$file->isFile()) continue;
                    $absPath = $file->getRealPath();

                    // Windows: 统一小写比较
                    if (!in_array(strtolower($absPath), $referencedLower, true)) {
                        if (unlink($absPath)) {
                            $deletedCount++;
                        } else {
                            $failCount++;
                        }
                    }
                }
            }

            // 清理空目录
            foreach (['generations', 'input-images'] as $bucket) {
                $bucketDir = $uploadsDir . '/' . $bucket;
                if (!is_dir($bucketDir)) continue;
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($bucketDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $item) {
                    if ($item->isDir()) {
                        @rmdir($item->getRealPath());
                    }
                }
            }

            $parts = [];
            if ($deletedCount > 0) $parts[] = "已清理 {$deletedCount} 个";
            if ($failCount > 0) $parts[] = "{$failCount} 个删除失败（权限不足）";
            $message = implode('，', $parts) ?: '没有需要清理的文件。';
            if ($failCount > 0 && $deletedCount === 0) $messageType = 'error';
            elseif ($failCount > 0) $messageType = 'warning';
        } else {
            $message = '请先确认批量删除操作。';
            $messageType = 'warning';
        }
    } elseif ($_POST['action'] === 'clean_phantom_inputs') {
        // 清理数据库中有 input_images 引用但文件已不存在的记录
        $confirmed = (string) ($_POST['confirmed'] ?? '');
        if ($confirmed === 'yes') {
            $count = 0;
            $stmt = db()->query("SELECT id, input_images_json FROM generation_records WHERE input_images_json IS NOT NULL AND input_images_json != ''");
            while ($row = $stmt->fetch()) {
                $images = json_decode((string) $row['input_images_json'], true);
                if (!is_array($images)) continue;

                $changed = false;
                $filtered = array_values(array_filter($images, function($img) use (&$changed) {
                    if (empty($img['url'])) return true;
                    $fp = local_public_file_from_url((string) $img['url']);
                    if ($fp === null || !is_file($fp)) {
                        $changed = true;
                        return false;
                    }
                    return true;
                }));

                if ($changed) {
                    $stmt2 = db()->prepare('UPDATE generation_records SET input_images_json = ? WHERE id = ?');
                    $stmt2->execute([
                        !empty($filtered) ? json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                        (int) $row['id'],
                    ]);
                    $count++;
                }
            }
            $message = "已清理 {$count} 条失效的参考图片引用。";
        } else {
            $message = '请先确认操作。';
            $messageType = 'warning';
        }
    }

    if ($message !== '') {
        flash($messageType, $message);
    }
    redirect('/admin/uploads?tab=' . $activeTab);
}

// ============================================================
// 辅助函数
// ============================================================

/**
 * 收集数据库所有 generation_records 中引用的文件绝对路径
 */
function collect_referenced_upload_paths(): array
{
    $paths = [];
    $pdo = db();

    // 1. 收集 output_url
    $stmt = $pdo->query("SELECT output_url FROM generation_records WHERE output_url IS NOT NULL AND output_url != ''");
    while ($row = $stmt->fetchColumn()) {
        $fp = local_public_file_from_url((string) $row);
        if ($fp !== null) {
            $paths[] = $fp;
        }
    }

    // 2. 收集 input_images_json 中的 url
    $stmt = $pdo->query("SELECT input_images_json FROM generation_records WHERE input_images_json IS NOT NULL AND input_images_json != ''");
    while ($row = $stmt->fetchColumn()) {
        $images = json_decode((string) $row, true);
        if (is_array($images)) {
            foreach ($images as $img) {
                if (!empty($img['url'])) {
                    $fp = local_public_file_from_url((string) $img['url']);
                    if ($fp !== null) {
                        $paths[] = $fp;
                    }
                }
            }
        }
    }

    return array_unique($paths);
}

/**
 * 扫描上传目录，返回所有文件信息
 */
function scan_upload_files(): array
{
    $files = [];
    $uploadsDir = dirname(__DIR__, 2) . '/public/uploads';

    foreach (['generations', 'input-images'] as $bucket) {
        $bucketDir = $uploadsDir . '/' . $bucket;
        if (!is_dir($bucketDir)) continue;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($bucketDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $realPath = $file->getRealPath();
            $relativePath = str_replace('\\', '/', substr($realPath, strlen(realpath($uploadsDir))));
            $files[] = [
                'path' => $realPath,
                'relative' => '/uploads' . $relativePath,
                'size' => $file->getSize(),
                'mtime' => $file->getMTime(),
                'bucket' => $bucket,
            ];
        }
    }

    // 按修改时间倒序
    usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return $files;
}

render_header('图片管理', 'admin');
render_admin_nav('uploads');
?>

<style>
.admin-tabs { display:flex; gap:6px; margin-bottom:20px; padding:8px; background:var(--main-surface); border:1px solid var(--line); border-radius:var(--radius); }
.admin-tabs .tab { min-height:34px; display:inline-flex; align-items:center; padding:0 14px; border-radius:var(--radius-sm); color:var(--text-soft); font-size:13px; font-weight:700; text-decoration:none; transition:all var(--duration) var(--ease-out); }
.admin-tabs .tab:hover { background:var(--sidebar-accent-soft); color:var(--sidebar-text-hover); }
.admin-tabs .tab.active { background:var(--sidebar-accent-soft); color:var(--sidebar-text-active); }

.upload-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px,1fr)); gap:14px; }
.upload-card { border:1px solid var(--line); border-radius:var(--radius); overflow:hidden; background:var(--main-surface); box-shadow:var(--shadow-sm); }
.upload-card .thumb { width:100%; height:160px; display:grid; place-items:center; overflow:hidden; background:linear-gradient(135deg,#eff6ff,#f8fafc); }
.upload-card .thumb img { width:100%; height:100%; object-fit:cover; }
.upload-card .thumb .no-img { font-size:12px; color:var(--text-muted); font-weight:700; }
.upload-card .info { padding:10px 12px; display:grid; gap:4px; font-size:12px; }
.upload-card .info .label { color:var(--text-muted); font-weight:700; }
.upload-card .info .val { color:var(--text); font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.upload-card .actions { padding:8px 12px 12px; display:flex; gap:6px; }
.upload-card .actions form { margin:0; }

.orphan-table { width:100%; border-collapse:collapse; }
.orphan-table th,.orphan-table td { padding:8px 12px; text-align:left; border-bottom:1px solid var(--line); font-size:13px; }
.orphan-table th { background:var(--main-surface-soft); color:var(--text-muted); font-weight:800; font-size:12px; }

.batch-cleanup-box { margin-bottom:20px; padding:16px 20px; border:1px solid var(--line); border-radius:var(--radius); background:var(--main-surface-soft); display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
.batch-cleanup-box .stat { font-weight:700; color:var(--text); }
.batch-cleanup-box .stat strong { font-size:20px; color:var(--danger); }
</style>

<div class="admin-tabs">
    <a class="tab <?= $activeTab === 'input' ? 'active' : '' ?>" href="?tab=input">📷 参考图片</a>
    <a class="tab <?= $activeTab === 'generated' ? 'active' : '' ?>" href="?tab=generated">🎨 生成图片</a>
</div>

<?php if ($activeTab === 'input'): ?>
    <?php
    $perPage = 20;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    // 查询有 input_images 的记录
    $stmt = db()->prepare('SELECT COUNT(*) FROM generation_records WHERE input_images_json IS NOT NULL AND input_images_json != \'\'');
    $stmt->execute();
    $total = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        'SELECT r.id, r.user_id, r.input_images_json, r.created_at, u.username
         FROM generation_records r
         JOIN users u ON u.id = r.user_id
         WHERE r.input_images_json IS NOT NULL AND r.input_images_json != \'\'
         ORDER BY r.created_at DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll();

    $totalPages = max(1, (int) ceil($total / $perPage));
    ?>

    <section class="card section-card" style="margin-top:0">
        <div class="card-head">
            <div>
                <p class="eyebrow">Input Images</p>
                <h2>参考图片</h2>
            </div>
            <span class="badge">共 <?= $total ?> 条记录</span>
        </div>

        <?php
        // 孤立文件检测
        $referenced = collect_referenced_upload_paths();
        $referencedLower = array_map('strtolower', $referenced);
        $allFiles = scan_upload_files();
        $orphans = array_values(array_filter($allFiles, fn($f) => !in_array(strtolower($f['path']), $referencedLower, true)));
        $orphanCount = count($orphans);
        $totalSize = array_sum(array_column($orphans, 'size'));
        ?>

        <div class="batch-cleanup-box" style="margin-bottom:20px;">
            <div>
                <?php if ($orphanCount > 0): ?>
                    <div class="stat">发现 <strong><?= $orphanCount ?></strong> 个孤立文件未清理</div>
                    <div style="color:var(--text-muted);font-size:13px;margin-top:4px">
                        总大小：<?= $totalSize > 1048576 ? round($totalSize / 1048576, 1) . ' MB' : round($totalSize / 1024, 1) . ' KB' ?>
                        ｜已无记录引用，可安全删除
                    </div>
                <?php else: ?>
                    <div class="stat">存储目录已清理干净，无孤立文件 🎉</div>
                    <div style="color:var(--text-muted);font-size:13px;margin-top:4px">
                        uploads/ 目录下所有文件均有记录引用
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($orphanCount > 0): ?>
                <form method="post" action="/admin/uploads?tab=input&page=<?= $page ?>" class="inline-delete-form"
                      onsubmit="return confirm('确认删除全部 <?= $orphanCount ?> 个孤立文件？此操作不可恢复！')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_all_orphans">
                    <input type="hidden" name="confirmed" value="yes">
                    <button class="button danger" type="submit">🧹 一键清理 <?= $orphanCount ?> 个孤立文件</button>
                </form>
            <?php endif; ?>
            <?php if ($total > 0): ?>
                <form method="post" action="/admin/uploads?tab=input&page=<?= $page ?>" class="inline-delete-form"
                      onsubmit="return confirm('确认清理全部 <?= $total ?> 条参考图片记录中已失效的引用？图片文件不存在的引用将被移除。')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="clean_phantom_inputs">
                    <input type="hidden" name="confirmed" value="yes">
                    <button class="button danger" type="submit">🗑️ 一键清理 <?= $total ?> 条记录中的失效引用</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$records): ?>
            <div class="history-empty-inline" style="grid-column:1/-1;display:grid;place-items:center;min-height:120px;border:1px dashed var(--line-strong);border-radius:var(--radius);color:var(--text-muted);font-weight:700;">
                暂无参考图片记录
            </div>
        <?php else: ?>
            <div class="upload-grid">
                <?php foreach ($records as $record): ?>
                    <?php
                    $images = json_decode((string) ($record['input_images_json'] ?? ''), true);
                    if (!is_array($images)) continue;
                    ?>
                    <?php foreach ($images as $index => $image): ?>
                        <?php if (empty($image['url'])) continue; ?>
                        <div class="upload-card">
                            <div class="thumb">
                                <img src="<?= e($image['url']) ?>" alt="参考图片"
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
                                <span class="no-img" style="display:none">图片不可用</span>
                            </div>
                            <div class="info">
                                <span><span class="label">用户：</span><span class="val"><?= e($record['username']) ?></span></span>
                                <span><span class="label">记录 #<?= (int) $record['id'] ?></span></span>
                                <span><span class="label">上传：</span><span class="val"><?= e($record['created_at']) ?></span></span>
                                <span style="font-size:11px;color:var(--text-muted);word-break:break-all"><?= e(basename($image['url'])) ?></span>
                            </div>
                            <div class="actions">
                                <form method="post" action="/admin/uploads?tab=input&page=<?= $page ?>" class="inline-delete-form"
                                      onsubmit="return confirm('确认删除此参考图片？')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_input_image">
                                    <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                    <input type="hidden" name="image_url" value="<?= e($image['url']) ?>">
                                    <button class="button danger small" type="submit">删除</button>
                                </form>
                                <a class="button secondary small" href="<?= e($image['url']) ?>" target="_blank" rel="noopener">查看原图</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="pagination" style="margin-top:20px">
                    <a class="button secondary small <?= $page <= 1 ? 'disabled' : '' ?>"
                       href="<?= $page > 1 ? ('/admin/uploads?tab=input&page=' . ($page - 1)) : '#' ?>">上一页</a>
                    <span>第 <?= $page ?> / <?= $totalPages ?> 页，共 <?= $total ?> 条记录</span>
                    <a class="button secondary small <?= $page >= $totalPages ? 'disabled' : '' ?>"
                       href="<?= $page < $totalPages ? ('/admin/uploads?tab=input&page=' . ($page + 1)) : '#' ?>">下一页</a>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

<?php elseif ($activeTab === 'generated'): ?>
    <?php
    $perPage = 24;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare("SELECT COUNT(*) FROM generation_records WHERE output_url IS NOT NULL AND output_url != '' AND output_url NOT LIKE 'http%' AND output_url NOT LIKE 'https%'");
    $stmt->execute();
    $total = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT r.id, r.user_id, r.output_url, r.status, r.mode, r.prompt, r.finished_at, r.deleted_at, u.username
         FROM generation_records r
         JOIN users u ON u.id = r.user_id
         WHERE r.output_url IS NOT NULL AND r.output_url != '' AND r.output_url NOT LIKE 'http%' AND r.output_url NOT LIKE 'https%'
         ORDER BY r.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll();

    $totalPages = max(1, (int) ceil($total / $perPage));
    ?>

    <section class="card section-card" style="margin-top:0">
        <div class="card-head">
            <div>
                <p class="eyebrow">Generated Images</p>
                <h2>生成图片</h2>
            </div>
            <span class="badge">共 <?= $total ?> 张</span>
        </div>

        <?php if (!$records): ?>
            <div style="display:grid;place-items:center;min-height:120px;border:1px dashed var(--line-strong);border-radius:var(--radius);color:var(--text-muted);font-weight:700;">
                暂无本地存储的生成图片
            </div>
        <?php else: ?>
            <div class="upload-grid">
                <?php foreach ($records as $record): ?>
                    <?php $isDeleted = !empty($record['deleted_at']); ?>
                    <?php $genSrc = generation_record_image_src($record, true); ?>
                    <div class="upload-card" style="<?= $isDeleted ? 'opacity:0.5' : '' ?>">
                        <div class="thumb">
                            <img src="<?= e($genSrc ?: $record['output_url']) ?>" alt="生成图片" loading="lazy" decoding="async"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
                            <span class="no-img" style="display:none">文件丢失</span>
                        </div>
                        <div class="info">
                            <span><span class="label">用户：</span><span class="val"><?= e($record['username']) ?></span></span>
                            <span><span class="label">记录 #<?= (int) $record['id'] ?></span>
                                <?php if ($isDeleted): ?><span class="status deleted" style="font-size:10px;margin-left:4px">已删除</span><?php endif; ?>
                            </span>
                            <span class="val" title="<?= e($record['prompt']) ?>"><?= e(mb_substr((string) $record['prompt'], 0, 30)) ?><?= mb_strlen((string) $record['prompt']) > 30 ? '…' : '' ?></span>
                            <span style="font-size:11px;color:var(--text-muted)"><?= e($record['finished_at'] ?: $record['deleted_at'] ?: '-') ?></span>
                        </div>
                        <div class="actions">
                            <a class="button secondary small" href="<?= e($record['output_url']) ?>" target="_blank" rel="noopener">查看</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="pagination" style="margin-top:20px">
                    <a class="button secondary small <?= $page <= 1 ? 'disabled' : '' ?>"
                       href="<?= $page > 1 ? ('/admin/uploads?tab=generated&page=' . ($page - 1)) : '#' ?>">上一页</a>
                    <span>第 <?= $page ?> / <?= $totalPages ?> 页，共 <?= $total ?> 张</span>
                    <a class="button secondary small <?= $page >= $totalPages ? 'disabled' : '' ?>"
                       href="<?= $page < $totalPages ? ('/admin/uploads?tab=generated&page=' . ($page + 1)) : '#' ?>">下一页</a>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

<?php endif; ?>

<script src="/assets/user.js"></script>
<?php render_footer(); ?>
