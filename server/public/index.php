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
    if ($path === '/auth/sessions' && $method === 'GET') {
        nav_require_origin();
        $db = nav_db();
        $user = nav_session_user($db);
        if (!$user) nav_reply(401, ['error'=>'Not authenticated']);
        nav_reply(200, ['sessions'=>nav_active_sessions($db, (int)$user['id'])]);
    }
    if ($path === '/auth/sessions/revoke-others' && $method === 'POST') {
        nav_require_origin();
        $db = nav_db();
        $user = nav_session_user($db);
        if (!$user) nav_reply(401, ['error'=>'Not authenticated']);
        nav_json_body();
        $count = nav_revoke_other_sessions($db, (int)$user['id']);
        nav_reply(200, ['ok'=>true, 'revoked_count'=>$count]);
    }
    if ($path === '/auth/change-password' && $method === 'POST') {
        nav_require_origin();
        $db = nav_db();
        $user = nav_session_user($db);
        if (!$user) nav_reply(401, ['error'=>'Not authenticated']);
        $body = nav_json_body();
        $current = $body['current_password'] ?? null;
        $next = $body['new_password'] ?? null;
        if (!is_string($current) || !is_string($next))
            nav_reply(400, ['error'=>'Current and new password required']);
        if (strlen($current)>1024 || strlen($next)<14 || strlen($next)>1024)
            nav_reply(400, ['error'=>'Invalid password length']);
        $key = nav_limit_key('password-change', (string)$user['id']);
        if (!nav_limit_try($db, $key, 8))
            nav_reply(429, ['error'=>'Too many attempts']);
        $result = nav_change_password($db, (int)$user['id'], $current, $next);
        if ($result === 'invalid') nav_reply(401, ['error'=>'Invalid current password']);
        if ($result === 'same') nav_reply(400, ['error'=>'New password must differ']);
        if ($result === 'conflict') nav_reply(409, ['error'=>'Password changed in another session']);
        nav_cookie('', time()-3600);
        nav_reply(200, ['ok'=>true, 'reauth_required'=>true]);
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
