<?php

declare(strict_types=1);

// ===== 璺緞甯搁噺 =====
define('ROOT_PATH', dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('PUBLIC_PATH', ROOT_PATH . '/public');


// ===== 鑷姩鍔犺浇 Logger =====
require_once __DIR__ . '/Logger.php';

// ===== 鍏ㄥ眬閰嶇疆鍔犺浇 =====
$configFile = ROOT_PATH . '/config.php';

if (!is_file($configFile)) {
    $configFile = ROOT_PATH . '/config.example.php';
}

$GLOBALS['config'] = require $configFile;
date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'Asia/Shanghai');
session_name($GLOBALS['config']['app']['session_name'] ?? 'image_platform_session');

// ===== CDN/鍙嶅悜浠ｇ悊 HTTPS 閫傞厤 =====
// EdgeOne/CDN 杞彂鍚庯紝鍘熷 HTTPS 淇℃伅涓㈠け锛屼粠 X-Forwarded-Proto 鎭㈠
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// Session Cookie 閰嶇疆锛歴ecure 鏍囧織璺熼殢瀹為檯鍗忚
$isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();


// 鍔犺浇鐗堟湰鍙凤紙鐢ㄤ簬鍦ㄧ嚎鏇存柊妫€娴嬶級
if (!defined('VERSION')) {
    $versionFile = ROOT_PATH . '/src/version.php';
    if (is_file($versionFile)) {
        require $versionFile;
    }
}


// ===== 鏁版嵁瀹屾暣鎬ф牎楠?=====
$checkFile = ROOT_PATH . '/config/.check';
if (!is_file($checkFile)) {
    http_response_code(500);
    echo 'Required data files are incomplete. Please reinstall the application.';
    exit;
}

// ===== 鍏ㄥ眬寮傚父澶勭悊鍣?=====
set_exception_handler(function (Throwable $e) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || (
            !empty($_SERVER['CONTENT_TYPE'])
            && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false
        );
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');

    // 璁板綍閿欒鏃ュ織
    Logger::error($e->getMessage(), [
        'file' => $e->getFile() . ':' . $e->getLine(),
        'uri' => $requestUri,
        'trace' => $e->getTraceAsString(),
    ]);

    // API 璇锋眰杩斿洖 JSON
    if ($isAjax || strpos($requestUri, '/check_record') === 0 || strpos($requestUri, '/generate_async') === 0) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => config('app.debug', false) ? $e->getMessage() : '绯荤粺鍐呴儴閿欒锛岃绋嶅悗閲嶈瘯',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // 闈?API 璇锋眰锛氬紑鍙戠幆澧冩樉绀鸿鎯咃紝鐢熶骇鐜鏄剧ず閿欒椤碉紙涓嶅仛閲嶅畾鍚戯紝閬垮厤寰幆锛?
    if (config('app.debug', false)) {
        echo '<h1>绯荤粺閿欒</h1>';
        echo '<p><strong>' . e($e->getMessage()) . '</strong></p>';
        echo '<pre>' . e($e->getFile() . ':' . $e->getLine()) . '</pre>';
        echo '<pre>' . e($e->getTraceAsString()) . '</pre>';
    } else {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>绯荤粺閿欒</title>';
        echo '<style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f0f7ff}';
        echo '.error-card{background:#fff;border-radius:12px;padding:40px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:520px}';
        echo 'h2{color:#1e293b;margin:0 0 8px}p{color:#64748b;margin:0 0 24px;font-size:14px}';
        echo '.btn{display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-size:14px}';
        echo '.debug-info{margin-top:20px;padding:12px;background:#fef2f2;border-radius:8px;text-align:left;font-size:12px;color:#991b1b;word-break:break-all}';
        echo '.debug-info strong{display:block;margin-bottom:4px}</style>';
        echo '</head><body><div class="error-card"><h2>绯荤粺绻佸繖</h2>';
        echo '<p>鏈嶅姟鍣ㄥ鐞嗚姹傛椂鍑虹幇閿欒锛岃绋嶅悗鍒锋柊椤甸潰閲嶈瘯銆?/p>';
        echo '<a class="btn" href="javascript:location.reload()">鍒锋柊椤甸潰</a>';
        echo '<div class="debug-info"><strong>閿欒璇︽儏锛堜粎渚涙帓鏌ワ紝涓婄嚎鍚庡垹闄わ級</strong>';
        echo e(get_class($e) . ': ' . $e->getMessage());
        echo '<br><br><em>' . e($e->getFile() . ':' . $e->getLine()) . '</em></div>';
        echo '</div></body></html>';
    }
    exit;
});

// ===== 瀹夎妫€娴嬶細妫€鏌ュ畨瑁呮爣璁版垨 config.php 鏄惁瀛樺湪 =====
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$isInstallCheck = strpos($requestPath, '/setup') === 0 || strpos($requestPath, '/setup.php') === 0 || strpos($requestPath, '/install') === 0;
$installedMarker = ROOT_PATH . '/storage/.installed';
$hasAppConfig = is_file(ROOT_PATH . '/config.php');
if (!$isInstallCheck && !$hasAppConfig && !is_file($installedMarker)) {
    redirect('/setup.php');
}

// ===== 鏍稿績鍑芥暟 =====

/**
 * 确认AI模型能力字段存在（比例/时长/尺导/模式options）
 */
function ensure_ai_models_capability_columns(): void
{
    static $checked = false;
    if ($checked) return;

    $columns = [];
    $stmt = db()->query('SHOW COLUMNS FROM ai_models');
    foreach ($stmt->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }

    if (empty($columns['image_aspect_options_json'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN image_aspect_options_json LONGTEXT NULL AFTER video_aspect_ratio");
    }
    if (empty($columns['image_default_aspect'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN image_default_aspect VARCHAR(20) NOT NULL DEFAULT 'auto' AFTER image_aspect_options_json");
    }
    if (empty($columns['image_size_options_json'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN image_size_options_json LONGTEXT NULL AFTER image_default_aspect");
    }
    if (empty($columns['image_default_size'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN image_default_size VARCHAR(30) NOT NULL DEFAULT 'auto' AFTER image_size_options_json");
    }
    if (empty($columns['video_duration_options_json'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_duration_options_json LONGTEXT NULL AFTER image_default_size");
    }
    if (empty($columns['video_default_duration'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_default_duration INT UNSIGNED DEFAULT NULL AFTER video_duration_options_json");
    }
    if (empty($columns['video_aspect_options_json'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_aspect_options_json LONGTEXT NULL AFTER video_default_duration");
    }
    if (empty($columns['video_default_aspect'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_default_aspect VARCHAR(20) NOT NULL DEFAULT '16:9' AFTER video_aspect_options_json");
    }
    if (empty($columns['video_size_options_json'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_size_options_json LONGTEXT NULL AFTER video_default_aspect");
    }
    if (empty($columns['video_default_size'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_default_size VARCHAR(30) NOT NULL DEFAULT 'auto' AFTER video_size_options_json");
    }
    if (empty($columns['video_mode_options_json'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_mode_options_json LONGTEXT NULL AFTER video_default_size");
    }
    if (empty($columns['video_default_mode'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_default_mode VARCHAR(50) NOT NULL DEFAULT 'text_to_video' AFTER video_mode_options_json");
    }
    if (empty($columns['video_reference_field'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_reference_field VARCHAR(50) NOT NULL DEFAULT 'reference_images' AFTER video_default_mode");
    }
    if (empty($columns['video_duration_field'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_duration_field VARCHAR(50) NOT NULL DEFAULT 'duration' AFTER video_reference_field");
    }
    if (empty($columns['video_aspect_field'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_aspect_field VARCHAR(50) NOT NULL DEFAULT 'aspect_ratio' AFTER video_duration_field");
    }
    if (empty($columns['video_size_field'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_size_field VARCHAR(50) NOT NULL DEFAULT 'size' AFTER video_aspect_field");
    }
    if (empty($columns['video_input_mode_field'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_input_mode_field VARCHAR(50) NOT NULL DEFAULT 'input_mode' AFTER video_size_field");
    }
    if (empty($columns['max_reference_videos'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN max_reference_videos TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER video_input_mode_field");
    }
    if (empty($columns['max_reference_audios'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN max_reference_audios TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER max_reference_videos");
    }
    if (empty($columns['video_reference_video_field'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_reference_video_field VARCHAR(50) NOT NULL DEFAULT 'extra_videos' AFTER max_reference_audios");
    }
    if (empty($columns['video_reference_audio_field'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_reference_audio_field VARCHAR(50) NOT NULL DEFAULT 'extra_audios' AFTER video_reference_video_field");
    }

    $checked = true;
}

/**
 * 确认生成记录选择项字段存在（比例/尺导/时长/模式）
 */
function ensure_generation_records_selection_columns(): void
{
    static $checked = false;
    if ($checked) return;

    $columns = [];
    $stmt = db()->query('SHOW COLUMNS FROM generation_records');
    foreach ($stmt->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }

    if (empty($columns['selected_aspect'])) {
        db()->exec("ALTER TABLE generation_records ADD COLUMN selected_aspect VARCHAR(20) NULL AFTER output_base64");
    }
    if (empty($columns['selected_size'])) {
        db()->exec("ALTER TABLE generation_records ADD COLUMN selected_size VARCHAR(30) NULL AFTER selected_aspect");
    }
    if (empty($columns['selected_duration'])) {
        db()->exec("ALTER TABLE generation_records ADD COLUMN selected_duration INT UNSIGNED DEFAULT NULL AFTER selected_size");
    }
    if (empty($columns['selected_video_mode'])) {
        db()->exec("ALTER TABLE generation_records ADD COLUMN selected_video_mode VARCHAR(50) NULL AFTER selected_duration");
    }

    $checked = true;
}

/**
 * 鑾峰彇閰嶇疆椤癸紙鏀寔鐐瑰彿鍒嗛殧锛? * @param  string $key  
 * @param  mixed $default  
 * @return mixed
 */
function config(string $key, $default = null)
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/**
 * 鑾峰彇 PDO 鏁版嵁搴撹繛鎺ワ紙鍗曚緥锛? * @return PDO
 */
function db(): PDO
{
    static $pdo = null;
    $connect = static function (): PDO {
        $db = config('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['database'],
            $db['charset']
        );

        $connection = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $charset = strtolower((string) ($db['charset'] ?? 'utf8mb4'));
        if ($charset === 'utf8mb4') {
            $connection->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } elseif ($charset !== '') {
            $connection->exec('SET NAMES ' . $charset);
        }

        return $connection;
    };

    if (!$pdo instanceof PDO) {
        $pdo = $connect();
        return $pdo;
    }

    try {
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        $message = strtolower($e->getMessage());
        $isGoneAway = strpos($message, 'server has gone away') !== false
            || strpos($message, 'lost connection') !== false
            || strpos($message, 'error while sending') !== false;

        if (!$isGoneAway) {
            throw $e;
        }

        Logger::warning('DB_RECONNECT', ['error' => $e->getMessage()]);
        $pdo = $connect();
    }

    return $pdo;
}

/**
 * HTML 杞箟杈撳嚭
 * @param  string $value  
 * @return string
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * HTTP 閲嶅畾鍚? * @param  string $path  
 */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * 鑾峰彇/鐢熸垚 CSRF Token
 * @return string
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 娓叉煋 CSRF 闅愯棌瀛楁 HTML
 * @return string
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * 楠岃瘉 CSRF Token
 */
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('CSRF token mismatch.');
    }
}

/**
 * 鍐欏叆闂瓨娑堟伅
 * @param  string $type  
 * @param  string $message  
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * 鑾峰彇骞舵竻闄ゆ墍鏈夐棯瀛樻秷鎭? * @return array
 */
function flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/**
 * 鏄惁鍚敤 SSL 楠岃瘉
 * @return bool
 */
function ssl_verify_enabled(): bool
{
    // 浼樺厛浣跨敤閰嶇疆锛岄粯璁?true锛堝畨鍏ㄤ紭鍏堬級
    return (bool) config('app.ssl_verify', true);
}

/**
 * 鑾峰彇褰撳墠鐧诲綍鐢ㄦ埛锛堝甫 Session 缂撳瓨锛? * @return ?array
 */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    // Session 绾х紦瀛橈細15 绉掑唴涓嶉噸澶嶆煡璇㈡暟鎹簱
    $cacheKey = 'user_cache_' . $_SESSION['user_id'];
    if (isset($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
        $cached = $_SESSION[$cacheKey];
        if (isset($cached['expires']) && $cached['expires'] > time()) {
            return $cached['data'];
        }
    }

    $stmt = db()->prepare('SELECT id, username, email, role, credits, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;

    if (!$user || (int) $user['is_active'] !== 1) {
        unset($_SESSION['user_id']);
        unset($_SESSION[$cacheKey]);
        return null;
    }

    // 鍐欏叆 session 缂撳瓨锛?5 绉掕繃鏈?
    $_SESSION[$cacheKey] = [
        'data' => $user,
        'expires' => time() + 15,
    ];

    return $user;
}

/**
 * 瑕佹眰鐢ㄦ埛鐧诲綍锛屾湭鐧诲綍璺宠浆
 * @return array
 */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login');
    }
    return $user;
}

/**
 * 瑕佹眰绠＄悊鍛樻潈闄愶紝闈炵鐞嗗憳 403
 * @return array
 */
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }
    return $user;
}

/**
 * 鑾峰彇搴旂敤璁剧疆鍊硷紙鍏ㄩ噺缂撳瓨锛岄伩鍏?N+1锛? * @param  string $key  
 * @param  ?string $default  
 * @return ?string
 */
function app_setting(string $key, ?string $default = null): ?string
{
    // 鍏ㄩ噺缂撳瓨锛氫竴娆℃€у姞杞芥墍鏈夐厤缃埌鍐呭瓨锛岄伩鍏?N+1 鏌ヨ
    static $allSettings = null;
    static $initialized = false;
    
    if (!$initialized || !empty($GLOBALS['_app_setting_cache_dirty'])) {
        try {
            $stmt = db()->query('SELECT setting_key, setting_value FROM app_settings');
            $allSettings = [];
            while ($row = $stmt->fetch()) {
                $allSettings[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (Throwable $e) {
            $allSettings = [];
        }
        $initialized = true;
        $GLOBALS['_app_setting_cache_dirty'] = false;
    }
    return array_key_exists($key, $allSettings) ? $allSettings[$key] : $default;
}

/**
 * 璁剧疆搴旂敤璁剧疆鍊? * @param  string $key  
 * @param  string $value  
 */
function set_app_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);

    // 娓呴櫎鍏ㄩ噺缂撳瓨锛屼笅娆¤鍙栨椂浼氶噸鏂板姞杞?    app_setting_clear_cache();
}

/**
 * 娓呴櫎 app_setting 鍏ㄩ噺缂撳瓨
 */
function app_setting_clear_cache(): void
{
    // 娓呴櫎 app_setting 鐨勫叏閲忕紦瀛?
    // 閫氳繃寮哄埗閲嶆柊鍒濆鍖栭潤鎬佸彉閲忕殑鏂瑰紡瀹炵幇
    // 鍦?PHP 涓棤娉曠洿鎺ヤ粠澶栭儴閲嶇疆鍑芥暟鐨勯潤鎬佸彉閲忥紝
    // 浣跨敤涓€涓叏灞€鏍囪鏉ヨЕ鍙戠紦瀛橀噸寤?
    $GLOBALS['_app_setting_cache_dirty'] = true;
}

/**
 * 纭繚 generation_records 琛ㄦ湁 soft delete 瀛楁锛堝箓绛夛級
 */
function ensure_generation_records_soft_delete(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = db()->query("SHOW COLUMNS FROM generation_records LIKE 'deleted_at'");
    if (!$stmt->fetch()) {
        db()->exec(
            'ALTER TABLE generation_records
             ADD COLUMN deleted_at DATETIME NULL AFTER finished_at,
             ADD KEY idx_generation_deleted_at (deleted_at)'
        );
    }

    $checked = true;
}

/**
 * 纭繚 generation_records 琛?status 瀛楁鍖呭惈 'queued' 鍊硷紙骞傜瓑锛? */
function ensure_generation_records_queue_status(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $stmt = db()->query("SHOW COLUMNS FROM generation_records LIKE 'status'");
    $column = $stmt->fetch();
    $type = is_array($column) ? (string) ($column['Type'] ?? '') : '';
    if (strpos($type, "'queued'") === false) {
        db()->exec(
            "ALTER TABLE generation_records
             MODIFY status ENUM('queued', 'running', 'succeeded', 'failed') NOT NULL DEFAULT 'running'"
        );
    }

    $checked = true;
}

/**
 * 纭繚 generation_records 琛ㄦ湁 mode/input_images_json 绛夋墿灞曞瓧娈碉紙骞傜瓑锛? */
function ensure_generation_records_generation_options(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [];
    $stmt = db()->query('SHOW COLUMNS FROM generation_records');
    foreach ($stmt->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }

    if (empty($columns['mode'])) {
        db()->exec("ALTER TABLE generation_records ADD COLUMN mode VARCHAR(16) NOT NULL DEFAULT 'draw' AFTER status");
    }
    if (empty($columns['input_images_json'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN input_images_json LONGTEXT NULL AFTER output_format');
    }
    if (empty($columns['request_id'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN request_id VARCHAR(128) NULL AFTER error_message');
    }
    if (empty($columns['started_at'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER request_id');
    }
    if (empty($columns['ai_model_id'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN ai_model_id INT UNSIGNED NULL AFTER model');
    }

    $checked = true;
}

/**
 * 纭繚 generation_records 琛ㄦ湁瑙嗛鐩稿叧鐨勫垪锛堝箓绛夛級
 */
function ensure_generation_records_video_columns(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [];
    $stmt = db()->query('SHOW COLUMNS FROM generation_records');
    foreach ($stmt->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }

    if (empty($columns['video_url'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN video_url TEXT NULL AFTER mime_type');
    }
    if (empty($columns['video_base64'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN video_base64 LONGTEXT NULL AFTER video_url');
    }
    if (empty($columns['video_mime_type'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN video_mime_type VARCHAR(64) NULL AFTER video_base64');
    }
    if (empty($columns['video_task_id'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN video_task_id VARCHAR(128) NULL AFTER video_mime_type');
    }
    if (empty($columns['video_task_status'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN video_task_status VARCHAR(32) NULL AFTER video_task_id');
    }
    if (empty($columns['video_task_response'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN video_task_response LONGTEXT NULL AFTER video_task_status');
    }
    if (empty($columns['updated_at'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    }
    if (empty($columns['remote_task_id'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN remote_task_id VARCHAR(191) NULL AFTER video_task_response');
    }
    if (empty($columns['remote_status'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN remote_status VARCHAR(50) NULL AFTER remote_task_id');
    }
    if (empty($columns['last_poll_at'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN last_poll_at DATETIME NULL AFTER remote_status');
    }


    if (empty($columns['generation_config_snapshot'])) {
        db()->exec('ALTER TABLE generation_records ADD COLUMN generation_config_snapshot LONGTEXT NULL AFTER ai_model_id');
    }

    $checked = true;
}

/**
 * 纭繚 ai_models 琛ㄦ湁 model_type 鍒楋紙骞傜瓑锛? */
function ensure_ai_models_type_column(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [];
    $stmt = db()->query('SHOW COLUMNS FROM ai_models');
    foreach ($stmt->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }

    if (empty($columns['model_type'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN model_type VARCHAR(16) NOT NULL DEFAULT 'image' AFTER api_key");
    }

    if (empty($columns['credits'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN credits INT UNSIGNED DEFAULT NULL AFTER model_type");
    }

    if (empty($columns['invoke_mode'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN invoke_mode VARCHAR(16) NOT NULL DEFAULT 'relay' AFTER credits");
    }

    if (empty($columns['supports_edit'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN supports_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER invoke_mode");
    }

    if (empty($columns['edit_adapter'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN edit_adapter VARCHAR(32) NOT NULL DEFAULT 'none' AFTER supports_edit");
    }

    if (empty($columns['edit_image_field'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN edit_image_field VARCHAR(50) NOT NULL DEFAULT 'image_urls' AFTER edit_adapter");
    }

    if (empty($columns['supports_reference'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN supports_reference TINYINT(1) NOT NULL DEFAULT 0 AFTER edit_image_field");
    }

    if (empty($columns['reference_required'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN reference_required TINYINT(1) NOT NULL DEFAULT 0 AFTER supports_reference");
    }

    if (empty($columns['max_reference_images'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN max_reference_images TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER reference_required");
    }

    if (empty($columns['video_adapter'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_adapter VARCHAR(32) NOT NULL DEFAULT 'none' AFTER max_reference_images");
    }

    if (empty($columns['fixed_seconds'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN fixed_seconds TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER video_adapter");
    }

    if (empty($columns['video_resolution'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_resolution VARCHAR(16) NOT NULL DEFAULT 'auto' AFTER fixed_seconds");
    }

    if (empty($columns['video_aspect_ratio'])) {
        db()->exec("ALTER TABLE ai_models ADD COLUMN video_aspect_ratio VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER video_resolution");
    }

    $checked = true;
}

/**
 * 纭繚鍏戞崲鐮佺浉鍏宠〃瀛樺湪锛堝箓绛夛級
 */
function ensure_credit_tables(): void
{
    static $checked = false;
    if ($checked) return;

    $pdo = db();

    $stmt = $pdo->query("SHOW TABLES LIKE 'credit_codes'");
    if (!$stmt->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `credit_codes` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `code` VARCHAR(32) NOT NULL,
              `credits` INT NOT NULL DEFAULT 0,
              `max_uses` INT NOT NULL DEFAULT 1,
              `used_count` INT NOT NULL DEFAULT 0,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `expires_at` DATETIME DEFAULT NULL,
              `created_by` INT UNSIGNED DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_credit_codes_code` (`code`),
              KEY `idx_credit_codes_active` (`is_active`, `expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'credit_redemptions'");
    if (!$stmt->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `credit_redemptions` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `code_id` INT UNSIGNED NOT NULL,
              `user_id` INT UNSIGNED NOT NULL,
              `credits` INT NOT NULL DEFAULT 0,
              `redeemed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_redemptions_user` (`user_id`),
              KEY `idx_redemptions_code` (`code_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'upstream_cost_logs'");
    if (!$stmt->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `upstream_cost_logs` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `record_id` BIGINT UNSIGNED NOT NULL,
              `user_id` BIGINT UNSIGNED NOT NULL,
              `provider` VARCHAR(64) NOT NULL DEFAULT 'newtoken',
              `remote_task_id` VARCHAR(191) DEFAULT NULL,
              `remote_status` VARCHAR(64) DEFAULT NULL,
              `credits_refunded` INT NOT NULL DEFAULT 0,
              `note` VARCHAR(255) DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_upstream_cost_record` (`record_id`),
              KEY `idx_upstream_cost_user` (`user_id`),
              KEY `idx_upstream_cost_remote_task` (`remote_task_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    $checked = true;
}

/**
 * 杈撳嚭 JSON 鍝嶅簲骞剁粓姝? * @param  array $payload  
 * @param  int $status  
 */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 鐢熸垚闅忔満楠岃瘉鐮? * @param  int $length  
 * @return string
 */
function random_code(int $length = 16): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

/**
 * 鑾峰彇骞冲彴鍚嶇О
 * @return string
 */
function platform_name(): string
{
    $name = trim((string) app_setting('platform_name', ''));
    if ($name === '') {
        $name = trim((string) config('generation.platform_name', ''));
    }
    if ($name === '') {
        $name = trim((string) config('app.name', ''));
    }
    return $name !== '' ? $name : 'AI 鍥剧墖瑙嗛鍒涗綔绯荤粺';
}

/**
 * 鑾峰彇骞冲彴鏍囪瘑瀛楁瘝
 * @return string
 */
function platform_mark(): string
{
    $name = platform_name();
    if (preg_match('/[A-Za-z0-9]/', $name, $matches)) {
        return strtoupper($matches[0]);
    }
    return 'T';
}

/**
 * 鑾峰彇骞冲彴鐗堟湰鍙? * @return string
 */
function platform_version(): string
{
    $version = trim((string) config('generation.version', ''));
    return $version !== '' ? $version : '1.0.0';
}

/**
 * 鑾峰彇浣欓鏍囩鍚? * @return string
 */
function balance_label(): string
{
    $label = trim((string) app_setting('balance_label', ''));
    return $label !== '' ? $label : '浣欓';
}

/**
 * 浣欓鏂囨湰锛堟爣绛?+ 鏁板瓧锛? * @param  int $credits  
 * @return string
 */
function balance_text(int $credits): string
{
    return balance_label() . ' ' . $credits;
}

/**
 * 鑾峰彇鐢熸垚鐘舵€佺殑涓枃鏍囩
 * @param  string $status  
 * @return string
 */
function generation_status_label(string $status): string
{
    $labels = [
        'queued' => '排队中',
        'running' => '生成中',
        'succeeded' => '已完成',
        'failed' => '失败',
        'deleted' => '已删除',
    ];
    return $labels[$status] ?? $status;
}

/**
 * ?????????????
 */
function mode_display_label(string $mode): string
{
    $labels = [
        'draw' => '绘画',
        'edit' => '编辑',
        'video' => '视频',
    ];
    return $labels[$mode] ?? $mode;
}

/**
 * 纭繚 social_logins 琛ㄥ瓨鍦紙骞傜瓑锛? */
function ensure_social_logins_table(): void
{
    static $checked = false;
    if ($checked) return;

    $stmt = db()->query("SHOW TABLES LIKE 'social_logins'");
    if (!$stmt->fetch()) {
        db()->exec(
            "CREATE TABLE social_logins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type VARCHAR(20) NOT NULL COMMENT '鐧诲綍鏂瑰紡',
                social_uid VARCHAR(100) NOT NULL,
                access_token VARCHAR(200) DEFAULT NULL,
                nickname VARCHAR(100) DEFAULT NULL,
                faceimg VARCHAR(500) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_type_social_uid (type, social_uid),
                KEY idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
    $checked = true;
}

/**
 * 鑱氬悎鐧诲綍鏀寔鐨勭被鍨? */
function social_login_types(): array
{
    $u = static function (string $hex): string {
        $chars = preg_split('/\s+/', trim($hex));
        $out = '';
        foreach ($chars as $cp) {
            if ($cp === '') {
                continue;
            }
            $out .= mb_chr(hexdec($cp), 'UTF-8');
        }
        return $out;
    };

    return [
        'qq'       => 'QQ',
        'wx'       => $u('5FAE 4FE1'),
        'alipay'   => $u('652F 4ED8 5B9D'),
        'sina'     => $u('5FAE 535A'),
        'baidu'    => $u('767E 5EA6'),
        'huawei'   => $u('534E 4E3A'),
        'xiaomi'   => $u('5C0F 7C73'),
        'douyin'   => $u('6296 97F3'),
        'bilibili' => $u('54D4 54E9 54D4 54E9'),
        'dingtalk' => $u('9489 9489'),
    ];
}

/**
 * 鑱氬悎鐧诲綍鏄惁鍚敤
 */
function social_login_enabled(): bool
{
    return app_setting('social_login_enabled', 'off') === 'on';
}

/**
 * 鑾峰彇宸插惎鐢ㄧ殑鐧诲綍鏂瑰紡
 */
function social_login_active_types(): array
{
    if (!social_login_enabled()) return [];
    $raw = (string) app_setting('social_login_types', '');
    $selected = $raw ? explode(',', $raw) : [];
    $all = social_login_types();
    $result = [];
    foreach ($selected as $key) {
        if (isset($all[$key])) $result[$key] = $all[$key];
    }
    return $result;
}

/**
 * 纭繚 gallery 琛ㄥ瓨鍦紙骞傜瓑锛? */
function ensure_gallery_table(): void
{
    static $checked = false;
    if ($checked) return;
    $stmt = db()->query("SHOW TABLES LIKE 'gallery'");
    if (!$stmt->fetch()) {
        db()->exec(
            "CREATE TABLE gallery (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                record_id INT NOT NULL,
                username VARCHAR(100) NOT NULL DEFAULT '',
                prompt TEXT,
                image_url VARCHAR(2000) NOT NULL DEFAULT '',
                video_url VARCHAR(2000) NULL DEFAULT NULL,
                mime_type VARCHAR(50) NOT NULL DEFAULT 'image/png',
                model VARCHAR(100) NOT NULL DEFAULT '',
                mode VARCHAR(20) NOT NULL DEFAULT 'draw',
                size VARCHAR(20) NOT NULL DEFAULT 'auto',
                likes INT NOT NULL DEFAULT 0,
                deleted_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user_id (user_id),
                KEY idx_record_id (record_id),
                KEY idx_created (created_at),
                KEY idx_mode (mode),
                KEY idx_deleted_at (deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } else {
        $columns = [];
        $colStmt = db()->query('SHOW COLUMNS FROM gallery');
        foreach ($colStmt->fetchAll() as $column) {
            $columns[(string) $column['Field']] = true;
        }
        if (empty($columns['video_url'])) {
            db()->exec("ALTER TABLE gallery ADD COLUMN video_url VARCHAR(2000) NULL DEFAULT NULL AFTER image_url");
        }
        if (empty($columns['deleted_at'])) {
            db()->exec("ALTER TABLE gallery ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER likes");
            db()->exec("ALTER TABLE gallery ADD KEY idx_deleted_at (deleted_at)");
        }
    }
    $checked = true;
}

/**
 * 纭繚閭€璇风浉鍏宠〃/鍒楀瓨鍦紙骞傜瓑锛? */
function ensure_invite_tables(): void
{
    static $checked = false;
    if ($checked) return;

    // 妫€鏌ュ垪鏄惁瀛樺湪鍐嶆坊鍔?
    $cols = [];
    try {
        $stmt = db()->query("SHOW COLUMNS FROM users");
        while ($row = $stmt->fetch()) { $cols[] = $row['Field']; }
    } catch (Throwable $e) { return; }

    if (!in_array('invite_code', $cols, true)) {
        db()->exec("ALTER TABLE users ADD COLUMN invite_code VARCHAR(20) NULL");
    }
    if (!in_array('invited_by', $cols, true)) {
        db()->exec("ALTER TABLE users ADD COLUMN invited_by INT NULL");
    }

    // 妫€鏌ョ储寮曟槸鍚﹀瓨鍦?
    try {
        $keys = db()->query("SHOW INDEX FROM users WHERE Key_name = 'idx_invite_code'")->fetchAll();
        if (!$keys) db()->exec("ALTER TABLE users ADD UNIQUE KEY idx_invite_code (invite_code)");
    } catch (Throwable $e) {}

    try {
        $keys = db()->query("SHOW INDEX FROM users WHERE Key_name = 'idx_invited_by'")->fetchAll();
        if (!$keys) db()->exec("ALTER TABLE users ADD KEY idx_invited_by (invited_by)");
    } catch (Throwable $e) {}

    $stmt = db()->query("SHOW TABLES LIKE 'invite_commissions'");
    if (!$stmt->fetch()) {
        db()->exec(
            "CREATE TABLE invite_commissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                inviter_id INT NOT NULL,
                invited_user_id INT NOT NULL,
                order_id INT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                credits INT NOT NULL DEFAULT 0,
                type VARCHAR(20) NOT NULL DEFAULT 'recharge',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_inviter (inviter_id),
                KEY idx_invited (invited_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
    $checked = true;
}

function invite_enabled(): bool
{ return app_setting('invite_enabled', 'off') === 'on'; }

function invite_commission_percent(): int
{ return max(0, min(100, (int) app_setting('invite_commission_percent', '10'))); }

function invite_bonus_credits(): int
{ return max(0, (int) app_setting('invite_bonus_credits', '0')); }

function generate_invite_code(int $userId): string
{
    ensure_invite_tables();
    for ($i = 0; $i < 5; $i++) {
        $code = substr(md5('inv_' . $userId . '_' . time() . '_' . random_int(1000, 9999)), 0, 8);
        $stmt = db()->prepare('SELECT id FROM users WHERE invite_code = ? AND id != ? LIMIT 1');
        $stmt->execute([$code, $userId]);
        if (!$stmt->fetch()) {
            db()->prepare('UPDATE users SET invite_code = ? WHERE id = ?')->execute([$code, $userId]);
            return $code;
        }
    }
    return '';
}

function user_invite_code(array $user): string
{
    $code = $user['invite_code'] ?? '';
    return ($code === '' || $code === null) ? generate_invite_code((int)$user['id']) : $code;
}

function user_invite_stats(int $userId): array
{
    ensure_invite_tables();
    $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE invited_by = ?');
    $stmt->execute([$userId]);
    return ['invited_count' => (int) $stmt->fetchColumn()];
}

function invite_process_commission(int $invitedUserId, float $orderAmount, int $orderCredits, int $orderId = 0): void
{
    if (!invite_enabled()) return;
    $stmt = db()->prepare('SELECT u.invited_by FROM users u WHERE u.id = ? LIMIT 1');
    $stmt->execute([$invitedUserId]);
    $row = $stmt->fetch();
    if (!$row || !$row['invited_by']) return;
    $inviterId = (int) $row['invited_by'];
    $pct = invite_commission_percent();
    if ($pct <= 0) return;
    $cc = (int) round($orderCredits * $pct / 100);
    $ca = round($orderAmount * $pct / 100, 2);
    if ($cc > 0) db()->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([$cc, $inviterId]);
    db()->prepare('INSERT INTO invite_commissions (inviter_id, invited_user_id, order_id, amount, credits, type)
        VALUES (?,?,?,?,?,?)')->execute([$inviterId, $invitedUserId, $orderId, $ca, $cc, 'recharge']);
}
