<?php

declare(strict_types=1);

/**
 * 纯 PHP SMTP 邮件发送类
 * 无需 Composer/外部库，直接通过 fsockopen 连接 SMTP 服务器
 *
 * 配置从 app_settings 中读取：
 *   smtp_host, smtp_port, smtp_username, smtp_password,
 *   smtp_encryption (ssl/tls/none), smtp_from_email, smtp_from_name
 */

/**
 * 发送邮件
 *
 * @param string $to 收件人邮箱
 * @param string $subject 邮件主题
 * @param string $body HTML 正文
 * @return array ['ok' => bool, 'message' => string]
 */
function smtp_is_configured(): bool
{
    $host = trim((string) app_setting('smtp_host', ''));
    $username = trim((string) app_setting('smtp_username', ''));
    $password = trim((string) app_setting('smtp_password', ''));
    $fromEmail = trim((string) app_setting('smtp_from_email', ''));

    return $host !== '' && $username !== '' && $password !== '' && $fromEmail !== '';
}

function send_mail(string $to, string $subject, string $body): array
{
    $host = trim((string) app_setting('smtp_host', ''));
    $port = (int) app_setting('smtp_port', '465');
    $username = trim((string) app_setting('smtp_username', ''));
    $password = trim((string) app_setting('smtp_password', ''));
    $encryption = trim((string) app_setting('smtp_encryption', 'ssl'));
    $fromEmail = trim((string) app_setting('smtp_from_email', ''));
    $fromName = trim((string) app_setting('smtp_from_name', ''));

    if (!smtp_is_configured()) {
        return ['ok' => false, 'message' => 'SMTP 配置不完整，请在后台配置邮件发送参数。'];
    }

    if ($fromName === '') {
        $fromName = $fromEmail;
    }

    return smtp_send($host, $port, $username, $password, $encryption, $fromEmail, $fromName, $to, $subject, $body);
}

/**
 * SMTP 底层发送
 */
function smtp_send(
    string $host, int $port, string $username, string $password, string $encryption,
    string $fromEmail, string $fromName, string $to, string $subject, string $body
): array {
    // 连接 SMTP 服务器
    $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 15);

    if ($socket === false) {
        return ['ok' => false, 'message' => '无法连接 SMTP 服务器：' . $errstr];
    }

    $log = [];
    $code = 0;

    try {
        // 读取欢迎信息
        if (!smtp_read($socket, 220, $log)) {
            throw new RuntimeException('SMTP 服务器无响应');
        }

        // EHLO
        smtp_write($socket, "EHLO localhost\r\n", $log);
        if (!smtp_read($socket, 250, $log)) {
            throw new RuntimeException('EHLO 失败');
        }

        // STARTTLS (如果使用 tls 加密)
        if ($encryption === 'tls') {
            smtp_write($socket, "STARTTLS\r\n", $log);
            if (!smtp_read($socket, 220, $log)) {
                throw new RuntimeException('STARTTLS 失败');
            }
            // 升级到 TLS
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS 加密失败');
            }
            // 重新 EHLO
            smtp_write($socket, "EHLO localhost\r\n", $log);
            if (!smtp_read($socket, 250, $log)) {
                throw new RuntimeException('EHLO (TLS) 失败');
            }
        }

        // AUTH LOGIN
        smtp_write($socket, "AUTH LOGIN\r\n", $log);
        if (!smtp_read($socket, 334, $log)) {
            throw new RuntimeException('AUTH 失败');
        }

        // 用户名 (Base64)
        smtp_write($socket, base64_encode($username) . "\r\n", $log);
        if (!smtp_read($socket, 334, $log)) {
            throw new RuntimeException('用户名验证失败');
        }

        // 密码 (Base64)
        smtp_write($socket, base64_encode($password) . "\r\n", $log);
        if (!smtp_read($socket, 235, $log)) {
            throw new RuntimeException('密码验证失败，请检查 SMTP 配置');
        }

        // MAIL FROM
        smtp_write($socket, "MAIL FROM:<{$fromEmail}>\r\n", $log);
        if (!smtp_read($socket, 250, $log)) {
            throw new RuntimeException('MAIL FROM 失败');
        }

        // RCPT TO
        smtp_write($socket, "RCPT TO:<{$to}>\r\n", $log);
        if (!smtp_read($socket, 250, $log)) {
            throw new RuntimeException('收件人地址无效：' . $to);
        }

        // DATA
        smtp_write($socket, "DATA\r\n", $log);
        if (!smtp_read($socket, 354, $log)) {
            throw new RuntimeException('DATA 命令失败');
        }

        // 构建邮件内容
        $headers = [
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>',
            'To: =?UTF-8?B?' . base64_encode($to) . '?= <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Date: ' . date('r'),
            'X-Mailer: PHP/' . phpversion(),
        ];

        $mailContent = implode("\r\n", $headers) . "\r\n\r\n";
        $mailContent .= chunk_split(base64_encode($body));
        $mailContent .= "\r\n.\r\n";

        smtp_write($socket, $mailContent, $log);
        if (!smtp_read($socket, 250, $log)) {
            throw new RuntimeException('邮件发送失败');
        }

        // QUIT
        smtp_write($socket, "QUIT\r\n", $log);
        @smtp_read($socket, 221, $log);

        fclose($socket);
        return ['ok' => true, 'message' => '邮件发送成功'];

    } catch (Throwable $e) {
        @fclose($socket);
        $logStr = implode("\n", array_slice($log, -5));
        return ['ok' => false, 'message' => $e->getMessage() . "\n日志：" . $logStr];
    }
}

/**
 * 向 SMTP 服务器写入数据
 */
function smtp_write($socket, string $data, array &$log): void
{
    $log[] = 'C: ' . trim($data);
    fwrite($socket, $data);
}

/**
 * 从 SMTP 服务器读取响应，验证期望的状态码
 */
function smtp_read($socket, int $expectedCode, array &$log): string
{
    $response = '';
    while ($line = fgets($socket, 512)) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    $log[] = 'S: ' . trim($response);

    // 提取状态码
    $code = (int) substr($response, 0, 3);
    if ($code !== $expectedCode) {
        return '';
    }
    return $response;
}

/**
 * 生成邮箱验证邮件 HTML
 */
function build_verify_email_html(string $platformName, string $username, string $verifyUrl): string
{
    return '
    <div style="max-width:600px;margin:0 auto;padding:30px 20px;font-family:system-ui,-apple-system,sans-serif;">
        <div style="text-align:center;margin-bottom:30px;">
            <h2 style="color:#213547;margin:0;">' . htmlspecialchars($platformName, ENT_QUOTES, 'UTF-8') . '</h2>
        </div>
        <div style="background:#ffffff;border:1px solid #dfe7e2;border-radius:12px;padding:30px;">
            <h3 style="color:#213547;margin:0 0 16px;">验证您的邮箱</h3>
            <p style="color:#47566a;line-height:1.6;">您好，' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '：</p>
            <p style="color:#47566a;line-height:1.6;">感谢您注册 ' . htmlspecialchars($platformName, ENT_QUOTES, 'UTF-8') . '！请点击下方按钮验证您的邮箱地址：</p>
            <div style="text-align:center;margin:24px 0;">
                <a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '"
                   style="display:inline-block;padding:12px 32px;background:linear-gradient(135deg,#42d392,#42b883);color:#fff;text-decoration:none;border-radius:10px;font-weight:700;">
                    验证邮箱
                </a>
            </div>
            <p style="color:#6b7280;font-size:13px;line-height:1.6;">如果按钮无法点击，请复制以下链接到浏览器打开：<br>
            <span style="color:#42b883;">' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '</span></p>
            <p style="color:#6b7280;font-size:13px;line-height:1.6;">此链接有效期 24 小时，请尽快验证。</p>
        </div>
        <div style="text-align:center;margin-top:20px;">
            <p style="color:#6b7280;font-size:12px;">此邮件由系统自动发送，请勿回复。</p>
        </div>
    </div>';
}

/**
 * 生成密码重置邮件 HTML
 */
function build_reset_email_html(string $platformName, string $username, string $resetUrl): string
{
    return '
    <div style="max-width:600px;margin:0 auto;padding:30px 20px;font-family:system-ui,-apple-system,sans-serif;">
        <div style="text-align:center;margin-bottom:30px;">
            <h2 style="color:#213547;margin:0;">' . htmlspecialchars($platformName, ENT_QUOTES, 'UTF-8') . '</h2>
        </div>
        <div style="background:#ffffff;border:1px solid #dfe7e2;border-radius:12px;padding:30px;">
            <h3 style="color:#213547;margin:0 0 16px;">重置密码</h3>
            <p style="color:#47566a;line-height:1.6;">您好，' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '：</p>
            <p style="color:#47566a;line-height:1.6;">您已申请重置密码，请点击下方按钮设置新密码：</p>
            <div style="text-align:center;margin:24px 0;">
                <a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '"
                   style="display:inline-block;padding:12px 32px;background:linear-gradient(135deg,#42d392,#42b883);color:#fff;text-decoration:none;border-radius:10px;font-weight:700;">
                    重置密码
                </a>
            </div>
            <p style="color:#6b7280;font-size:13px;line-height:1.6;">如果按钮无法点击，请复制以下链接到浏览器打开：<br>
            <span style="color:#42b883;">' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '</span></p>
            <p style="color:#6b7280;font-size:13px;line-height:1.6;">此链接有效期 1 小时，如果不是您本人操作，请忽略此邮件。</p>
        </div>
        <div style="text-align:center;margin-top:20px;">
            <p style="color:#6b7280;font-size:12px;">此邮件由系统自动发送，请勿回复。</p>
        </div>
    </div>';
}

/**
 * 生成验证令牌
 */
function generate_auth_token(): string
{
    return bin2hex(random_bytes(32));
}
