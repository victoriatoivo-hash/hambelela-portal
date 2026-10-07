<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
try{\Hambelela\Delivery\EmployeeDriverSession::csrf((string)($_POST['csrf']??''));logout_user();header('Location: '.BASE_URL.'/login.php',true,303);}
catch(DomainException $e){http_response_code(403);exit('Session verification failed.');}
