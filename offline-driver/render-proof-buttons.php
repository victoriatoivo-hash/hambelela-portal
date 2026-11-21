<?php
if(PHP_SAPI!=='cli')exit(1);
define('BASE_PATH',dirname(__DIR__));$local=true;$base='';$assignmentId=123;$error=null;$canSubmit=true;$back='/apps/delivery/index.php?delivery=123';
$h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');$asset=static fn($s)=>'/'.$s;$icon=static fn($s)=>'';
$_GET=['compact'=>'1'];$_SESSION=['csrf'=>'synthetic-csrf'];
$ctx=['receipt'=>null,'reference'=>'SYNTHETIC-123','name'=>'Test recipient','arrival'=>null,'reason'=>null,'version'=>7,'payment_verified'=>true,'payment_label'=>'EFT paid','due'=>0,'policy'=>['evidence'=>'signature','representative'=>'relationship']];
require BASE_PATH.'/shared/delivery/proof/View.php';
