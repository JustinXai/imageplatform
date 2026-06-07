<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$user = require_login();

ensure_credit_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $redirectTo = (string) ($_POST['redirect_to'] ?? '/index');
    if ($redirectTo === '' || strpos($redirectTo, '/') !== 0 || strpos($redirectTo, '//') === 0) {
        $redirectTo = '/index';
    }

    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    if ($code === '') {
        flash('error', '请输入兑换码。');
        redirect($redirectTo);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM credit_codes WHERE code = ? FOR UPDATE');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        if (!$row || (int) $row['is_active'] !== 1) {
            throw new RuntimeException('兑换码无效。');
        }
        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            throw new RuntimeException('兑换码已过期。');
        }
        if ((int) $row['used_count'] >= (int) $row['max_uses']) {
            throw new RuntimeException('兑换码已被使用完。');
        }

        $stmt = $pdo->prepare('INSERT INTO credit_redemptions (code_id, user_id, credits) VALUES (?, ?, ?)');
        $stmt->execute([$row['id'], $user['id'], $row['credits']]);
        $stmt = $pdo->prepare('UPDATE credit_codes SET used_count = used_count + 1 WHERE id = ?');
        $stmt->execute([$row['id']]);
        $stmt = $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
        $stmt->execute([$row['credits'], $user['id']]);
        $pdo->commit();

        // 清除用户 Session 缓存，确保下次请求显示最新余额
        unset($_SESSION['user_cache_' . $user['id']]);

        flash('success', '兑换成功，已增加 ' . (int) $row['credits'] . ' ' . balance_label() . '。');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('error', $e->getMessage());
    }

    redirect($redirectTo);
}

render_header('兑换' . balance_label(), 'app');
?>
<main class="auth-wrap">
    <section class="card auth-card">
        <h2>兑换<?= e(balance_label()) ?></h2>
        <p class="muted">当前<?= e(balance_label()) ?>：<?= (int) $user['credits'] ?></p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="redirect_to" value="/index">
            <label class="field">
                <span>兑换码</span>
                <input name="code" placeholder="例如 ABCD1234EFGH5678" required>
            </label>
            <button class="button primary" type="submit">立即兑换</button>
        </form>
    </section>
</main>
<?php render_footer(); ?>
