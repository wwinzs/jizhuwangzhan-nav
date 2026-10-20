<?php
declare(strict_types=1);
// CI-only: parse the synthetic SMTPS mailbox and exercise one-use HTTP confirmation.
if (getenv('CI') !== 'true') exit(2);
require_once dirname(__DIR__) . '/src/auth.php';

function httpReset(string $route, array $body): int {
    $curl = curl_init('http://127.0.0.1:8777'.$route);
    curl_setopt_array($curl,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER=>['Origin: https://nav.wwinzs.cn','Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8,
        CURLOPT_PROXY=>'',
    ]);
    $raw=curl_exec($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($raw)) throw new RuntimeException('Reset HTTP call failed');
    return $status;
}

$mail=file_get_contents('/tmp/nav-smtp-captured.eml');
if (!is_string($mail)) throw new RuntimeException('Mail fixture not captured');
$parts=explode("\r\n\r\n",$mail,2);
if(count($parts)!==2)throw new RuntimeException('Invalid MIME fixture');
$body=base64_decode(preg_replace('/\s+/','',$parts[1]),true);
if(!is_string($body) ||
    !preg_match('~https://nav\.wwinzs\.cn/\#password-reset=([0-9a-f]{64})~',$body,$matches))
    throw new RuntimeException('No reset URL in synthetic mail');
$token=$matches[1]; // Never echo the token or put it in logs.
$password='CI rotated password only 987654321!';
$original='a very long CI password 987654!';
if(httpReset('/auth/password-reset/confirm',['token'=>$token,'new_password'=>$password])!==200)
    throw new RuntimeException('Valid mail token redemption failed');
if(httpReset('/auth/password-reset/confirm',['token'=>$token,'new_password'=>$original])!==400)
    throw new RuntimeException('Replayed mail token accepted');
if(httpReset('/auth/login',['email'=>'owner@example.com','password'=>$original])!==401)
    throw new RuntimeException('Old password still works');
if(httpReset('/auth/login',['email'=>'owner@example.com','password'=>$password])!==200)
    throw new RuntimeException('New password does not work');
if(!nav_recover_owner_password(nav_db(),'owner@example.com',$original))
    throw new RuntimeException('Cannot restore isolated test fixture');
unset($token,$mail,$body,$matches);
fwrite(STDOUT,"Synthetic TLS mail to one-use HTTP confirmation and new login: PASS.\n");
