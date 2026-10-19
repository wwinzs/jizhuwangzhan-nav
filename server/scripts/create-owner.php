<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/auth.php';

function privateInput(string $label): string {
    fwrite(STDOUT, $label);
    $isTty = function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($isTty) @system('stty -echo');
    try { $value = fgets(STDIN); }
    finally { if ($isTty) { @system('stty echo'); fwrite(STDOUT, PHP_EOL); } }
    if (!is_string($value)) throw new RuntimeException('No password input');
    return rtrim($value, "\r\n");
}

try {
    $email = nav_email(trim($argv[1] ?? ''));
    if (!$email) throw new RuntimeException('Usage: php scripts/create-owner.php owner@example.com');
    if (!defined('PASSWORD_ARGON2ID')) throw new RuntimeException('PHP Argon2id support required');
    $password = privateInput('Enter a new strong password (not shown): ');
    if (strlen($password)<14 || strlen($password)>1024)
        throw new RuntimeException('Password must be at least 14 bytes and at most 1024 bytes.');
    $confirm = privateInput('Confirm password: ');
    if (!hash_equals($password, $confirm)) throw new RuntimeException('Passwords do not match');
    $db = nav_db();
    $lock = $db->query("SELECT GET_LOCK('nav_only_one_owner',10)")->fetchColumn();
    if ((int)$lock!==1) throw new RuntimeException('Failed to acquire installation lock');
    try {
        $existing=(int)$db->query("SELECT COUNT(*) FROM nav_users WHERE role='owner'")->fetchColumn();
        if($existing>0)throw new RuntimeException('Owner account already exists');
        $hash=password_hash($password,PASSWORD_ARGON2ID,['memory_cost'=>65536,'time_cost'=>3,'threads'=>1]);
        $db->prepare("INSERT INTO nav_users (email,password_hash,role,status) VALUES (?,?,'owner','active')")
           ->execute([$email,$hash]);
    } finally {
        $db->query("SELECT RELEASE_LOCK('nav_only_one_owner')");
    }
    fwrite(STDOUT, "Owner created. Public registration remains disabled.\n");
} catch(Throwable $error) {
    fwrite(STDERR, 'Initialization failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
