<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

/**
 * The HTTP request queues encrypted addresses without looking up account existence.
 * Only a separate CLI worker ever queries the nav_users table or sends mail.
 * No raw recipient email, password-reset token or SMTP credentials are persisted.
 */
function nav_reset_queue_key(): string {
    $secret = hex2bin(substr(nav_config()['app_key'], 0, 64));
    if ($secret === false || strlen($secret) !== 32)
        throw new RuntimeException('Invalid reset queue encryption key');
    return hash_hkdf('sha256', $secret, 32, 'jizhu-nav-reset-mail-v1');
}

function nav_reset_queue_ready(): bool {
    return extension_loaded('openssl') && function_exists('hash_hkdf')
        && strlen(nav_reset_queue_key()) === 32;
}

function nav_enqueue_reset_mail(PDO $db, string $email): void {
    $normalized = nav_email($email);
    if ($normalized === null) throw new InvalidArgumentException('Invalid address');
    $key = nav_reset_queue_key();
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($normalized, 'aes-256-gcm', $key,
        OPENSSL_RAW_DATA, $nonce, $tag, 'nav-reset-v1');
    if ($cipher === false || strlen($tag) !== 16)
        throw new RuntimeException('Unable to seal recipient');
    $recipientHash = hash_hmac('sha256', $normalized, $key);
    // Serialize admission across PHP-FPM processes. A count-then-insert without
    // this lock can exceed the bound when several requests arrive concurrently.
    // 64-char MySQL advisory lock names: scope with app key, not user email.
    $lockName = 'nav:reset:' . substr(hash('sha256', nav_config()['app_key']), 0, 40);
    $lock = $db->prepare('SELECT GET_LOCK(?, 2)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) return; // Generic HTTP response.
    try {
        $backlog = (int)$db->query("SELECT COUNT(*) FROM nav_reset_mail_queue")->fetchColumn();
        if ($backlog >= 50) return;
        // Same work for existing and unknown emails: no owner lookup or SMTP.
        $sql = "INSERT INTO nav_reset_mail_queue
            (recipient_hash,recipient_cipher,nonce,auth_tag)
            VALUES (?,?,?,?)";
        $db->prepare($sql)->execute([$recipientHash,$cipher,$nonce,$tag]);
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function nav_open_reset_recipient(array $job): ?string {
    $key = nav_reset_queue_key();
    $email = openssl_decrypt($job['recipient_cipher'],'aes-256-gcm',
        $key,OPENSSL_RAW_DATA,$job['nonce'],$job['auth_tag'],'nav-reset-v1');
    if (!is_string($email) || nav_email($email) !== $email) return null;
    if (!hash_equals($job['recipient_hash'],hash_hmac('sha256',$email,$key))) return null;
    return $email;
}

/** Claim one job via MySQL 8 SKIP LOCKED and a 120-second lease. */
function nav_claim_reset_mail(PDO $db): ?array {
    $db->beginTransaction();
    try {
        $stmt = $db->query("SELECT id,recipient_hash,recipient_cipher,nonce,auth_tag,attempts
            FROM nav_reset_mail_queue
            WHERE created_at>UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE
              AND attempts<3
              AND state='pending' AND available_at<=UTC_TIMESTAMP(6)
            ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
        $job = $stmt->fetch();
        if (!$job) {
            $db->commit();
            return null;
        }
        $db->prepare("UPDATE nav_reset_mail_queue
            SET state='processing',attempts=attempts+1,
                lease_until=UTC_TIMESTAMP(6) + INTERVAL 120 SECOND
            WHERE id=?")->execute([$job['id']]);
        $db->commit();
        return $job;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

/**
 * At-most-once SMTP attempt per queued request.
 * If a process dies after SMTP accepts a message, delivery is unknowable;
 * retrying could send a second valid link. Discard instead.
 */
function nav_finish_reset_mail(PDO $db, int $id, int $claimAttempt): void {
    $db->prepare("DELETE FROM nav_reset_mail_queue
        WHERE id=? AND state='processing' AND attempts=?")
        ->execute([$id,$claimAttempt]);
}

/**
 * One iteration, for an explicitly invoked server CLI worker.
 * Returns false only when no job is ready. No logs include addresses or tokens.
 */
function nav_process_reset_mail(PDO $db): bool {
    // v1.9.9: never reclaim a processing task after lease expiration:
    // SMTP might already have accepted mail, so replay risks duplicate links.
    // Monitoring detects expired leases; stale task cleanup discards them.
    // v1.9.5: prune jobs whose 15-minute recovery window has elapsed.
    // Do not send new reset links for requests that have already gone stale.
    $db->exec("DELETE FROM nav_reset_mail_queue
        WHERE created_at<=UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE
           OR attempts>=3");
    $job = nav_claim_reset_mail($db);
    if ($job === null) return false;
    $id = (int)$job['id'];
    $attempt = (int)$job['attempts'] + 1;
    $recipient = nav_open_reset_recipient($job);
    if ($recipient !== null) {
        // Only the worker checks ownership, after the HTTP response has completed.
        $stmt = $db->prepare("SELECT id FROM nav_users
            WHERE email=? AND role='owner' AND status='active' LIMIT 1");
        $stmt->execute([$recipient]);
        if ($stmt->fetchColumn() !== false) {
            // Retry only after SMTP returns false or throws. Each attempt uses
            // a new token and invalidates the previous pending token.
            $token = nav_issue_password_reset_token($db,$recipient);
            if ($token !== null) {
                try {
                    $delivered = nav_send_reset_mail($recipient,$token);
                } catch (Throwable) {
                    $delivered = false;
                }
                if (!$delivered) {
                    nav_discard_password_reset_token($db,$token);
                }
                unset($token);
            }
        }
    }
    nav_finish_reset_mail($db,$id,$attempt);
    return true;
}
