<?php
declare(strict_types=1);
/**
 * v1.9.10 read-only schema gate; no SQL DDL/DML is ever executed here.
 * Does not print credentials, owner email, bearer tokens, DSN, or SQL errors.
 */
function nav_check_upgrade_schema(PDO $db): array {
    $base = ['nav_login_limits','nav_sessions','nav_user_documents','nav_users'];
    $stage = [
        'nav_security_events',
        'nav_password_reset_tokens',
        'nav_reset_mail_queue',
    ];
    $actual = $db->query('SELECT table_name FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\'')
        ->fetchAll(PDO::FETCH_COLUMN);
    sort($actual);
    $prefix = $base;
    for ($step=0; $step<=3; $step++) {
        $sorted=$prefix;
        sort($sorted);
        if ($actual === $sorted) {
            $owner=(int)$db->query("SELECT COUNT(*) FROM nav_users
                WHERE role='owner' AND status='active'")->fetchColumn();
            $owners=(int)$db->query("SELECT COUNT(*) FROM nav_users
                WHERE role='owner'")->fetchColumn();
            if ($owner !== 1 || $owners !== 1)
                return ['state'=>'BLOCKED', 'reason'=>'owner_invariant'];
            $requirements = [
                'nav_users'=>['email','password_hash','role','status'],
                'nav_sessions'=>['user_id','token_hash','expires_at','revoked_at'],
                'nav_login_limits'=>['key_hash','attempts','window_start'],
                'nav_user_documents'=>['user_id','revision','document_json'],
            ];
            if ($step>=1) $requirements['nav_security_events']=['user_id','event_type','created_at'];
            if ($step>=2) $requirements['nav_password_reset_tokens']=['user_id','token_hash','expires_at','consumed_at'];
            if ($step>=3) $requirements['nav_reset_mail_queue']=['recipient_hash','recipient_cipher',
                'nonce','auth_tag','state','attempts','lease_until','created_at'];
            $columns=$db->query("SELECT table_name,column_name FROM information_schema.columns
                WHERE table_schema=DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
            $have=[];
            foreach ($columns as $row) $have[$row['table_name']][$row['column_name']]=true;
            foreach ($requirements as $table=>$required) {
                foreach ($required as $column) {
                    if (empty($have[$table][$column]))
                        return ['state'=>'BLOCKED','reason'=>'missing_column'];
                }
            }
            if ($step>=2) {
                $column=$db->query("SELECT column_type FROM information_schema.columns
                    WHERE table_schema=DATABASE() AND table_name='nav_security_events'
                      AND column_name='event_type'")->fetchColumn();
                if (!is_string($column) || !str_contains($column, "'password_reset'"))
                    return ['state'=>'BLOCKED','reason'=>'audit_enum_incomplete'];
            }
            return ['state'=> $step===3 ? 'READY' : 'MIGRATION_REQUIRED',
                'completed_migrations'=>$step,
                'next_migration'=>$step<3 ? sprintf('%03d', $step+1) : null];
        }
        if ($step<3) $prefix[]=$stage[$step];
    }
    return ['state'=>'BLOCKED', 'reason'=>'unexpected_or_partial_tables'];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    try {
        require_once dirname(__DIR__) . '/src/bootstrap.php';
        $result=nav_check_upgrade_schema(nav_db());
        fwrite(STDOUT, json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL);
        if ($result['state'] !== 'READY') exit(2);
    } catch (Throwable) {
        fwrite(STDERR, "Schema preflight failed; do not apply migrations automatically.\n");
        exit(3);
    }
}
