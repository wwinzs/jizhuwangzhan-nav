<?php
declare(strict_types=1);
if (getenv('CI') !== 'true') { fwrite(STDERR, "CI-only SMTP integration.\n"); exit(2); }
require_once dirname(__DIR__) . '/src/mail.php';
function mailCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL SMTP: '.$label);
    fwrite(STDOUT, 'PASS SMTP: '.$label.PHP_EOL);
}
$cfg = nav_config();
$settings = nav_mail_settings($cfg);
mailCheck(is_array($settings) && nav_mail_ready(), 'valid TLS-only SMTP configuration');
mailCheck(nav_mail_settings(array_merge($cfg,['password_reset_enabled'=>false]))===null,
    'disabled flag closes transport');
$bad = $cfg;
$bad['smtp']['tls']='plain';
mailCheck(nav_mail_settings($bad)===null, 'plaintext SMTP refused');
$bad = $cfg;
$bad['smtp']['port']=25;
mailCheck(nav_mail_settings($bad)===null, 'unencrypted SMTP port refused');
$bad = $cfg;
$bad['smtp']['host']="localhost\r\nBcc:danger@example.com";
mailCheck(nav_mail_settings($bad)===null, 'header injection in hostname refused');
$bad = $cfg;
$bad['smtp']['from']="no-reply@example.com\r\nBcc:danger@example.com";
mailCheck(nav_mail_settings($bad)===null, 'header injection in sender refused');

$token=str_repeat('a',64);
$link=nav_reset_mail_url($token);
mailCheck($link === 'https://nav.wwinzs.cn/#password-reset='.$token,
    'reset URL uses HTTPS fragment, not query or HTTP log path');
try { nav_reset_mail_url('bad'); throw new RuntimeException('Invalid token accepted'); }
catch (InvalidArgumentException) { mailCheck(true,'invalid raw tokens refused'); }
$message=nav_reset_mail_content('owner@example.com',$token,$settings['from']);
mailCheck(str_contains($message,'MIME-Version: 1.0') &&
    !str_contains($message,'\r\nBcc:') &&
    str_contains($message,"Content-Transfer-Encoding: base64"),
    'safe MIME headers and encoded Unicode body');
mailCheck(nav_send_reset_mail('owner@example.com',$token),
    'curl successfully delivered through verified TLS fixture');
fwrite(STDOUT,"TLS SMTP transport validated without real mailbox or secrets.\n");
