<?php
declare(strict_types=1);
// Run manually or from a dedicated maintenance timer. CLI only; no HTTP entrypoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $db = nav_db();
    // A bounded batch prevents a maintenance job from holding large table locks.
    $sessionDelete = $db->prepare(
        "DELETE FROM nav_sessions
         WHERE expires_at <= UTC_TIMESTAMP(6)
            OR (revoked_at IS NOT NULL
                AND revoked_at < UTC_TIMESTAMP(6) - INTERVAL 30 DAY)
         ORDER BY id LIMIT 500"
    );
    $sessionDelete->execute();

    $limitDelete = $db->prepare(
        "DELETE FROM nav_login_limits
         WHERE window_start < UTC_TIMESTAMP(6) - INTERVAL 1 DAY
         ORDER BY window_start LIMIT 500"
    );
    $limitDelete->execute();

    fwrite(STDOUT, sprintf(
        "Maintenance complete: removed %d expired/stale sessions, %d expired rate-limit entries.\n",
        $sessionDelete->rowCount(),
        $limitDelete->rowCount()
    ));
} catch (Throwable $error) {
    // Avoid logging credentials, DSNs or SQL driver exception details.
    fwrite(STDERR, "Maintenance failed; inspect private server logs.\n");
    exit(1);
}
