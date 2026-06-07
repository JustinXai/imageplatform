<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';

$user = require_login();
ensure_invite_tables();

$inviteCode = user_invite_code($user);
$stats = user_invite_stats((int) $user['id']);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$inviteLink = $scheme . '://' . $host . '/login?invite=' . urlencode($inviteCode);
$enabled = invite_enabled();
$commissionPct = invite_commission_percent();
$bonusCredits = invite_bonus_credits();

// 已邀请用户列表
$stmt = db()->prepare('SELECT u.username, u.email, u.created_at FROM users u WHERE u.invited_by = ? ORDER BY u.created_at DESC LIMIT 50');
$stmt->execute([(int)$user['id']]);
$invitedUsers = $stmt->fetchAll();

// 佣金记录
$stmt = db()->prepare('SELECT ic.*, o.trade_no, o.amount as order_amount FROM invite_commissions ic LEFT JOIN orders o ON o.id = ic.order_id WHERE ic.inviter_id = ? ORDER BY ic.created_at DESC LIMIT 50');
$stmt->execute([(int)$user['id']]);
$commissions = $stmt->fetchAll();

render_header('邀请有礼', 'invite');
?>
<style>
.invite-page { max-width: 800px; margin: 0 auto; }
.invite-hero {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 20px;
    padding: 36px 32px;
    color: #fff;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.invite-hero::after {
    content: '';
    position: absolute;
    right: -40px; top: -40px;
    width: 200px; height: 200px;
    border-radius: 50%;
    background: rgba(255,255,255,0.08);
}
.invite-hero h2 { font-size: 22px; font-weight: 700; margin: 0 0 8px; }
.invite-hero p { opacity: 0.9; margin: 0 0 20px; font-size: 14px; }
.invite-code-box {
    display: flex; align-items: center; gap: 12px;
    background: rgba(255,255,255,0.15);
    border-radius: 12px;
    padding: 14px 20px;
    backdrop-filter: blur(10px);
}
.invite-code-box .code-text {
    font-size: 24px; font-weight: 800; letter-spacing: 4px;
    font-family: 'Courier New', monospace;
    flex: 1;
}
.invite-copy-btn {
    padding: 8px 20px;
    border-radius: 8px;
    border: 2px solid rgba(255,255,255,0.5);
    background: rgba(255,255,255,0.15);
    color: #fff;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s;
    white-space: nowrap;
}
.invite-copy-btn:hover { background: rgba(255,255,255,0.25); }
.invite-copy-btn.copied { background: #10b981; border-color: #10b981; }

.invite-link-box {
    display: flex; align-items: center; gap: 10px;
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
    padding: 10px 16px;
    margin-top: 12px;
}
.invite-link-box .link-text {
    flex: 1; font-size: 13px; opacity: 0.85;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.invite-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
.invite-stat {
    background: var(--card-bg); border: 1px solid var(--line);
    border-radius: 14px; padding: 18px; text-align: center;
}
.invite-stat .num { font-size: 26px; font-weight: 700; color: var(--text); }
.invite-stat .lbl { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

.invite-section {
    background: var(--card-bg); border: 1px solid var(--line);
    border-radius: 16px; padding: 24px; margin-bottom: 20px;
}
.invite-section h3 { font-size: 16px; font-weight: 700; margin: 0 0 16px; color: var(--text); }

.invite-rule-list { list-style: none; padding: 0; margin: 0; }
.invite-rule-list li {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 14px; color: var(--text);
}
.invite-rule-list li:last-child { border-bottom: none; }
.invite-rule-list .step {
    display: inline-flex; align-items: center; justify-content: center;
    width: 24px; height: 24px; border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: #fff; font-size: 12px; font-weight: 700; flex-shrink: 0;
}

.invited-table { width: 100%; border-collapse: collapse; }
.invited-table th, .invited-table td { padding: 10px 12px; text-align: left; font-size: 13px; }
.invited-table th { font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--line); }
.invited-table td { border-bottom: 1px solid var(--line); color: var(--text); }
.invited-table tr:last-child td { border-bottom: none; }

.commission-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; }
.commission-tag.recharge { background: #dbeafe; color: #1d4ed8; }
</style>

<main class="invite-page">
    <?php if (!$enabled): ?>
    <div style="text-align:center;padding:80px 20px;color:var(--text-muted);">
        <div style="font-size:48px;margin-bottom:12px;">🎁</div>
        <p>邀请功能暂未开放，请联系管理员。</p>
    </div>
    <?php else: ?>
    <!-- Hero -->
    <div class="invite-hero">
        <h2>🎁 邀请好友，一起赚积分</h2>
        <p>邀请好友注册，你们都能获得奖励！好友充值你还能持续获得佣金。</p>
        <div class="invite-code-box">
            <span class="code-text"><?= e($inviteCode) ?></span>
            <button class="invite-copy-btn" onclick="copyInvite(this)" data-code="<?= e($inviteCode) ?>">📋 复制</button>
        </div>
        <div class="invite-link-box">
            <span class="link-text"><?= e($inviteLink) ?></span>
            <button class="invite-copy-btn" onclick="copyInvite(this)" data-code="<?= e($inviteLink) ?>">📋 复制链接</button>
        </div>
    </div>

    <!-- 统计数据 -->
    <div class="invite-stats">
        <div class="invite-stat">
            <div class="num"><?= $stats['invited_count'] ?></div>
            <div class="lbl">已邀请用户</div>
        </div>
        <div class="invite-stat">
            <div class="num"><?= $commissionPct ?>%</div>
            <div class="lbl">充值佣金比例</div>
        </div>
        <div class="invite-stat">
            <div class="num">+<?= $bonusCredits ?></div>
            <div class="lbl">邀请奖励(<?= e(balance_label()) ?>)</div>
        </div>
    </div>

    <!-- 邀请规则 -->
    <div class="invite-section">
        <h3>📜 邀请规则</h3>
        <ul class="invite-rule-list">
            <li><span class="step">1</span> 复制你的专属邀请码或邀请链接分享给好友</li>
            <li><span class="step">2</span> 好友通过邀请链接注册，注册后双方各得 <strong><?= $bonusCredits ?> <?= e(balance_label()) ?></strong></li>
            <li><span class="step">3</span> 好友每次充值，你将获得充值金额的 <strong><?= $commissionPct ?>%</strong> 作为佣金（以<?= e(balance_label()) ?>形式发放）</li>
        </ul>
    </div>

    <!-- 已邀请用户 -->
    <div class="invite-section">
        <h3>👥 已邀请用户 (<?= count($invitedUsers) ?>)</h3>
        <?php if ($invitedUsers): ?>
        <table class="invited-table">
            <thead>
                <tr><th>用户名</th><th>邮箱</th><th>注册时间</th></tr>
            </thead>
            <tbody>
                <?php foreach ($invitedUsers as $iu): ?>
                <tr>
                    <td><?= e($iu['username']) ?></td>
                    <td><?= e($iu['email']) ?></td>
                    <td><?= e($iu['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="color:var(--text-muted);text-align:center;padding:20px;">还没有邀请用户，快去分享吧！</p>
        <?php endif; ?>
    </div>

    <!-- 佣金记录 -->
    <?php if ($commissions): ?>
    <div class="invite-section">
        <h3>💰 佣金记录</h3>
        <table class="invited-table">
            <thead>
                <tr><th>时间</th><th>类型</th><th><?= e(balance_label()) ?></th><th>金额</th></tr>
            </thead>
            <tbody>
                <?php foreach ($commissions as $c): ?>
                <tr>
                    <td><?= e($c['created_at']) ?></td>
                    <td><span class="commission-tag <?= e($c['type']) ?>">充值佣金</span></td>
                    <td><strong>+<?= (int)$c['credits'] ?></strong></td>
                    <td>¥<?= number_format((float)$c['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</main>

<script>
function copyInvite(btn) {
    const code = btn.dataset.code;
    navigator.clipboard.writeText(code).then(() => {
        btn.textContent = '✅ 已复制';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = '📋 复制'; btn.classList.remove('copied'); }, 2000);
    }).catch(() => {
        // fallback
        const ta = document.createElement('textarea');
        ta.value = code; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        btn.textContent = '✅ 已复制';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = '📋 复制'; btn.classList.remove('copied'); }, 2000);
    });
}
</script>

<?php render_footer(); ?>
