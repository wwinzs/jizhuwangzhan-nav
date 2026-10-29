<?php
declare(strict_types=1);
/**
 * The shipped schema/migrations contain simple semicolon-delimited DDL only.
 * Strip entire MySQL "--" comment lines BEFORE splitting; comments may contain
 * semicolons. Do not use for arbitrary SQL with quoted semicolons or DELIMITER.
 */
function nav_migration_statements(string $sql): array {
    $clean = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $sql);
    if (!is_string($clean)) throw new RuntimeException('Invalid migration SQL');
    if (preg_match('/\bDELIMITER\b|\/\*|\*\//i', $clean))
        throw new RuntimeException('Unsupported migration syntax');
    $statements=[];
    foreach (explode(';', $clean) as $statement) {
        $statement=trim($statement);
        if ($statement !== '') $statements[]=$statement;
    }
    if (!$statements) throw new RuntimeException('Empty migration');
    return $statements;
}
