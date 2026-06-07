<?php

declare(strict_types=1);

/**
 * API 认证中间件
 *
 * 在 API 端点入口处调用 require_api_token() 验证 Bearer Token，
 * 再用 require_api_permission() 检查具体权限。
 *
 * @author Senior Developer
 */

// 自动确保 api_tokens 表存在（首次调用时建表）
if (!defined('API_TOKENS_ENSURED')) {
    define('API_TOKENS_ENSURED', true);
    require_once __DIR__ . '/migration.php';
    ensure_api_tokens_table();
}

/** @var array|null 当前请求的令牌信息，由 require_api_token() 设置 */
$_api_token_context = null;

/**
 * 从请求头中提取并验证 API 令牌
 *
 * @return array{user_id: int, permissions: array, user_info: array}
 * @throws RuntimeException 验证失败时
 */
function require_api_token(): array
{
    global $_api_token_context;

    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        throw new RuntimeException('缺少有效的 Authorization 头，格式：Bearer <token>');
    }

    $tokenRaw = $m[1];
    $result = validate_api_token($tokenRaw);

    if ($result === null) {
        throw new RuntimeException('API 令牌无效、已过期或已被撤销。');
    }

    $_api_token_context = $result;
    return $result;
}

/**
 * 检查当前令牌是否有指定权限
 *
 * @param  string $permission
 * @throws RuntimeException 无权限时
 */
function require_api_permission(string $permission): void
{
    global $_api_token_context;

    if ($_api_token_context === null) {
        throw new RuntimeException('请先调用 require_api_token() 验证令牌。');
    }

    $permissions = $_api_token_context['permissions'] ?? [];

    if (!in_array($permission, $permissions, true)) {
        throw new RuntimeException('当前令牌无"' . $permission . '"权限。');
    }
}

/**
 * 双重鉴权：优先使用 API Token，其次使用 Web Session
 *
 * 适用于 OpenAI 兼容端点（支持 Bearer Token 和 Cookie Session 两种方式）
 *
 * @return array{user_id: int, permissions: array, user_info: array}
 * @throws RuntimeException 两种方式都验证失败时
 */
function require_dual_auth(): array
{
    // 先尝试 API Token
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        $tokenRaw = $m[1];
        $result = validate_api_token($tokenRaw);
        if ($result !== null) {
            return $result;
        }
        throw new RuntimeException('API 令牌无效、已过期或已被撤销。');
    }

    // 降级到 Web Session
    if (!isset($GLOBALS['_api_token_context'])) {
        $GLOBALS['_api_token_context'] = null;
    }
    require_once __DIR__ . '/bootstrap.php';
    $user = require_login();
    return [
        'user_id'     => (int) $user['id'],
        'permissions' => ['chat'],
        'user_info'   => $user,
    ];
}
