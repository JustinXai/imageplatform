<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/migration.php';

ensure_auth_features();

$token = trim((string) ($_GET['token'] ?? ''));
$verified = false;
$message = '';

if ($token === '') {
    $message = '验证链接无效。';
} else {
    $stmt = db()->prepare('SELECT id, username, email FROM users WHERE email_verify_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user && is_array($user)) {
        $stmt = db()->prepare(
            'UPDATE users SET email_verified_at = NOW(), email_verify_token = NULL WHERE id = ?'
        );
        $stmt->execute([(int) $user['id']]);
        $verified = true;
        $message = '邮箱验证成功！您现在可以登录了。';
    } else {
        $message = '验证链接无效或已过期。如果您已经验证过邮箱，请直接登录。如未收到验证邮件，请联系管理员。';
    }
}

$platformName = platform_name();
render_header('验证邮箱', 'auth');
?>
<style>
    .verify-wrap {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 24px;
        text-align: center;
        font-family: 'Inter', 'PingFang SC', 'Microsoft YaHei', sans-serif;
        background: #f9fafb;
    }
    .verify-card {
        max-width: 420px;
        width: 100%;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        padding: 40px 32px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.06);
    }
    .verify-card .icon { font-size: 56px; line-height: 1; margin-bottom: 12px; }
    .verify-card h2 { font-size: 20px; font-weight: 700; margin: 0 0 8px; color: #111; }
    .verify-card p { color: #6b7280; font-size: 14px; margin: 0 0 28px; line-height: 1.6; }
    .verify-btn {
        display: inline-block;
        padding: 12px 32px;
        border-radius: 8px;
        background: #111;
        color: #fff;
        font-weight: 600;
        font-size: 15px;
        text-decoration: none;
        transition: background 0.2s;
    }
    .verify-btn:hover { background: #333; }
    .verify-logo {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 18px;
        font-weight: 700;
        color: #111;
        margin-bottom: 32px;
    }
    .verify-logo-icon {
        width: 32px; height: 32px;
        background: #111;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        color: #fff;
    }
</style>
<main class="verify-wrap">
    <div class="verify-logo">
        <span class="verify-logo-icon">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3"><polyline points="9 18 15 12 9 6"/></svg>
        </span>
        <?= e($platformName) ?>
    </div>
    <div class="verify-card">
        <div class="icon"><?= $verified ? '✅' : '❌' ?></div>
        <h2><?= $verified ? '邮箱验证成功' : '验证链接无效' ?></h2>
        <p><?= e($message) ?></p>
        <a class="verify-btn" href="/login"><?= $verified ? '前往登录' : '返回登录' ?></a>
    </div>
</main>
<?php render_footer(); ?>
