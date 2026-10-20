<?php
declare(strict_types=1);
// CI only, creates an ephemeral database separate from the API test database.
if (getenv('CI') !== 'true') exit(2);
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/preflight-upgrade.php';
$cfg=nav_config();
if (!str_contains($cfg['db_dsn'], 'dbname=nav_ci;'))
    throw new RuntimeException('Not the isolated CI database');
$name='nav_preflight_stage_ci';
$root=nav_db();
$root->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$name.';charset=utf8mb4',
    $cfg['db_user'],$cfg['db_password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
$db->exec("SET time_zone = '+00:00'");
function applySql(PDO $db, string $path): void {
    $sql=file_get_contents($path);
    if ($sql===false) throw new RuntimeException('Missing fixture migration');
    foreach (explode(';',$sql) as $statement)
        if (trim($statement)!=='') $db->exec($statement);
}
function checkStage(PDO $db, string $state, ?int $completed=null): void {
    $actual=nav_check_upgrade_schema($db);
    if ($actual['state']!==$state) throw new RuntimeException('Wrong migration stage: '.$state);
    if ($completed!==null && ($actual['completed_migrations']??null)!==$completed)
        throw new RuntimeException('Incorrect completed migration count');
    fwrite(STDOUT, 'PASS preflight: '.$state.' stage '.(string)$completed.PHP_EOL);
}
try {
    applySql($db, dirname(__DIR__) . '/schema.sql');
    $password=password_hash('fixture password only 1234567!',PASSWORD_ARGON2ID);
    $db->prepare("INSERT INTO nav_users(email,password_hash,role,status)
        VALUES (?,?, 'owner','active')")->execute(['ci-owner@example.com',$password]);
    checkStage($db,'MIGRATION_REQUIRED',0);
    $db->exec('CREATE TABLE nav_unrelated_data (id INT PRIMARY KEY)');
    checkStage($db,'BLOCKED');
    $db->exec('DROP TABLE nav_unrelated_data');
    applySql($db,dirname(__DIR__) . '/migrations/001_security_events.sql');
    checkStage($db,'MIGRATION_REQUIRED',1);
    applySql($db,dirname(__DIR__) . '/migrations/002_password_reset_tokens.sql');
    checkStage($db,'MIGRATION_REQUIRED',2);
    applySql($db,dirname(__DIR__) . '/migrations/003_async_reset_mail.sql');
    checkStage($db,'READY',3);
    $saved=$db->query("SELECT password_hash FROM nav_users WHERE email='ci-owner@example.com'")
        ->fetchColumn();
    if ($saved!==$password || (int)$db->query('SELECT COUNT(*) FROM nav_users')->fetchColumn()!==1)
        throw new RuntimeException('Migrating unexpectedly modified the owner');
    $db->exec("UPDATE nav_users SET status='disabled' WHERE email='ci-owner@example.com'");
    checkStage($db,'BLOCKED');
    fwrite(STDOUT, "PASS preflight: staged migrations retained original owner hash and identity.\n");
} finally {
    $db=null;
    $root->exec('DROP DATABASE `'.$name.'`');
}
