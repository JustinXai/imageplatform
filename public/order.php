<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/migration.php';
require_once __DIR__ . '/../src/pay.php';

$user = require_login();
ensure_all_tables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/shop');
}

verify_csrf();

$packageId = (int) ($_POST['package_id'] ?? 0);
$payType = trim((string) ($_POST['pay_type'] ?? 'alipay'));

// 验证支付方式
$allowedTypes = array_keys(pay_type_list());
if ($payType !== '' && !in_array($payType, $allowedTypes, true)) {
    flash('error', '不支持的支付方式。');
    redirect('/user/shop');
}

// 验证支付配置
if (!pay_is_configured()) {
    flash('error', '支付功能尚未配置，请联系管理员。');
    redirect('/user/shop');
}

// 查询套餐
$stmt = db()->prepare('SELECT * FROM shop_packages WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$packageId]);
$package = $stmt->fetch();

if (!$package) {
    flash('error', '套餐不存在或已下架。');
    redirect('/user/shop');
}

// 生成订单号
$orderNo = pay_generate_order_no();

// 获取支付配置
$pid = pay_config('pid');
$key = pay_config('key');
$baseUrl = rtrim((string) config('app.base_url', ''), '/');
if ($baseUrl === '') {
    // 自动检测 base URL
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $baseUrl = $scheme . '://' . $host;
}

$notifyUrl = pay_config('notify_url') ?: ($baseUrl . '/pay_notify');
$returnUrl = pay_config('return_url') ?: ($baseUrl . '/pay_return');

// 创建订单记录
$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO orders (user_id, order_no, package_id, package_name, credits, amount, pay_type, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, "pending")'
    );
    $stmt->execute([
        $user['id'],
        $orderNo,
        $packageId,
        $package['name'],
        $package['credits'],
        $package['price'],
        $payType ?: null,
    ]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', '创建订单失败：' . $e->getMessage());
    redirect('/user/shop');
}

// ========== 页面跳转支付：生成表单跳转到支付页面 ==========
$formParams = [
    'pid' => (int) $pid,
    'type' => $payType,
    'out_trade_no' => $orderNo,
    'notify_url' => $notifyUrl,
    'return_url' => $returnUrl,
    'name' => $package['name'] . ' - ' . $package['credits'] . e(balance_label()),
    'money' => number_format((float) $package['price'], 2, '.', ''),
    'param' => (string) $orderId,
];
echo pay_build_form($formParams, $key);
exit;
