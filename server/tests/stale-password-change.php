<?php
declare(strict_types=1);
// Isolated CI regression: a revoked bearer may NOT change a known password.
if (getenv('CI') !== 'true') exit(2);
require_once dirname(__DIR__) . '/src/auth.php';
$db=nav_db();
if (!str_contains(nav_config()['db_dsn'],'dbname=nav_ci;'))
    throw new RuntimeException('Only isolated CI DB');
$row=$db->query("SELECT id,password_hash FROM nav_users
    WHERE role='owner' AND email='owner@example.com'")->fetch();
if (!$row) throw new RuntimeException('No CI owner');
$id=(int)$row['id'];$original=$row['password_hash'];
$before=(int)$db->query("SELECT COALESCE(MAX(id),0) FROM nav_sessions")->fetchColumn();
$stale=bin2hex(random_bytes(32));
$valid=bin2hex(random_bytes(32));
try {
    $insert=$db->prepare("INSERT INTO nav_sessions(user_id,token_hash,expires_at,revoked_at)
        VALUES(?,?,UTC_TIMESTAMP(6)+INTERVAL 1 DAY,?)");
    $insert->execute([$id,hash('sha256',$stale),gmdate('Y-m-d H:i:s')]);
    $insert->execute([$id,hash('sha256',$valid),null]);
    if (nav_change_password($db,$id,'a very long CI password 987654!',
        'a new rejected password 7654321!',$stale)!=='unauthenticated')
        throw new RuntimeException('Revoked bearer changed password');
    if (nav_change_password($db,$id,'a very long CI password 987654!',
        'a new rejected password 7654321!','bad')!=='unauthenticated')
        throw new RuntimeException('Malformed bearer changed password');
    if (nav_change_password($db,$id,'a very long CI password 987654!',
        'a very long CI password 987654!',$valid)!=='same')
        throw new RuntimeException('Valid same-password rule changed');
    $check=$db->prepare('SELECT password_hash FROM nav_users WHERE id=?');
    $check->execute([$id]);
    if ($check->fetchColumn()!==$original)
        throw new RuntimeException('Stale caller changed owner password hash');
    fwrite(STDOUT,"PASS change-password: stale bearer rejected atomically.\n");
} finally {
    $db->prepare('DELETE FROM nav_sessions WHERE user_id=? AND id>?')
        ->execute([$id,$before]);
    $db->prepare('UPDATE nav_users SET password_hash=? WHERE id=?')
        ->execute([$original,$id]);
}
