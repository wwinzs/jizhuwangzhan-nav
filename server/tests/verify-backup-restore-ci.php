<?php
declare(strict_types=1);
// Verify only a disposable CI backup/restore of the synthetic nav_ci database.
if (getenv('CI') !== 'true') exit(2);
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/preflight-upgrade.php';
$config=nav_config();
if (!str_contains($config['db_dsn'],'dbname=nav_ci;'))
    throw new RuntimeException('Refusing non-CI source database');
$source=nav_db();
$dest=new PDO('mysql:host=127.0.0.1;port=3306;dbname=nav_restore_ci;charset=utf8mb4',
    $config['db_user'],$config['db_password'],[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
$dest->exec("SET time_zone='+00:00'");
foreach ([$source,$dest] as $db) {
    $result=nav_check_upgrade_schema($db);
    if ($result['state']!=='READY')
        throw new RuntimeException('Restored schema is not fully usable');
}
$tables=['nav_users','nav_sessions','nav_user_documents','nav_login_limits',
    'nav_security_events','nav_password_reset_tokens','nav_reset_mail_queue'];
foreach ($tables as $table) {
    $before=(int)$source->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    $after=(int)$dest->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    if ($before!==$after) throw new RuntimeException('Row count changed during restore');
}
$fields=['id','email','password_hash','role','status'];
$original=$source->query("SELECT id,email,password_hash,role,status FROM nav_users
    WHERE role='owner' AND status='active' LIMIT 1")->fetch();
$restored=$dest->query("SELECT id,email,password_hash,role,status FROM nav_users
    WHERE role='owner' AND status='active' LIMIT 1")->fetch();
if (!$original || $original!==$restored)
    throw new RuntimeException('Owner identity or password hash changed in restore');
$docQuery='SELECT user_id,revision,document_json FROM nav_user_documents LIMIT 1';
$a=$source->query($docQuery)->fetch();
$b=$dest->query($docQuery)->fetch();
if (!$a || !$b || (int)$a['revision']!==7 || (int)$b['revision']!==7 ||
    json_decode($a['document_json'],true,16,JSON_THROW_ON_ERROR)
        !==json_decode($b['document_json'],true,16,JSON_THROW_ON_ERROR))
    throw new RuntimeException('Local test document was not preserved byte-equivalently');
foreach ([$source,$dest] as $db) {
    $count=(int)$db->query("SELECT COUNT(*) FROM nav_sessions
        WHERE token_hash=REPEAT('6',64)")->fetchColumn();
    if ($count!==1) throw new RuntimeException('Fixture session was lost during restore');
}
fwrite(STDOUT,"PASS backup restore: seven tables, owner hash, document, session, foreign keys and indexes.\\n");
