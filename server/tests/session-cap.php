<?php
declare(strict_types=1);
// CI-only: login enforces 50 active sessions without invalidating newest token.
if (getenv('CI') !== 'true') exit(2);
require_once dirname(__DIR__) . '/src/auth.php';
$db=nav_db();
if (!str_contains(nav_config()['db_dsn'],'dbname=nav_ci;'))
    throw new RuntimeException('Only the isolated CI database is allowed');
$id=(int)$db->query("SELECT id FROM nav_users WHERE email='owner@example.com' AND role='owner'")
    ->fetchColumn();
if ($id<1) throw new RuntimeException('No CI owner');
$max=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM nav_sessions')->fetchColumn();
$ins=$db->prepare('INSERT INTO nav_sessions (user_id,token_hash,expires_at)
    VALUES (?,?,UTC_TIMESTAMP(6)+INTERVAL 1 DAY)');
try {
    for ($i=0;$i<55;$i++) {
        $ins->execute([$id,hash('sha256','synthetic-sessions-v19-'.$i)]);
    }
    $user=nav_login($db,'owner@example.com','a very long CI password 987654!','192.0.2.223');
    if (!$user || $user['role']!=='owner')
        throw new RuntimeException('Owner login was unexpectedly blocked');
    $count=(int)$db->query('SELECT COUNT(*) FROM nav_sessions
        WHERE user_id='.$id.' AND revoked_at IS NULL
          AND expires_at>UTC_TIMESTAMP(6)')->fetchColumn();
    if ($count!==50) throw new RuntimeException('Login did not cap active sessions at 50');
    $fresh=$db->query('SELECT revoked_at,expires_at FROM nav_sessions
        WHERE user_id='.$id.' AND id>'.$max.'
        ORDER BY id DESC LIMIT 1')->fetch();
    if (!$fresh || $fresh['revoked_at']!==null)
        throw new RuntimeException('Newest authenticated session was revoked');
    fwrite(STDOUT,"PASS sessions: 55 seeded sessions cap at 50; fresh login survives.\n");
} finally {
    $db->prepare('DELETE FROM nav_sessions WHERE user_id=? AND id>?')->execute([$id,$max]);
}
