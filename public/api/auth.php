<?php
/**
 * Auth API 端点 — 供 HTML 前端调用的认证接口
 *
 * POST /api/auth
 * Body: {"_action": "login|register|forgot_password|reset_password|logout|csrf", ...}
 *
 * 返回 JSON: {ok: true/false, message: "...", data: {...}}
 */

// ── 最优先：防 SG16 加密文件崩溃导致空白响应 ──
error_reporting(0);
ini_set('display_errors', '0');

// 尽早设置 JSON 响应头，防止 SG16 loader 输出导致 header 错误
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

ob_start();
register_shutdown_function(function () {
    // 已经正常发送了 JSON 响应头 → 脚本正常完成
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type: application/json') !== false) return;
    }
    // SG16 loader 崩溃：清除垃圾输出，返回 JSON
    ob_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'message' => '系统内部错误，请稍后重试。'], JSON_UNESCAPED_UNICODE);
});

// bootstrap.php 不允许加密（已知问题）
require_once __DIR__ . '/../../src/bootstrap.php';

// 以下 src/ 文件可能被 SG16 加密，加载失败时静默降级
try { require_once __DIR__ . '/../../src/migration.php'; } catch (Throwable $e) {}

$captchaLoaded = false; $mailLoaded = false;
try { require_once __DIR__ . '/../../src/captcha.php'; $captchaLoaded = true; } catch (Throwable $e) {}
try { require_once __DIR__ . '/../../src/mail.php';     $mailLoaded = true;     } catch (Throwable $e) {}

// 清除 SG16 加密文件可能产生的非预期输出（loader 警告/错误字节）
if (ob_get_length() > 0) {
    ob_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

// ── 读取 JSON 输入 ──
$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    $input = $_POST; // fallback to form POST
}
// GET 参数也合并进来（优先级低于 POST/JSON，用于链接跳转类请求）
$input = array_merge($_GET, $input);
$action = (string) ($input['_action'] ?? $_GET['_action'] ?? '');

/**
 * JSON 成功响应
 */
function api_ok(array $data = [], string $message = 'ok'): void
{
    echo json_encode(['ok' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * JSON 错误响应
 */
function api_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════
// CSRF Token
// ═══════════════════════════════════════════════
if ($action === 'csrf') {
    // 简单会话级 CSRF token（浏览器 session cookie 传递）
    session_start();
    if (empty($_SESSION['api_csrf_token'])) {
        $_SESSION['api_csrf_token'] = bin2hex(random_bytes(32));
    }
    api_ok(['token' => $_SESSION['api_csrf_token']]);
}

// ═══════════════════════════════════════════════
// 登录
// ═══════════════════════════════════════════════
if ($action === 'login') {
    if (function_exists('ensure_auth_features')) {
        try { ensure_auth_features(); } catch (Throwable $e) {}
    }

    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    if ($username === '' || $password === '') {
        api_error('请输入账号和密码。');
    }

    // 极验验证码校验（captcha.php 加密不可用时跳过）
    if ($captchaLoaded && function_exists('captcha_validate_from_request')) {
        $captchaCheck = captcha_validate_from_request($input);
        if (!$captchaCheck['ok']) {
            api_error($captchaCheck['message']);
        }
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, $user['password_hash'])) {
        api_error('账号或密码不正确。', 401);
    }

    // 管理员跳过邮箱验证；普通用户必须验证
    if (
        $user['role'] !== 'admin'
        && empty($user['email_verified_at'])
        && function_exists('smtp_is_configured')
        && smtp_is_configured()
    ) {
        api_error('请先验证邮箱后再登录。请检查收件箱中的验证邮件。', 403);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];

    api_ok([
        'user_id' => (int) $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'credits' => (int) $user['credits'],
        'redirect' => $user['role'] === 'admin' ? '/admin/index' : '/user/index',
    ], '登录成功');
}

// ═══════════════════════════════════════════════
// 注册
// ═══════════════════════════════════════════════
if ($action === 'register') {
    if (function_exists('ensure_auth_features')) {
        try { ensure_auth_features(); } catch (Throwable $e) {}
    }

    $username = trim((string) ($input['username'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $passwordConfirm = (string) ($input['password_confirm'] ?? '');
    $agreeTerms = !empty($input['agree_terms']);

    if (!$agreeTerms) {
        api_error('请同意服务条款和隐私政策。');
    }

    // 极验验证码校验（captcha.php 加密不可用时跳过）
    if ($captchaLoaded && function_exists('captcha_validate_from_request')) {
        $captchaCheck = captcha_validate_from_request($input);
        if (!$captchaCheck['ok']) {
            api_error($captchaCheck['message']);
        }
    }

    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
        api_error('用户名只能包含字母、数字、下划线，长度 3-32 位。');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('请填写有效的邮箱地址。');
    }
    if (strlen($password) < 8) {
        api_error('密码至少 8 位。');
    }
    if ($password !== $passwordConfirm) {
        api_error('两次输入的密码不一致。');
    }

    $bonusEnabled = app_setting('signup_bonus_enabled', 'off') === 'on';
    $bonusCredits = max(0, (int) app_setting('signup_bonus_credits', '0'));
    $initialCredits = $bonusEnabled ? $bonusCredits : 0;
    ensure_invite_tables();

    // 邀请码处理
    $inviteCode = trim((string) ($input['invite_code'] ?? ''));
    $invitedBy = null;
    if ($inviteCode !== '' && invite_enabled()) {
        $stmt = db()->prepare('SELECT id FROM users WHERE invite_code = ? LIMIT 1');
        $stmt->execute([$inviteCode]);
        $inviter = $stmt->fetch();
        if ($inviter) {
            $invitedBy = (int) $inviter['id'];
            $inviteBonus = invite_bonus_credits();
            if ($inviteBonus > 0) $initialCredits += $inviteBonus;
        }
    }

    $mailEnabled = $mailLoaded && function_exists('smtp_is_configured') && smtp_is_configured();
    $verifyToken = $mailEnabled ? generate_auth_token() : null;

    try {
        $stmt = db()->prepare(
            'INSERT INTO users (username, email, password_hash, role, credits, email_verified_at, email_verify_token, invited_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
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
    } catch (PDOException $e) {
        api_error('注册失败，用户名或邮箱已存在。');
    }

    // 发送验证邮件
    $platformName = platform_name();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $verifyUrl = $scheme . '://' . $host . '/verify_email?token=' . urlencode((string) $verifyToken);
    // 发送验证邮件（mail.php 加密不可用时跳过）
    $mailResult = ['ok' => false, 'message' => '邮件功能不可用'];
    if ($mailEnabled && function_exists('send_mail')) {
        $mailResult = send_mail(
            $email,
            $platformName . ' - 验证您的邮箱',
            build_verify_email_html($platformName, $username, $verifyUrl)
        );
    }

    $msg = '注册成功！验证邮件已发送到 ' . $email;
    if ($bonusEnabled && $bonusCredits > 0) $msg .= '，赠送 ' . $bonusCredits . ' 积分已到账。';
    if (!$mailResult['ok']) $msg .= '（邮件发送失败：' . $mailResult['message'] . '）';

    api_ok(['user_id' => $userId, 'username' => $username], $msg);
}

// ═══════════════════════════════════════════════
// 忘记密码
// ═══════════════════════════════════════════════
if ($action === 'forgot_password') {
    if (function_exists('ensure_auth_features')) {
        try { ensure_auth_features(); } catch (Throwable $e) {}
    }

    $email = trim((string) ($input['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('请填写有效的邮箱地址。');
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // 不暴露用户是否存在
        api_ok([], '如果该邮箱已注册，重置密码链接已发送。');
    }

    $token = generate_auth_token();
    try {
        db()->prepare('UPDATE users SET reset_token = ?, reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?')
            ->execute([$token, $user['id']]);
    } catch (PDOException $e) {
        api_error('系统错误，请稍后重试。', 500);
    }

    $platformName = platform_name();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $resetUrl = $scheme . '://' . $host . '/login?reset=' . urlencode($token);

    $body = '<h2>密码重置</h2>'
        . '<p>您好，您请求了密码重置。</p>'
        . '<p><a href="' . e($resetUrl) . '" style="display:inline-block;padding:12px 24px;background:#8b5cf6;color:#fff;border-radius:8px;text-decoration:none;">重置密码</a></p>'
        . '<p>此链接 1 小时内有效。</p>'
        . '<p>如果您没有请求重置密码，请忽略此邮件。</p>';

    if ($mailLoaded && function_exists('send_mail')) {
        send_mail($email, $platformName . ' - 密码重置', $body);
    }

    api_ok([], '如果该邮箱已注册，重置密码链接已发送。');
}

// ═══════════════════════════════════════════════
// 重置密码
// ═══════════════════════════════════════════════
if ($action === 'reset_password') {
    if (function_exists('ensure_auth_features')) {
        try { ensure_auth_features(); } catch (Throwable $e) {}
    }

    $token = trim((string) ($input['token'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $passwordConfirm = (string) ($input['password_confirm'] ?? '');

    if ($token === '') {
        api_error('无效的重置链接。');
    }
    if (strlen($password) < 8) {
        api_error('密码至少 8 位。');
    }
    if ($password !== $passwordConfirm) {
        api_error('两次输入的密码不一致。');
    }

    $stmt = db()->prepare(
        'SELECT id FROM users WHERE reset_token = ? AND reset_token_expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        api_error('重置链接无效或已过期。');
    }

    db()->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires_at = NULL WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);

    api_ok([], '密码重置成功，请使用新密码登录。');
}

// ═══════════════════════════════════════════════
// 登出
// ═══════════════════════════════════════════════
if ($action === 'logout') {
    session_start();
    session_destroy();
    api_ok([], '已登出');
}

// ═══════════════════════════════════════════════
// 检查当前登录状态
// ═══════════════════════════════════════════════
if ($action === 'me' || $action === '') {
    session_start();
    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare('SELECT id, username, email, role, credits, email_verified_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            api_ok([
                'user_id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'credits' => (int) $user['credits'],
                'email_verified' => !empty($user['email_verified_at']),
            ]);
        }
    }
    api_ok(['user_id' => null], '未登录');
}

// ═══════════════════════════════════════════════
// 聚合登录 - 获取登录跳转URL
// ═══════════════════════════════════════════════
if ($action === 'social_login') {
    $type = trim((string) ($input['type'] ?? ''));
    $activeTypes = social_login_active_types();
    if (!isset($activeTypes[$type])) {
        api_error('不支持的登录方式。');
    }

    $apiUrl = (string) app_setting('social_login_api_url', '');
    $apiUrl = rtrim($apiUrl, '/') . '/connect.php';
    $appid = (string) app_setting('social_login_appid', '');
    $appkey = (string) app_setting('social_login_appkey', '');

    if ($appid === '' || $appkey === '') {
        api_error('聚合登录尚未配置。');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $redirectUri = $scheme . '://' . $host . '/callback.html?type=' . urlencode($type);

    $url = $apiUrl . '?act=login&appid=' . urlencode($appid)
         . '&appkey=' . urlencode($appkey)
         . '&type=' . urlencode($type)
         . '&redirect_uri=' . urlencode($redirectUri);

    // 请求聚合登录接口获取跳转地址
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $response = @file_get_contents($url, false, $ctx);
    if (!$response) {
        api_error('请求聚合登录接口失败。');
    }
    $result = json_decode($response, true);
    if (!is_array($result) || ($result['code'] ?? -1) !== 0) {
        api_error($result['msg'] ?? '获取登录地址失败。');
    }

    $oauthUrl = $result['url'] ?? '';
    $qrcode = $result['qrcode'] ?? '';

    // 如果请求带了 redirect 参数（如 login.php 的直接跳转链接），直接重定向到 OAuth 页面
    if (!empty($input['redirect']) || !empty($_GET['redirect'])) {
        if ($oauthUrl === '') {
            http_response_code(500);
            echo '<h2>登录失败</h2><p>聚合登录平台未返回授权地址，请稍后重试。</p>';
            exit;
        }
        header('Location: ' . $oauthUrl);
        exit;
    }

    api_ok([
        'url' => $oauthUrl,
        'qrcode' => $qrcode,
    ]);
}

// ═══════════════════════════════════════════════
// 聚合登录 - 回调处理（通过code获取用户信息并登录/绑定）
// ═══════════════════════════════════════════════
if ($action === 'social_callback') {
    $type = trim((string) ($_GET['type'] ?? ''));
    $code = trim((string) ($_GET['code'] ?? ''));

    if ($type === '' || $code === '') {
        api_error('参数不完整。');
    }

    $apiUrl = (string) app_setting('social_login_api_url', '');
    $apiUrl = rtrim($apiUrl, '/') . '/connect.php';
    $appid = (string) app_setting('social_login_appid', '');
    $appkey = (string) app_setting('social_login_appkey', '');

    $url = $apiUrl . '?act=callback&appid=' . urlencode($appid)
         . '&appkey=' . urlencode($appkey)
         . '&type=' . urlencode($type)
         . '&code=' . urlencode($code);

    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $response = @file_get_contents($url, false, $ctx);
    if (!$response) {
        api_error('请求聚合登录回调接口失败。');
    }
    $result = json_decode($response, true);
    if (!is_array($result) || ($result['code'] ?? -1) !== 0) {
        api_error($result['msg'] ?? '获取用户信息失败。');
    }

    $socialUid = (string) ($result['social_uid'] ?? '');
    if ($socialUid === '') {
        api_error('未获取到第三方用户标识。');
    }

    // 检查是否已绑定
    $stmt = db()->prepare('SELECT sl.user_id, u.id, u.username, u.email, u.role, u.credits, u.email_verified_at, u.is_active FROM social_logins sl LEFT JOIN users u ON sl.user_id = u.id WHERE sl.type = ? AND sl.social_uid = ? LIMIT 1');
    $stmt->execute([$type, $socialUid]);
    $bound = $stmt->fetch();

    if ($bound && (int) $bound['is_active'] === 1) {
        // 已绑定且账号正常 → 直接登录
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $bound['user_id'];

        // 更新 access_token
        $accessToken = (string) ($result['access_token'] ?? '');
        if ($accessToken) {
            db()->prepare('UPDATE social_logins SET access_token = ?, nickname = ?, faceimg = ?, updated_at = NOW() WHERE type = ? AND social_uid = ?')
                ->execute([$accessToken, $result['nickname'] ?? '', $result['faceimg'] ?? '', $type, $socialUid]);
        }

        api_ok([
            'bind_status' => 'login',
            'user_id' => (int) $bound['user_id'],
            'username' => $bound['username'] ?? $bound['id'],
            'role' => $bound['role'] ?? 'user',
            'redirect' => ($bound['role'] ?? 'user') === 'admin' ? '/admin/index' : '/user/index',
        ], '登录成功');
    } else {
        // 未绑定 → 先检查当前是否已登录（从个人中心来的绑定操作）
        session_start();
        $currentUserId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($currentUserId > 0) {
            // 已登录用户直接绑定
            ensure_social_logins_table();
            $accessToken = (string) ($result['access_token'] ?? '');
            db()->prepare('INSERT INTO social_logins (user_id, type, social_uid, access_token, nickname, faceimg)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), nickname = VALUES(nickname), faceimg = VALUES(faceimg), updated_at = NOW()')
                ->execute([$currentUserId, $type, $socialUid, $accessToken, $result['nickname'] ?? '', $result['faceimg'] ?? '']);

            api_ok([
                'bind_status' => 'login',
                'user_id' => $currentUserId,
                'redirect' => '/user/ucenter',
            ], '绑定成功');
        }

        // 未登录 → 返回第三方信息，前端引导登录或注册
        api_ok([
            'bind_status' => 'unbound',
            'type' => $type,
            'social_uid' => $socialUid,
            'access_token' => $result['access_token'] ?? '',
            'nickname' => $result['nickname'] ?? '',
            'faceimg' => $result['faceimg'] ?? '',
        ], '该第三方账号未绑定，请登录后绑定。');
    }
}

// ═══════════════════════════════════════════════
// 聚合登录 - 绑定第三方账号（需已登录）
// ═══════════════════════════════════════════════
if ($action === 'social_bind') {
    $user = require_login();
    ensure_social_logins_table();

    $type = trim((string) ($input['type'] ?? ''));
    $socialUid = trim((string) ($input['social_uid'] ?? ''));
    $accessToken = trim((string) ($input['access_token'] ?? ''));
    $nickname = trim((string) ($input['nickname'] ?? ''));
    $faceimg = trim((string) ($input['faceimg'] ?? ''));

    if ($type === '' || $socialUid === '') {
        api_error('参数不完整。');
    }

    // 检查是否已绑定到其他账号
    $stmt = db()->prepare('SELECT user_id FROM social_logins WHERE type = ? AND social_uid = ? LIMIT 1');
    $stmt->execute([$type, $socialUid]);
    $existing = $stmt->fetch();
    if ($existing) {
        api_error('该第三方账号已绑定其他账户。');
    }

    db()->prepare('INSERT INTO social_logins (user_id, type, social_uid, access_token, nickname, faceimg)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), nickname = VALUES(nickname), faceimg = VALUES(faceimg), updated_at = NOW()')
        ->execute([$user['id'], $type, $socialUid, $accessToken, $nickname, $faceimg]);

    api_ok([], '绑定成功');
}

// ═══════════════════════════════════════════════
// 聚合登录 - 解绑（需已登录）
// ═══════════════════════════════════════════════
if ($action === 'social_unbind') {
    $user = require_login();
    ensure_social_logins_table();

    $type = trim((string) ($input['type'] ?? ''));
    if ($type === '') api_error('请指定解绑的登录方式。');

    db()->prepare('DELETE FROM social_logins WHERE user_id = ? AND type = ?')
        ->execute([$user['id'], $type]);

    api_ok([], '解绑成功');
}

// ═══════════════════════════════════════════════
// 聚合登录 - 获取当前用户绑定列表
// ═══════════════════════════════════════════════
if ($action === 'social_my_bindings') {
    $user = require_login();
    ensure_social_logins_table();

    $stmt = db()->prepare('SELECT type, nickname, faceimg, created_at FROM social_logins WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    api_ok(['bindings' => $stmt->fetchAll()]);
}

// ═══════════════════════════════════════════════
// 聚合登录 - 获取可用登录方式（供前端渲染按钮）
// ═══════════════════════════════════════════════
if ($action === 'social_types') {
    api_ok(['types' => social_login_active_types()]);
}

// ═══════════════════════════════════════════════
// 邀请 — 生成/获取邀请码
// ═══════════════════════════════════════════════
if ($action === 'invite_code') {
    $user = require_login();
    ensure_invite_tables();
    $code = user_invite_code($user);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    api_ok([
        'code' => $code,
        'link' => $scheme . '://' . $host . '/login?invite=' . urlencode($code),
    ]);
}

// 邀请 — 获取邀请统计
if ($action === 'invite_stats') {
    $user = require_login();
    ensure_invite_tables();
    $stats = user_invite_stats((int) $user['id']);
    $stats['code'] = user_invite_code($user);

    $stmt = db()->prepare('SELECT u.username, u.email, u.created_at, ic.id as commission_id, ic.credits as commission_credits, ic.created_at as commission_time
        FROM users u LEFT JOIN invite_commissions ic ON ic.invited_user_id = u.id AND ic.inviter_id = ?
        WHERE u.invited_by = ? ORDER BY u.created_at DESC LIMIT 50');
    $stmt->execute([(int)$user['id'], (int)$user['id']]);
    $stats['invited_users'] = $stmt->fetchAll();

    api_ok($stats);
}

// 邀请 — 验证邀请码是否有效
if ($action === 'invite_validate') {
    $code = trim((string) ($input['code'] ?? ''));
    if ($code === '') api_error('邀请码不能为空。');
    ensure_invite_tables();
    $stmt = db()->prepare('SELECT id, username FROM users WHERE invite_code = ? LIMIT 1');
    $stmt->execute([$code]);
    $inviter = $stmt->fetch();
    api_ok([
        'valid' => (bool) $inviter,
        'inviter_name' => $inviter ? $inviter['username'] : '',
    ]);
}

api_error('未知操作: ' . $action);
