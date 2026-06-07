<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';
require_once __DIR__ . '/../../src/api_token.php';

$user = require_login();
ensure_api_tokens_table();

$balanceLabel = balance_label();
$tokens = get_user_api_tokens((int) $user['id']);
$allPerms = api_available_permissions();

// 处理 POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['api_token_action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['token_name'] ?? ''));
        if ($name === '') {
            flash('error', '请填写令牌名称。');
            redirect('/user/api_tokens');
        }

        $permissions = array_intersect(
            (array) ($_POST['permissions'] ?? []),
            array_keys($allPerms)
        );

        if (empty($permissions)) {
            flash('error', '请至少选择一个权限。');
            redirect('/user/api_tokens');
        }

        $expireDays = (int) ($_POST['expire_days'] ?? 0);
        $result = create_api_token((int) $user['id'], $name, $permissions, $expireDays > 0 ? $expireDays : null);

        $_SESSION['api_token_created'] = true;
        $_SESSION['api_token_raw'] = $result['token_raw'];
        flash('success', 'API 令牌已创建，请立即复制保存！');
        redirect('/user/api_tokens');
    }

    if ($action === 'revoke') {
        $tokenId = (int) ($_POST['token_id'] ?? 0);
        if ($tokenId > 0) {
            revoke_api_token((int) $user['id'], $tokenId);
            flash('success', 'API 令牌已撤销。');
        }
        redirect('/user/api_tokens');
    }

    flash('error', '未知操作。');
    redirect('/user/api_tokens');
}

render_header('API 令牌', 'api-tokens');
?>
<main style="max-width:860px;margin:0 auto;padding:0 4px;">
    <div class="page-hd">
        <div>
            <h1>API 令牌</h1>
            <p>用于第三方站点或脚本调用本程序接口，令牌仅创建时显示一次</p>
        </div>
        <div class="page-hd-actions">
            <a href="/user/shop" class="badge-balance">
                <span class="num" data-balance-display><?= number_format((int) $user['credits']) ?></span>
                <span class="label"><?= e($balanceLabel) ?></span>
            </a>
        </div>
    </div>

    <!-- 使用示例 -->
    <div class="card-v3" style="margin-bottom:20px;">
        <div class="card-v3-body" style="padding:16px 20px;">
            <details>
                <summary style="cursor:pointer;font-size:13px;font-weight:700;color:var(--primary);margin-bottom:8px;">查看使用示例</summary>
                <pre style="margin:0;padding:14px;background:var(--main-surface-soft);border-radius:12px;overflow-x:auto;font-size:12px;line-height:1.6;"><code># 生成图片
curl -X POST https://ai.kbl6.cn/api/generate \
  -H "Authorization: Bearer 你的令牌" \
  -H "Content-Type: application/json" \
  -d '{"prompt":"一只猫","size":"1024x1024"}'

# 查询结果
curl https://ai.kbl6.cn/api/check?id=123 \
  -H "Authorization: Bearer 你的令牌"

# 查看积分
curl https://ai.kbl6.cn/api/credits \
  -H "Authorization: Bearer 你的令牌"</code></pre>
            </details>
        </div>
    </div>

    <!-- 创建令牌 -->
    <div class="card-v3" style="margin-bottom:20px;">
        <div class="card-v3-head">
            <h3>创建新令牌</h3>
        </div>
        <div class="card-v3-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="api_token_action" value="create">

                <div class="field-v3">
                    <label>令牌名称</label>
                    <input name="token_name" type="text" placeholder="如：我的博客对接" maxlength="50" required>
                </div>

                <div class="field-v3">
                    <label>权限选择</label>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                        <?php foreach ($allPerms as $key => $label): ?>
                            <label style="display:flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--line);border-radius:8px;cursor:pointer;font-size:13px;transition:all.12s;">
                                <input type="checkbox" name="permissions[]" value="<?= e($key) ?>" checked style="width:14px;height:14px;">
                                <span><?= e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="field-v3">
                    <label>过期时间</label>
                    <select name="expire_days">
                        <option value="0">永不过期</option>
                        <option value="30">30 天</option>
                        <option value="90" selected>90 天</option>
                        <option value="180">180 天</option>
                        <option value="365">1 年</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">生成令牌</button>
            </form>
        </div>
    </div>

    <!-- 已有令牌 -->
    <div class="card-v3">
        <div class="card-v3-head">
            <h3>已有令牌</h3>
            <span class="sub">共 <?= count($tokens) ?> 个</span>
        </div>
        <div class="card-v3-body" style="padding:0;">
            <?php if (empty($tokens)): ?>
                <div class="empty-state">
                    <div class="icon">🔑</div>
                    <h3>暂无 API 令牌</h3>
                    <p>在上方创建你的第一个令牌</p>
                </div>
            <?php else: ?>
                <div style="padding:4px 0;">
                    <?php foreach ($tokens as $t): ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:8px;">
                            <div>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                    <strong style="font-size:14px;"><?= e($t['name']) ?></strong>
                                    <?php if ($t['is_revoked']): ?>
                                        <span class="status-badge failed">已撤销</span>
                                    <?php else: ?>
                                        <span class="status-badge succeeded">启用</span>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex;flex-wrap:wrap;gap:4px 12px;font-size:12px;color:var(--text-muted);">
                                    <span>权限：<?php
                                        $perms = json_decode((string) ($t['permissions'] ?? '[]'), true) ?: [];
                                        echo $perms ? implode('、', array_map(function($p) use ($allPerms) {
                                            return $allPerms[$p] ?? $p;
                                        }, $perms)) : '无权限';
                                    ?></span>
                                    <span>最后使用：<?= $t['last_used_at'] ? e($t['last_used_at']) : '从未' ?></span>
                                    <span>创建于：<?= e($t['created_at']) ?></span>
                                    <?php if ($t['expires_at']): ?>
                                        <span>过期于：<?= e($t['expires_at']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!$t['is_revoked']): ?>
                                <form method="post" style="margin:0;" onsubmit="return confirm('确认撤销此令牌？撤销后无法恢复。')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="api_token_action" value="revoke">
                                    <input type="hidden" name="token_id" value="<?= (int) $t['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">撤销</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['api_token_created'])): ?>
        <div style="margin-top:20px;padding:16px 20px;border-radius:16px;background:#ecfdf5;border:1px solid #a7f3d0;">
            <strong style="color:#059669;font-size:14px;">令牌已创建！请立即复制并妥善保存，关闭后将无法再次查看。</strong>
            <div style="margin-top:8px;font-family:monospace;font-size:14px;word-break:break-all;background:#fff;padding:12px;border:1px solid #a7f3d0;border-radius:10px;color:#059669;">
                <?= e((string) ($_SESSION['api_token_raw'] ?? '')) ?>
            </div>
        </div>
        <?php unset($_SESSION['api_token_created'], $_SESSION['api_token_raw']); ?>
    <?php endif; ?>
</main>
<?php render_footer(); ?>
