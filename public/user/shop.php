<?php

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/migration.php';
require_once __DIR__ . '/../../src/pay.php';

$user = require_login();
ensure_all_tables();
seed_default_packages();

$stmt = db()->prepare('SELECT * FROM shop_packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
$stmt->execute();
$packages = $stmt->fetchAll();

$stmt = db()->prepare('SELECT id, order_no, package_name, credits, amount, pay_type, status, paid_at, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$stmt->execute([$user['id']]);
$recentOrders = $stmt->fetchAll();

$payTypes = pay_type_list();
$isPayConfigured = pay_is_configured();
$balanceLabel = balance_label();

render_header('商城', 'shop');
?>
<style>
.balance-banner { display: flex; align-items: center; gap: 14px; padding: 18px 22px; background: linear-gradient(135deg, rgba(139,92,246,.08), rgba(59,130,246,.06)); border: 1px solid rgba(139,92,246,.15); border-radius: 16px; margin-bottom: 20px; }
.balance-banner .icon { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #8b5cf6, #3b82f6); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px; flex-shrink: 0; }
.balance-banner .info { flex: 1; }
.balance-banner .info .label { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.balance-banner .info .value { font-size: 28px; font-weight: 800; color: var(--text); }
.balance-banner .actions { display: flex; gap: 8px; flex-shrink: 0; }
.pricing-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 28px; }
.pricing-card { position: relative; background: var(--main-surface); border: 1.5px solid var(--line); border-radius: 20px; padding: 28px 24px 24px; text-align: center; transition: all .2s; overflow: hidden; }
.pricing-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,.08); }
.pricing-card.featured { border-color: #8b5cf6; box-shadow: 0 0 0 1px #8b5cf6; }
.pricing-card.featured::before { content: '热门'; position: absolute; top: 14px; right: -28px; background: linear-gradient(135deg, #8b5cf6, #3b82f6); color: #fff; font-size: 10px; font-weight: 700; padding: 3px 32px; transform: rotate(45deg); }
.pricing-card .pkg-name { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
.pricing-card .pkg-desc { font-size: 12px; color: var(--text-muted); margin-bottom: 16px; min-height: 18px; }
.pricing-card .pkg-price { font-size: 36px; font-weight: 800; margin-bottom: 4px; }
.pricing-card .pkg-price sup { font-size: 18px; font-weight: 600; }
.pricing-card .pkg-credits { font-size: 14px; color: var(--primary); font-weight: 600; margin-bottom: 20px; padding: 6px 0; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.pricing-card .pkg-btn { width: 100%; padding: 12px; border-radius: 12px; font-weight: 700; font-size: 14px; border: none; cursor: pointer; transition: all .15s; }
.pricing-card .pkg-btn.primary { background: #111; color: #fff; }
.pricing-card .pkg-btn.primary:hover { background: #333; }
.pricing-card .pkg-btn.outline { background: transparent; border: 1.5px solid var(--line); color: var(--text); }
.pricing-card .pkg-btn:disabled { opacity: .4; cursor: not-allowed; }
.order-list { display: flex; flex-direction: column; gap: 6px; }
.order-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: var(--main-surface); border: 1px solid var(--line); border-radius: 12px; font-size: 13px; transition: all .1s; }
.order-item:hover { border-color: var(--primary-soft); }
.order-item .oid { font-family: monospace; font-size: 11px; color: var(--text-muted); min-width: 140px; word-break: break-all; }
.order-item .pkg { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.order-item .amt { font-weight: 700; min-width: 70px; text-align: right; }
.order-item .time { font-size: 11px; color: var(--text-muted); min-width: 90px; text-align: right; }
</style>
<main>
    <div class="page-hd">
        <div><h1>点数商城</h1><p>Shop</p></div>
    </div>

    <!-- 余额横幅 -->
    <div class="balance-banner">
        <div class="icon">💰</div>
        <div class="info">
            <div class="label">当前<?= e($balanceLabel) ?></div>
            <div class="value" data-balance-display><?= number_format((int) $user['credits']) ?></div>
        </div>
        <div class="actions">
            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('redeemDialog').classList.remove('hidden');document.body.classList.add('has-dialog');">🎫 兑换码</button>
        </div>
    </div>

    <?php if (!$isPayConfigured): ?>
        <div class="alert warning"><strong>支付功能暂未配置</strong><span>管理员尚未配置支付接口。</span></div>
    <?php endif; ?>

    <!-- 套餐卡片 -->
    <?php if ($packages): ?>
        <div class="pricing-row">
            <?php foreach ($packages as $pkg):
                $pid = (int) $pkg['id']; $pn = e((string) $pkg['name']); $pd = e((string) ($pkg['description'] ?? ''));
                $pc = (int) $pkg['credits']; $pp = (float) $pkg['price'];
            ?>
            <div class="pricing-card <?= $pid === 3 ? 'featured' : '' ?>">
                <div class="pkg-name"><?= $pn ?></div>
                <div class="pkg-desc"><?= $pd ?: '&nbsp;' ?></div>
                <div class="pkg-price"><sup>¥</sup><?= number_format($pp, 2) ?></div>
                <div class="pkg-credits">+<?= number_format($pc) ?> <?= e($balanceLabel) ?></div>
                <?php if ($isPayConfigured): ?>
                    <button class="pkg-btn primary" data-package-id="<?= $pid ?>" data-package-name="<?= $pn ?>" data-package-credits="<?= $pc ?>" data-package-price="<?= $pp ?>" data-open-buy>立即购买</button>
                <?php else: ?>
                    <button class="pkg-btn outline" disabled>暂不可用</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><div class="icon">📦</div><h3>暂无可用套餐</h3></div>
    <?php endif; ?>

    <!-- 最近订单 -->
    <?php if ($recentOrders): ?>
    <section class="card-v3">
        <div class="card-v3-head">
            <div><h3>最近订单</h3><p class="sub">共 <?= count($recentOrders) ?> 条</p></div>
        </div>
        <div class="card-v3-body">
            <div class="order-list">
                <?php foreach ($recentOrders as $o):
                    $sl = ['pending'=>'待支付','paid'=>'已支付','failed'=>'失败','refunded'=>'已退款'][$o['status']] ?? $o['status'];
                    $sc = ['pending'=>'running','paid'=>'succeeded','failed'=>'failed','refunded'=>'deleted'][$o['status']] ?? '';
                    $pt = (string) ($o['pay_type'] ?? '');
                ?>
                <div class="order-item">
                    <span class="oid"><?= e($o['order_no']) ?></span>
                    <span class="pkg"><?= e($o['package_name']) ?></span>
                    <span class="status-badge <?= $sc ?>"><?= e($sl) ?></span>
                    <span class="amt">¥<?= number_format((float) $o['amount'], 2) ?></span>
                    <span class="time"><?= e($o['created_at']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
</main>

<!-- 购买确认弹窗 -->
<div id="buyDialog" class="redeem-dialog hidden">
    <div class="redeem-panel" role="dialog" aria-modal="true">
        <div class="redeem-head">
            <div><h2>确认购买</h2></div>
            <button type="button" class="dialog-close" data-close-buy>关闭</button>
        </div>
        <div class="redeem-form">
            <div class="buy-summary">
                <div class="buy-summary-row"><span>套餐</span><strong id="buyPkgName">-</strong></div>
                <div class="buy-summary-row"><span><?= e($balanceLabel) ?></span><strong id="buyPkgCredits">-</strong></div>
                <div class="buy-summary-row total"><span>支付金额</span><strong id="buyPkgPrice">-</strong></div>
            </div>
            <div class="pay-type-select">
                <span class="field-hint">支付方式</span>
                <div class="pay-type-grid">
                    <label class="pay-type-item"><input type="radio" name="pay_type" value="alipay" checked><span>支付宝</span></label>
                    <label class="pay-type-item"><input type="radio" name="pay_type" value="wxpay"><span>微信支付</span></label>
                    <label class="pay-type-item"><input type="radio" name="pay_type" value="qqpay"><span>QQ钱包</span></label>
                </div>
            </div>
            <form id="orderForm" method="post" action="/order">
                <?= csrf_field() ?>
                <input type="hidden" name="package_id" id="buyPkgId" value="">
                <input type="hidden" name="pay_type" id="buyPayType" value="alipay">
                <button class="btn btn-primary" type="submit" style="width:100%;">确认支付</button>
            </form>
        </div>
    </div>
</div>

<script>
(function(){
    var d = document.querySelector('#buyDialog');
    var id = document.querySelector('#buyPkgId'), nm = document.querySelector('#buyPkgName');
    var cr = document.querySelector('#buyPkgCredits'), pr = document.querySelector('#buyPkgPrice');
    var pt = document.querySelector('#buyPayType');
    document.addEventListener('click', function(e) {
        var b = e.target.closest('[data-open-buy]'); if (!b) return;
        id.value = b.dataset.packageId; nm.textContent = b.dataset.packageName;
        cr.textContent = b.dataset.packageCredits + ' <?= e($balanceLabel) ?>';
        pr.textContent = '¥' + parseFloat(b.dataset.packagePrice).toFixed(2);
        d.classList.remove('hidden'); document.body.classList.add('has-dialog');
    });
    document.addEventListener('click', function(e) {
        if (e.target === d || e.target.closest('[data-close-buy]')) { d.classList.add('hidden'); document.body.classList.remove('has-dialog'); }
    });
    document.querySelectorAll('input[name="pay_type"]').forEach(function(r) {
        r.addEventListener('change', function() { pt.value = this.value; });
    });
    window.addEventListener('keydown', function(e) { if (e.key === 'Escape') { d.classList.add('hidden'); document.body.classList.remove('has-dialog'); } });
})();
</script>
<?php render_footer(); ?>
