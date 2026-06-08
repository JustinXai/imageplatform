<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/image_generation.php';

$user = require_login();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

// 统计
$countStmt = db()->prepare(
    "SELECT COUNT(*) FROM generation_records WHERE user_id = ? AND deleted_at IS NULL"
);
$countStmt->execute([$user['id']]);
$total = (int) $countStmt->fetchColumn();
$maxPage = max(1, (int) ceil($total / $perPage));
if ($page > $maxPage) $page = $maxPage;
$offset = ($page - 1) * $perPage;

$sumStmt = db()->prepare(
    "SELECT COALESCE(SUM(credits_cost), 0) FROM generation_records WHERE user_id = ? AND deleted_at IS NULL AND status = 'succeeded'"
);
$sumStmt->execute([$user['id']]);
$totalCreditsUsed = (int) $sumStmt->fetchColumn();

// 本月消耗
$sumMonth = db()->prepare(
    "SELECT COALESCE(SUM(credits_cost), 0) FROM generation_records
     WHERE user_id = ? AND deleted_at IS NULL AND status = 'succeeded'
       AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
);
$sumMonth->execute([$user['id']]);
$monthCredits = (int) $sumMonth->fetchColumn();

// 记录
$stmt = db()->prepare(
    "SELECT id, status, mode, prompt, size, quality, output_format, credits_cost, created_at, finished_at, error_message
     FROM generation_records
     WHERE user_id = ? AND deleted_at IS NULL
     ORDER BY created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$stmt->bindValue(2, $perPage, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll();

$balanceLabel = balance_label();

render_header('点数记录', 'credits');
?>
<style>
.credits-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 20px; }
.credits-stat { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; }
.credits-stat .label { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
.credits-stat .value { font-size: 24px; font-weight: 800; color: var(--text); }
.credits-stat .sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.credits-list { display: flex; flex-direction: column; gap: 10px; }
.credits-item { display: flex; align-items: center; gap: 14px; padding: 14px 16px; background: var(--main-surface); border: 1px solid var(--line); border-radius: 12px; transition: all .15s; }
.credits-item:hover { border-color: var(--primary-soft); box-shadow: 0 2px 8px rgba(0,0,0,.04); }
.credits-item .icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.credits-item .icon.draw { background: rgba(139,92,246,.12); color: #8b5cf6; }
.credits-item .icon.edit { background: rgba(59,130,246,.12); color: #3b82f6; }
.credits-item .icon.video { background: rgba(236,72,153,.12); color: #ec4899; }
.credits-item .body { flex: 1; min-width: 0; }
.credits-item .body .prompt { font-size: 13px; color: var(--text); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-all; }
.credits-item .body .meta { font-size: 11px; color: var(--text-muted); margin-top: 4px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.credits-item .cost { font-size: 15px; font-weight: 700; text-align: right; flex-shrink: 0; min-width: 60px; }
.credits-item .cost.charged { color: var(--danger, #ef4444); }
.credits-item .cost.refunded { color: var(--success, #10b981); }
.credits-item .cost.pending { color: var(--text-muted); }
</style>
<main>
    <div class="page-hd">
        <div>
            <h1>点数使用记录</h1>
            <p>Usage History</p>
        </div>
        <div class="page-hd-actions">
            <a href="/user/shop" class="btn btn-primary btn-sm">去充值</a>
        </div>
    </div>

    <!-- 统计卡片 -->
    <div class="credits-stats">
        <div class="credits-stat">
            <div class="label">累计消耗</div>
            <div class="value"><?= number_format($totalCreditsUsed) ?></div>
            <div class="sub"><?= e($balanceLabel) ?></div>
        </div>
        <div class="credits-stat">
            <div class="label">本月消耗</div>
            <div class="value"><?= number_format($monthCredits) ?></div>
            <div class="sub"><?= e($balanceLabel) ?></div>
        </div>
        <div class="credits-stat">
            <div class="label">当前余额</div>
            <div class="value"><?= number_format((int) $user['credits']) ?></div>
            <div class="sub"><?= e($balanceLabel) ?></div>
        </div>
        <div class="credits-stat">
            <div class="label">总记录数</div>
            <div class="value"><?= number_format($total) ?></div>
            <div class="sub">条生成记录</div>
        </div>
    </div>

    <!-- 记录列表 -->
    <section class="card-v3">
        <div class="card-v3-head">
            <div>
                <h3>使用记录</h3>
                <p class="sub">共 <?= $total ?> 条</p>
            </div>
        </div>
        <div class="card-v3-body">
            <?php if (!$records): ?>
                <div class="empty-state">
                    <div class="icon">📊</div>
                    <h3>暂无使用记录</h3>
                    <p>开始生成你的第一张图片吧</p>
                </div>
            <?php else: ?>
                <div class="credits-list">
                    <?php foreach ($records as $r): ?>
                        <?php
                        $modeLabel = match ($r['mode'] ?? 'draw') {
                            'video' => '视频',
                            'edit'  => '编辑',
                            default => '绘画',
                        };
                        $modeIcon = match ($r['mode'] ?? 'draw') {
                            'video' => '🎬',
                            'edit'  => '🖌️',
                            default => '🎨',
                        };
                        $modeClass = match ($r['mode'] ?? 'draw') {
                            'video' => 'video',
                            'edit'  => 'edit',
                            default => 'draw',
                        };
                        $paramLabel = match ($r['mode'] ?? 'draw') {
                            'video' => ($r['size'] ?? 'auto') . ' · ' . ($r['output_format'] ?? 'mp4'),
                            default => ($r['size'] ?? 'auto') . ' · ' . ($r['quality'] ?? 'auto'),
                        };
                        $statusLabel = generation_status_label((string) $r['status']);
                        $statusClass = match ((string) $r['status']) {
                            'succeeded' => 'succeeded',
                            'failed'    => 'failed',
                            'queued'    => 'queued',
                            'running'   => 'running',
                            default     => '',
                        };
                        $isSucceeded = $r['status'] === 'succeeded';
                        $isFailed    = $r['status'] === 'failed';
                        $creditsNum  = (int) ($r['credits_cost'] ?? 0);
                        ?>
                        <div class="credits-item">
                            <div class="icon <?= $modeClass ?>"><?= $modeIcon ?></div>
                            <div class="body">
                                <div class="prompt"><?= e($r['prompt'] ?: '(无提示词)') ?></div>
                                <div class="meta">
                                    <span class="status-badge <?= $statusClass ?>"><?= e($statusLabel) ?></span>
                                    <span><?= e($modeLabel) ?> · <?= e($paramLabel) ?></span>
                                    <span><?= e($r['created_at']) ?></span>
                                </div>
                            </div>
                            <div class="cost <?= $isSucceeded ? 'charged' : ($isFailed ? 'refunded' : 'pending') ?>">
                                <?php if ($isSucceeded): ?>-<?= $creditsNum ?>
                                <?php elseif ($isFailed): ?>已退回
                                <?php else: ?>-
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($maxPage > 1): ?>
        <div style="border-top:1px solid var(--line);padding:12px 20px;">
            <nav class="pages">
                <?php if ($page > 1): ?>
                    <a class="page-btn" href="/user/credits?page=<?= $page - 1 ?>">上一页</a>
                <?php endif; ?>
                <span style="display:flex;align-items:center;padding:0 12px;font-size:13px;color:var(--text-muted);">第 <?= $page ?> / <?= $maxPage ?> 页</span>
                <?php if ($page < $maxPage): ?>
                    <a class="page-btn" href="/user/credits?page=<?= $page + 1 ?>">下一页</a>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
    </section>
</main>
<?php render_footer(); ?>
