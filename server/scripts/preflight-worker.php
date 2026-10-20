<?php
declare(strict_types=1);
/**
 * v1.9.11 CLI-only, read-only worker deployment gate.
 * Does not connect to SMTP, dequeue mail, rotate keys or change DB rows.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/mail.php';
require_once dirname(__DIR__) . '/src/reset-queue.php';
require_once __DIR__ . '/preflight-upgrade.php';
try {
    $file=dirname(__DIR__) . '/config.local.php';
    if (!nav_private_config_secure($file))
        throw new RuntimeException('unsafe private config file or worker identity');
    $config=nav_config();
    if (($config['require_https'] ?? true) !== true
        || ($config['frontend_origin'] ?? null) !== 'https://nav.wwinzs.cn')
        throw new RuntimeException('unexpected production HTTPS origin');
    if (!nav_mail_ready() || !nav_reset_queue_ready())
        throw new RuntimeException('mail service or encrypted queue is disabled');
    $db=nav_db();
    if (nav_check_upgrade_schema($db)['state'] !== 'READY')
        throw new RuntimeException('database upgrade is incomplete');
    $state=$db->query("SELECT COUNT(*) AS jobs,
        SUM(CASE WHEN state='processing' AND lease_until<UTC_TIMESTAMP(6)
            THEN 1 ELSE 0 END) AS stuck FROM nav_reset_mail_queue")->fetch();
    if ((int)$state['jobs'] > 50 || (int)$state['stuck'] > 0)
        throw new RuntimeException('queue requires inspection');
    fwrite(STDOUT, "Worker read-only preflight: READY (no message sent, no queue changes).\n");
} catch (Throwable) {
    fwrite(STDERR, "Worker preflight BLOCKED; review private config, schema, permissions and queue.\n");
    exit(2);
}
