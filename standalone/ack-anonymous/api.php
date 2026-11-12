<?php
declare(strict_types=1);
// Deliberately no portal bootstrap, session, telemetry, notification or personalized ticket.
require __DIR__.'/Store.php';
header('Content-Type: application/json');header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
try{
    $origin=rtrim((string)getenv('ACK_ANON_ORIGIN'),'/');$secret=(string)getenv('ACK_ANON_GROUP_SECRET');
    if(getenv('ACK_ANON_PRIVACY_READY')!=='1'||!$origin||strtolower((string)($_SERVER['HTTP_HOST']??''))!==strtolower((string)parse_url($origin,PHP_URL_HOST))||strlen($secret)<32||isset($_SERVER['HTTP_COOKIE'])||session_status()===PHP_SESSION_ACTIVE||isset($_SERVER['HTTP_AUTHORIZATION'])||
       $_SERVER['REQUEST_METHOD']!=='POST'||($_SERVER['HTTP_ORIGIN']??'')!==$origin||!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json'))throw new DomainException('Anonymous intake is unavailable.');
    $raw=file_get_contents('php://input',false,null,0,10001);
    if(strlen($raw)>10000)throw new DomainException('Report too long.');
    $d=json_decode($raw,true,8);if(!is_array($d))throw new DomainException('Invalid request.');
    if(($d['action']??'')==='submit'){
        $ticket=(string)($d['ticket']??'');$today=hash_hmac('sha256','ack-drop:'.gmdate('Y-m-d'),$secret);
        $yesterday=hash_hmac('sha256','ack-drop:'.gmdate('Y-m-d',time()-86400),$secret);
        if(!hash_equals($today,$ticket)&&!hash_equals($yesterday,$ticket))throw new DomainException('Reopen anonymous reporting from the portal to submit.');
    }
    $db=new PDO((string)getenv('ACK_ANON_DSN'),(string)getenv('ACK_ANON_DB_USER'),(string)getenv('ACK_ANON_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    echo json_encode((new \Hambelela\AnonymousIntake\Store($db,(int)getenv('ACK_CONTACT_OWNER_ID')))->run($d),JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(400);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){http_response_code(503);echo '{"error":"Service unavailable. Keep your code and retry."}';}
