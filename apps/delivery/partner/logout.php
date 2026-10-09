<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
try{\Hambelela\Delivery\PartnerAuth::csrf((string)($_POST['csrf']??''));}catch(DomainException $e){http_response_code(403);exit('Session verification failed.');}
$_SESSION=[];setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_destroy();header('Location: login.php');
