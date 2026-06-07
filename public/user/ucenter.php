<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/mail.php';
require_once __DIR__ . '/../../src/captcha.php';

$user = require_login();
$balanceLabel = balance_label();

// 生成验证码并发送到邮箱
function send_verify_code(string $email, string $action): array
{
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['verify_code'] = $code;
    $_SESSION['verify_code_expires'] = time() + 600; // 10 分钟
    $_SESSION['verify_code_action'] = $action;

    $actionLabels = [
        'change_password' => '修改密码',
        'change_email' => '修改绑定邮箱',
    ];
    $actionLabel = $actionLabels[$action] ?? '安全操作';

    $platformName = platform_name();
    $subject = $platformName . ' - ' . $actionLabel . '验证码';
    $body = '
    <div style="max-width:600px;margin:0 auto;padding:30px 20px;font-family:system-ui,-apple-system,sans-serif;">
        <h2 style="color:#213547;">' . htmlspecialchars($platformName, ENT_QUOTES, 'UTF-8') . '</h2>
        <div style="background:#fff;border:1px solid #dfe7e2;border-radius:12px;padding:30px;margin-top:16px;">
            <h3>' . htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') . '</h3>
            <p style="color:#47566a;">您的验证码为：</p>
            <div style="text-align:center;margin:24px 0;">
                <span style="font-size:36px;font-weight:900;letter-spacing:8px;color:#42b883;">'
                . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span>
            </div>
            <p style="color:#6b7280;font-size:13px;">验证码有效期为 10 分钟，请勿泄露给他人。</p>
        </div>
    </div>';

    return send_mail($email, $subject, $body);
}

// 验证验证码
function verify_code(string $inputCode, string $expectedAction): bool
{
    $storedCode = (string) ($_SESSION['verify_code'] ?? '');
    $expires = (int) ($_SESSION['verify_code_expires'] ?? 0);
    $action = (string) ($_SESSION['verify_code_action'] ?? '');

    if ($action !== $expectedAction || $storedCode === '' || time() > $expires) {
        return false;
    }

    return hash_equals($storedCode, $inputCode);
}

// 清除验证码 session
function clear_verify_code(): void
{
    unset($_SESSION['verify_code'], $_SESSION['verify_code_expires'], $_SESSION['verify_code_action']);
}

// 处理 POST 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['uc_action'] ?? '');

    // === 发送验证码 ===
    if ($action === 'send_code') {
        $targetAction = (string) ($_POST['target_action'] ?? '');
        if (!in_array($targetAction, ['change_password', 'change_email'], true)) {
            flash('error', '参数不合法。');
            redirect('/ucenter');
        }

        if ($targetAction === 'change_email') {
            $newEmail = trim((string) ($_POST['new_email'] ?? ''));
            if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                json_response(['ok' => false, 'message' => '请填写有效的邮箱地址。']);
            }
            if ($newEmail === $user['email']) {
                json_response(['ok' => false, 'message' => '新邮箱与当前邮箱相同。']);
            }
            // 检查邮箱是否已被占用
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $stmt->execute([$newEmail, $user['id']]);
            if ($stmt->fetch()) {
                json_response(['ok' => false, 'message' => '该邮箱已被其他账号使用。']);
            }
            $_SESSION['verify_code_new_email'] = $newEmail;
        }

        if (empty($user['email'])) {
            json_response(['ok' => false, 'message' => '您的账号没有绑定邮箱，无法发送验证码。']);
        }

        $result = send_verify_code($user['email'], $targetAction);

        // 开发调试：也存到 flash 消息里
        if (!$result['ok']) {
            json_response(['ok' => false, 'message' => '验证码发送失败：' . $result['message']]);
        }

        json_response(['ok' => true, 'message' => '验证码已发送到您绑定的邮箱（' . hide_email($user['email']) . '），请查收。']);
    }

    // === 修改密码 ===
    if ($action === 'change_password') {
        $code = trim((string) ($_POST['verify_code'] ?? ''));
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        // 验证码验证
        if (!verify_code($code, 'change_password')) {
            flash('error', '验证码无效或已过期，请重新获取。');
            redirect('/ucenter');
        }

        // 极验 V4
        $captchaCheck = captcha_validate_from_request($_POST);
        if (!$captchaCheck['ok']) {
            clear_verify_code();
            flash('error', $captchaCheck['message']);
            redirect('/ucenter');
        }

        // 验证密码
        if (strlen($newPassword) < 8) {
            flash('error', '新密码至少 8 位。');
            redirect('/ucenter');
        }
        if ($newPassword !== $confirmPassword) {
            flash('error', '两次输入的新密码不一致。');
            redirect('/ucenter');
        }

        // 更新密码
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);

        clear_verify_code();
        flash('success', '密码已更新。');
        redirect('/ucenter');
    }

    // === 修改邮箱 ===
    if ($action === 'change_email') {
        $code = trim((string) ($_POST['verify_code'] ?? ''));
        $newEmail = (string) ($_SESSION['verify_code_new_email'] ?? '');

        // 验证码验证
        if (!verify_code($code, 'change_email')) {
            flash('error', '验证码无效或已过期，请重新获取。');
            redirect('/ucenter');
        }

        // 极验 V4
        $captchaCheck = captcha_validate_from_request($_POST);
        if (!$captchaCheck['ok']) {
            clear_verify_code();
            flash('error', $captchaCheck['message']);
            redirect('/ucenter');
        }

        // 验证邮箱
        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            flash('error', '请填写有效的邮箱地址。');
            redirect('/ucenter');
        }

        // 更新邮箱
        try {
            $stmt = db()->prepare('UPDATE users SET email = ? WHERE id = ?');
            $stmt->execute([$newEmail, $user['id']]);
        } catch (PDOException $e) {
            flash('error', '该邮箱已被其他账号使用。');
            redirect('/ucenter');
        }

        clear_verify_code();
        unset($_SESSION['verify_code_new_email']);
        flash('success', '绑定邮箱已更新为 ' . e($newEmail) . '。');
        redirect('/ucenter');
    }

    flash('error', '未知操作。');
    redirect('/ucenter');
}

function hide_email(string $email): string
{
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return $email;
    }
    $name = $parts[0];
    $domain = $parts[1];
    $hidden = substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 2));
    return $hidden . '@' . $domain;
}

$captchaEnabled = captcha_is_enabled();
$hasEmail = !empty($user['email']);

render_header('用户中心', 'app');
?>
<main class="grid two-col" style="max-width:800px;margin:0 auto;">
    <!-- 账号信息卡片 -->
    <section class="card">
        <div class="uc-header">
            <div class="uc-avatar"><?= e(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></div>
            <div class="uc-header-info">
                <h3><?= e($user['username']) ?></h3>
                <p><?= $user['role'] === 'admin' ? '管理员' : '普通用户' ?> · <?= e($user['email'] ? hide_email($user['email']) : '未绑定邮箱') ?></p>
            </div>
        </div>
        <div class="uc-stats">
            <div class="uc-stat">
                <strong><?= number_format((int) $user['credits']) ?></strong>
                <span><?= e($balanceLabel) ?></span>
            </div>
            <div class="uc-stat">
                <strong><?= e($user['role'] === 'admin' ? '管理员' : '普通用户') ?></strong>
                <span>角色</span>
            </div>
            <div class="uc-stat">
                <strong><?= e($user['email'] ? '已绑定' : '未绑定') ?></strong>
                <span>邮箱</span>
            </div>
        </div>
    </section>

    <!-- 安全设置卡片 -->
    <section class="card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Security</p>
                <h2>安全设置</h2>
            </div>
        </div>

        <!-- 修改密码 -->
        <div class="uc-section" id="ucChangePassword">
            <h3>修改密码</h3>
            <p class="field-hint">修改登录密码，需要验证当前绑定的邮箱。</p>

            <div class="uc-step" id="cpStep1">
                <p class="field-hint">点击下方按钮，验证码将发送到您的绑定邮箱。</p>
                <button type="button" class="button primary" id="cpSendCodeBtn" data-target="change_password">发送验证码到邮箱</button>
            </div>

            <div class="uc-step hidden" id="cpStep2">
                <form method="post" class="form" id="cpForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="uc_action" value="change_password">
                    <input type="hidden" name="verify_code" id="cpVerifyCode">

                    <label class="field">
                        <span>邮箱验证码</span>
                        <input type="text" id="cpCodeInput" placeholder="输入 6 位验证码" maxlength="6" autocomplete="off" required>
                    </label>
                    <label class="field">
                        <span>新密码</span>
                        <input name="new_password" type="password" autocomplete="new-password" minlength="8" placeholder="至少 8 位" required>
                    </label>
                    <label class="field">
                        <span>确认新密码</span>
                        <input name="confirm_password" type="password" autocomplete="new-password" minlength="8" placeholder="再次输入新密码" required>
                    </label>

                    <?php if ($captchaEnabled): ?>
                        <div id="captcha-container-cp"></div>
                        <button type="button" class="button primary" data-captcha-btn data-form="cpForm">验证并修改密码</button>
                    <?php else: ?>
                        <button class="button primary" type="submit">修改密码</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- 修改邮箱 -->
        <div class="uc-section" id="ucChangeEmail">
            <h3>修改绑定邮箱</h3>
            <p class="field-hint">修改绑定的邮箱地址，需要验证当前邮箱。</p>

            <?php if ($hasEmail): ?>
                <div class="uc-step" id="ceStep1">
                    <p class="field-hint">当前邮箱：<strong><?= e(hide_email($user['email'])) ?></strong></p>
                    <label class="field">
                        <span>新邮箱地址</span>
                        <input type="email" id="ceNewEmail" placeholder="输入新邮箱地址" autocomplete="off" required>
                    </label>
                    <button type="button" class="button primary" id="ceSendCodeBtn" data-target="change_email">发送验证码到当前邮箱</button>
                </div>

                <div class="uc-step hidden" id="ceStep2">
                    <form method="post" class="form" id="ceForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="uc_action" value="change_email">
                        <input type="hidden" name="verify_code" id="ceVerifyCode">

                        <label class="field">
                            <span>邮箱验证码</span>
                            <input type="text" id="ceCodeInput" placeholder="输入 6 位验证码" maxlength="6" autocomplete="off" required>
                        </label>
                        <p class="field-hint">新邮箱：<strong id="ceNewEmailDisplay"></strong></p>

                        <?php if ($captchaEnabled): ?>
                            <div id="captcha-container-ce"></div>
                            <button type="button" class="button primary" data-captcha-btn data-form="ceForm">验证并修改邮箱</button>
                        <?php else: ?>
                            <button class="button primary" type="submit">修改邮箱</button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php else: ?>
                <p class="muted">您的账号没有绑定邮箱，无法修改。请联系管理员。</p>
            <?php endif; ?>
        </div>

        <!-- 聚合登录绑定管理 -->
        <?php
        ensure_social_logins_table();
        $socialTypes = social_login_types();
        $activeSocialTypes = social_login_active_types();
        if ($activeSocialTypes):
            $stmt = db()->prepare('SELECT type, nickname, faceimg, created_at FROM social_logins WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $myBindings = [];
            foreach ($stmt->fetchAll() as $row) {
                $myBindings[$row['type']] = $row;
            }
        ?>
        <div class="uc-section" id="ucSocialBind">
            <h3>第三方账号绑定</h3>
            <p class="field-hint">绑定第三方账号后可使用一键登录。</p>
            <div style="display:flex;flex-direction:column;gap:12px;margin-top:16px;">
                <?php foreach ($activeSocialTypes as $key => $label): ?>
                <div class="uc-social-row" data-type="<?= e($key) ?>" style="display:flex;align-items:center;justify-content:space-between;padding:12px;border:1px solid var(--line);border-radius:12px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span style="font-size:18px;font-weight:700;color:#111;"><?= e($label) ?></span>
                        <?php if (isset($myBindings[$key])): ?>
                            <span style="color:#10b981;font-size:12px;">已绑定</span>
                            <?php if ($myBindings[$key]['nickname']): ?>
                                <span style="color:var(--text-muted);font-size:12px;"><?= e($myBindings[$key]['nickname']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:12px;">未绑定</span>
                        <?php endif; ?>
                    </div>
                    <?php if (isset($myBindings[$key])): ?>
                        <button class="btn btn-sm btn-secondary" data-social-unbind data-type="<?= e($key) ?>">解绑</button>
                    <?php else: ?>
                        <button class="btn btn-sm btn-primary" data-social-bind data-type="<?= e($key) ?>">绑定</button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div id="socialMsg" class="hidden" style="margin-top:12px;"></div>
        </div>
        <?php endif; ?>
    </section>
</main>

<?php if ($activeSocialTypes): ?>
<script>
(function() {
    // 解绑
    document.addEventListener('click', async function(e) {
        const btn = e.target.closest('[data-social-unbind]');
        if (!btn) return;
        if (!confirm('确认解绑该第三方账号？')) return;
        btn.disabled = true;
        try {
            const res = await fetch('/api/auth', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({_action:'social_unbind', type: btn.dataset.type}) });
            const d = await res.json();
            if (d.ok) { window.showToast?.('解绑成功', 'success'); setTimeout(() => location.reload(), 500); }
            else window.showToast?.(d.message, 'error');
        } catch(e) { window.showToast?.('网络错误', 'error'); }
        btn.disabled = false;
    });

    // 绑定：获取跳转URL
    document.addEventListener('click', async function(e) {
        const btn = e.target.closest('[data-social-bind]');
        if (!btn) return;
        btn.disabled = true;
        btn.textContent = '跳转中...';
        try {
            const res = await fetch('/api/auth', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({_action:'social_login', type: btn.dataset.type}) });
            const d = await res.json();
            if (d.ok && d.data.url) window.location.href = d.data.url;
            else alert(d.message || '获取登录地址失败');
        } catch(e) { alert('网络错误'); }
        btn.disabled = false;
        btn.textContent = '绑定';
    });
})();
</script>
<?php endif; ?>

<script>
(function() {
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

    // 发送验证码
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[id$="SendCodeBtn"]');
        if (!btn) return;

        const targetAction = btn.dataset.target;
        const isEmail = targetAction === 'change_email';
        const step1 = btn.closest('.uc-step');
        const step2 = step1?.nextElementSibling;

        let newEmail = '';
        if (isEmail) {
            newEmail = document.getElementById('ceNewEmail')?.value?.trim() || '';
            if (!newEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(newEmail)) {
                window.showToast?.('请填写有效的邮箱地址。', 'error');
                return;
            }
        }

        btn.disabled = true;
        btn.textContent = '正在发送...';

        const body = new URLSearchParams();
        body.append('csrf_token', csrfToken);
        body.append('uc_action', 'send_code');
        body.append('target_action', targetAction);
        if (isEmail) {
            body.append('new_email', newEmail);
        }

        fetch('/ucenter', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                window.showToast?.(data.message, 'success');
                if (step2) {
                    step1.classList.add('hidden');
                    step2.classList.remove('hidden');
                    // 显示新邮箱
                    if (isEmail && newEmail) {
                        const display = document.getElementById('ceNewEmailDisplay');
                        if (display) display.textContent = newEmail;
                    }
                }
            } else {
                window.showToast?.(data.message, 'error');
                btn.disabled = false;
                btn.textContent = isEmail ? '发送验证码到当前邮箱' : '发送验证码到邮箱';
            }
        })
        .catch(function() {
            window.showToast?.('网络错误，请重试。', 'error');
            btn.disabled = false;
            btn.textContent = isEmail ? '发送验证码到当前邮箱' : '发送验证码到邮箱';
        });
    });

    // 验证码输入后自动填充隐藏字段
    document.addEventListener('input', function(e) {
        const input = e.target.closest('#cpCodeInput, #ceCodeInput');
        if (!input) return;
        const hiddenId = input.id === 'cpCodeInput' ? 'cpVerifyCode' : 'ceVerifyCode';
        const hidden = document.getElementById(hiddenId);
        if (hidden) hidden.value = input.value;
    });
})();
</script>

<?php if ($captchaEnabled): ?>
    <?php echo captcha_render_html(''); ?>
<?php endif; ?>

<?php render_footer(); ?>
