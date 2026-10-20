<?php
declare(strict_types=1);

/**
 * Validate candidate configuration independently from the per-request cache.
 * An Origin contains only scheme, host and optional port; credentials, fragments,
 * URLs with paths and ambiguous trailing garbage are never trusted.
 */
function nav_validate_config(array $candidate): array {
    foreach (['db_dsn','db_user','db_password','frontend_origin','app_key'] as $name) {
        if (!isset($candidate[$name]) || !is_string($candidate[$name]) || $candidate[$name] === '')
            throw new RuntimeException('Missing configuration.');
    }
    if (!preg_match('/\\A[a-f0-9]{64,}\\z/iD', $candidate['app_key']))
        throw new RuntimeException('Invalid secret key.');
    $value = $candidate['frontend_origin'];
    if (!preg_match('~\\Ahttps?://(?:[a-z0-9.-]+|\\[[0-9a-f:.]+\\])(?::[0-9]{1,5})?\\z~iD', $value))
        throw new RuntimeException('Invalid frontend origin.');
    $origin = parse_url($value);
    if (!$origin || !in_array($origin['scheme'] ?? '', ['https','http'],true)
        || empty($origin['host']) || isset($origin['user']) || isset($origin['pass'])
        || isset($origin['path']) || isset($origin['query']) || isset($origin['fragment'])
        || (isset($origin['port']) && ($origin['port']<1 || $origin['port']>65535)))
        throw new RuntimeException('Invalid frontend origin.');
    if (($candidate['require_https'] ?? true) !== false && $origin['scheme'] !== 'https')
        throw new RuntimeException('HTTPS frontend required.');
    return $candidate;
}

function nav_config(): array {
    static $config = null;
    if ($config !== null) return $config;
    $path = dirname(__DIR__) . '/config.local.php';
    if (!is_file($path)) throw new RuntimeException('Server is not configured.');
    $candidate = require $path;
    if (!is_array($candidate)) throw new RuntimeException('Invalid server configuration.');
    $candidate = nav_validate_config($candidate);
    $config = $candidate;
    return $config;
}

/**
 * A CLI mail process must use a regular, private server config file.
 * Symlinks are rejected to avoid accidentally following files outside the API.
 */
function nav_private_config_secure(string $file): bool {
    if (is_link($file) || !is_file($file)) return false;
    $stat=@stat($file);
    if (!$stat || ($stat['mode'] & 0077)!==0) return false;
    if (function_exists('posix_geteuid')) {
        $uid=posix_geteuid();
        if ($uid!==0 && $uid!==$stat['uid']) return false;
    }
    return true;
}

function nav_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $config = nav_config();
    $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function nav_reply(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function nav_email(string $email): ?string {
    $email = strtolower(trim($email));
    if (strlen($email)>254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    return $email;
}

function nav_require_origin(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!hash_equals(nav_config()['frontend_origin'], (string)$origin))
        nav_reply(403, ['error' => 'Invalid request origin']);
}

function nav_json_body(): array {
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json')
        nav_reply(415, ['error' => 'JSON content type required']);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>4096)
        nav_reply(413, ['error' => 'Request too large']);
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if (!is_string($raw) || strlen($raw)>4096) nav_reply(413, ['error'=>'Request too large']);
    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) throw new RuntimeException('JSON object required');
        return $data;
    } catch (Throwable) {
        nav_reply(400, ['error'=>'Invalid JSON request']);
    }
}

function nav_cookie(string $value, int $expires): void {
    setcookie('__Host-nav_session', $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}
