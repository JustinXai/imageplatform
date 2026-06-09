<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/image_generation.php';
require_once __DIR__ . '/../../src/generation_record_view_helpers.php';

require_admin();
ensure_generation_records_soft_delete();
ensure_generation_records_queue_status();
cleanup_stale_running_generation_records();

// 统计
$stats = [
    'users'   => (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'records' => (int) db()->query('SELECT COUNT(*) FROM generation_records')->fetchColumn(),
    'success' => (int) db()->query('SELECT COUNT(*) FROM generation_records WHERE status = "succeeded" AND deleted_at IS NULL')->fetchColumn(),
    'failed'  => (int) db()->query('SELECT COUNT(*) FROM generation_records WHERE status = "failed" AND deleted_at IS NULL')->fetchColumn(),
    'queued'  => (int) db()->query('SELECT COUNT(*) FROM generation_records WHERE status = "queued" AND deleted_at IS NULL')->fetchColumn(),
    'credits' => (int) db()->query('SELECT COALESCE(SUM(credits_cost), 0) FROM generation_records WHERE status = "succeeded" AND deleted_at IS NULL')->fetchColumn(),
];

$perPage = 12;
$page = max(1, (int) ($_GET['page'] ?? 1));
$modeFilter = (string) ($_GET['mode'] ?? '');
if (!in_array($modeFilter, ['', 'draw', 'edit', 'video'], true)) $modeFilter = '';

$whereMode = $modeFilter !== '' ? "WHERE r.mode = " . db()->quote($modeFilter) : '';
$total = (int) db()->query("SELECT COUNT(*) FROM generation_records r " . ($whereMode ?: "WHERE 1=1"))->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = 'SELECT r.id, r.user_id, r.status, r.mode, r.model, r.prompt, r.size, r.quality,
            r.output_format, r.output_url, r.mime_type, r.credits_cost, r.error_message,
            r.input_images_json, r.started_at, r.finished_at, r.deleted_at, r.created_at,
            r.output_base64 IS NOT NULL AS has_image_base64, u.username
     FROM generation_records r
     JOIN users u ON u.id = r.user_id' .
     ($modeFilter !== '' ? ' WHERE r.mode = ' . db()->quote($modeFilter) : '') . '
     ORDER BY r.created_at DESC
     LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll();

render_header('管理后台', 'admin');
render_admin_nav('index');
?>
<style>
.dash-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; }
.dash-stat { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
.dash-stat .icon { font-size: 20px; margin-bottom: 6px; }
.dash-stat .num { font-size: 26px; font-weight: 800; color: var(--text); }
.dash-stat .lbl { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-top: 2px; }
.filter-bar { display: flex; gap: 4px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
.filter-bar .tab { padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 500; text-decoration: none; border: 1px solid var(--line); color: var(--text-muted); background: var(--main-surface); transition: all .15s; }
.filter-bar .tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.filter-bar .tab:hover:not(.active) { border-color: var(--primary-soft); }
.filter-bar .count { margin-left: auto; font-size: 13px; color: var(--text-muted); }
.record-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.record-card { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; overflow: hidden; transition: all .15s; cursor: pointer; }
.record-card:hover { border-color: var(--primary-soft); box-shadow: 0 4px 16px rgba(0,0,0,.06); transform: translateY(-1px); }
.record-card.deleted { opacity: .5; }
.record-card .thumb { width: 100%; aspect-ratio: 1; background: var(--main-surface-soft); display: flex; align-items: center; justify-content: center; overflow: hidden; }
.record-card .thumb img, .record-card .thumb video { width: 100%; height: 100%; object-fit: cover; }
.record-card .thumb .no-img { font-size: 32px; color: var(--text-muted); }
.record-card .body { padding: 12px 14px; }
.record-card .body .user { font-size: 12px; font-weight: 600; color: var(--primary); margin-bottom: 4px; }
.record-card .body .prompt { font-size: 12px; color: var(--text); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-all; margin-bottom: 8px; }
.record-card .body .meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 11px; color: var(--text-muted); }
.record-card .body .foot { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; padding-top: 8px; border-top: 1px solid var(--line); }
.record-card .body .foot .id { font-size: 10px; color: var(--text-muted); }
.record-card .body .foot .actions { display: flex; gap: 6px; }
</style>
<main>
    <div class="page-hd">
        <div><h1>生成记录</h1><p>Records Dashboard</p></div>
    </div>

    <!-- 统计 -->
    <div class="dash-stats">
        <div class="dash-stat"><div class="icon">👥</div><div class="num"><?= number_format($stats['users']) ?></div><div class="lbl">用户数</div></div>
        <div class="dash-stat"><div class="icon">📊</div><div class="num"><?= number_format($stats['records']) ?></div><div class="lbl">总记录</div></div>
        <div class="dash-stat"><div class="icon">✅</div><div class="num"><?= number_format($stats['success']) ?></div><div class="lbl">成功</div></div>
        <div class="dash-stat"><div class="icon">⏳</div><div class="num"><?= number_format($stats['queued']) ?></div><div class="lbl">排队中</div></div>
        <div class="dash-stat"><div class="icon">❌</div><div class="num"><?= number_format($stats['failed']) ?></div><div class="lbl">失败</div></div>
        <div class="dash-stat"><div class="icon">💰</div><div class="num"><?= number_format($stats['credits']) ?></div><div class="lbl">总消耗</div></div>
    </div>

    <!-- 筛选 -->
    <div class="filter-bar">
        <a href="/admin/index" class="tab <?= $modeFilter === '' ? 'active' : '' ?>">全部</a>
        <a href="/admin/index?mode=draw" class="tab <?= $modeFilter === 'draw' ? 'active' : '' ?>">绘画</a>
        <a href="/admin/index?mode=edit" class="tab <?= $modeFilter === 'edit' ? 'active' : '' ?>">编辑</a>
        <a href="/admin/index?mode=video" class="tab <?= $modeFilter === 'video' ? 'active' : '' ?>">视频</a>
        <span class="count">共 <?= $total ?> 条</span>
    </div>

    <?php if (!$records): ?>
        <div class="empty-state"><div class="icon">📭</div><h3>暂无生成记录</h3></div>
    <?php else: ?>
        <div class="record-grid" id="adminRecordGrid">
            <?php foreach ($records as $r): ?>
                <?php
                $src = generation_record_image_src($r);
                $isDeleted = !empty($r['deleted_at']);
                $modeIcon = ['draw' => '🎨', 'edit' => '🖌️', 'video' => '🎬'][$r['mode'] ?? 'draw'] ?? '🎨';
                $statusClass = $isDeleted ? 'deleted' : $r['status'];
                ?>
                <div class="record-card <?= $isDeleted ? 'deleted' : '' ?>"
                     data-record-id="<?= (int) $r['id'] ?>"
                     data-status="<?= e($isDeleted ? 'deleted' : $r['status']) ?>"
                     data-mode="<?= e($r['mode'] ?? 'draw') ?>"
                     data-prompt="<?= e($r['prompt']) ?>"
                     data-size="<?= e($r['size']) ?>"
                     data-quality="<?= e($r['quality']) ?>"
                     data-format="<?= e($r['output_format']) ?>"
                     data-credits="<?= (int) ($r['credits_cost'] ?? 0) ?>"
                     data-created="<?= e($r['created_at']) ?>"
                     data-finished="<?= e($r['finished_at'] ?: '-') ?>"
                     data-error="<?= e($r['error_message'] ?: '') ?>"
                     data-input-count="<?= generation_input_image_count($r) ?>"
                     data-open-record>
                    <div class="thumb">
                        <?php $recVideoSrc = generation_record_video_src($r); ?>
                        <?php if ($r['mode'] === 'video' && $recVideoSrc): ?>
                            <video src="<?= e($recVideoSrc) ?>" muted preload="metadata"></video>
                        <?php elseif ($src): ?>
                            <img src="<?= e($src) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="no-img"><?= $modeIcon ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="body">
                        <div class="user">@<?= e($r['username']) ?></div>
                        <div class="prompt"><?= e($r['prompt'] ?: '(无提示词)') ?></div>
                        <div class="meta">
                            <span class="status-badge <?= $statusClass ?>"><?= e(generation_status_label($isDeleted ? 'deleted' : (string) $r['status'])) ?></span>
                            <span><?= e(generation_record_param_label($r)) ?></span>
                            <span><?= e($r['created_at']) ?></span>
                        </div>
                        <div class="foot">
                            <span class="id">#<?= (int) $r['id'] ?></span>
                            <?php if (!$isDeleted): ?>
                            <span class="actions">
                                <form method="post" action="/delete_record" onsubmit="return confirm('确认删除记录 #<?= (int) $r['id'] ?>？'); event.stopPropagation();">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="redirect_to" value="/admin/index?page=<?= $page ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);font-size:11px;">删除</button>
                                </form>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <nav class="pages" style="margin-top:20px;">
        <a class="page-btn <?= $page <= 1 ? 'active' : '' ?>" href="<?= $page > 1 ? '/admin/index?page='.($page-1).($modeFilter?'&mode='.$modeFilter:'') : '#' ?>">上一页</a>
        <span><?= $page ?> / <?= $totalPages ?></span>
        <a class="page-btn <?= $page >= $totalPages ? 'active' : '' ?>" href="<?= $page < $totalPages ? '/admin/index?page='.($page+1).($modeFilter?'&mode='.$modeFilter:'') : '#' ?>">下一页</a>
    </nav>
    <?php endif; ?>
</main>

<script>
// 点击卡片打开详情弹窗
document.getElementById("adminRecordGrid")?.addEventListener("click", function(e) {
    // 不拦截删除按钮点击
    if (e.target.closest("form") || e.target.closest("button")) return;
    var card = e.target.closest("[data-open-record]");
    if (!card) return;
    if (typeof openRecordDialog === "function") openRecordDialog(card);
});
</script>
<script src="/assets/user.js"></script>
<?php render_footer(); ?>
