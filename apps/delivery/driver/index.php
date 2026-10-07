<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
try{$driverActor=\Hambelela\Delivery\DriverSession::actor($driverAuth);}catch(Throwable $e){header('Location: login.php');exit;}
if(DELIVERY_ENABLED!==true){
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Driver sign-in verified · Hambelela</title><link rel="stylesheet" href="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/css/delivery-core.css"></head><body><main class="delivery-login"><div class="eyebrow">LIVE · SIGN-IN TEST ONLY</div><h1>Sign-in verified</h1><p>Your restricted Driver login is working. Employee ID: <?= (int)$driverActor['id'] ?>.</p><p>No delivery records or payment actions are available in this test. Operational activation is still pending.</p><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>"><button class="primary">Sign out</button></form></main></body></html><?php
exit;
}
$driverPage=true;$endpoint='api.php';require BASE_PATH.'/shared/delivery/CoreView.php';
