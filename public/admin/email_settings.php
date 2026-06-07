<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/mail.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        set_app_setting('smtp_host', trim((string) ($_POST['smtp_host'] ?? '')));
        set_app_setting('smtp_port', (string) max(1, (int) ($_POST['smtp_port'] ?? 465)));
        set_app_setting('smtp_username', trim((string) ($_POST['smtp_username'] ?? '')));
        set_app_setting('smtp_encryption', (string) ($_POST['smtp_encryption'] ?? 'ssl'));
        set_app_setting('smtp_from_email', trim((string) ($_POST['smtp_from_email'] ?? '')));
        set_app_setting('smtp_from_name', trim((string) ($_POST['smtp_from_name'] ?? '')));
        $newPassword = trim((string) ($_POST['smtp_password'] ?? ''));
        if ($newPassword !== '') set_app_setting('smtp_password', $newPassword);
        flash('success', '邮件配置已保存。');
        redirect('/admin/email_settings');
    }

    if ($action === 'test') {
        $testEmail = trim((string) ($_POST['test_email'] ?? ''));
        if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            flash('error', '请输入有效的测试邮箱。');
            redirect('/admin/email_settings');
        }
        $platformName = platform_name();
        $result = send_mail($testEmail, $platformName . ' - 邮件发送测试',
            '<h3>邮件发送测试</h3><p>如果您收到此邮件，说明 SMTP 配置正确，邮件发送功能正常。</p>');
        if ($result['ok']) {
            flash('success', '测试邮件已发送到 ' . e($testEmail) . '，请查收。');
        } else {
            flash('error', '测试邮件发送失败：' . $result['message']);
        }
        redirect('/admin/email_settings');
    }
    flash('error', '未知操作。');
    redirect('/admin/email_settings');
}

$smtpHost       = app_setting('smtp_host', 'smtp.qq.com');
$smtpPort       = app_setting('smtp_port', '465');
$smtpUsername   = app_setting('smtp_username', '');
$smtpEncryption = app_setting('smtp_encryption', 'ssl');
$smtpFromEmail  = app_setting('smtp_from_email', '');
$smtpFromName   = app_setting('smtp_from_name', '');
$hasPassword    = app_setting('smtp_password', '') !== '';
$isConfigured   = $smtpUsername !== '' && $hasPassword;

render_header('邮件配置', 'admin');
render_admin_nav('email_settings');
?>
<style>
.email-page { max-width: 760px; margin: 0 auto; }

.email-status {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 20px; border-radius: 14px; margin-bottom: 24px;
    font-size: 14px; font-weight: 600;
}
.email-status.ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
.email-status.pending { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
.email-status-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.email-status.ok .email-status-dot { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
.email-status.pending .email-status-dot { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.2); }

.email-section {
    background: var(--card-bg); border: 1px solid var(--line);
    border-radius: 16px; margin-bottom: 20px; overflow: hidden;
}
.email-section-header {
    display: flex; align-items: center; gap: 12px;
    padding: 18px 24px; border-bottom: 1px solid var(--line);
    background: var(--main-surface-soft);
}
.email-section-header .icon {
    display: flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; border-radius: 10px; font-size: 16px; flex-shrink: 0;
}
.icon-blue   { background: #dbeafe; color: #2563eb; }
.icon-green  { background: #d1fae5; color: #059669; }
.icon-purple { background: #ede9fe; color: #7c3aed; }
.icon-amber  { background: #fef3c7; color: #d97706; }
.email-section-header h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--text); }
.email-section-header p { margin: 2px 0 0; font-size: 12px; color: var(--text-muted); }
.email-section-body { padding: 24px; }

.email-field { margin-bottom: 18px; }
.email-field:last-child { margin-bottom: 0; }
.email-field label {
    display: block; font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 6px;
}
.email-field input,
.email-field select {
    width: 100%; padding: 10px 14px; font-size: 14px;
    border: 1px solid var(--line); border-radius: 10px;
    background: var(--main-surface); color: var(--text);
    box-sizing: border-box; transition: border-color .2s, box-shadow .2s;
}
.email-field select { cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }
.email-field input:focus,
.email-field select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow); }
.email-field .hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.6; }
.email-field .hint code { background: var(--main-surface-soft); padding: 1px 6px; border-radius: 4px; border: 1px solid var(--line); font-size: 11px; }

.email-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 640px) { .email-grid { grid-template-columns: 1fr; } }
.email-grid .email-field { margin-bottom: 0; }

.email-tip {
    margin-top: 18px; padding: 14px 18px;
    background: #fef9c3; border: 1px solid #fde68a; border-radius: 10px;
    font-size: 13px; color: #854d0e; line-height: 1.7;
}
.email-tip strong { color: #713f12; }

/* test section */
.email-test-row {
    display: flex; gap: 10px; align-items: flex-end;
}
.email-test-row .email-field { flex: 1; }

.email-submit { display: flex; justify-content: flex-end; padding-top: 4px; }
.email-btn {
    padding: 12px 32px; font-size: 15px; font-weight: 700;
    background: var(--primary); color: #fff; border: none;
    border-radius: 12px; cursor: pointer; transition: opacity .15s, transform .15s;
}
.email-btn:hover { opacity: .9; transform: translateY(-1px); }
.email-btn.test { background: #fff; color: var(--primary); border: 1.5px solid var(--primary); font-size: 13px; padding: 9px 20px; }
.email-btn.test:hover { background: var(--primary-glow); }
</style>

<main class="email-page">
    <div class="email-status <?= $isConfigured ? 'ok' : 'pending' ?>">
        <span class="email-status-dot"></span>
        <span><?= $isConfigured ? 'SMTP 已配置' : 'SMTP 尚未完成配置' ?></span>
        <?php if ($isConfigured): ?>
        <span style="font-weight:400;opacity:.8;">— 邮件发送功能可用，请测试验证</span>
        <?php endif; ?>
    </div>

    <!-- 保存配置 -->
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">

        <!-- 服务器 -->
        <div class="email-section">
            <div class="email-section-header">
                <div class="icon icon-blue">🖥️</div>
                <div>
                    <h3>SMTP 服务器</h3>
                    <p>邮件发送服务器连接信息</p>
                </div>
            </div>
            <div class="email-section-body">
                <div class="email-grid">
                    <div class="email-field">
                        <label>SMTP 服务器地址</label>
                        <input name="smtp_host" value="<?= e($smtpHost) ?>" placeholder="smtp.qq.com" required>
                    </div>
                    <div class="email-field">
                        <label>端口</label>
                        <input name="smtp_port" type="number" value="<?= e($smtpPort) ?>" placeholder="465" required>
                    </div>
                </div>
                <div class="email-field">
                    <label>加密方式</label>
                    <select name="smtp_encryption">
                        <option value="ssl" <?= $smtpEncryption === 'ssl' ? 'selected' : '' ?>>SSL（端口 465）</option>
                        <option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>TLS（端口 587）</option>
                        <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>>无加密</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- 身份验证 -->
        <div class="email-section">
            <div class="email-section-header">
                <div class="icon icon-green">🔐</div>
                <div>
                    <h3>身份验证</h3>
                    <p>SMTP 登录凭据</p>
                </div>
            </div>
            <div class="email-section-body">
                <div class="email-grid">
                    <div class="email-field">
                        <label>SMTP 用户名</label>
                        <input name="smtp_username" value="<?= e($smtpUsername) ?>" placeholder="your@qq.com" required>
                    </div>
                    <div class="email-field">
                        <label>SMTP 密码 <?= $hasPassword ? '<span style="color:#059669;font-size:11px;">（已配置）</span>' : '' ?></label>
                        <input name="smtp_password" type="password" autocomplete="off" placeholder="<?= $hasPassword ? '已配置，留空不修改' : '授权码' ?>">
                    </div>
                </div>
                <?php if ($smtpHost === 'smtp.qq.com'): ?>
                <div class="email-tip">
                    💡 QQ邮箱需使用 <strong>授权码</strong> 而非登录密码。<br>
                    获取方式：QQ邮箱 → 设置 → 账户 → 开启 <strong>POP3/SMTP服务</strong> → 生成授权码
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 发件人 -->
        <div class="email-section">
            <div class="email-section-header">
                <div class="icon icon-purple">📧</div>
                <div>
                    <h3>发件人信息</h3>
                    <p>收件方看到的发件人名称和邮箱</p>
                </div>
            </div>
            <div class="email-section-body">
                <div class="email-grid">
                    <div class="email-field">
                        <label>发件人邮箱</label>
                        <input name="smtp_from_email" value="<?= e($smtpFromEmail) ?>" placeholder="noreply@yourdomain.com" required>
                    </div>
                    <div class="email-field">
                        <label>发件人名称</label>
                        <input name="smtp_from_name" value="<?= e($smtpFromName ?: platform_name()) ?>" placeholder="<?= e(platform_name()) ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="email-submit">
            <button type="submit" class="email-btn">💾 保存配置</button>
        </div>
    </form>

    <!-- 测试发送 -->
    <form method="post">
        <input type="hidden" name="action" value="test">
        <?= csrf_field() ?>
        <div class="email-section" style="margin-top:8px;">
            <div class="email-section-header">
                <div class="icon icon-amber">🧪</div>
                <div>
                    <h3>测试发送</h3>
                    <p>保存配置后，发送一封测试邮件验证配置是否正确</p>
                </div>
            </div>
            <div class="email-section-body">
                <div class="email-test-row">
                    <div class="email-field">
                        <label>测试邮箱地址</label>
                        <input name="test_email" type="email" placeholder="输入接收测试邮件的邮箱" required>
                    </div>
                    <button type="submit" class="email-btn test">发送测试</button>
                </div>
            </div>
        </div>
    </form>
</main>
<?php render_footer(); ?>
