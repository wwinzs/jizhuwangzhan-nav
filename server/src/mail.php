<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/**
 * Fail-closed SMTP credentials and TLS configuration.
 * An opt-in flag plus the curl extension is required, even for local testing.
 * Never include credentials, tokens or transport error messages in logs.
 */
function nav_mail_settings(array $config): ?array {
    if (($config['password_reset_enabled'] ?? false) !== true) return null;
    if (!extension_loaded('curl')) return null;
    $smtp = $config['smtp'] ?? null;
    if (!is_array($smtp)) return null;
    foreach (['host','username','password','from'] as $key) {
        if (!is_string($smtp[$key] ?? null) || $smtp[$key] === '') return null;
    }
    $host = $smtp['host'];
    if (strlen($host) > 253 || !preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])$/D', $host)
        || str_contains($host, '..')) return null;
    $mode = $smtp['tls'] ?? null;
    $port = $smtp['port'] ?? null;
    if (($mode !== 'smtps' || $port !== 465)
        && ($mode !== 'starttls' || $port !== 587)) return null;
    if (nav_email($smtp['from']) !== $smtp['from']) return null;
    if (strlen($smtp['username']) > 254 || strlen($smtp['password']) > 512
        || preg_match('/[\r\n]/', $smtp['username'] . $smtp['password'])) return null;
    $caFile = $smtp['ca_file'] ?? null;
    if ($caFile !== null && (!is_string($caFile) || $caFile === ''
        || !is_file($caFile) || !is_readable($caFile))) return null;
    return [
        'host'=>$host, 'port'=>$port, 'tls'=>$mode,
        'username'=>$smtp['username'], 'password'=>$smtp['password'],
        'from'=>$smtp['from'], 'ca_file'=>$caFile,
    ];
}

function nav_mail_ready(): bool {
    return nav_mail_settings(nav_config()) !== null;
}

/** An HTTPS first-party URL with a fragment: reset token is not sent to Nginx in the request URL. */
function nav_reset_mail_url(string $token): string {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new InvalidArgumentException('Invalid reset token');
    $origin = nav_config()['frontend_origin'];
    if (!str_starts_with($origin, 'https://'))
        throw new RuntimeException('Reset link requires an HTTPS website');
    return $origin . '/#password-reset=' . $token;
}

/** No dynamic headers or attacker-controlled subject/from fields. */
function nav_reset_mail_content(string $recipient, string $token): string {
    if (nav_email($recipient) !== $recipient) throw new InvalidArgumentException('Invalid recipient');
    $link = nav_reset_mail_url($token);
    $body = "收到记住网站的密码重置请求。\n\n"
      . "请在 15 分钟内使用以下链接修改密码：\n"
      . $link . "\n\n"
      . "如果不是你操作，请忽略本邮件。不要转发此链接。\n"
      . "新密码设置后，所有旧设备会话将退出。\n";
    return "To: <" . $recipient . ">\r\n"
      . "From: <" . nav_mail_settings(nav_config())['from'] . ">\r\n"
      . "Subject: =?UTF-8?B?" . base64_encode('记住网站 - 重置登录密码') . "?=\r\n"
      . "MIME-Version: 1.0\r\n"
      . "Content-Type: text/plain; charset=UTF-8\r\n"
      . "Content-Transfer-Encoding: base64\r\n"
      . "\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";
}

/**
 * Deliver through libcurl SMTP with TLS verification enabled.
 * No curl verbose mode or header output: messages contain sensitive reset links.
 * Caller must handle false without echoing transport diagnostics.
 */
function nav_send_reset_mail(string $recipient, string $token): bool {
    $settings = nav_mail_settings(nav_config());
    if (!$settings || nav_email($recipient) !== $recipient) return false;
    $message = nav_reset_mail_content($recipient, $token);
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) return false;
    $curl = null;
    try {
        if (fwrite($stream, $message) !== strlen($message)) return false;
        rewind($stream);
        $curl = curl_init();
        if ($curl === false) return false;
        $url = ($settings['tls'] === 'smtps' ? 'smtps' : 'smtp')
            . '://' . $settings['host'] . ':' . $settings['port'];
        $opts = [
            CURLOPT_URL=>$url,
            CURLOPT_USERNAME=>$settings['username'],
            CURLOPT_PASSWORD=>$settings['password'],
            CURLOPT_MAIL_FROM=>$settings['from'],
            CURLOPT_MAIL_RCPT=>[$recipient],
            CURLOPT_USE_SSL=>CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_UPLOAD=>true,
            CURLOPT_INFILE=>$stream,
            CURLOPT_INFILESIZE=>strlen($message),
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>12,
            CURLOPT_NOSIGNAL=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROXY=>'',
            CURLOPT_VERBOSE=>false,
        ];
        if ($settings['ca_file'] !== null) $opts[CURLOPT_CAINFO] = $settings['ca_file'];
        if (defined('CURLOPT_PROTOCOLS_STR')) $opts[CURLOPT_PROTOCOLS_STR] = 'smtp,smtps';
        else $opts[CURLOPT_PROTOCOLS] = CURLPROTO_SMTP | CURLPROTO_SMTPS;
        if (!curl_setopt_array($curl, $opts)) return false;
        return curl_exec($curl) !== false;
    } finally {
        if ($curl !== null) curl_close($curl);
        fclose($stream);
    }
}
