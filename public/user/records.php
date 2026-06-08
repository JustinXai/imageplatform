<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/image_generation.php';
require_once __DIR__ . '/../../src/migration.php';

$user = require_login();
ensure_generation_records_soft_delete();
ensure_generation_records_queue_status();
cleanup_stale_running_generation_records();

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$modeFilter = (string) ($_GET['mode'] ?? '');
$allowedModes = ['', 'draw', 'edit', 'video'];
if (!in_array($modeFilter, $allowedModes, true)) $modeFilter = '';

$modeWhere = $modeFilter !== '' ? ' AND mode = ' . db()->quote($modeFilter) : '';

$stmt = db()->prepare(
    'SELECT COUNT(*) FROM generation_records
     WHERE user_id = ? AND deleted_at IS NULL' . $modeWhere
);
$stmt->execute([$user['id']]);
$total = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    'SELECT id, user_id, status, mode, model, prompt, size, quality, output_format,
            input_images_json,
            output_url, mime_type, credits_cost, error_message, started_at, finished_at,
            deleted_at, created_at, output_base64 IS NOT NULL AS has_image_base64
     FROM generation_records
     WHERE user_id = ? AND deleted_at IS NULL' . $modeWhere . '
     ORDER BY created_at DESC
     LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, (int) $user['id'], PDO::PARAM_INT);
$stmt->bindValue(2, $perPage, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll();

render_header('生成记录', 'records');
?>
<div class="hidden"><?= csrf_field() ?></div>
<main>
    <div class="page-hd">
        <div>
            <h1>生成记录</h1>
            <p>History</p>
        </div>
        <div class="page-hd-actions">
            <div class="badge-balance">
                <span class="num"><?= $total ?></span>
                <span class="label">共 <?= $total ?> 条</span>
            </div>
            <a class="btn btn-secondary btn-sm" href="/user/index">返回生成</a>
        </div>
    </div>

    <section class="card-v3">
        <div class="card-v3-head">
            <nav class="tabs" role="group" aria-label="记录类型筛选">
                <a href="/user/records" class="tab <?= $modeFilter === '' ? 'active' : '' ?>">全部</a>
                <a href="/user/records?mode=draw" class="tab <?= $modeFilter === 'draw' ? 'active' : '' ?>">绘画</a>
                <a href="/user/records?mode=edit" class="tab <?= $modeFilter === 'edit' ? 'active' : '' ?>">编辑</a>
                <a href="/user/records?mode=video" class="tab <?= $modeFilter === 'video' ? 'active' : '' ?>">视频</a>
            </nav>
        </div>

        <div class="card-v3-body">
            <?php if (!$records): ?>
                <div class="empty-state">
                    <div class="icon">&#128247;</div>
                    <h3>暂无生成记录</h3>
                    <p>开始创作你的第一幅作品吧</p>
                </div>
            <?php endif; ?>

            <div class="grid-3" id="historyList">
                <?php foreach ($records as $record): ?>
                    <?php $src = record_image_src($record); ?>
                    <?php $inputImageCount = generation_input_image_count($record); ?>
                    <?php
                    $statusClass = match ((string) $record['status']) {
                        'succeeded' => 'succeeded',
                        'failed'    => 'failed',
                        'queued'    => 'queued',
                        'running'   => 'running',
                        default     => '',
                    };
                    ?>
                    <article
                        class="media-card"
                        tabindex="0"
                        data-record-id="<?= (int) $record['id'] ?>"
                        data-status="<?= e($record['status']) ?>"
                        data-mode="<?= e($record['mode'] ?? 'draw') ?>"
                        data-prompt="<?= e($record['prompt']) ?>"
                        data-size="<?= e($record['size']) ?>"
                        data-quality="<?= e($record['quality']) ?>"
                        data-format="<?= e($record['output_format']) ?>"
                        data-credits="<?= (int) ($record['credits_cost'] ?? 0) ?>"
                        data-created="<?= e($record['created_at']) ?>"
                        data-finished="<?= e($record['finished_at'] ?: '-') ?>"
                        data-error="<?= e($record['error_message'] ?: '') ?>"
                        data-input-count="<?= $inputImageCount ?>"
                    >
                        <?php if ($src): ?>
                            <img src="<?= e($src) ?>" alt="生成图片">
                        <?php else: ?>
                            <div style="display:grid;place-items:center;aspect-ratio:1;background:var(--main-surface-soft);color:var(--text-muted);font-weight:700;"><?= e(generation_status_label((string) $record['status'])) ?></div>
                        <?php endif; ?>
                        <div class="media-card-body">
                            <div class="prompt"><?= e($record['prompt']) ?></div>
                            <div class="meta">
                                <span class="status-badge <?= $statusClass ?>"><?= e(generation_status_label((string) $record['status'])) ?></span>
                                <span><?= e(mode_display_label((string) ($record['mode'] ?? 'draw'))) ?> / <?= e($record['size']) ?> / <?= e($record['quality']) ?></span>
                                <time><?= e($record['created_at']) ?></time>
                                <form method="post" action="/delete_record" style="display:inline;margin-left:auto;" onsubmit="return confirm('确认删除这条生成记录？')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                    <input type="hidden" name="redirect_to" value="/records<?= $totalPages > 1 ? ('?page=' . $page) : '' ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);">删除</button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="pages" aria-label="生成记录分页">
                    <a class="page-btn <?= $page <= 1 ? 'active' : '' ?>" href="<?= $page > 1 ? ('/records?page=' . ($page - 1)) : '#' ?>">上一页</a>
                    <span style="display:flex;align-items:center;padding:0 12px;font-size:13px;color:var(--text-muted);">第 <?= $page ?> / <?= $totalPages ?> 页</span>
                    <a class="page-btn <?= $page >= $totalPages ? 'active' : '' ?>" href="<?= $page < $totalPages ? ('/records?page=' . ($page + 1)) : '#' ?>">下一页</a>
                </nav>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="/assets/user.js"></script>
<?php render_footer(); ?>
