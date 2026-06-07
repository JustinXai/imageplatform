<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$admin = require_admin();

function admin_users_redirect(int $page): void { redirect('/admin/users?page=' . max(1, $page)); }
function admin_user_by_id(int $userId): ?array {
    $stmt = db()->prepare('SELECT id, username, role, credits, is_active, email, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]); return $stmt->fetch() ?: null;
}
function active_admin_count(): int {
    return (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "admin" AND is_active = 1')->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) ($_POST['user_id'] ?? 0);
    $page   = max(1, (int) ($_POST['page'] ?? 1));
    $target = $userId > 0 ? admin_user_by_id($userId) : null;
    if (!$target) { flash('error', '用户不存在。'); admin_users_redirect($page); }
    $isSelf = (int) $target['id'] === (int) $admin['id'];

    if ($action === 'toggle_active') {
        $newStatus = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
        if ($isSelf && $newStatus === 0) { flash('error', '不能封禁自己。'); admin_users_redirect($page); }
        if ($newStatus === 0 && $target['role'] === 'admin' && active_admin_count() <= 1) { flash('error', '至少保留一个管理员。'); admin_users_redirect($page); }
        db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$newStatus, $userId]);
        flash('success', $newStatus ? '已解封。' : '已封禁。');
        admin_users_redirect($page);
    }
    if ($action === 'update_credits') {
        $credits = max(0, (int) ($_POST['credits'] ?? 0));
        db()->prepare('UPDATE users SET credits = ? WHERE id = ?')->execute([$credits, $userId]);
        flash('success', balance_label() . '已更新。'); admin_users_redirect($page);
    }
    if ($action === 'reset_password') {
        $password = trim((string) ($_POST['password'] ?? ''));
        $generated = false;
        if ($password === '') { $password = bin2hex(random_bytes(6)); $generated = true; }
        if (strlen($password) < 6) { flash('error', '密码至少 6 位。'); admin_users_redirect($page); }
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        flash('success', $generated ? '新密码：' . $password : '密码已重置。');
        admin_users_redirect($page);
    }
    if ($action === 'delete') {
        if ($isSelf) { flash('error', '不能删除自己。'); admin_users_redirect($page); }
        if ($target['role'] === 'admin' && active_admin_count() <= 1) { flash('error', '至少保留一个管理员。'); admin_users_redirect($page); }
        db()->prepare('DELETE FROM generation_records WHERE user_id = ?')->execute([$userId]);
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        flash('success', '用户及记录已删除。'); admin_users_redirect($page);
    }
    flash('error', '未知操作。'); admin_users_redirect($page);
}

$stats = [
    'total'    => (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'active'   => (int) db()->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn(),
    'inactive' => (int) db()->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn(),
    'admins'   => (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn(),
    'credits'  => (int) db()->query('SELECT COALESCE(SUM(credits), 0) FROM users')->fetchColumn(),
];

$search = trim((string) ($_GET['search'] ?? ''));
$where = ''; $params = [];
if ($search !== '') { $where = ' WHERE u.username LIKE ? OR u.email LIKE ?'; $p = '%'.$search.'%'; $params = [$p, $p]; }

$perPage = 20; $page = max(1, (int) ($_GET['page'] ?? 1));
$countStmt = db()->prepare('SELECT COUNT(*) FROM users u'.$where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) redirect('/admin/users?page='.$totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT u.*, COUNT(r.id) AS gen_count,
        COALESCE(SUM(CASE WHEN r.status="succeeded" THEN 1 ELSE 0 END),0) AS ok,
        COALESCE(SUM(CASE WHEN r.status="failed" THEN 1 ELSE 0 END),0) AS fail
     FROM users u LEFT JOIN generation_records r ON r.user_id=u.id'.$where.
     ' GROUP BY u.id ORDER BY u.created_at DESC LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);
$i = 1; foreach ($params as $p) $stmt->bindValue($i++, $p);
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT); $stmt->bindValue($i, $offset, PDO::PARAM_INT);
$stmt->execute(); $users = $stmt->fetchAll();

render_header('用户管理', 'admin');
render_admin_nav('users');
?>
<style>
.user-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 20px; }
.user-stat { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
.user-stat .n { font-size: 26px; font-weight: 800; color: var(--text); }
.user-stat .l { font-size: 11px; color: var(--text-muted); font-weight: 600; letter-spacing: .5px; margin-top: 2px; }
.user-search { display: flex; gap: 8px; margin-bottom: 16px; }
.user-search input { flex: 1; max-width: 280px; padding: 8px 14px; border: 1px solid var(--line); border-radius: 10px; font-size: 13px; background: var(--main-surface); }
.user-search input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
.user-list { display: flex; flex-direction: column; gap: 10px; }
.user-card { background: var(--main-surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; transition: all .12s; }
.user-card:hover { border-color: var(--primary-soft); }
.user-card.banned { opacity: .55; }
.user-card .top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.user-card .info { flex: 1; min-width: 0; }
.user-card .info .name { font-size: 15px; font-weight: 700; color: var(--text); }
.user-card .info .name .role { font-size: 10px; background: var(--primary-soft); color: var(--primary); padding: 2px 8px; border-radius: 10px; margin-left: 8px; font-weight: 600; }
.user-card .info .email { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.user-card .stats-row { display: flex; gap: 16px; margin-top: 10px; flex-wrap: wrap; }
.user-card .stats-row .stat { font-size: 12px; color: var(--text-muted); }
.user-card .stats-row .stat b { color: var(--text); }
.user-card .actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.user-card .actions input[type=number] { width: 80px; padding: 5px 8px; border: 1px solid var(--line); border-radius: 8px; font-size: 13px; background: var(--main-surface); }
.user-card .actions input[type=text] { width: 110px; padding: 5px 8px; border: 1px solid var(--line); border-radius: 8px; font-size: 12px; background: var(--main-surface); }
</style>
<main>
    <div class="page-hd">
        <div><h1>用户管理</h1><p>Users</p></div>
    </div>

    <div class="user-stats">
        <div class="user-stat"><div class="n"><?= $stats['total'] ?></div><div class="l">总用户</div></div>
        <div class="user-stat"><div class="n"><?= $stats['active'] ?></div><div class="l">活跃</div></div>
        <div class="user-stat"><div class="n"><?= $stats['inactive'] ?></div><div class="l">已封禁</div></div>
        <div class="user-stat"><div class="n"><?= $stats['admins'] ?></div><div class="l">管理员</div></div>
        <div class="user-stat"><div class="n"><?= number_format($stats['credits']) ?></div><div class="l">总<?= e(balance_label()) ?></div></div>
    </div>

    <form method="get" class="user-search">
        <input name="search" value="<?= e($search) ?>" placeholder="搜索用户名或邮箱...">
        <button type="submit" class="btn btn-primary btn-sm">搜索</button>
        <?php if ($search): ?><a href="/admin/users" class="btn btn-secondary btn-sm">清除</a><?php endif; ?>
        <span style="margin-left:auto;font-size:13px;color:var(--text-muted);align-self:center;">共 <?= $total ?> 个</span>
    </form>

    <?php if (!$users): ?>
        <div class="empty-state"><div class="icon">👥</div><h3>暂无用户</h3></div>
    <?php else: ?>
        <div class="user-list">
            <?php foreach ($users as $u):
                $uid = (int) $u['id']; $isSelf = $uid === (int) $admin['id']; $active = (int) $u['is_active'] === 1;
            ?>
            <div class="user-card <?= !$active ? 'banned' : '' ?>">
                <div class="top">
                    <div class="info">
                        <span class="name">
                            <?= e($u['username']) ?>
                            <?php if ($u['role'] === 'admin'): ?><span class="role">管理员</span><?php endif; ?>
                            <span class="status-badge <?= $active ? 'succeeded' : 'deleted' ?>" style="font-size:10px;"><?= $active ? '正常' : '已封禁' ?></span>
                        </span>
                        <div class="email"><?= e($u['email'] ?: '未填写邮箱') ?> · 注册于 <?= e($u['created_at']) ?></div>
                        <div class="stats-row">
                            <span class="stat"><?= e(balance_label()) ?>: <b><?= number_format((int) $u['credits']) ?></b></span>
                            <span class="stat">生成: <b><?= (int) $u['gen_count'] ?></b></span>
                            <span class="stat">成功: <b><?= (int) $u['ok'] ?></b></span>
                            <span class="stat">失败: <b><?= (int) $u['fail'] ?></b></span>
                        </div>
                    </div>
                    <div class="actions">
                        <form method="post" style="display:flex;gap:4px;align-items:center;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_credits">
                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                            <input type="hidden" name="page" value="<?= $page ?>">
                            <input type="number" name="credits" min="0" value="<?= (int) $u['credits'] ?>" required>
                            <button type="submit" class="btn btn-secondary btn-sm">保存</button>
                        </form>
                        <form method="post" style="display:flex;gap:4px;align-items:center;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                            <input type="hidden" name="page" value="<?= $page ?>">
                            <input type="text" name="password" placeholder="留空随机" minlength="6">
                            <button type="submit" class="btn btn-secondary btn-sm">重置密码</button>
                        </form>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                            <input type="hidden" name="page" value="<?= $page ?>">
                            <input type="hidden" name="is_active" value="<?= $active ? 0 : 1 ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" <?= $isSelf && $active ? 'disabled' : '' ?>><?= $active ? '封禁' : '解封' ?></button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('确认删除？')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                            <input type="hidden" name="page" value="<?= $page ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--danger);" <?= $isSelf ? 'disabled' : '' ?>>删除</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <nav class="pages" style="margin-top:20px;">
        <a class="page-btn <?= $page <= 1 ? 'active' : '' ?>" href="<?= $page > 1 ? '/admin/users?page='.($page-1).($search?'&search='.urlencode($search):'') : '#' ?>">上一页</a>
        <span><?= $page ?> / <?= $totalPages ?></span>
        <a class="page-btn <?= $page >= $totalPages ? 'active' : '' ?>" href="<?= $page < $totalPages ? '/admin/users?page='.($page+1).($search?'&search='.urlencode($search):'') : '#' ?>">下一页</a>
    </nav>
    <?php endif; ?>
</main>
<?php render_footer(); ?>
