<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $passwordHash = (string) $stmt->fetchColumn();

    if (!password_verify($currentPassword, $passwordHash)) {
        flash('error', '当前密码不正确。');
        redirect('/user/change_password');
    }
    if (strlen($newPassword) < 8) {
        flash('error', '新密码至少 8 位。');
        redirect('/user/change_password');
    }
    if ($newPassword !== $confirmPassword) {
        flash('error', '两次输入的新密码不一致。');
        redirect('/user/change_password');
    }
    if (password_verify($newPassword, $passwordHash)) {
        flash('error', '新密码不能与当前密码相同。');
        redirect('/user/change_password');
    }

    $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
    session_regenerate_id(true);

    flash('success', '密码已更新。');
    redirect('/user/change_password');
}

render_header('修改密码', 'app');
?>
<main class="auth-wrap">
    <section class="card auth-card">
        <div class="auth-card-head">
            <p class="eyebrow">Account</p>
            <h2>修改密码</h2>
            <span>更新后请使用新密码登录。</span>
        </div>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <label class="field">
                <span>当前密码</span>
                <input name="current_password" type="password" autocomplete="current-password" required>
            </label>
            <label class="field">
                <span>新密码</span>
                <input name="new_password" type="password" autocomplete="new-password" minlength="8" required>
            </label>
            <label class="field">
                <span>确认新密码</span>
                <input name="confirm_password" type="password" autocomplete="new-password" minlength="8" required>
            </label>
            <button class="button primary" type="submit">保存新密码</button>
            <a class="button secondary" href="/user/index">返回生成</a>
        </form>
    </section>
</main>
<?php render_footer(); ?>
