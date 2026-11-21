<?php
if(!defined('DRIVER_OFFLINE_LAB')||!DRIVER_OFFLINE_LAB)exit;
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
$base='';$local=true;$reference=true;$assignmentId=0;$error=null;
$c=['reference'=>'ISSUE · DESIGN EXAMPLE','name'=>'Sample customer','version'=>0,'driver'=>true,'incident'=>null,'blocked'=>null];
require BASE_PATH.'/shared/delivery/issues/Helpers.php';require BASE_PATH.'/shared/delivery/issues/View.php';
