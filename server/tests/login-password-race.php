<?php
declare(strict_types=1);
/**
 * Isolated real MySQL race: password changes while a login is blocked on the
 * owner row lock. The old password MUST NOT result in any session/audit.
 */
if (getenv('CI') !== 'true') exit(2);
require_once dirname(__DIR__) . '/src/auth.php';
$db=nav_db();
if (!str_contains(nav_config()['db_dsn'],'dbname=nav_ci;'))
    throw new RuntimeException('Refusing to test on a non-CI database.');
$old='a very long CI password 987654!';
$new='a new temporary password only for race test 456!';
if (in_array('--child',$argv,true)) {
    fwrite(STDOUT,"ready\n"); fflush(STDOUT);
    $result=nav_login($db,'owner@example.com',$old,'127.0.0.54');
    if ($result !== null) throw new RuntimeException('Stale credential created a session');
    exit(0);
}

$row=$db->query("SELECT id,password_hash FROM nav_users WHERE email='owner@example.com'")->fetch();
if (!$row || !password_verify($old,$row['password_hash']))
    throw new RuntimeException('CI owner fixture has unexpected credentials');
$userId=(int)$row['id'];
$initial=(int)$db->query("SELECT COUNT(*) FROM nav_sessions WHERE user_id=".$userId)->fetchColumn();
$auditBefore=(int)$db->query("SELECT COUNT(*) FROM nav_security_events
    WHERE user_id=".$userId." AND event_type='login_success'")->fetchColumn();
$child=null;$pipes=[];
try {
    $db->beginTransaction();
    $lock=$db->prepare('SELECT password_hash FROM nav_users WHERE id=? FOR UPDATE');
    $lock->execute([$userId]);
    if ($lock->fetchColumn()!==$row['password_hash'])
        throw new RuntimeException('Original hash changed before race setup');
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --child';
    $child=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($child)) throw new RuntimeException('Failed to start parallel login');
    fclose($pipes[0]);
    stream_set_timeout($pipes[1],8);
    if (trim((string)fgets($pipes[1]))!=='ready')
        throw new RuntimeException('Concurrent login did not start');
    // Allow child enough time to reach SELECT ... FOR UPDATE, then replace hash.
    usleep(350000);
    $db->prepare('UPDATE nav_users SET password_hash=? WHERE id=?')
        ->execute([password_hash($new,PASSWORD_ARGON2ID),$userId]);
    $db->commit();
    $childOutput=stream_get_contents($pipes[1]);
    $childErrors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $status=proc_close($child);$child=null;
    if ($status!==0)
        throw new RuntimeException('Concurrent login accepted stale password');
    $now=(int)$db->query("SELECT COUNT(*) FROM nav_sessions WHERE user_id=".$userId)->fetchColumn();
    $audits=(int)$db->query("SELECT COUNT(*) FROM nav_security_events
        WHERE user_id=".$userId." AND event_type='login_success'")->fetchColumn();
    if ($now!==$initial || $audits!==$auditBefore)
        throw new RuntimeException('Stale login created session or audit record');
    fwrite(STDOUT,"PASS login race: password rotation wins; no post-reset stale session.\n");
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if (is_resource($child)) {
        proc_terminate($child);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($child);
    }
    // Restore ONLY the isolated CI fixture's original exact hash.
    $db->prepare('UPDATE nav_users SET password_hash=? WHERE id=?')
        ->execute([$row['password_hash'],$userId]);
}
