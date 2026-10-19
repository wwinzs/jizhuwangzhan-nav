<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/auth.php';

try {
    $config = nav_config();
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'");
    header('Cache-Control: no-store');
    if ($origin === $config['frontend_origin']) {
        header('Access-Control-Allow-Origin: '.$origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (($config['require_https'] ?? true) !== false && !$secure)
        nav_reply(400, ['error'=>'HTTPS required']);
    if ($secure) header('Strict-Transport-Security: max-age=31536000');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($path)) $path = '/';
    $path = rtrim($path, '/') ?: '/';

    if ($method === 'OPTIONS') {
        nav_require_origin();
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
        exit;
    }

    if ($path === '/health' && $method === 'GET') {
        nav_db()->query('SELECT 1')->fetchColumn();
        nav_reply(200, ['status'=>'ok']);
    }
    if ($path === '/auth/me' && $method === 'GET') {
        $user = nav_session_user(nav_db());
        if (!$user) nav_reply(401, ['error'=>'Not authenticated']);
        nav_reply(200, ['user'=>$user]);
    }
    if ($path === '/auth/login' && $method === 'POST') {
        nav_require_origin();
        $body = nav_json_body();
        if (!is_string($body['email'] ?? null) || !is_string($body['password'] ?? null))
            nav_reply(400, ['error'=>'Email and password required']);
        $user = nav_authenticate(nav_db(), $body['email'], $body['password'], $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!$user) nav_reply(401, ['error'=>'Invalid credentials']);
        nav_create_session(nav_db(), (int)$user['id']);
        nav_reply(200, ['user'=>$user]);
    }
    if ($path === '/auth/logout' && $method === 'POST') {
        nav_require_origin();
        nav_logout(nav_db());
        nav_reply(200, ['ok'=>true]);
    }
    nav_reply(404, ['error'=>'Not found']);
} catch (Throwable $error) {
    error_log('nav backend request failed: '.$error->getMessage());
    nav_reply(500, ['error'=>'Internal server error']);
}
