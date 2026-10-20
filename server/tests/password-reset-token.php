<?php
declare(strict_types=1);
// CI-only isolated MySQL test; never run against real accounts.
if (getenv('CI') !== 'true') {
    fwrite(STDERR, "Password reset suite is CI-only.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/src/auth.php';
function assertReset(bool $condition,string $label): void {
    if (!$condition) throw new RuntimeException('FAIL reset: '.$label);
    fwrite(STDOUT, 'PASS reset: '.$label.PHP_EOL);
}
$db = nav_db();
$ownerId = (int)$db->query("SELECT id FROM nav_users WHERE email='owner@example.com' AND role='owner'")->fetchColumn();
assertReset($ownerId>0,'owner is fixture only');
$oldPass = 'a very long CI password 987654!';
$newPass = 'an entirely different CI password 24680!';
$t1 = nav_issue_password_reset_token($db,'OWNER@EXAMPLE.COM');
assertReset(is_string($t1) && preg_match('/^[0-9a-f]{64}$/D',$t1)===1,'cryptographically random reset token');
assertReset(nav_issue_password_reset_token($db,'nobody@example.com') === null,'unknown address cannot issue');
$h = hash_hmac('sha256',$t1,nav_config()['app_key']);
$stmt = $db->prepare('SELECT token_hash, TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(6),expires_at) AS remaining
    FROM nav_password_reset_tokens WHERE user_id=? ORDER BY id DESC LIMIT 1');
$stmt->execute([$ownerId]);
$row=$stmt->fetch();
assertReset($row && $row['token_hash']===$h && $row['token_hash']!==$t1,'only keyed digest saved');
assertReset((int)$row['remaining']>=840 && (int)$row['remaining']<=900,'15-minute TTL');

$t2=nav_issue_password_reset_token($db,'owner@example.com');
assertReset(is_string($t2) && $t2!==$t1,'replacement grant is distinct');
assertReset(nav_issue_password_reset_token($db,'owner@example.com',true)===null,
    'SMTP worker preserves existing valid emailed grant');
assertReset(nav_redeem_password_reset_token($db,$t2,'too short')===false,
    'preserved token is still the one in effect');
assertReset(!nav_redeem_password_reset_token($db,$t1,$newPass),'reissuing invalidates prior grant');
assertReset(!nav_redeem_password_reset_token($db,$t2,'too short'),'weak replacement password rejected');
assertReset(!nav_redeem_password_reset_token($db,str_repeat('g',64),$newPass),'invalid token syntax rejected');
assertReset(!nav_redeem_password_reset_token($db,str_repeat('a',64),$newPass),'unknown token rejected');

$db->prepare('UPDATE nav_password_reset_tokens SET expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND
    WHERE token_hash=?')->execute([hash_hmac('sha256',$t2,nav_config()['app_key'])]);
assertReset(!nav_redeem_password_reset_token($db,$t2,$newPass),'expired token rejected');
$t3=nav_issue_password_reset_token($db,'owner@example.com');

// Seed a live session to assert revocation with a successful recovery.
$dummyTokenHash=str_repeat('9',64);
$db->prepare('INSERT INTO nav_sessions (user_id,token_hash,expires_at)
    VALUES (?,?,UTC_TIMESTAMP(6)+INTERVAL 1 DAY)')->execute([$ownerId,$dummyTokenHash]);
assertReset(nav_redeem_password_reset_token($db,$t3,$newPass),'valid token redeems');
assertReset(!nav_redeem_password_reset_token($db,$t3,$oldPass),'used token cannot replay');
$query=$db->prepare('SELECT password_hash FROM nav_users WHERE id=?');
$query->execute([$ownerId]);
assertReset(password_verify($newPass,(string)$query->fetchColumn()),'password really changed');
$check=$db->prepare('SELECT revoked_at IS NOT NULL FROM nav_sessions WHERE token_hash=?');
$check->execute([$dummyTokenHash]);
assertReset((int)$check->fetchColumn()===1,'all old sessions revoked');
$events=nav_recent_security_events($db,$ownerId);
assertReset($events[0]['event_type']==='password_reset','successful reset audited without token');
assertReset(array_keys($events[0])===['event_type','created_at'],'audit hides secrets');

$t4=nav_issue_password_reset_token($db,'owner@example.com');
assertReset(nav_recover_owner_password($db,'owner@example.com',$oldPass),'CLI recovery restores fixture password');
assertReset(!nav_redeem_password_reset_token($db,$t4,$newPass),'CLI recovery also invalidates pending grant');
$t5=nav_issue_password_reset_token($db,'owner@example.com');
assertReset(nav_change_password($db,$ownerId,$oldPass,$newPass)==='changed','authenticated change works');
assertReset(!nav_redeem_password_reset_token($db,$t5,$oldPass),'authenticated change invalidates grant');
assertReset(nav_recover_owner_password($db,'owner@example.com',$oldPass),'restore CI password for HTTP suite');
fwrite(STDOUT,"Password reset token lifecycle and session invalidation passed.\n");
