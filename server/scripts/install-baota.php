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
    fwrite(STDERR, "请使用服务器 root 用户运行安装程序，以便安全设置配置文件权限。\n");
    exit(1);
}
if (!defined('PASSWORD_ARGON2ID') || !extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "缺少必要的 PHP 扩展：Argon2id 或 pdo_mysql。\n");
    exit(1);
}
if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
    fwrite(STDERR, "请在交互式 SSH 终端中运行，勿通过管道传入密码。\n");
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
    if (!is_string($line)) throw new RuntimeException('输入已取消。');
    return rtrim($line, "\r\n");
}

try {
    if (!is_dir($public) || realpath($public) !== $expectedPublic) {
        throw new RuntimeException('安装目录不正确，预期目录：' . $expectedPublic);
    }
    if (!is_file($schemaFile) || !is_file($public . '/index.php')) {
        throw new RuntimeException('后端安装包文件不完整，请检查是否解压成功。');
    }
    if (file_exists($configFile) || is_link($configFile)) {
        throw new RuntimeException('已存在 config.local.php，为避免覆盖已有账号配置，安装已停止。');
    }

    echo "记住网站 v1.9（测试版）—— 独立账号数据库安装\n";
    echo "请先在宝塔创建一个全新的空数据库和专用数据库用户。\n";
    $name = installPrompt('请输入数据库名称：');
    $dbUser = installPrompt('请输入数据库用户名：');
    $dbPassword = installPrompt('请输入数据库密码（输入时不显示）：', true);
    $ownerEmail = strtolower(trim(installPrompt('请输入站长邮箱：')));
    $ownerPassword = installPrompt('请设置站长登录密码（至少 14 字节，输入时不显示）：', true);
    $confirmation = installPrompt('请再次输入站长登录密码（不显示）：', true);

    if (!preg_match('/^[a-zA-Z0-9_]{1,48}$/D', $name)
        || !preg_match('/^[a-zA-Z0-9_]{1,80}$/D', $dbUser)
        || $dbPassword === '' || strlen($dbPassword) > 4096) {
        throw new RuntimeException('数据库名称、用户名或密码不符合要求。');
    }
    if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || strlen($ownerEmail) > 254) {
        throw new RuntimeException('站长邮箱格式无效。');
    }
    if (strlen($ownerPassword) < 14 || strlen($ownerPassword) > 1024
        || !hash_equals($ownerPassword, $confirmation)) {
        throw new RuntimeException('站长密码必须为 14～1024 字节，且两次输入一致。');
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
        throw new RuntimeException('数据库不是空库。为保护已有数据，安装已停止。');
    }

    // DDL is not transactional in MySQL. If a statement fails, preserve partial
    // state for inspection rather than blindly dropping any table.
    $sql = file_get_contents($schemaFile);
    if (!is_string($sql) || $sql === '') throw new RuntimeException('缺少数据库建表文件 schema.sql。');
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
        throw new RuntimeException('无法写入数据库安全配置文件。');
    }
    if (!chown($configFile, 'www') || !chgrp($configFile, 'www') || !chmod($configFile, 0600)) {
        throw new RuntimeException('无法为数据库配置文件设置 www 属主及 0600 权限。');
    }

    $tables = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
    )->fetchColumn();
    if ($tables !== 4) throw new RuntimeException('数据库建表数量不正确。');
    if ((int) $pdo->query("SELECT COUNT(*) FROM nav_users WHERE role='owner'")->fetchColumn() !== 1) {
        throw new RuntimeException('站长账号验证失败。');
    }
    echo "安装成功：已建立 4 张独立数据表、1 个站长账号，配置文件权限为 0600。\n";
    echo "目前未开启公开注册、云同步，也未启用导航页上的登录入口。\n";
    echo "下一步：验证 https://api-nav.wwinzs.cn/health 和 PHP-FPM 访问限制。\n";
} catch (Throwable $error) {
    fwrite(STDERR, '安装已停止：' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, "未自动删除数据库或表。如遇中途失败，请保留现场检查，勿直接重复安装。\n");
    exit(1);
}
