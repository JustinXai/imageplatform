<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';
require_once __DIR__ . '/../../src/pay.php';

$admin = require_admin();
ensure_all_tables();

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$keyword = trim((string) ($_GET['q'] ?? ''));

$allowedStatuses = ['all', 'pending', 'paid', 'failed', 'refunded'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'all';

$where = []; $params = [];
if ($statusFilter !== 'all') { $where[] = 'o.status = ?'; $params[] = $statusFilter; }
if ($keyword !== '') { $where[] = '(o.order_no LIKE ? OR o.package_name LIKE ? OR u.username LIKE ?)'; $like = '%'.$keyword.'%'; $params = array_merge($params, [$like, $like, $like]); }
$whereSql = $where ? ' WHERE '.implode(' AND ', $where) : '';

$totalStmt = db()->prepare('SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u.id=o.user_id'.$whereSql);
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) { $q = http_build_query(['q'=>$keyword,'status'=>$statusFilter,'page'=>$totalPages]); redirect('/admin/orders?'.$q); }
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare('SELECT o.*, u.username FROM orders o LEFT JOIN users u ON u.id=o.user_id'.$whereSql.' ORDER BY o.created_at DESC LIMIT ? OFFSET ?');
$i = 1; foreach ($params as $p) $stmt->bindValue($i++, $p);
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT); $stmt->bindValue($i, $offset, PDO::PARAM_INT);
$stmt->execute(); $orders = $stmt->fetchAll();
$payTypes = pay_type_list();
$baseQuery = ['q' => $keyword, 'status' => $statusFilter];

$stats = db()->query(
    "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) AS revenue,
     COALESCE(SUM(CASE WHEN status='paid' THEN credits ELSE 0 END),0) AS sold,
     COUNT(CASE WHEN status='pending' THEN 1 END) AS pending FROM orders"
)->fetch();

render_header('订单管理', 'admin');
render_admin_nav('orders');
?>
<style>
.order-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 20px; }
.order-stat { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
.order-stat .n { font-size: 26px; font-weight: 800; color: var(--text); }
.order-stat .l { font-size: 11px; color: var(--text-muted); font-weight: 600; letter-spacing: .5px; margin-top: 2px; }
.filter-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
.filter-row input, .filter-row select { padding: 7px 12px; border: 1px solid var(--line); border-radius: 10px; font-size: 13px; background: var(--main-surface); }
.filter-row input:focus, .filter-row select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
.filter-row input { flex: 1; max-width: 240px; }
.filter-row .tabs { display: flex; gap: 4px; }
.filter-row .tab { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 500; text-decoration: none; border: 1px solid var(--line); color: var(--text-muted); background: var(--main-surface); transition: all .12s; }
.filter-row .tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.order-list { display: flex; flex-direction: column; gap: 8px; }
.order-card { display: flex; flex-wrap: wrap; gap: 12px; padding: 14px 18px; background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; align-items: center; transition: all .12s; }
.order-card:hover { border-color: var(--primary-soft); }
.order-card .id { font-family: monospace; font-size: 12px; color: var(--text-muted); min-width: 160px; word-break: break-all; }
.order-card .id span { display: block; font-size: 10px; margin-top: 2px; }
.order-card .user { font-size: 13px; font-weight: 600; min-width: 80px; }
.order-card .pkg { font-size: 13px; min-width: 100px; }
.order-card .amt { font-size: 15px; font-weight: 700; min-width: 70px; text-align: right; }
.order-card .detail { font-size: 11px; color: var(--text-muted); min-width: 60px; text-align: right; }
.order-card .status-col { min-width: 70px; text-align: center; }
.order-card .time { font-size: 11px; color: var(--text-muted); min-width: 100px; text-align: right; }
</style>
<main>
    <div class="page-hd">
        <div><h1>订单管理</h1><p>Orders</p></div>
    </div>

    <div class="order-stats">
        <div class="order-stat"><div class="n"><?= (int) $stats['total'] ?></div><div class="l">总订单</div></div>
        <div class="order-stat"><div class="n">¥<?= number_format((float) $stats['revenue'], 2) ?></div><div class="l">总收入</div></div>
        <div class="order-stat"><div class="n"><?= number_format((int) $stats['sold']) ?></div><div class="l">已售<?= e(balance_label()) ?></div></div>
        <div class="order-stat"><div class="n"><?= (int) $stats['pending'] ?></div><div class="l">待处理</div></div>
    </div>

    <form method="get" class="filter-row">
        <div class="tabs">
            <a href="/admin/orders<?= $keyword?'?q='.urlencode($keyword):'' ?>" class="tab <?= $statusFilter==='all' ? 'active' : '' ?>">全部</a>
            <a href="/admin/orders?status=pending<?= $keyword?'&q='.urlencode($keyword):'' ?>" class="tab <?= $statusFilter==='pending' ? 'active' : '' ?>">待支付</a>
            <a href="/admin/orders?status=paid<?= $keyword?'&q='.urlencode($keyword):'' ?>" class="tab <?= $statusFilter==='paid' ? 'active' : '' ?>">已支付</a>
            <a href="/admin/orders?status=failed<?= $keyword?'&q='.urlencode($keyword):'' ?>" class="tab <?= $statusFilter==='failed' ? 'active' : '' ?>">失败</a>
            <a href="/admin/orders?status=refunded<?= $keyword?'&q='.urlencode($keyword):'' ?>" class="tab <?= $statusFilter==='refunded' ? 'active' : '' ?>">已退款</a>
        </div>
        <input name="q" value="<?= e($keyword) ?>" placeholder="搜索订单号/套餐/用户...">
        <button type="submit" class="btn btn-primary btn-sm">搜索</button>
        <?php if ($keyword !== '' || $statusFilter !== 'all'): ?>
        <a href="/admin/orders" class="btn btn-secondary btn-sm">重置</a>
        <?php endif; ?>
        <span style="margin-left:auto;font-size:13px;color:var(--text-muted);">共 <?= $total ?> 条</span>
    </form>

    <?php if (!$orders): ?>
        <div class="empty-state"><div class="icon">📦</div><h3>暂无订单</h3></div>
    <?php else: ?>
        <div class="order-list">
            <?php foreach ($orders as $o):
                $s = (string) $o['status'];
                $sl = ['pending'=>'待支付','paid'=>'已支付','failed'=>'失败','refunded'=>'已退款'][$s] ?? $s;
                $sc = ['pending'=>'running','paid'=>'succeeded','failed'=>'failed','refunded'=>'deleted'][$s] ?? '';
                $pt = (string) ($o['pay_type'] ?? '');
            ?>
            <div class="order-card">
                <div class="id">
                    <?= e($o['order_no']) ?>
                    <?php if ($o['trade_no']): ?><span>交易号: <?= e($o['trade_no']) ?></span><?php endif; ?>
                </div>
                <div class="user"><?= e($o['username'] ?? '-') ?></div>
                <div class="pkg"><?= e($o['package_name']) ?></div>
                <div class="detail">
                    <?= number_format((int) $o['credits']) ?> <?= e(balance_label()) ?><br>
                    <?= e($payTypes[$pt] ?? ($pt ?: '未知')) ?>
                </div>
                <div class="amt">¥<?= number_format((float) $o['amount'], 2) ?></div>
                <div class="status-col"><span class="status-badge <?= $sc ?>"><?= e($sl) ?></span></div>
                <div class="time">
                    <?= e($o['paid_at'] ?: $o['created_at']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <nav class="pages" style="margin-top:20px;">
        <a class="page-btn <?= $page<=1?'active':'' ?>" href="<?= $page>1?'/admin/orders?'.http_build_query($baseQuery+['page'=>$page-1]):'#' ?>">上一页</a>
        <span><?= $page ?> / <?= $totalPages ?></span>
        <a class="page-btn <?= $page>=$totalPages?'active':'' ?>" href="<?= $page<$totalPages?'/admin/orders?'.http_build_query($baseQuery+['page'=>$page+1]):'#' ?>">下一页</a>
    </nav>
    <?php endif; ?>
</main>
<?php render_footer(); ?>
