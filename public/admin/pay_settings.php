<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/pay.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $payPid       = trim((string) ($_POST['pay_pid'] ?? ''));
    $payKey       = trim((string) ($_POST['pay_key'] ?? ''));
    $paySubmitUrl = trim((string) ($_POST['pay_submit_url'] ?? ''));
    if ($paySubmitUrl !== '' && substr(rtrim($paySubmitUrl, '/'), -11) !== '/submit.php') {
        $paySubmitUrl = rtrim($paySubmitUrl, '/') . '/submit.php';
    }
    $payNotifyUrl = trim((string) ($_POST['pay_notify_url'] ?? ''));
    $payReturnUrl = trim((string) ($_POST['pay_return_url'] ?? ''));

    if ($payPid !== '' && !ctype_digit($payPid)) {
        flash('error', '商户 ID 必须为数字。');
        redirect('/admin/pay_settings');
    }
    if ($paySubmitUrl === '') {
        flash('error', '支付接口地址不能为空。');
        redirect('/admin/pay_settings');
    }
    if ($payNotifyUrl !== '' && !filter_var($payNotifyUrl, FILTER_VALIDATE_URL)) {
        flash('error', '异步通知地址格式不正确。');
        redirect('/admin/pay_settings');
    }
    if ($payReturnUrl !== '' && !filter_var($payReturnUrl, FILTER_VALIDATE_URL)) {
        flash('error', '跳转通知地址格式不正确。');
        redirect('/admin/pay_settings');
    }

    set_app_setting('pay_pid', $payPid);
    set_app_setting('pay_mode', 'redirect');
    if ($payKey !== '' || app_setting('pay_key', '') === '') {
        set_app_setting('pay_key', $payKey);
    }
    set_app_setting('pay_submit_url', $paySubmitUrl);
    set_app_setting('pay_notify_url', $payNotifyUrl);
    set_app_setting('pay_return_url', $payReturnUrl);

    flash('success', '支付配置已保存。');
    redirect('/admin/pay_settings');
}

$payPid       = app_setting('pay_pid', '');
$payKey       = app_setting('pay_key', '');
$paySubmitUrl = app_setting('pay_submit_url', '');
$payNotifyUrl = app_setting('pay_notify_url', '');
$payReturnUrl = app_setting('pay_return_url', '');
$hasKey       = $payKey !== '';
$isConfigured = pay_is_configured();

$baseUrl = rtrim((string) config('app.base_url', ''), '/');
if ($baseUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $baseUrl = $scheme . '://' . $host;
}
$autoNotifyUrl = $baseUrl . '/pay_notify';
$autoReturnUrl = $baseUrl . '/pay_return';

render_header('支付配置', 'admin');
render_admin_nav('pay_settings');
?>
<style>
.pay-page { max-width: 800px; margin: 0 auto; }

.pay-status-bar {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 20px;
    border-radius: 14px;
    margin-bottom: 24px;
    font-weight: 600;
    font-size: 14px;
}
.pay-status-bar.success {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #065f46;
}
.pay-status-bar.warning {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
}
.pay-status-dot {
    width: 10px; height: 10px;
    border-radius: 50%; flex-shrink: 0;
}
.pay-status-bar.success .pay-status-dot { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
.pay-status-bar.warning .pay-status-dot { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.2); }
.pay-status-bar .txt { flex: 1; }
.pay-status-bar .desc { font-size: 12px; font-weight: 400; opacity: .7; }

.pay-section {
    background: var(--card-bg);
    border: 1px solid var(--line);
    border-radius: 16px;
    margin-bottom: 20px;
    overflow: hidden;
}
.pay-section-header {
    display: flex; align-items: center; gap: 12px;
    padding: 18px 24px;
    border-bottom: 1px solid var(--line);
    background: var(--main-surface-soft);
}
.pay-section-header .icon {
    display: flex; align-items: center; justify-content: center;
    width: 34px; height: 34px;
    border-radius: 10px;
    font-size: 16px;
    flex-shrink: 0;
}
.icon-blue  { background: #dbeafe; color: #2563eb; }
.icon-green { background: #d1fae5; color: #059669; }
.icon-amber { background: #fef3c7; color: #d97706; }
.icon-purple { background: #ede9fe; color: #7c3aed; }
.pay-section-header h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--text); }
.pay-section-header p { margin: 2px 0 0; font-size: 12px; color: var(--text-muted); }

.pay-section-body { padding: 24px; }

.pay-field { margin-bottom: 18px; }
.pay-field:last-child { margin-bottom: 0; }
.pay-field label {
    display: block;
    font-size: 13px; font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
}
.pay-field input {
    width: 100%;
    padding: 10px 14px;
    font-size: 14px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--main-surface);
    color: var(--text);
    box-sizing: border-box;
    transition: border-color .2s, box-shadow .2s;
}
.pay-field input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
}
.pay-field .hint {
    font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.6;
}
.pay-field .hint code {
    background: var(--main-surface-soft);
    padding: 1px 6px;
    border-radius: 4px;
    border: 1px solid var(--line);
    font-size: 12px;
}

.pay-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 18px;
}
.pay-grid:last-child { margin-bottom: 0; }
@media (max-width: 640px) { .pay-grid { grid-template-columns: 1fr; } }
.pay-grid .pay-field { margin-bottom: 0; }

.pay-info-card {
    padding: 16px 20px;
    background: var(--main-surface-soft);
    border-radius: 12px;
    border: 1px solid var(--line);
    font-size: 13px;
    color: var(--text-soft);
    line-height: 1.8;
}
.pay-info-card strong { color: var(--text); }

.pay-submit-row {
    display: flex; justify-content: flex-end;
    padding-top: 8px;
}
.pay-btn {
    padding: 12px 32px;
    font-size: 15px; font-weight: 700;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: opacity .15s, transform .15s;
}
.pay-btn:hover { opacity: .9; transform: translateY(-1px); }
</style>

<main class="pay-page">
    <!-- 状态条 -->
    <?php if ($isConfigured): ?>
    <div class="pay-status-bar success">
        <span class="pay-status-dot"></span>
        <span class="txt">支付功能已配置</span>
        <span class="desc">用户可以在商城在线购买<?= e(balance_label()) ?>套餐。</span>
    </div>
    <?php else: ?>
    <div class="pay-status-bar warning">
        <span class="pay-status-dot"></span>
        <span class="txt">支付功能未配置</span>
        <span class="desc">请填写商户 ID、密钥和接口地址以启用支付功能。</span>
    </div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <!-- 基础配置 -->
        <div class="pay-section">
            <div class="pay-section-header">
                <div class="icon icon-blue">🔑</div>
                <div>
                    <h3>基础配置</h3>
                    <p>商户 ID 与密钥</p>
                </div>
            </div>
            <div class="pay-section-body">
                <div class="pay-grid">
                    <div class="pay-field">
                        <label>商户 ID (pid)</label>
                        <input name="pay_pid" value="<?= e($payPid) ?>" placeholder="例如：1001">
                        <div class="hint">彩虹易支付平台分配给你的商户 ID。</div>
                    </div>
                    <div class="pay-field">
                        <label>商户密钥 (KEY) <?= $hasKey ? '<span style="color:#059669;font-size:11px;">（已配置）</span>' : '' ?></label>
                        <input name="pay_key" type="password" autocomplete="off" placeholder="<?= $hasKey ? '已配置，留空不修改' : '请输入密钥' ?>">
                        <div class="hint"><?= $hasKey ? '已有密钥，留空则不修改。' : '支付签名所使用的商户密钥。' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 接口配置 -->
        <div class="pay-section">
            <div class="pay-section-header">
                <div class="icon icon-green">🔗</div>
                <div>
                    <h3>支付接口配置</h3>
                    <p>页面跳转支付地址</p>
                </div>
            </div>
            <div class="pay-section-body">
                <div class="pay-field">
                    <label>页面跳转接口地址</label>
                    <input name="pay_submit_url" value="<?= e($paySubmitUrl) ?>" placeholder="https://你的域名" required>
                    <div class="hint"><code>/submit.php</code> 会自动拼接。用户提交订单后跳转至此地址完成支付。</div>
                </div>
            </div>
        </div>

        <!-- 通知地址 -->
        <div class="pay-section">
            <div class="pay-section-header">
                <div class="icon icon-purple">📡</div>
                <div>
                    <h3>通知地址配置</h3>
                    <p>支付回调与跳转地址</p>
                </div>
            </div>
            <div class="pay-section-body">
                <div class="pay-grid">
                    <div class="pay-field">
                        <label>异步通知地址 (notify_url)</label>
                        <input name="pay_notify_url" value="<?= e($payNotifyUrl ?: $autoNotifyUrl) ?>" placeholder="<?= e($autoNotifyUrl) ?>">
                        <div class="hint">默认：<code><?= e($autoNotifyUrl) ?></code></div>
                    </div>
                    <div class="pay-field">
                        <label>跳转通知地址 (return_url)</label>
                        <input name="pay_return_url" value="<?= e($payReturnUrl ?: $autoReturnUrl) ?>" placeholder="<?= e($autoReturnUrl) ?>">
                        <div class="hint">默认：<code><?= e($autoReturnUrl) ?></code></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 接口信息 -->
        <div class="pay-section">
            <div class="pay-section-header">
                <div class="icon icon-amber">📋</div>
                <div>
                    <h3>接口信息（参考）</h3>
                    <p>对接所需的参数说明</p>
                </div>
            </div>
            <div class="pay-section-body">
                <div class="pay-info-card">
                    <strong>签名算法</strong>：MD5 &nbsp;|&nbsp; <strong>字符编码</strong>：UTF-8<br>
                    <strong>支付方式</strong>：页面跳转支付 (redirect)<br>
                    <strong>支持渠道</strong>：支付宝、微信支付、QQ钱包、银联支付、京东支付、PayPal、抖音支付
                </div>
            </div>
        </div>

        <div class="pay-submit-row">
            <button type="submit" class="pay-btn">💾 保存配置</button>
        </div>
    </form>
</main>

<?php render_footer(); ?>
