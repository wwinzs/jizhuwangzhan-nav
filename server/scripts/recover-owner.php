<?php
declare(strict_types=1);
/**
 * Emergency owner recovery; deliberately not an HTTP endpoint.
 * Requires an interactive server terminal with effective root/config-file-owner privileges.
 * NEVER supply secrets through argv, an environment variable, a file or CI logs.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/auth.php';

function readRecoveryLine(string $prompt, bool $hidden = false): string {
    fwrite(STDOUT, $prompt);
    if ($hidden) @system('stty -echo < /dev/tty');
    try { $line = fgets(STDIN); }
    finally {
        if ($hidden) {
            @system('stty echo < /dev/tty');
            fwrite(STDOUT, PHP_EOL);
        }
    }
    if (!is_string($line)) throw new RuntimeException('已取消输入。');
    return rtrim($line, "\r\n");
}

try {
    $configPath = dirname(__DIR__) . '/config.local.php';
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN) ||
        !function_exists('posix_geteuid') || !is_file($configPath)) {
        throw new RuntimeException('仅允许在已配置的服务器交互式 SSH 终端执行。');
    }
    $uid = posix_geteuid();
    if ($uid !== 0 && $uid !== fileowner($configPath)) {
        throw new RuntimeException('仅允许 root 或配置文件所属账号执行。');
    }
    $email = readRecoveryLine('请输入需恢复的站长邮箱：');
    $approval = readRecoveryLine('输入 RECOVER 确认重置站长密码并退出所有登录会话：');
    if ($approval !== 'RECOVER') throw new RuntimeException('未确认恢复操作。');
    $password = readRecoveryLine('设置新密码（至少 14 字节，不显示）：', true);
    $confirmation = readRecoveryLine('再次输入新密码（不显示）：', true);
    if (!hash_equals($password, $confirmation)) throw new RuntimeException('两次密码不一致。');
    if (!nav_recover_owner_password(nav_db(), $email, $password)) {
        throw new RuntimeException('账号不符合恢复条件或新密码长度不合法。');
    }
    unset($password, $confirmation);
    fwrite(STDOUT, "站长密码已恢复，所有旧会话已失效。请用新密码登录。\n");
} catch (Throwable $error) {
    fwrite(STDERR, '恢复未完成：' . ($error instanceof RuntimeException
        ? $error->getMessage() : '请检查私有服务端日志及数据库迁移状态。') . PHP_EOL);
    exit(1);
}
