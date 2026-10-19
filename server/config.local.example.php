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
];
