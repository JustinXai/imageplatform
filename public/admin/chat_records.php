<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

require_admin();
ensure_chat_records_table();

$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$search = trim((string) ($_GET['search'] ?? ''));
$params = [];
$where  = '';

if ($search !== '') {
    $where = 'WHERE u.username LIKE ? OR c.prompt LIKE ? OR c.user_ip LIKE ?';
    $like  = '%' . $search . '%';
    $params = [$like, $like, $like];
}

$countSql = "SELECT COUNT(*) FROM chat_records c LEFT JOIN users u ON u.id = c.user_id $where";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $limit));

$sql = "SELECT c.*, u.username
        FROM chat_records c
        LEFT JOIN users u ON u.id = c.user_id
        $where
        ORDER BY c.created_at DESC
        LIMIT $limit OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// 统计
$totalTokens = 0; $totalCost = 0;
foreach ($records as $r) { $totalTokens += (int) $r['tokens']; $totalCost += (int) $r['credits_cost']; }

render_header('对话记录', 'admin');
render_admin_nav('chat_records');
?>
<style>
.chat-stats-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 18px; }
.chat-stat-box { background: var(--main-surface); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; text-align: center; }
.chat-stat-box .num { font-size: 22px; font-weight: 800; color: var(--text); }
.chat-stat-box .lbl { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.chat-search-row { display: flex; gap: 8px; align-items: center; margin-bottom: 16px; }
.chat-search-row input { flex: 1; max-width: 320px; padding: 8px 14px; border: 1px solid var(--line); border-radius: 10px; font-size: 13px; background: var(--main-surface); }
.chat-search-row input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
.chat-list { display: flex; flex-direction: column; gap: 8px; }
.chat-item { display: flex; gap: 14px; padding: 14px; background: var(--main-surface); border: 1px solid var(--line); border-radius: 12px; transition: all .12s; }
.chat-item:hover { border-color: var(--primary-soft); box-shadow: 0 2px 8px rgba(0,0,0,.03); }
.chat-item .avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--primary-soft); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; flex-shrink: 0; }
.chat-item .body { flex: 1; min-width: 0; }
.chat-item .body .top-row { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; flex-wrap: wrap; }
.chat-item .body .top-row .user-name { font-weight: 700; font-size: 13px; color: var(--text); }
.chat-item .body .top-row .meta { font-size: 11px; color: var(--text-muted); }
.chat-item .body .q { font-size: 13px; color: var(--text); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-all; }
.chat-item .body .q .label { color: var(--primary); font-weight: 600; margin-right: 6px; font-size: 11px; }
.chat-item .body .a { font-size: 12px; color: var(--text-muted); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin-top: 4px; }
.chat-item .body .a .label { color: #10b981; font-weight: 600; margin-right: 6px; font-size: 11px; }
.chat-item .stats { text-align: right; flex-shrink: 0; min-width: 70px; }
.chat-item .stats .tokens { font-size: 12px; color: var(--text-muted); }
.chat-item .stats .cost { font-size: 16px; font-weight: 700; color: var(--danger, #ef4444); margin-top: 2px; }
</style>
<main>
    <div class="page-hd">
        <div>
            <h1>对话记录</h1>
            <p>Chat History</p>
        </div>
    </div>

    <!-- 统计 -->
    <div class="chat-stats-bar">
        <div class="chat-stat-box"><div class="num"><?= number_format($total) ?></div><div class="lbl">总记录数</div></div>
        <div class="chat-stat-box"><div class="num"><?= number_format($totalTokens) ?></div><div class="lbl">本页 Tokens</div></div>
        <div class="chat-stat-box"><div class="num"><?= number_format($totalCost) ?></div><div class="lbl">本页消耗</div></div>
    </div>

    <!-- 搜索 -->
    <form method="get" class="chat-search-row">
        <input name="search" value="<?= e($search) ?>" placeholder="搜索用户名 / 对话内容 / IP...">
        <button type="submit" class="btn btn-primary btn-sm">搜索</button>
        <?php if ($search !== ''): ?>
            <a href="/admin/chat_records" class="btn btn-secondary btn-sm">清除</a>
        <?php endif; ?>
    </form>

    <!-- 记录列表 -->
    <?php if (empty($records)): ?>
        <div class="empty-state">
            <div class="icon">💬</div>
            <h3>暂无对话记录</h3>
            <p>用户开始对话后将在这里显示。</p>
        </div>
    <?php else: ?>
        <div class="chat-list">
            <?php foreach ($records as $r): ?>
            <div class="chat-item">
                <div class="avatar"><?= e(mb_strtoupper(mb_substr($r['username'] ?: '?', 0, 1))) ?></div>
                <div class="body">
                    <div class="top-row">
                        <span class="user-name"><?= e($r['username'] ?: '(已删除)') ?></span>
                        <span class="meta"><?= e($r['created_at']) ?></span>
                        <span class="meta">IP: <?= e($r['user_ip'] ?? '-') ?></span>
                        <span class="meta">ID: <?= (int) $r['id'] ?></span>
                    </div>
                    <div class="q"><span class="label">Q:</span><?= e($r['prompt'] ?: '(空)') ?></div>
                    <?php if ($r['reply']): ?>
                    <div class="a"><span class="label">A:</span><?= e($r['reply']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="stats">
                    <div class="tokens"><?= (int) $r['tokens'] ?> tokens</div>
                    <div class="cost">-<?= (int) $r['credits_cost'] ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- 分页 -->
    <?php if ($pages > 1): ?>
    <nav class="pages" style="margin-top:20px;">
        <?php if ($page > 1): ?>
            <a class="page-btn" href="/admin/chat_records?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>">上一页</a>
        <?php endif; ?>
        <span style="display:flex;align-items:center;padding:0 12px;font-size:13px;color:var(--text-muted);"><?= $page ?> / <?= $pages ?></span>
        <?php if ($page < $pages): ?>
            <a class="page-btn" href="/admin/chat_records?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>">下一页</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
</main>
<?php render_footer(); ?>
