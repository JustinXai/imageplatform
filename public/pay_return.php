<?php

/**
 * 彩虹易支付 - 同步跳转通知处理
 *
 * 支付完成后跳转到此页面
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/migration.php';
require_once __DIR__ . '/../src/pay.php';

ensure_all_tables();

$pid = pay_config('pid');
$key = pay_config('key');
$params = $_GET;

// 验证签名
$signValid = pay_verify_sign($params, $key);

// 获取订单号
$orderNo = (string) ($params['out_trade_no'] ?? '');
$tradeStatus = (string) ($params['trade_status'] ?? '');

$order = null;
if ($orderNo !== '') {
    $stmt = db()->prepare('SELECT * FROM orders WHERE order_no = ? LIMIT 1');
    $stmt->execute([$orderNo]);
    $order = $stmt->fetch();
}

$isSuccess = $signValid && $tradeStatus === 'TRADE_SUCCESS' && $order && $order['status'] === 'paid';

render_header('支付结果', 'shop');
?>
<main class="auth-wrap">
    <section class="card auth-card" style="text-align:center;">
        <div class="card-head" style="justify-content:center;">
            <div>
                <p class="eyebrow">Payment Result</p>
                <h2><?= $isSuccess ? '支付成功' : '支付处理中' ?></h2>
            </div>
        </div>

        <?php if ($isSuccess): ?>
            <div style="margin:20px 0;font-size:48px;">✅</div>
            <p class="muted">订单号：<?= e($orderNo) ?></p>
            <?php if ($order): ?>
                <p class="muted">
                    已购买：<?= e($order['package_name']) ?>（<?= number_format((int) $order['credits']) ?> <?= e(balance_label()) ?>）
                </p>
                <p class="muted">金额：¥<?= number_format((float) $order['amount'], 2) ?></p>
            <?php endif; ?>
            <p class="muted" style="margin-top:12px;">你的<?= e(balance_label()) ?>已到账，可以开始创作了！</p>
            <div style="display:flex;gap:10px;justify-content:center;margin-top:18px;">
                <a class="button primary" href="/user/index">开始创作</a>
                <a class="button secondary" href="/user/shop">返回商城</a>
            </div>
        <?php else: ?>
            <div style="margin:20px 0;font-size:48px;">⏳</div>
            <p class="muted">订单号：<?= e($orderNo ?: '-') ?></p>
            <p class="muted">
                <?php if (!$signValid): ?>
                    签名验证失败，请联系管理员核实订单。
                <?php elseif ($tradeStatus !== 'TRADE_SUCCESS'): ?>
                    支付尚未完成，请确认是否已付款。
                <?php else: ?>
                    订单正在处理中，<?= e(balance_label()) ?>会在到账后自动更新。
                <?php endif; ?>
            </p>
            <div style="display:flex;gap:10px;justify-content:center;margin-top:18px;">
                <a class="button primary" href="/user/shop">返回商城</a>
                <a class="button secondary" href="/user/index">查看<?= e(balance_label()) ?></a><?= e(balance_label()) ?></a>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php render_footer(); ?>
