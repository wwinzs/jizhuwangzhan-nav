<?php
declare(strict_types=1);
// CI-only real MySQL boundary test: saturated unsigned SMALLINT must not overflow.
if (getenv('CI') !== 'true') exit(2);
require_once dirname(__DIR__) . '/src/auth.php';
$db=nav_db();
if (!str_contains(nav_config()['db_dsn'],'dbname=nav_ci;'))
    throw new RuntimeException('Not isolated CI database');
$key=hash('sha256','rate-boundary-v1.9.20-ci');
try {
    $db->prepare("INSERT INTO nav_login_limits (key_hash,attempts,window_start)
        VALUES (?,65535,UTC_TIMESTAMP(6))
        ON DUPLICATE KEY UPDATE attempts=65535,window_start=UTC_TIMESTAMP(6)")
        ->execute([$key]);
    if (nav_limit_try($db,$key,60)!==false)
        throw new RuntimeException('Saturated counter accepted a request');
    $stmt=$db->prepare('SELECT attempts FROM nav_login_limits WHERE key_hash=?');
    $stmt->execute([$key]);
    if ((int)$stmt->fetchColumn()!==65535)
        throw new RuntimeException('Counter changed unexpectedly on overflow');
    $db->prepare("UPDATE nav_login_limits
        SET window_start=UTC_TIMESTAMP(6)-INTERVAL 16 MINUTE WHERE key_hash=?")
        ->execute([$key]);
    if (nav_limit_try($db,$key,60)!==true)
        throw new RuntimeException('Expired counter did not reset');
    $stmt->execute([$key]);
    if ((int)$stmt->fetchColumn()!==1)
        throw new RuntimeException('Window reset did not restore first attempt');
    fwrite(STDOUT,"PASS limits: saturated counter never overflows; stale window resets.\n");
} finally {
    $db->prepare('DELETE FROM nav_login_limits WHERE key_hash=?')->execute([$key]);
}
