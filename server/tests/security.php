<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';

function check(bool $condition,string $label): void {
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
check(nav_email('TEST@Example.COM')==='test@example.com','email canonicalization');
check(nav_email('not-an-email')===null,'invalid emails rejected');
check(nav_email(str_repeat('x',255).'@example.com')===null,'oversized email rejected');
check(defined('PASSWORD_ARGON2ID'),'argon2id supported');
$hash=password_hash('a deliberately long test password',PASSWORD_ARGON2ID);
check(password_verify('a deliberately long test password',$hash),'argon2id verification');
check(!password_verify('wrong password',$hash),'argon2id rejects invalid password');
check(strlen(bin2hex(random_bytes(32)))===64,'cryptographic token length');
check(hash('sha256','sample')!==hash('sha256','sample2'),'tokens hash distinctly');
