<?php
declare(strict_types=1);

function nav_config(): array {
    static $config = null;
    if ($config !== null) return $config;
    $path = dirname(__DIR__) . '/config.local.php';
    if (!is_file($path)) throw new RuntimeException('Server is not configured.');
    $candidate = require $path;
    if (!is_array($candidate)) throw new RuntimeException('Invalid server configuration.');
    foreach (['db_dsn', 'db_user', 'db_password', 'frontend_origin', 'app_key'] as $name) {
        if (!isset($candidate[$name]) || !is_string($candidate[$name]) || $candidate[$name] === '')
            throw new RuntimeException('Missing configuration.');
    }
    if (!preg_match('/^[a-f0-9]{64,}$/i', $candidate['app_key']))
        throw new RuntimeException('Invalid secret key.');
    $origin = parse_url($candidate['frontend_origin']);
    if (!$origin || !in_array($origin['scheme'] ?? '', ['https','http'], true)
        || empty($origin['host']) || isset($origin['path']) || isset($origin['query']))
        throw new RuntimeException('Invalid frontend origin.');
    if (($candidate['require_https'] ?? true) !== false && $origin['scheme'] !== 'https')
        throw new RuntimeException('HTTPS frontend required.');
    $config = $candidate;
    return $config;
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
    if (!str_starts_with(strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])), 'application/json'))
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
