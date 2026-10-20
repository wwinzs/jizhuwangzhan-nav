<?php
declare(strict_types=1);
// CI-only: independent SMTP workers race for the same owner's live reset grant.
if (getenv('CI') !== 'true') exit(2);
require_once dirname(__DIR__) . '/src/auth.php';
$db=nav_db();
if (!str_contains(nav_config()['db_dsn'],'dbname=nav_ci;'))
    throw new RuntimeException('Concurrent test must use CI database');
$id=(int)$db->query("SELECT id FROM nav_users WHERE email='owner@example.com' AND role='owner'")->fetchColumn();
if ($id<1) throw new RuntimeException('No isolated test owner');
if (in_array('--child',$argv,true)) {
    $issued=nav_issue_password_reset_token($db,'owner@example.com',true);
    // Deliberately never print bearer material.
    echo is_string($issued) ? 'issued' : 'preserved';
    exit(0);
}
$processes=[];
try {
    $db->prepare("UPDATE nav_password_reset_tokens SET consumed_at=UTC_TIMESTAMP(6)
        WHERE user_id=? AND consumed_at IS NULL")->execute([$id]);
    for ($i=0;$i<2;$i++) {
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --child';
        $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($proc)) throw new RuntimeException('Failed to spawn child');
        fclose($pipes[0]);
        $processes[]=[$proc,$pipes];
    }
    $outputs=[];
    foreach ($processes as [$proc,$pipes]) {
        $outputs[]=trim(stream_get_contents($pipes[1]));
        $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        if (proc_close($proc)!==0) throw new RuntimeException('Grant worker failed');
    }
    $processes=[];
    sort($outputs);
    if ($outputs!==['issued','preserved'])
        throw new RuntimeException('Two workers both issued or both dropped grants');
    $stmt=$db->prepare("SELECT COUNT(*) FROM nav_password_reset_tokens
        WHERE user_id=? AND consumed_at IS NULL
          AND expires_at>UTC_TIMESTAMP(6)");
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn()!==1)
        throw new RuntimeException('Parallel worker created multiple valid grants');
    fwrite(STDOUT,"PASS grants: two concurrent workers preserve one valid reset link.\n");
    $db->prepare("UPDATE nav_password_reset_tokens SET expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND
        WHERE user_id=? AND consumed_at IS NULL")->execute([$id]);
    $replacement=nav_issue_password_reset_token($db,'owner@example.com',true);
    if (!is_string($replacement) || strlen($replacement)!==64)
        throw new RuntimeException('Expired grant incorrectly prevented new request');
    fwrite(STDOUT,"PASS grants: expired link permits fresh issue.\n");
} finally {
    foreach ($processes as [$proc,$pipes]) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($proc);
        }
    }
    $db->prepare("UPDATE nav_password_reset_tokens SET consumed_at=UTC_TIMESTAMP(6)
        WHERE user_id=? AND consumed_at IS NULL")->execute([$id]);
}
