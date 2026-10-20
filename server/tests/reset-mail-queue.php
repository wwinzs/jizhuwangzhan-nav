<?php
declare(strict_types=1);
if (getenv('CI') !== 'true') exit(2);
require_once dirname(__DIR__) . '/src/reset-queue.php';

$db=nav_db();
function expectQueue(bool $condition,string $label): void {
    if (!$condition) throw new RuntimeException('Queue test failed: '.$label);
    fwrite(STDOUT,'PASS queue: '.$label.PHP_EOL);
}
expectQueue(nav_reset_queue_ready(),'authenticated encryption available');
$db->exec('DELETE FROM nav_reset_mail_queue');
nav_enqueue_reset_mail($db,'owner@example.com');
nav_enqueue_reset_mail($db,'other@example.com');
$rows=$db->query('SELECT * FROM nav_reset_mail_queue ORDER BY id')->fetchAll();
expectQueue(count($rows)===2,'known and unknown recipients queued identically');
expectQueue(!str_contains($rows[0]['recipient_cipher'],'owner@example.com'),
    'no plaintext address in queue');
expectQueue(strlen($rows[0]['nonce'])===12 && strlen($rows[0]['auth_tag'])===16,
    'unique 96-bit nonce and 128-bit GCM authentication tag');
expectQueue(!hash_equals($rows[0]['nonce'],$rows[1]['nonce']),'fresh IV per request');
expectQueue(nav_open_reset_recipient($rows[0])==='owner@example.com',
    'decrypt recipient only with correct key and tag');
$tampered=$rows[0];
$tampered['auth_tag']=str_repeat("\x00",16);
expectQueue(nav_open_reset_recipient($tampered)===null,'tampered tag fails authentication');
$job=nav_claim_reset_mail($db);
expectQueue(is_array($job) && (int)$job['attempts']===0,'first job leased once');
$next=nav_claim_reset_mail($db);
expectQueue(is_array($next) && $next['id']!==$job['id'],'leased job cannot be double claimed');
nav_finish_reset_mail($db,(int)$job['id'],true);
$state=$db->query("SELECT attempts,state FROM nav_reset_mail_queue WHERE id=".(int)$job['id'])
    ->fetch();
expectQueue((int)$state['attempts']===1 && $state['state']==='pending',
    'failed job retry pending with bounded delay');
nav_finish_reset_mail($db,(int)$next['id'],false);
$db->exec('DELETE FROM nav_reset_mail_queue');
fwrite(STDOUT,'Encrypted mail queue regression passed.\n');
