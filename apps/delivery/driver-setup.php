<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$setupNonce=bin2hex(random_bytes(18));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$setupNonce}'; style-src 'self' 'nonce-{$setupNonce}'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if((!defined('DELIVERY_SETUP_ENABLED')||DELIVERY_SETUP_ENABLED!==true)&&(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED!==true)){http_response_code(503);exit('Delivery setup is not enabled.');}
if(empty($_SERVER['HTTPS'])||strtolower((string)$_SERVER['HTTPS'])==='off'){http_response_code(403);exit('Secure connection required.');}
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/FrontSession.php';require_once BASE_PATH.'/shared/delivery/DriverProvisioning.php';
try{$actor=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));if($actor['role']!=='owner_admin')throw new DomainException('Owner access required.');}
catch(DomainException $e){http_response_code(403);exit('Owner access required.');}
$_SESSION['delivery_front_csrf']??=bin2hex(random_bytes(32));$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        \Hambelela\Delivery\FrontSession::csrf((string)($_POST['csrf']??''));
        if(($_POST['verified']??'')!=='yes')throw new DomainException('Confirm the identity and mobile ownership first.');
        if(($_POST['secret']??'')!==($_POST['confirmation']??''))throw new DomainException('Access secrets do not match.');
        $id=(new \Hambelela\Delivery\DriverProvisioning(db()))->save($actor['id'],(string)($_POST['name']??''),(string)($_POST['mobile']??''),(string)($_POST['secret']??''),(int)($_POST['employee_id']??0));
        $message='Driver account saved. Employee ID: '.$id.'. Previous Driver sessions are revoked. No normal staff login was created.';
        $_SESSION['delivery_front_csrf']=bin2hex(random_bytes(32));
    }catch(DomainException $e){$error=$e->getMessage();}
    catch(Throwable $e){
        $reference=bin2hex(random_bytes(4));
        $sqlState=$e instanceof PDOException?(string)($e->errorInfo[0]??'unknown'):'none';
        $driverCode=$e instanceof PDOException?(int)($e->errorInfo[1]??0):0;
        error_log('Delivery setup failure ref='.$reference.' type='.get_class($e).' sqlstate='.$sqlState.' drivercode='.$driverCode);
        http_response_code(503);$error='Setup could not be completed. No partial account was saved. Reference: '.$reference.'. Please share this reference with the administrator, not your secret.';
    }
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Driver setup · Hambelela</title>
<link rel="stylesheet" href="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/css/delivery-core.css">
<style nonce="<?=$setupNonce?>">
.secret-field{display:flex;align-items:stretch;gap:8px}.secret-field input{flex:1;min-width:0}.secret-toggle{flex:0 0 auto;min-width:64px}.secret-toggle[hidden]{display:none}
.secret-hint{font-size:12px;margin-top:-4px}.setup-field{margin-bottom:13px}.setup-field>label{margin-bottom:6px}.secret-feedback{font-size:12px;min-height:18px;margin:6px 0 0}
</style></head><body><main class="delivery-login">
<div class="eyebrow">OWNER · DELIVERY ACCESS</div><h1>Driver account</h1>
<p>Create a restricted mobile-only Driver account, or reset a verified existing Driver’s secret. No email is required.</p>
<p role="status"><?=htmlspecialchars($message,ENT_QUOTES)?></p>
<p class="form-error" role="alert"><?=htmlspecialchars($error,ENT_QUOTES)?></p>
<form id="driver-setup-form" method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['delivery_front_csrf'],ENT_QUOTES)?>">
<label>Verified employee name<input name="name" required maxlength="160" value="<?=htmlspecialchars($message===''?(string)($_POST['name']??''):'',ENT_QUOTES)?>"></label>
<label>Mobile including country code<input name="mobile" type="tel" required maxlength="30" value="<?=htmlspecialchars($message===''?(string)($_POST['mobile']??''):'',ENT_QUOTES)?>"></label>
<label>Existing Driver employee ID (leave blank for new)<input name="employee_id" type="number" min="1" value="<?=htmlspecialchars($message===''?(string)($_POST['employee_id']??''):'',ENT_QUOTES)?>"></label>
<div class="setup-field"><label for="driver-secret">New private access secret</label>
<div class="secret-field"><input id="driver-secret" name="secret" type="password" minlength="16" maxlength="72" autocomplete="new-password" aria-describedby="secret-hint secret-feedback" required><button hidden class="secondary secret-toggle" type="button" data-secret-toggle="driver-secret" aria-controls="driver-secret" aria-label="Show new access secret" aria-pressed="false">Show</button></div></div>
<p class="secret-hint" id="secret-hint">Use 16–72 letters, numbers or symbols. A long phrase is easier to type. Do not include the mobile number. Both fields must match exactly. Special characters may count as more than one byte (maximum 72 bytes).</p>
<div class="setup-field"><label for="driver-confirmation">Confirm access secret</label>
<div class="secret-field"><input id="driver-confirmation" name="confirmation" type="password" minlength="16" maxlength="72" autocomplete="new-password" aria-describedby="secret-feedback" required><button hidden class="secondary secret-toggle" type="button" data-secret-toggle="driver-confirmation" aria-controls="driver-confirmation" aria-label="Show confirmed access secret" aria-pressed="false">Show</button></div>
<p class="secret-feedback" id="secret-feedback" role="status" aria-live="polite"></p></div>
<label class="check"><input type="checkbox" name="verified" value="yes" required>I verified this person’s identity and mobile ownership and approve restricted Delivery access.</label>
<button class="primary">Save Driver account</button>
</form>
<p>The Owner must enter and submit the secret privately. Do not send it in chat. If saving fails, your name and mobile are kept, but secrets must be entered again.</p>
<a href="index.php">Back to Delivery</a>
</main>
<script nonce="<?=$setupNonce?>">
'use strict';
const setupForm=document.getElementById('driver-setup-form');
const secretInput=document.getElementById('driver-secret');
const confirmationInput=document.getElementById('driver-confirmation');
const secretFeedback=document.getElementById('secret-feedback');
const secretToggles=[...document.querySelectorAll('[data-secret-toggle]')];
function maskSecrets(){
  secretToggles.forEach(button=>{
    const input=document.getElementById(button.dataset.secretToggle);
    input.type='password';button.textContent='Show';button.setAttribute('aria-pressed','false');
    button.setAttribute('aria-label',input===secretInput?'Show new access secret':'Show confirmed access secret');
  });
}
secretToggles.forEach(button=>{
  button.hidden=false;
  button.addEventListener('click',()=>{
    const input=document.getElementById(button.dataset.secretToggle);
    const show=input.type==='password';input.type=show?'text':'password';
    button.textContent=show?'Hide':'Show';button.setAttribute('aria-pressed',String(show));
    button.setAttribute('aria-label',(show?'Hide ':'Show ')+(input===secretInput?'new access secret':'confirmed access secret'));
  });
});
function checkSecret(){
  const bytes=new TextEncoder().encode(secretInput.value).length;
  const tooShort=secretInput.value.length>0&&secretInput.value.length<16;
  const tooLong=bytes>72;
  const mismatch=confirmationInput.value.length>0&&secretInput.value!==confirmationInput.value;
  secretInput.setCustomValidity(tooShort?'Use at least 16 characters for the access secret.':tooLong?'This secret is longer than 72 bytes. Use fewer characters.':'');
  confirmationInput.setCustomValidity(mismatch?'The two access secrets do not match. Check them with Show.':'');
  secretFeedback.textContent=tooShort?'Use at least 16 characters.':tooLong?'Use fewer characters (maximum 72 bytes).':mismatch?'The two secrets do not match.':confirmationInput.value?'The secrets match.':'';
}
secretInput.addEventListener('input',checkSecret);
confirmationInput.addEventListener('input',checkSecret);
setupForm.addEventListener('submit',maskSecrets);
window.addEventListener('pagehide',maskSecrets);
document.addEventListener('visibilitychange',()=>{if(document.hidden)maskSecrets();});
</script></body></html>
