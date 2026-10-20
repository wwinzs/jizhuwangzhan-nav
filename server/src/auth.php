<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function nav_limit_key(string $scope, string $value): string {
    return hash_hmac('sha256', $scope . ':' . $value, nav_config()['app_key']);
}

function nav_limit_try(PDO $db, string $key, int $allowed): bool {
    $sql = "INSERT INTO nav_login_limits (key_hash, attempts, window_start)
      VALUES (?, 1, UTC_TIMESTAMP(6))
      ON DUPLICATE KEY UPDATE
        attempts=IF(window_start < UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE, 1, attempts+1),
        window_start=IF(window_start < UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE, UTC_TIMESTAMP(6), window_start)";
    $db->prepare($sql)->execute([$key]);
    $stmt = $db->prepare('SELECT attempts FROM nav_login_limits WHERE key_hash=?');
    $stmt->execute([$key]);
    return (int)$stmt->fetchColumn() <= $allowed;
}

function nav_authenticate(PDO $db, string $email, string $password, string $ip): ?array {
    $normalized = nav_email($email);
    if ($normalized === null || strlen($password) > 1024) return null;
    $ipKey = nav_limit_key('ip', $ip);
    $userKey = nav_limit_key('email', $normalized);
    if (!nav_limit_try($db, $ipKey, 60) || !nav_limit_try($db, $userKey, 10))
        nav_reply(429, ['error'=>'Too many login attempts']);
    $stmt = $db->prepare("SELECT id,email,role,password_hash FROM nav_users WHERE email=? AND status='active' LIMIT 1");
    $stmt->execute([$normalized]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) return null;
    if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID))
        $db->prepare('UPDATE nav_users SET password_hash=? WHERE id=?')
            ->execute([password_hash($password, PASSWORD_ARGON2ID), $user['id']]);
    $db->prepare('DELETE FROM nav_login_limits WHERE key_hash IN (?,?)')->execute([$ipKey,$userKey]);
    unset($user['password_hash']);
    return $user;
}

function nav_create_session(PDO $db, int $userId): void {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expiry = time() + 14 * 86400;
    $stmt = $db->prepare('INSERT INTO nav_sessions (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $hash, gmdate('Y-m-d H:i:s', $expiry)]);
    nav_cookie($token, $expiry);
}

function nav_session_user(PDO $db): ?array {
    $token = $_COOKIE['__Host-nav_session'] ?? '';
    if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    $stmt = $db->prepare("SELECT u.id,u.email,u.role FROM nav_sessions s
       INNER JOIN nav_users u ON u.id=s.user_id
       WHERE s.token_hash=? AND s.revoked_at IS NULL
         AND s.expires_at>UTC_TIMESTAMP(6) AND u.status='active' LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    $result = $stmt->fetch();
    return $result ?: null;
}

function nav_logout(PDO $db): void {
    $token = $_COOKIE['__Host-nav_session'] ?? '';
    if (is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token))
        $db->prepare('UPDATE nav_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE token_hash=? AND revoked_at IS NULL')
            ->execute([hash('sha256', $token)]);
    nav_cookie('', time()-3600);
}

/**
 * Change a user's password atomically and revoke all their sessions.
 * The caller must authenticate the session, require a trusted Origin,
 * validate the new password length and enforce an attempt limit.
 */
function nav_change_password(PDO $db, int $userId, string $currentPassword, string $newPassword): string {
    $stmt = $db->prepare("SELECT password_hash FROM nav_users WHERE id=? AND status='active' LIMIT 1");
    $stmt->execute([$userId]);
    $oldHash = $stmt->fetchColumn();
    if (!is_string($oldHash) || !password_verify($currentPassword, $oldHash))
        return 'invalid';
    if (hash_equals($currentPassword, $newPassword))
        return 'same';

    $newHash = password_hash($newPassword, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1,
    ]);
    $db->beginTransaction();
    try {
        // Compare-and-swap ensures a concurrent password change cannot be overwritten.
        $update = $db->prepare(
            "UPDATE nav_users SET password_hash=? WHERE id=? AND password_hash=? AND status='active'"
        );
        $update->execute([$newHash, $userId, $oldHash]);
        if ($update->rowCount() !== 1) {
            $db->rollBack();
            return 'conflict';
        }

        $revoke = $db->prepare(
            "UPDATE nav_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE user_id=? AND revoked_at IS NULL"
        );
        $revoke->execute([$userId]);
        $db->commit();
        return 'changed';
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
