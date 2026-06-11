<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

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
    "SELECT g.id, g.user_id, g.record_id, g.username, g.prompt, g.image_url,
            g.mime_type, g.model, g.mode, g.size, g.likes, g.created_at
     FROM gallery g $where
     ORDER BY g.created_at DESC
     LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);
$items = $stmt->fetchAll();

$currentUser = current_user();

render_header('图片广场', 'gallery');
?>

<main>
    <div class="page-hd">
        <div>
            <h1>图片广场</h1>
            <p>浏览社区公开作品，获取灵感</p>
        </div>
    </div>

    <!-- 模式筛选 -->
    <nav class="tabs" style="margin-bottom:20px;">
        <a href="/gallery" class="tab <?= $mode === '' ? 'active' : '' ?>">全部</a>
        <a href="/gallery?mode=draw" class="tab <?= $mode === 'draw' ? 'active' : '' ?>">绘画</a>
        <a href="/gallery?mode=edit" class="tab <?= $mode === 'edit' ? 'active' : '' ?>">编辑</a>
        <a href="/gallery?mode=video" class="tab <?= $mode === 'video' ? 'active' : '' ?>">视频</a>
    </nav>

    <?php if (!$items): ?>
        <div class="empty-state">
            <div class="icon">🖼️</div>
            <h3>暂无公开作品</h3>
            <p>去生成你的第一张图片，并分享到广场吧！</p>
        </div>
    <?php else: ?>
        <div class="grid-3cols" id="galleryGrid">
            <?php foreach ($items as $item): ?>
                <div class="media-card" style="cursor:pointer;"
                     data-record-id="<?= (int) $item['record_id'] ?>"
                     data-status="succeeded"
                     data-mode="<?= e($item['mode']) ?>"
                     data-prompt="<?= e($item['prompt'] ?? '') ?>"
                     data-size="<?= e($item['size'] ?? 'auto') ?>"
                     data-credits="0"
                     data-created="<?= e($item['created_at']) ?>"
                     data-finished="<?= e($item['created_at']) ?>"
                     data-input-count="0"
                     tabindex="0"
                >
                    <?php if ($item['mode'] === 'video'): ?>
                        <video src="<?= e($item['image_url']) ?>" muted preload="metadata" onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='grid';"></video>
                        <div class="no-img" style="display:none">文件丢失</div>
                    <?php else: ?>
                        <img src="<?= e($item['image_url']) ?>" alt="" loading="lazy" decoding="async"
                             style="aspect-ratio:1/1;object-fit:cover;width:100%;"
                             onerror="this.onerror=null; this.src='/assets/placeholder-image.svg';">
                    <?php endif; ?>
                    <div class="media-card-body">
                        <div class="prompt"><?= e($item['prompt'] ?? '') ?></div>
                        <div class="meta">
                            <span>@<?= e($item['username'] ?? '用户' . $item['user_id']) ?></span>
                            <span><?= e($item['model'] ?? '-') ?> · <?= e(['draw' => '绘画', 'edit' => '编辑', 'video' => '视频'][$item['mode']] ?? $item['mode']) ?></span>
                        </div>
                        <time><?= e($item['created_at']) ?></time>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
        <nav class="pages" style="margin-top:24px;">
            <?php if ($page > 1): ?>
                <a class="page-btn" href="/gallery?page=<?= $page - 1 ?><?= $mode ? '&mode=' . $mode : '' ?>">上一页</a>
            <?php endif; ?>
            <span><?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="page-btn" href="/gallery?page=<?= $page + 1 ?><?= $mode ? '&mode=' . $mode : '' ?>">下一页</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</main>

<?php if ($currentUser): ?>
<script>
// 已登录用户：点击卡片打开详情弹窗（复用 user.js 的 openRecordDialog）
(function(){
    document.getElementById("galleryGrid")?.addEventListener("click", function(e) {
        var card = e.target.closest(".media-card");
        if (!card) return;
        // 使用 user.js 的 openRecordDialog
        if (typeof openRecordDialog === "function") {
            openRecordDialog(card);
        }
    });
})();
</script>
<?php endif; ?>

<script src="/assets/user.js?v=<?= e((string) (@filemtime(__DIR__ . '/assets/user.js') ?: time())) ?>"></script>
<?php render_footer(); ?>
