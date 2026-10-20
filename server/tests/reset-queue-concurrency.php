<?php
declare(strict_types=1);
// CI-only: 12 independent PHP processes race for 5 free queue slots.
if (getenv('CI') !== 'true') { fwrite(STDERR, "CI only.\n"); exit(2); }
require_once dirname(__DIR__) . '/src/reset-queue.php';
$db=nav_db();
if (in_array('--child', $argv, true)) {
    nav_enqueue_reset_mail($db, 'unknown@example.com');
    exit(0);
}
$db->exec('DELETE FROM nav_reset_mail_queue');
for ($i=0; $i<45; $i++) nav_enqueue_reset_mail($db, 'unknown@example.com');
$count=(int)$db->query('SELECT COUNT(*) FROM nav_reset_mail_queue')->fetchColumn();
if ($count !== 45) throw new RuntimeException('Queue prefill failed');
$workers=[];
for ($i=0; $i<12; $i++) {
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --child';
    $proc=proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('Unable to start synthetic worker');
    fclose($pipes[0]);
    $workers[]=[$proc,$pipes];
}
foreach ($workers as [$proc,$pipes]) {
    $stdout=stream_get_contents($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    if (proc_close($proc)!==0) throw new RuntimeException('Concurrent admission failed');
}
$count=(int)$db->query('SELECT COUNT(*) FROM nav_reset_mail_queue')->fetchColumn();
if ($count!==50) throw new RuntimeException('Concurrent queue exceeded capacity or rejected too early');
$db->exec('DELETE FROM nav_reset_mail_queue');
fwrite(STDOUT,"PASS queue: 12 real parallel PHP processes never exceed 50 slots.\n");
