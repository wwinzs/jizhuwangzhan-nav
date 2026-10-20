<?php
declare(strict_types=1);

// Copy this file to config.local.php OUTSIDE public/.
// Store a new random app_key; do not commit real credentials.
return [
    'db_dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=jizhu_nav;charset=utf8mb4',
    'db_user' => 'nav_only',
    'db_password' => 'REPLACE_ME',
    'frontend_origin' => 'https://nav.example.com',
    'app_key' => 'REPLACE_WITH_AT_LEAST_64_RANDOM_HEX_CHARACTERS',
    'require_https' => true,
    // Disabled until SMTP is verified, rate limiting and end-to-end browser testing pass.
    'password_reset_enabled' => false, // Requires migration 003 and a separately enabled CLI mail worker.
    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 465,
        'tls' => 'smtps', // or 'starttls' with port 587. TLS must never be optional.
        'username' => 'noreply@example.com',
        'password' => 'REPLACE_IN_PRIVATE_CONFIG_ONLY',
        'from' => 'noreply@example.com',
        // 'ca_file' => '/path/to/private/trusted-ca.pem', // optional; default system CA trust
    ],
];
