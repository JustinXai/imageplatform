<?php

declare(strict_types=1);

/**
 * API 令牌核心函数
 *
 * @author Senior Developer
 */

/**
 * 生成随机令牌字符串（显示给用户一次）
 */
function generate_api_token(): string
{
    $bytes = random_bytes(32);
    return 'sk-' . bin2hex($bytes);
}

/**
 * 创建 API 令牌
 *
 * @param  int      $userId     用户 ID
 * @param  string   $name       令牌名称（用户自定义）
 * @param  array    $permissions 权限列表
 * @param  int|null $expireDays  过期天数（null=永不过期）
 * @return array{token_raw: string, token: array}
 */
function create_api_token(int $userId, string $name, array $permissions, ?int $expireDays = null): array
{
    $raw    = generate_api_token();
    $hash   = hash('sha256', $raw);
    $expire = $expireDays !== null ? date('Y-m-d H:i:s', time() + $expireDays * 86400) : null;

    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO api_tokens (user_id, name, token_hash, permissions, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        trim($name),
        $hash,
        json_encode($permissions, JSON_UNESCAPED_UNICODE),
        $expire,
    ]);

    $tokenId = (int) $pdo->lastInsertId();
    return [
        'token_raw' => $raw,
        'token'     => api_token_record_by_id($tokenId),
    ];
}

/**
 * 撤销 API 令牌
 */
function revoke_api_token(int $userId, int $tokenId): void
{
    $stmt = db()->prepare('UPDATE api_tokens SET is_revoked = 1 WHERE id = ? AND user_id = ?');
    $stmt->execute([$tokenId, $userId]);
}

/**
 * 验证 API 令牌
 *
 * @param  string      $tokenRaw 原始令牌字符串
 * @return array|null  验证通过返回 {user_id, permissions, user_info}，否则 null
 */
function validate_api_token(string $tokenRaw): ?array
{
    $hash = hash('sha256', $tokenRaw);

    $stmt = db()->prepare(
        'SELECT t.*, u.id AS uid, u.username, u.credits, u.role, u.email
         FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.is_revoked = 0
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    // 检查过期
    if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
        return null;
    }

    // 更新最后使用时间
    $stmt2 = db()->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?');
    $stmt2->execute([$row['id']]);

    $permissions = [];
    if (!empty($row['permissions'])) {
        $decoded = json_decode((string) $row['permissions'], true);
        if (is_array($decoded)) {
            $permissions = $decoded;
        }
    }

    return [
        'user_id'     => (int) $row['uid'],
        'permissions' => $permissions,
        'user_info'   => [
            'id'       => (int) $row['uid'],
            'username' => (string) $row['username'],
            'credits'  => (int) $row['credits'],
            'role'     => (string) $row['role'],
            'email'    => (string) $row['email'],
        ],
    ];
}

/**
 * 获取用户的 API 令牌列表
 *
 * @param  int   $userId
 * @return array
 */
function get_user_api_tokens(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT id, user_id, name, permissions, last_used_at, expires_at, is_revoked, created_at
         FROM api_tokens
         WHERE user_id = ?
         ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * 按 ID 查询令牌记录
 */
function api_token_record_by_id(int $tokenId): array
{
    $stmt = db()->prepare(
        'SELECT id, user_id, name, permissions, last_used_at, expires_at, is_revoked, created_at
         FROM api_tokens
         WHERE id = ?'
    );
    $stmt->execute([$tokenId]);
    return $stmt->fetch() ?: [];
}

/**
 * 令牌是否拥有指定权限
 */
function api_token_has_permission(array $permissions, string $required): bool
{
    return in_array($required, $permissions, true);
}

/**
 * 可用权限列表
 */
function api_available_permissions(): array
{
    return [
        'generate_image' => '图片生成',
        'generate_video' => '视频生成',
        'check_record'   => '查询记录',
        'check_credits'  => '查询积分',
    ];
}
