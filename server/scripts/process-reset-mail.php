<?php
declare(strict_types=1);
/**
 * CLI-only asynchronous mail worker.
 * Run with the same private config as the PHP API; do NOT expose via web.
 * Scheduler deployment remains a separately reviewed operational step.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/reset-queue.php';
require_once dirname(__DIR__) . '/src/mail.php';

try {
    if (!nav_private_config_secure(dirname(__DIR__) . '/config.local.php'))
        throw new RuntimeException('Private worker config is not secure');
    if (!nav_mail_ready() || !nav_reset_queue_ready())
        throw new RuntimeException('Mail worker not enabled');
    $db = nav_db();
    $completed = 0;
    // Bounded execution makes a 1-minute cron or systemd timer safe.
    for ($i=0; $i<10; $i++) {
        if (!nav_process_reset_mail($db)) break;
        $completed++;
    }
    fwrite(STDOUT, "Reset mail worker checked up to 10 jobs; processed {$completed}.\n");
} catch (Throwable) {
    // Never disclose account addresses, tokens, SQL or SMTP credentials.
    fwrite(STDERR,"Reset mail worker could not complete; check private monitoring.\n");
    exit(1);
}
