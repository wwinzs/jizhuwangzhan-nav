<?php
declare(strict_types=1);
// v1.9.18 isolated owner-only authorization and legacy hash rehash regression.
if (getenv('CI') !== 'true') exit(2);
require_once dirname(__DIR__) . '/src/auth.php';
$db=nav_db();
if (!str_contains(nav_config()['db_dsn'],'dbname=nav_ci;'))
    throw new RuntimeException('Not the CI database');
$pass='a very long CI password 987654!';
$stmt=$db->query("SELECT id,password_hash FROM nav_users
    WHERE email='owner@example.com' AND role='owner' AND status='active'");
$owner=$stmt->fetch();
if (!$owner || !password_verify($pass,$owner['password_hash']))
    throw new RuntimeException('Unexpected owner fixture');
$id=(int)$owner['id'];
$sessionMax=(int)$db->query("SELECT COALESCE(MAX(id),0) FROM nav_sessions")->fetchColumn();
$memberId=null;
try {
    $db->prepare("INSERT INTO nav_users(email,password_hash,role,status)
        VALUES (?,?, 'member','active')")
        ->execute(['member@example.com',password_hash($pass,PASSWORD_ARGON2ID)]);
    $memberId=(int)$db->lastInsertId();
    if (nav_login($db,'member@example.com',$pass,'192.0.2.151')!==null)
        throw new RuntimeException('Reserved member role granted private login');
    $n=(int)$db->query("SELECT COUNT(*) FROM nav_sessions WHERE user_id=".$memberId)->fetchColumn();
    if ($n!==0) throw new RuntimeException('Member session was created');
    // A legacy bcrypt hash must be upgraded inside the locked login txn.
    $db->prepare('UPDATE nav_users SET password_hash=? WHERE id=?')
        ->execute([password_hash($pass,PASSWORD_BCRYPT),$id]);
    $user=nav_login($db,'owner@example.com',$pass,'192.0.2.152');
    if (!$user || $user['role']!=='owner')
        throw new RuntimeException('Legacy bcrypt owner login failed');
    $after=$db->prepare('SELECT password_hash FROM nav_users WHERE id=?');
    $after->execute([$id]);
    $rotated=$after->fetchColumn();
    if (!is_string($rotated) || !password_verify($pass,$rotated)
        || password_needs_rehash($rotated,PASSWORD_ARGON2ID))
        throw new RuntimeException('Legacy hash not upgraded to Argon2id');
    $created=(int)$db->query("SELECT COUNT(*) FROM nav_sessions
        WHERE user_id=".$id." AND id>".$sessionMax)->fetchColumn();
    if ($created!==1) throw new RuntimeException('Exactly one session was not issued');
    fwrite(STDOUT,"PASS private auth: reserved member cannot log in.\n");
    fwrite(STDOUT,"PASS private auth: legacy hash rehashed and exactly one session issued.\n");
} finally {
    $db->prepare('DELETE FROM nav_sessions WHERE user_id=? AND id>?')
        ->execute([$id,$sessionMax]);
    $db->prepare('UPDATE nav_users SET password_hash=? WHERE id=?')
        ->execute([$owner['password_hash'],$id]);
    if ($memberId!==null)
        $db->prepare('DELETE FROM nav_users WHERE id=?')->execute([$memberId]);
}
