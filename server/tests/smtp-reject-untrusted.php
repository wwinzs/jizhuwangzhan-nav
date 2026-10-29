<?php
declare(strict_types=1);
if (getenv('CI') !== 'true') { fwrite(STDERR, "CI only.\n"); exit(2); }
require_once dirname(__DIR__) . '/src/mail.php';
if (!nav_mail_ready()) throw new RuntimeException('SMTP test must have an enabled config.');
if (nav_send_reset_mail('owner@example.com', str_repeat('b', 64))) {
    throw new RuntimeException('Untrusted SMTP TLS certificate was accepted.');
}
fwrite(STDOUT, "PASS SMTP: untrusted server TLS certificate refused.\n");
