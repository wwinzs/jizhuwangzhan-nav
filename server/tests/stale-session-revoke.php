<?php
declare(strict_types=1);
// CI-only: revocation must not act from a stale bearer after logout/reset.
if (getenv('CI') !== 'true') exit(2);
require_once dirname(__DIR__) . '/src/auth.php';
$db=nav_db();
if (!str_contains(nav_config()['db_dsn'],'dbname=nav_ci;'))
    throw new RuntimeException('Refusing to test on a non-CI database');
$id=(int)$db->query("SELECT id FROM nav_users WHERE email='owner@example.com' AND role='owner'")->fetchColumn();
if (!$id) throw new RuntimeException('Missing CI owner');
$max=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM nav_sessions')->fetchColumn();
$old=$_COOKIE['__Host-nav_session'] ?? null;
$a=bin2hex(random_bytes(32));$b=bin2hex(random_bytes(32));
try {
    $ins=$db->prepare("INSERT INTO nav_sessions(user_id,token_hash,expires_at)
        VALUES (?,?,UTC_TIMESTAMP(6) + INTERVAL 1 DAY)");
    $ins->execute([$id,hash('sha256',$a)]);
    $ins->execute([$id,hash('sha256',$b)]);
    // This simulates a reset revoking a caller after initial HTTP auth.
    $db->prepare("UPDATE nav_sessions SET revoked_at=UTC_TIMESTAMP(6)
        WHERE user_id=? AND token_hash=?")->execute([$id,hash('sha256',$a)]);
    $_COOKIE['__Host-nav_session']=$a;
    if (nav_revoke_other_sessions($db,$id)!==null)
        throw new RuntimeException('Revoked bearer performed privileged session mutation');
    $sel=$db->prepare("SELECT revoked_at FROM nav_sessions WHERE token_hash=?");
    $sel->execute([hash('sha256',$b)]);
    if ($sel->fetchColumn()!==null)
        throw new RuntimeException('Stale bearer revoked a different valid session');
    $_COOKIE['__Host-nav_session']=$b;
    if (nav_revoke_other_sessions($db,$id)<1)
        throw new RuntimeException('Valid caller failed to revoke other sessions');
    $sel->execute([hash('sha256',$b)]);
    if ($sel->fetchColumn()!==null)
        throw new RuntimeException('Valid caller's own session was revoked');
    fwrite(STDOUT,"PASS sessions: revoked bearer denied; active caller retained.\n");
} finally {
    $db->prepare('DELETE FROM nav_sessions WHERE user_id=? AND id>?')->execute([$id,$max]);
    if ($old===null) unset($_COOKIE['__Host-nav_session']);
    else $_COOKIE['__Host-nav_session']=$old;
}
