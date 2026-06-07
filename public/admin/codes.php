<?php



require_once __DIR__ . '/../../src/bootstrap.php';

require_once __DIR__ . '/../../src/layout.php';



$admin = require_admin();

ensure_credit_tables();



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $credits = max(1, (int) ($_POST['credits'] ?? 1));

    $maxUses = max(1, (int) ($_POST['max_uses'] ?? 1));

    $count = max(1, min(100, (int) ($_POST['count'] ?? 1)));

    $expiresAt = trim((string) ($_POST['expires_at'] ?? '')) ?: null;

    if ($expiresAt !== null) {

        $expiresAt = str_replace('T', ' ', $expiresAt) . ':00';

    }



    $created = [];

    $stmt = db()->prepare(

        'INSERT INTO credit_codes (code, credits, max_uses, expires_at, created_by) VALUES (?, ?, ?, ?, ?)'

    );



    for ($i = 0; $i < $count; $i++) {

        do {

            $code = random_code(16);

            try {

                $stmt->execute([$code, $credits, $maxUses, $expiresAt, $admin['id']]);

                $created[] = $code;

                break;

            } catch (PDOException $e) {

                $code = null;

            }

        } while ($code === null);

    }



    flash('success', '已生成兑换码：' . implode('，', $created));

    redirect('/admin/codes');

}



$perPage = 30;

$page = max(1, (int) ($_GET['page'] ?? 1));

$keyword = trim((string) ($_GET['q'] ?? ''));

$status = (string) ($_GET['status'] ?? 'all');

$allowedStatuses = ['all', 'available', 'unused', 'used', 'used_up', 'expired', 'disabled'];

if (!in_array($status, $allowedStatuses, true)) {

    $status = 'all';

}



$where = [];

$params = [];

if ($keyword !== '') {

    $where[] = '(c.code LIKE ? OR u.username LIKE ?)';

    $like = '%' . $keyword . '%';

    $params[] = $like;

    $params[] = $like;

}



if ($status === 'available') {

    $where[] = 'c.is_active = 1 AND (c.expires_at IS NULL OR c.expires_at >= NOW()) AND c.used_count < c.max_uses';

} elseif ($status === 'unused') {

    $where[] = 'c.used_count = 0';

} elseif ($status === 'used') {

    $where[] = 'c.used_count > 0';

} elseif ($status === 'used_up') {

    $where[] = 'c.used_count >= c.max_uses';

} elseif ($status === 'expired') {

    $where[] = 'c.expires_at IS NOT NULL AND c.expires_at < NOW()';

} elseif ($status === 'disabled') {

    $where[] = 'c.is_active = 0';

}



$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$countStmt = db()->prepare(

    'SELECT COUNT(*)

     FROM credit_codes c

     LEFT JOIN users u ON u.id = c.created_by' . $whereSql

);

$countStmt->execute($params);

$total = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($total / $perPage));

if ($page > $totalPages) {

    $query = http_build_query(['q' => $keyword, 'status' => $status, 'page' => $totalPages]);

    redirect('/admin/codes?' . $query);

}

$offset = ($page - 1) * $perPage;



$listStmt = db()->prepare(

    'SELECT c.*, u.username AS created_by_name

     FROM credit_codes c

     LEFT JOIN users u ON u.id = c.created_by' . $whereSql . '

     ORDER BY c.created_at DESC, c.id DESC

     LIMIT ? OFFSET ?'

);

$bindIndex = 1;

foreach ($params as $param) {

    $listStmt->bindValue($bindIndex, $param, PDO::PARAM_STR);

    $bindIndex++;

}

$listStmt->bindValue($bindIndex, $perPage, PDO::PARAM_INT);

$listStmt->bindValue($bindIndex + 1, $offset, PDO::PARAM_INT);

$listStmt->execute();

$codes = $listStmt->fetchAll();



$baseQuery = ['q' => $keyword, 'status' => $status];



function code_status_label(array $code): array

{

    if ((int) $code['is_active'] !== 1) {

        return ['已停用', 'deleted'];

    }

    if (!empty($code['expires_at']) && strtotime((string) $code['expires_at']) < time()) {

        return ['已过期', 'failed'];

    }

    if ((int) $code['used_count'] >= (int) $code['max_uses']) {

        return ['已用完', 'running'];

    }

    if ((int) $code['used_count'] === 0) {

        return ['未使用', 'succeeded'];

    }

    return ['可用', 'succeeded'];

}



render_header('兑换码管理', 'admin');

render_admin_nav('codes');

?>

<main class="grid">

    <section class="card code-create-card">

        <div class="card-head">

            <div>

                <p class="eyebrow">Create</p>

                <h2>生成兑换码</h2>

            </div>

        </div>

        <form method="post" class="form code-create-form">

            <?= csrf_field() ?>

            <label class="field">

                <span>每个兑换码增加<?= e(balance_label()) ?></span>

                <input name="credits" type="number" min="1" value="10" required>

            </label>

            <label class="field">

                <span>每个兑换码可使用次数</span>

                <input name="max_uses" type="number" min="1" value="1" required>

            </label>

            <label class="field">

                <span>生成数量</span>

                <input name="count" type="number" min="1" max="100" value="1" required>

            </label>

            <label class="field">

                <span>过期时间</span>

                <input name="expires_at" type="datetime-local">

            </label>

            <button class="button primary" type="submit">生成</button>

        </form>

    </section>



    <section class="card">

        <div class="card-head">

            <div>

                <p class="eyebrow">Codes</p>

                <h2>全部兑换码</h2>

            </div>

            <span class="badge">共 <?= $total ?> 个</span>

        </div>

        <form method="get" class="filter-bar">

            <label class="field">

                <span>搜索</span>

                <input name="q" value="<?= e($keyword) ?>" placeholder="兑换码或创建人">

            </label>

            <label class="field">

                <span>状态</span>

                <select name="status">

                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>全部</option>

                    <option value="available" <?= $status === 'available' ? 'selected' : '' ?>>可用</option>

                    <option value="unused" <?= $status === 'unused' ? 'selected' : '' ?>>未使用</option>

                    <option value="used" <?= $status === 'used' ? 'selected' : '' ?>>已使用</option>

                    <option value="used_up" <?= $status === 'used_up' ? 'selected' : '' ?>>已用完</option>

                    <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>已过期</option>

                    <option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>已停用</option>

                </select>

            </label>

            <div class="filter-actions">

                <button class="button primary small" type="submit">筛选</button>

                <a class="button secondary small" href="/admin/codes">重置</a>

            </div>

        </form>

        <div class="table-wrap">

            <table data-admin-codes>

                <thead>

                    <tr>

                        <th>兑换码</th>

                        <th><?= e(balance_label()) ?></th>

                        <th>使用</th>

                        <th>剩余</th>

                        <th>状态</th>

                        <th>过期</th>

                        <th>创建人</th>

                        <th>创建</th>

                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($codes as $code): ?>

                        <?php $codeStatus = code_status_label($code); ?>

                        <tr>

                            <td><code><?= e($code['code']) ?></code></td>

                            <td><?= (int) $code['credits'] ?></td>

                            <td><?= (int) $code['used_count'] ?> / <?= (int) $code['max_uses'] ?></td>

                            <td><?= max(0, (int) $code['max_uses'] - (int) $code['used_count']) ?></td>

                            <td><span class="status <?= e($codeStatus[1]) ?>"><?= e($codeStatus[0]) ?></span></td>

                            <td><?= e($code['expires_at'] ?: '-') ?></td>

                            <td><?= e($code['created_by_name'] ?: '-') ?></td>

                            <td><?= e($code['created_at']) ?></td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (!$codes): ?>

                        <tr>

                            <td colspan="8" class="muted">暂无兑换码</td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <?php if ($totalPages > 1): ?>

            <nav class="pagination" aria-label="兑换码分页">

                <a class="button secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page > 1 ? ('/admin/codes?' . http_build_query($baseQuery + ['page' => $page - 1])) : '#' ?>">上一页</a>

                <span>第 <?= $page ?> / <?= $totalPages ?> 页，共 <?= $total ?> 个</span>

                <a class="button secondary <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page < $totalPages ? ('/admin/codes?' . http_build_query($baseQuery + ['page' => $page + 1])) : '#' ?>">下一页</a>

            </nav>

        <?php endif; ?>

    </section>

</main>

<?php render_footer(); ?>

