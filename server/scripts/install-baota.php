<?php
declare(strict_types=1);

/**
 * Private, interactive installer for an EMPTY, dedicated Baota MySQL 8 database.
 * Run via CLI as root on the intended VPS; no passwords on command line.
 * This is a Draft API installation only; it does not enable the website login UI.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Run as root on the VPS so the config can be owned by www.\n");
    exit(1);
}
if (!defined('PASSWORD_ARGON2ID') || !extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "PHP Argon2id and pdo_mysql are required.\n");
    exit(1);
}
if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
    fwrite(STDERR, "Interactive SSH terminal required. Do not pipe secrets into this script.\n");
    exit(1);
}

$server = dirname(__DIR__);
$configFile = $server . '/config.local.php';
$schemaFile = $server . '/schema.sql';
$public = $server . '/public';
$expectedPublic = '/www/wwwroot/api-nav.wwinzs.cn/server/public';
umask(0077);

function installPrompt(string $label, bool $secret = false): string {
    fwrite(STDOUT, $label);
    if ($secret) @system('stty -echo');
    try {
        $line = fgets(STDIN);
    } finally {
        if ($secret) { @system('stty echo'); fwrite(STDOUT, PHP_EOL); }
    }
    if (!is_string($line)) throw new RuntimeException('Input cancelled.');
    return rtrim($line, "\r\n");
}

try {
    if (!is_dir($public) || realpath($public) !== $expectedPublic) {
        throw new RuntimeException('Unexpected project path. Expected ' . $expectedPublic);
    }
    if (!is_file($schemaFile) || !is_file($public . '/index.php')) {
        throw new RuntimeException('Backend package files are incomplete.');
    }
    if (file_exists($configFile) || is_link($configFile)) {
        throw new RuntimeException('config.local.php already exists. Refusing to overwrite credentials or data.');
    }

    echo "Private account API v1.9 Draft: isolated database installation\n";
    echo "Prerequisite: create an EMPTY database and its dedicated user in Baota first.\n";
    $name = installPrompt('Dedicated database name: ');
    $dbUser = installPrompt('Dedicated database user: ');
    $dbPassword = installPrompt('Database user password (hidden): ', true);
    $ownerEmail = strtolower(trim(installPrompt('Owner email: ')));
    $ownerPassword = installPrompt('New owner password (at least 14 bytes, hidden): ', true);
    $confirmation = installPrompt('Confirm owner password (hidden): ', true);

    if (!preg_match('/^[a-zA-Z0-9_]{1,48}$/D', $name)
        || !preg_match('/^[a-zA-Z0-9_]{1,80}$/D', $dbUser)
        || $dbPassword === '' || strlen($dbPassword) > 4096) {
        throw new RuntimeException('Invalid dedicated database name, user, or password.');
    }
    if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || strlen($ownerEmail) > 254) {
        throw new RuntimeException('Invalid owner email.');
    }
    if (strlen($ownerPassword) < 14 || strlen($ownerPassword) > 1024
        || !hash_equals($ownerPassword, $confirmation)) {
        throw new RuntimeException('Owner password must be 14–1024 bytes and match confirmation.');
    }

    // Avoid accidentally initializing another existing application's database.
    $cfg = [
        'db_dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=' . $name . ';charset=utf8mb4',
        'db_user' => $dbUser,
        'db_password' => $dbPassword,
        'frontend_origin' => 'https://nav.wwinzs.cn',
        'app_key' => bin2hex(random_bytes(32)),
        'require_https' => true,
    ];
    $pdo = new PDO($cfg['db_dsn'], $cfg['db_user'], $cfg['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $count = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
    )->fetchColumn();
    if ($count !== 0) {
        throw new RuntimeException('Database is not empty. Refusing to modify existing tables.');
    }

    // DDL is not transactional in MySQL. If a statement fails, preserve partial
    // state for inspection rather than blindly dropping any table.
    $sql = file_get_contents($schemaFile);
    if (!is_string($sql) || $sql === '') throw new RuntimeException('Missing schema.');
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }

    $passwordHash = password_hash($ownerPassword, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1,
    ]);
    $stmt = $pdo->prepare(
        "INSERT INTO nav_users (email, password_hash, role, status) VALUES (?, ?, 'owner', 'active')"
    );
    $stmt->execute([$ownerEmail, $passwordHash]);
    unset($ownerPassword, $confirmation, $passwordHash);

    $configContents = "<?php\n"
        . "declare(strict_types=1);\n"
        . "// Private server configuration; not under public/.\n"
        . "return " . var_export($cfg, true) . ";\n";
    if (file_put_contents($configFile, $configContents, LOCK_EX) === false) {
        throw new RuntimeException('Unable to create secure config file.');
    }
    if (!chown($configFile, 'www') || !chgrp($configFile, 'www') || !chmod($configFile, 0600)) {
        throw new RuntimeException('Cannot set private config owner and 0600 permissions.');
    }

    $tables = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
    )->fetchColumn();
    if ($tables !== 4) throw new RuntimeException('Database table count mismatch.');
    if ((int) $pdo->query("SELECT COUNT(*) FROM nav_users WHERE role='owner'")->fetchColumn() !== 1) {
        throw new RuntimeException('Owner account verification failed.');
    }
    echo "INSTALL PASS: 4 dedicated tables, one owner, private config mode 0600.\n";
    echo "No public signup, cloud sync, or navigation login UI was enabled.\n";
    echo "Next: test https://api-nav.wwinzs.cn/health and PHP-FPM isolation.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'INSTALL STOPPED: ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, "No automatic database deletion was performed. Preserve any partial data for review.\n");
    exit(1);
}
