<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{\Hambelela\Delivery\DriverSession::csrf((string)($_POST['csrf']??''));
  \Hambelela\Delivery\DriverSession::login($driverAuth,(string)($_POST['mobile']??''),(string)($_POST['secret']??''));header('Location: index.php');exit;
 }catch(DomainException $e){$error=$e->getMessage();}
 catch(Throwable $e){error_log('Driver login unavailable: '.$e->getMessage());$error='Sign-in is temporarily unavailable.';http_response_code(503);}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Driver sign-in · Hambelela</title><link rel="stylesheet" href="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/css/delivery-core.css"></head><body><main class="delivery-login"><div class="eyebrow">HAMBELELA DELIVERY</div><h1>Driver sign-in</h1><p>Sign in with your mobile number and private access secret.</p><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>"><label>Mobile number<input type="tel" name="mobile" autocomplete="username" required maxlength="30"></label><label>Access secret<input type="password" name="secret" autocomplete="current-password" required maxlength="128"></label><p role="alert"><?=htmlspecialchars($error,ENT_QUOTES)?></p><button class="primary">Sign in</button></form><p>If you cannot sign in, contact your Front administrator. Never share your access secret.</p></main></body></html>
