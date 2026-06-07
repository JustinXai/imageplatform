<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/migration.php';
require_once __DIR__ . '/../src/captcha.php';
require_once __DIR__ . '/../src/mail.php';
ensure_auth_features();

// ── 处理表单提交 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? '');
    $captchaCheck = captcha_validate_from_request($_POST);

    // 登录
    if ($action === 'login') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = '请输入账号和密码。';
        } elseif (!$captchaCheck['ok']) {
            $error = $captchaCheck['message'];
        } else {
            $stmt = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, $user['password_hash'])) {
                $error = '账号或密码不正确。';
            } elseif (
                $user['role'] !== 'admin'
                && empty($user['email_verified_at'])
                && function_exists('smtp_is_configured')
                && smtp_is_configured()
            ) {
                $error = '请先验证邮箱后再登录。';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                redirect($user['role'] === 'admin' ? '/admin/index' : '/user/index');
            }
        }
    }

    // 注册
    if ($action === 'register') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        $agreeTerms = !empty($_POST['agree_terms']);
        $inviteCode = trim((string) ($_POST['invite_code'] ?? ''));

        if (!$agreeTerms) { $error = '请同意服务条款和隐私政策。'; }
        elseif (!$captchaCheck['ok']) { $error = $captchaCheck['message']; }
        elseif (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) { $error = '用户名只能包含字母、数字、下划线，长度 3-32 位。'; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = '请填写有效的邮箱地址。'; }
        elseif (strlen($password) < 8) { $error = '密码至少 8 位。'; }
        elseif ($password !== $passwordConfirm) { $error = '两次密码输入不一致。'; }
        else {
            $invitedBy = null;
            $bonusEnabled = app_setting('signup_bonus_enabled', 'off') === 'on';
            $bonusCredits = max(0, (int) app_setting('signup_bonus_credits', '0'));
            $initialCredits = $bonusEnabled ? $bonusCredits : 0;
            ensure_invite_tables();

            if ($inviteCode !== '' && invite_enabled()) {
                $stmt = db()->prepare('SELECT id FROM users WHERE invite_code = ? LIMIT 1');
                $stmt->execute([$inviteCode]);
                $inviter = $stmt->fetch();
                if ($inviter) {
                    $invitedBy = (int) $inviter['id'];
                    if (invite_bonus_credits() > 0) $initialCredits += invite_bonus_credits();
                }
            }

            $mailEnabled = function_exists('smtp_is_configured') && smtp_is_configured();
            $verifyToken = $mailEnabled ? generate_auth_token() : null;
            try {
                db()->prepare('INSERT INTO users (username, email, password_hash, role, credits, email_verified_at, email_verify_token, invited_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        $username,
                        $email,
                        password_hash($password, PASSWORD_DEFAULT),
                        'user',
                        $initialCredits,
                        $mailEnabled ? null : date('Y-m-d H:i:s'),
                        $verifyToken,
                        $invitedBy,
                    ]);

                $userId = (int) db()->lastInsertId();
                $platform = platform_name();
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                if (!$mailEnabled) {
                    $success = '注册成功，当前未配置邮件验证，账号已直接激活。';
                } else {
                $verifyUrl = $scheme . '://' . $host . '/verify_email?token=' . urlencode($verifyToken);
                send_mail($email, $platform . ' - 验证您的邮箱', build_verify_email_html($platform, $username, $verifyUrl));
                $success = '注册成功！验证邮件已发送到 ' . e($email) . '，请查收邮件完成验证。';
                }
            } catch (PDOException $e) {
                $error = '注册失败，用户名或邮箱已存在。';
            }
        }
    }

    // 忘记密码
    if ($action === 'forgot_password') {
        $email = trim((string) ($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '请填写有效的邮箱地址。';
        } else {
            $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $token = generate_auth_token();
                db()->prepare('UPDATE users SET reset_token = ?, reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?')
                    ->execute([$token, $user['id']]);
                $platform = platform_name();
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $resetUrl = $scheme . '://' . $host . '/login?reset=' . urlencode($token);
                $body = '<h2>密码重置</h2><p>点击下方链接重置密码：</p><p><a href="' . e($resetUrl) . '">重置密码</a></p><p>此链接 1 小时内有效。</p>';
                send_mail($email, $platform . ' - 密码重置', $body);
            }
            $success = '如果该邮箱已注册，重置密码链接已发送。';
        }
    }

    // 重置密码
    if ($action === 'reset_password') {
        $token = trim((string) ($_POST['token'] ?? $_GET['reset'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($token === '') { $error = '重置链接无效。'; }
        elseif (strlen($password) < 8) { $error = '密码至少 8 位。'; }
        elseif ($password !== $passwordConfirm) { $error = '两次密码输入不一致。'; }
        else {
            $stmt = db()->prepare("SELECT * FROM users WHERE reset_token = ? AND reset_token_expires_at > NOW() LIMIT 1");
            $stmt->execute([$token]);
            $user = $stmt->fetch();
            if (!$user) {
                $error = '重置链接已过期或无效。';
            } else {
                db()->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires_at = NULL WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                $success = '密码已重置，请使用新密码登录。';
            }
        }
    }
}

// 已登录用户直接跳转
$currentUser = current_user();
if ($currentUser && empty($_GET['reset'])) {
    redirect($currentUser['role'] === 'admin' ? '/admin/index' : '/user/index');
}

$resetToken = trim((string) ($_GET['reset'] ?? ''));
$captchaEnabled = captcha_is_enabled();
$captchaId = $captchaEnabled ? captcha_id() : '';
$socialTypes = social_login_active_types();
$inviteEnabled = invite_enabled();
$inviteFromUrl = trim((string) ($_GET['invite'] ?? ''));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(platform_name()) ?> - 登录/注册</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { primary: '#000000', secondary: '#6B7280', border: '#E5E7EB', link: '#000000', error: '#EF4444', success: '#10B981' },
                    fontFamily: { sans: ['Inter', 'PingFang SC', 'Microsoft YaHei', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        .form-input-focus:focus { outline: none; ring: 1px solid #000; border-color: #000; }
        .divider-line { display: flex; align-items: center; width: 100%; margin: 1rem 0; }
        .divider-line::before, .divider-line::after { content: ''; flex: 1; height: 1px; background: #e5e7eb; }
        .divider-line span { padding: 0 12px; color: #6b7280; font-size: 12px; }
        .btn-loading { opacity: 0.7; pointer-events: none; }
        .msg-box { padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
        .msg-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .msg-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .msg-info { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .captcha-passed { background: #10B981 !important; }
        .auth-bg {
            position: fixed; inset: 0; z-index: -1;
            background: center / cover no-repeat;
        }
        .auth-bg::after {
            content: ''; position: absolute; inset: 0;
            background: rgba(0, 0, 0, 0.45);
        }
        .auth-card {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
        @media (max-width: 480px) {
            body { padding: 16px 12px; }
            .auth-card { padding: 20px 16px; border-radius: 12px; }
            .auth-bg::after { background: rgba(0, 0, 0, 0.52); }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center p-5 font-sans relative">
<div class="auth-bg" id="authBg"></div>

<script>
(function() {
    var bg = document.getElementById('authBg');
    // 默认兜底背景
    bg.style.backgroundImage = 'url(https://www.dmoe.cc/random.php)';
    fetch('https://www.dmoe.cc/random.php?return=json')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === '200' && data.source) {
                bg.style.backgroundImage = 'url(' + data.source + ')';
            }
        });
})();
</script>

<div class="w-full max-w-md">
    <!-- Logo -->
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold tracking-tight text-white drop-shadow-lg"><?= e(platform_name()) ?></h1>
        <p class="text-white/70 text-sm mt-1">AI 图片视频创作平台</p>
    </div>

    <?php if (isset($error)): ?>
    <div class="msg-box msg-error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if (isset($success)): ?>
    <div class="msg-box msg-success"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($inviteFromUrl !== '' && $inviteEnabled): ?>
    <div class="msg-box msg-info">已识别邀请码 <strong><?= e($inviteFromUrl) ?></strong>，注册后双方都可获得奖励！</div>
    <?php endif; ?>

    <!-- ═══════════ 登录 ═══════════ -->
    <div id="card-login" class="<?= $resetToken ? 'hidden' : '' ?> auth-card border border-border rounded-lg p-8 shadow-sm">
        <h2 class="text-lg font-bold mb-5">登录</h2>
        <form method="post" id="form-login">
            <input type="hidden" name="_action" value="login">
            <?= csrf_field() ?>
            <div class="mb-4"><label class="block text-sm font-medium mb-1">账号</label>
                <input name="username" class="w-full px-4 py-2 border border-border rounded form-input-focus" placeholder="用户名或邮箱" required autocomplete="username"></div>
            <div class="mb-4"><label class="block text-sm font-medium mb-1">密码</label>
                <input type="password" name="password" class="w-full px-4 py-2 border border-border rounded form-input-focus" placeholder="输入密码" required autocomplete="current-password"></div>
            <?php if ($captchaEnabled): ?>
            <div id="captcha-login" class="mb-4"></div>
            <?php endif; ?>
            <button type="submit" class="w-full bg-primary text-white py-2 rounded hover:bg-gray-800 transition mb-4" id="btn-login">登录</button>
            <div class="flex justify-between text-sm"><a href="?reset=1" class="text-link hover:underline">忘记密码？</a></div>
        </form>

        <?php if ($socialTypes):
        // 快捷登录图标映射
        $socialIcons = [
            'qq'       => 'fab fa-qq',
            'wx'       => 'fab fa-weixin',
            'alipay'   => 'fab fa-alipay',
            'sina'     => 'fab fa-weibo',
            'baidu'    => 'fas fa-globe',
            'huawei'   => 'fas fa-globe',
            'xiaomi'   => 'fas fa-mobile-alt',
            'douyin'   => 'fab fa-tiktok',
            'bilibili' => 'fab fa-bilibili',
            'dingtalk' => 'fas fa-comments',
        ];
        ?>
        <div class="divider-line"><span>或者继续</span></div>
        <div class="flex gap-3 mb-4 flex-wrap">
            <?php foreach ($socialTypes as $key => $name): ?>
            <a href="/api/auth?_action=social_login&type=<?= e($key) ?>&redirect=1" class="flex-1 flex items-center justify-center gap-2 px-3 py-2 border border-border rounded hover:bg-gray-50 transition text-sm text-secondary no-underline">
                <i class="<?= e($socialIcons[$key] ?? 'fas fa-link') ?>"></i>
                <?= e($name) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p class="text-center text-sm text-secondary">没有账号？<a href="#" onclick="showCard('register');return false" class="text-link font-medium hover:underline">注册</a></p>
    </div>

    <!-- ═══════════ 注册 ═══════════ -->
    <div id="card-register" class="hidden auth-card border border-border rounded-lg p-8 shadow-sm">
        <h2 class="text-lg font-bold mb-5">注册</h2>
        <form method="post" id="form-register">
            <input type="hidden" name="_action" value="register">
            <?= csrf_field() ?>
            <div class="mb-4"><label class="block text-sm font-medium mb-1">用户名</label>
                <input name="username" class="w-full px-4 py-2 border border-border rounded form-input-focus" placeholder="3-32 位字母数字下划线" required autocomplete="username"></div>
            <div class="mb-4"><label class="block text-sm font-medium mb-1">邮箱</label>
                <input type="email" name="email" class="w-full px-4 py-2 border border-border rounded form-input-focus" placeholder="your@email.com" required autocomplete="email"></div>
            <div class="mb-4"><label class="block text-sm font-medium mb-1">密码</label>
                <input type="password" name="password" class="w-full px-4 py-2 border border-border rounded form-input-focus" placeholder="至少 8 位" minlength="8" required autocomplete="new-password"></div>
            <div class="mb-4"><label class="block text-sm font-medium mb-1">确认密码</label>
                <input type="password" name="password_confirm" class="w-full px-4 py-2 border border-border rounded form-input-focus" placeholder="再次输入密码" minlength="8" required></div>
            <?php if ($inviteEnabled): ?>
            <div class="mb-4"><label class="block text-sm font-medium mb-1">邀请码 <span class="text-secondary text-xs">（选填）</span></label>
                <input name="invite_code" class="w-full px-4 py-2 border border-border rounded form-input-focus <?= $inviteFromUrl ? 'bg-gray-50' : '' ?>" placeholder="输入邀请码获取额外奖励" value="<?= e($inviteFromUrl) ?>" <?= $inviteFromUrl ? 'readonly' : '' ?>></div>
            <?php endif; ?>
            <div class="flex items-center mb-4">
                <input type="checkbox" name="agree_terms" value="1" class="w-4 h-4 border border-border rounded" required>
                <label class="ml-2 text-sm text-secondary">我同意 <a href="#" class="text-link hover:underline">服务条款</a> 及 <a href="#" class="text-link hover:underline">隐私政策</a></label>
            </div>
            <?php if ($captchaEnabled): ?>
            <div id="captcha-register" class="mb-4"></div>
            <?php endif; ?>
            <button type="submit" class="w-full bg-primary text-white py-2 rounded hover:bg-gray-800 transition mb-4">创建账户</button>
        </form>
        <p class="text-center text-sm text-secondary">已有账号？<a href="#" onclick="showCard('login');return false" class="text-link font-medium hover:underline">登录</a></p>
    </div>

    <!-- ═══════════ 重置密码 ═══════════ -->
    <div id="card-reset" class="<?= $resetToken ? '' : 'hidden' ?> auth-card border border-border rounded-lg p-8 shadow-sm">
        <h2 class="text-lg font-bold mb-5">重置密码</h2>
        <form method="post">
            <input type="hidden" name="_action" value="reset_password">
            <input type="hidden" name="token" value="<?= e($resetToken) ?>">
            <?= csrf_field() ?>
            <div class="mb-4"><label class="block text-sm font-medium mb-1">新密码</label>
                <input type="password" name="password" class="w-full px-4 py-2 border border-border rounded form-input-focus" placeholder="至少 8 位" minlength="8" required></div>
            <div class="mb-4"><label class="block text-sm font-medium mb-1">确认新密码</label>
                <input type="password" name="password_confirm" class="w-full px-4 py-2 border border-border rounded form-input-focus" placeholder="再次输入密码" minlength="8" required></div>
            <button type="submit" class="w-full bg-primary text-white py-2 rounded hover:bg-gray-800 transition mb-4">重置密码</button>
        </form>
        <p class="text-center text-sm text-secondary"><a href="/login" class="text-link font-medium hover:underline">返回登录</a></p>
    </div>

    <!-- ═══════════ 忘记密码 ═══════════ -->
    <div id="card-forgot" class="hidden auth-card border border-border rounded-lg p-8 shadow-sm">
        <h2 class="text-lg font-bold mb-5">忘记密码</h2>
        <p class="text-sm text-secondary mb-4">输入注册邮箱，我们将发送重置链接。</p>
        <form method="post">
            <input type="hidden" name="_action" value="forgot_password">
            <?= csrf_field() ?>
            <div class="mb-4"><label class="block text-sm font-medium mb-1">邮箱</label>
                <input type="email" name="email" class="w-full px-4 py-2 border border-border rounded form-input-focus" placeholder="your@email.com" required></div>
            <button type="submit" class="w-full bg-primary text-white py-2 rounded hover:bg-gray-800 transition mb-4">发送重置链接</button>
        </form>
        <p class="text-center text-sm text-secondary"><a href="#" onclick="showCard('login');return false" class="text-link font-medium hover:underline">返回登录</a></p>
    </div>
</div>

<script>
// 卡片切换
function showCard(name) {
    document.querySelectorAll('[id^="card-"]').forEach(function(c) { c.classList.add('hidden'); });
    var card = document.getElementById('card-' + name);
    if (card) card.classList.remove('hidden');
}
// 从 URL hash 自动切换
(function() {
    var h = window.location.hash.replace('#', '');
    if (h === 'register') showCard('register');
    else if (h === 'forgot') showCard('forgot');
})();

<?php if ($captchaEnabled): ?>
// ── 极验验证码 ──
(function() {
    var s = document.createElement('script');
    s.src = 'https://static.geetest.com/v4/gt4.js';
    s.onload = function() {
        var initCaptcha = function(form, btn) {
            initGeetest4({ captchaId: '<?= e($captchaId) ?>', product: 'bind', language: 'zho', hideSuccess: true }, function(captchaObj) {
                captchaObj.onReady(function() {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        captchaObj.showCaptcha();
                    });
                });
                captchaObj.onSuccess(function() {
                    var result = captchaObj.getValidate();
                    ['geetest_lot_number','geetest_captcha_output','geetest_pass_token','geetest_gen_time'].forEach(function(k, i) {
                        var f = form.querySelector('[name="' + k + '"]');
                        if (!f) { f = document.createElement('input'); f.type = 'hidden'; f.name = k; form.appendChild(f); }
                        f.value = [result.lot_number, result.captcha_output, result.pass_token, result.gen_time][i];
                    });
                    form.submit();
                });
                captchaObj.onError(function() { form.submit(); });
                btn.type = 'button';
            });
        };
        var loginForm = document.getElementById('form-login'), regForm = document.getElementById('form-register');
        if (loginForm) initCaptcha(loginForm, document.getElementById('btn-login'));
        if (regForm) initCaptcha(regForm, regForm.querySelector('button[type="submit"]'));
    };
    document.head.appendChild(s);
})();
<?php endif; ?>
</script>
</body>
</html>
