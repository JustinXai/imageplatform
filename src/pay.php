<?php

declare(strict_types=1);

/**
 * 彩虹易支付 - 支付核心库
 *
 * 所有 URL 地址均从后台配置读取，无内置默认值。
 *
 * 签名算法：MD5
 * 字符编码：UTF-8
 */

/**
 * 获取支付接口配置值，无默认值
 */
function pay_config(string $key)
{
    static $cache = [];
    if (!isset($cache[$key])) {
        $cache[$key] = app_setting('pay_' . $key, '');
    }
    return $cache[$key];
}

/**
 * 获取页面跳转支付接口 URL（submit.php）
 */
function pay_submit_url(): string
{
    $url = pay_config('submit_url');
    if ($url !== '' && substr(rtrim($url, '/'), -11) !== '/submit.php') {
        $url = rtrim($url, '/') . '/submit.php';
    }
    return $url;
}

/**
 * 检查支付是否已配置完整
 */
function pay_is_configured(): bool
{
    $pid = pay_config('pid');
    $key = pay_config('key');
    $submitUrl = pay_submit_url();

    return $pid !== '' && $key !== '' && $submitUrl !== '';
}

/**
 * 生成 MD5 签名
 *
 * 规则：
 * 1. 所有参数按参数名 ASCII 码从小到大排序（a-z）
 * 2. sign、sign_type 和空值不参与签名
 * 3. 拼接成 URL 键值对格式 a=b&c=d&e=f，参数值不 URL 编码
 * 4. 拼接字符串 + 商户密钥 KEY 进行 MD5 加密
 * 5. md5 结果为小写
 */
function pay_sign(array $params, string $key): string
{
    // 过滤：去掉 sign、sign_type 和空值
    $filtered = [];
    foreach ($params as $k => $v) {
        if ($k === 'sign' || $k === 'sign_type') {
            continue;
        }
        if ((string) $v === '') {
            continue;
        }
        $filtered[$k] = (string) $v;
    }

    // 按参数名 ASCII 排序
    ksort($filtered, SORT_STRING);

    // 拼接成 URL 键值对
    $parts = [];
    foreach ($filtered as $k => $v) {
        $parts[] = $k . '=' . $v;
    }
    $signStr = implode('&', $parts);

    // MD5(拼接字符串 + KEY)
    return strtolower(md5($signStr . $key));
}

/**
 * 验证回调签名
 */
function pay_verify_sign(array $params, string $key): bool
{
    if (empty($params['sign'])) {
        return false;
    }
    $expectedSign = pay_sign($params, $key);
    return hash_equals($expectedSign, (string) $params['sign']);
}

/**
 * 页面跳转支付 - 生成支付 URL (GET 方式)
 *
 * @param array $params 支付参数
 * @param string $key 商户密钥
 * @return string 支付跳转 URL
 * @throws RuntimeException 当 submit 地址未配置时
 */
function pay_build_url(array $params, string $key): string
{
    $submitUrl = pay_submit_url();
    if ($submitUrl === '') {
        throw new RuntimeException('后台未配置页面跳转支付接口地址。');
    }

    $params['sign'] = pay_sign($params, $key);
    $params['sign_type'] = 'MD5';

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return $submitUrl . '?' . $query;
}

/**
 * 页面跳转支付 - 生成支付表单 HTML (POST 方式，推荐)
 *
 * @param array $params 支付参数
 * @param string $key 商户密钥
 * @return string HTML 表单
 * @throws RuntimeException 当 submit 地址未配置时
 */
function pay_build_form(array $params, string $key): string
{
    $submitUrl = pay_submit_url();
    if ($submitUrl === '') {
        throw new RuntimeException('后台未配置页面跳转支付接口地址。');
    }

    $params['sign'] = pay_sign($params, $key);
    $params['sign_type'] = 'MD5';

    $html = '<form id="payForm" method="post" action="' . e($submitUrl) . '">';
    foreach ($params as $k => $v) {
        $html .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
    }
    $html .= '</form>';
    $html .= '<script>document.getElementById("payForm").submit();</script>';
    return $html;
}

/**
 * API 接口支付 - 发起支付并返回 JSON
 *
 * @param array $params 支付参数（需含 clientip）
 * @param string $key 商户密钥
 * @return array {code, msg, trade_no, payurl?, qrcode?, urlscheme?}
 */
function pay_api_request(array $params, string $key): array
{
    $apiUrl = pay_api_url();
    if ($apiUrl === '') {
        return [
            'code' => -1,
            'msg' => '后台未配置 API 支付接口地址。',
        ];
    }

    $params['sign'] = pay_sign($params, $key);
    $params['sign_type'] = 'MD5';

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&'),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => ssl_verify_enabled(),
        CURLOPT_SSL_VERIFYHOST => ssl_verify_enabled() ? 2 : 0,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        return [
            'code' => -1,
            'msg' => $curlError ?: '支付接口请求失败',
        ];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [
            'code' => -1,
            'msg' => '支付接口返回数据异常',
            'raw' => mb_substr((string) $raw, 0, 500),
        ];
    }

    return $data;
}

/**
 * 生成商户订单号
 */
function pay_generate_order_no(): string
{
    $prefix = date('YmdHis');
    $rand = bin2hex(random_bytes(4));
    return $prefix . $rand;
}

/**
 * 获取支付方式列表（前端展示用）
 */
function pay_type_list(): array
{
    return [
        'alipay' => '支付宝',
        'wxpay' => '微信支付',
        'qqpay' => 'QQ 钱包',
        'bank' => '银联支付',
        'jdpay' => '京东支付',
        'paypal' => 'PayPal',
        'douyinpay' => '抖音支付',
    ];
}
