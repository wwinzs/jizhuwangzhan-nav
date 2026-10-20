<?php
declare(strict_types=1);
/**
 * CLI health signal for operators. Does not reveal addresses, tokens or email.
 * Intended for private monitoring after the production worker is reviewed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $db = nav_db();
    $row = $db->query("SELECT COUNT(*) AS jobs,
        COALESCE(TIMESTAMPDIFF(SECOND,MIN(created_at),UTC_TIMESTAMP(6)),0) AS oldest_seconds,
        SUM(CASE WHEN state='processing' AND lease_until<UTC_TIMESTAMP(6)
                 THEN 1 ELSE 0 END) AS expired_leases
        FROM nav_reset_mail_queue")->fetch();
    $jobs = (int)$row['jobs'];
    $age = max(0,(int)$row['oldest_seconds']);
    $expired = (int)$row['expired_leases'];
    // Remediation limits for a private single-owner navigation site.
    if ($jobs > 50 || $age > 1200 || $expired > 0) {
        fwrite(STDERR,"Reset queue backlog alert: jobs={$jobs}, oldest_seconds={$age}, expired_leases={$expired}.\n");
        exit(1);
    }
    fwrite(STDOUT,"Reset queue healthy: jobs={$jobs}, oldest_seconds={$age}, expired_leases={$expired}.\n");
} catch (Throwable) {
    fwrite(STDERR,"Reset queue check failed; review private service health.\n");
    exit(2);
}
