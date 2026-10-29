<?php
declare(strict_types=1);
// Isolated GitHub MySQL integration suite only. No live account may be changed here.
if (getenv('CI') !== 'true') {
    fwrite(STDERR, "Audit integration test is restricted to CI.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/src/auth.php';

function verify(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    fwrite(STDOUT, "PASS " . $label . "\n");
}

$db = nav_db();
$user = $db->query("SELECT id FROM nav_users WHERE email='owner@example.com' AND role='owner'")->fetchColumn();
verify($user !== false, 'isolated owner exists');
$userId = (int)$user;

$events = nav_recent_security_events($db, $userId);
verify(count($events) >= 4, 'login, logout, password change and session revocation audited');
$names = array_column($events, 'event_type');
foreach (['login_success','logout','password_changed','other_sessions_revoked'] as $event) {
    verify(in_array($event, $names, true), 'audited ' . $event);
}
foreach ($events as $event) {
    verify(array_keys($event) === ['event_type', 'created_at'], 'audit emits no secrets or IP information');
}
verify(!nav_recover_owner_password($db, 'other@example.com', 'new dummy password 12345678'),
    'recovery refuses unknown account');
verify(!nav_recover_owner_password($db, 'owner@example.com', 'short'),
    'recovery refuses weak password');

// Seed one disposable, known-hash session to prove recovery revokes it.
$tokenHash = str_repeat('f', 64);
$db->prepare('INSERT INTO nav_sessions (user_id,token_hash,expires_at)
    VALUES (?, ?, UTC_TIMESTAMP(6) + INTERVAL 1 DAY)')->execute([$userId, $tokenHash]);
verify(nav_recover_owner_password($db, 'owner@example.com', 'a very long CI password 987654!'),
    'valid owner recovered with original CI password');
$h = $db->prepare('SELECT password_hash FROM nav_users WHERE id=?');
$h->execute([$userId]);
$hash = $h->fetchColumn();
verify(is_string($hash) && password_verify('a very long CI password 987654!', $hash),
    'new password hash verified');
$check = $db->prepare('SELECT revoked_at IS NOT NULL FROM nav_sessions WHERE token_hash=?');
$check->execute([$tokenHash]);
verify((int)$check->fetchColumn() === 1, 'recovery invalidates all sessions');
$events = nav_recent_security_events($db, $userId);
verify($events[0]['event_type'] === 'owner_recovery', 'recovery is audited');

verify(!in_array('password_hash', array_keys($events[0]), true), 'no password hash in event');
fwrite(STDOUT, "All isolated security audit and owner recovery assertions passed.\n");
