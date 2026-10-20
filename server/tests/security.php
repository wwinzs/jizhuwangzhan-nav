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

$valid = [
    'db_dsn'=>'mysql:host=127.0.0.1;dbname=nav_ci;charset=utf8mb4',
    'db_user'=>'ci', 'db_password'=>'synthetic-ci-only',
    'frontend_origin'=>'https://nav.wwinzs.cn',
    'app_key'=>str_repeat('a',64),
    'require_https'=>true,
];
check(nav_validate_config($valid)===$valid,'production config shape accepted');
$testOrigin=$valid;
$testOrigin['frontend_origin']='http://127.0.0.1:8766';
$testOrigin['require_https']=false;
check(nav_validate_config($testOrigin)===$testOrigin,'isolated loopback CI origin accepted');
foreach ([
    'https://nav.wwinzs.cn/path',
    'https://nav.wwinzs.cn/#fragment',
    'https://nav.wwinzs.cn?query=1',
    'https://evil@nav.wwinzs.cn',
    'https://nav.wwinzs.cn:99999',
    'https://nav.wwinzs.cn'."\n".'junk',
] as $origin) {
    $bad=$valid;
    $bad['frontend_origin']=$origin;
    try {nav_validate_config($bad);throw new RuntimeException('Accepted unsafe origin');}
    catch (RuntimeException $e) {
        if ($e->getMessage()==='Accepted unsafe origin') throw $e;
    }
}
check(true,'userinfo, path, query, fragment and trailing garbage origins refused');
foreach ([str_repeat('a',65),str_repeat('a',64).'evil',str_repeat('a',64)."\n",str_repeat('a',63)] as $key) {
    $bad=$valid;$bad['app_key']=$key;
    try {nav_validate_config($bad);throw new RuntimeException('Accepted bad app key');}
    catch (RuntimeException $e) {
        if ($e->getMessage()==='Accepted bad app key') throw $e;
    }
}
check(true,'malformed and truncated app keys refused');
