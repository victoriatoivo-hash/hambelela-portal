<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Referrer-Policy: no-referrer');
$screen=(string)($_GET['mode']??'pin');
if(!in_array($screen,['pin','setup','email'],true))$screen='pin';
$error='';$profile=(string)($_POST['profile']??'');
if($_SERVER['REQUEST_METHOD']==='POST')try{
    \Hambelela\Delivery\PartnerAuth::csrf((string)($_POST['csrf']??''));
    $access=new \Hambelela\Delivery\PartnerAccess(db());
    $secret=(string)($_POST['secret']??'');$selected=null;
    if($screen==='email')$email=(string)($_POST['email']??'');
    else {
        $selected=$access->pinProfile($profile);$email=(string)$selected['email'];
        if($screen==='pin'&&!preg_match('/^[0-9]{8,12}$/D',$secret))throw new DomainException('Enter your PIN, or choose Set up my PIN.');
        if($screen==='setup'){
            $new=(string)($_POST['pin']??'');
            \Hambelela\Delivery\PartnerAccess::validatePin($new);
            if(!hash_equals($new,(string)($_POST['confirmation']??'')))throw new DomainException('The new PINs do not match.');
        }
    }
    $identity=$partnerAuth->authenticate($email,$secret,(string)($_SERVER['REMOTE_ADDR']??''));
    $actor=$partnerAuth->actor($identity,true);
    if($selected&&(int)$selected['id']!==$actor['id'])throw new DomainException('Account changed. Please try again.');
    if($screen==='setup'){
        $access->password($new,'',$actor,$secret,$profile);
        session_regenerate_id(true);$_SESSION=['csrf'=>bin2hex(random_bytes(32)),'pin_saved'=>true];
        header('Location: login.php',true,303);exit;
    }
    session_regenerate_id(true);$_SESSION=['partner'=>$identity,'csrf'=>bin2hex(random_bytes(32))];
    header('Location: '.(!empty($actor['must_change_password'])?'password.php':($actor['role']==='partner_admin'?'workspace.php':'index.php')),true,303);exit;
}catch(DomainException $e){$error=$e->getMessage();}catch(Throwable $e){$error='Sign-in is temporarily unavailable.';}
$saved=!empty($_SESSION['pin_saved']);unset($_SESSION['pin_saved']);
function tl_h(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tedlaser delivery sign-in</title>
<?php foreach(['delivery-core','delivery-controls'] as $sheet): ?><link rel="stylesheet" href="<?=tl_h(BASE_URL)?>/assets/css/<?=$sheet?>.css?v=<?=filemtime(BASE_PATH.'/assets/css/'.$sheet.'.css')?>"><?php endforeach;?>
</head><body data-delivery-ui><main class="delivery-login"><div class="eyebrow">HAMBELELA · TEDLASER DELIVERY</div>
<h1><?=$screen==='setup'?'Set up your PIN':'Tedlaser sign-in'?></h1>
<?php if($saved):?><p role="status">Your PIN is ready. Select your profile and sign in.</p><?php endif;?>
<p><?=$screen==='setup'?'Verify your account once and choose a private PIN. It will replace your current password.':($screen==='email'?'Use your existing Tedlaser account.':'Choose your profile and enter your private PIN.')?></p>
<form method="post"><input type="hidden" name="csrf" value="<?=tl_h($_SESSION['csrf'])?>">
<?php if($screen==='email'):?><label>Email<input type="email" name="email" autocomplete="username" required></label>
<?php else:?><label>Profile<select name="profile" autocomplete="username" required><option value="">Choose a profile</option><?php foreach(['toivo'=>'Toivo','admin'=>'Admin'] as $key=>$caption):?><option value="<?=$key?>" <?=$profile===$key?'selected':''?>><?=$caption?></option><?php endforeach;?></select></label><?php endif;?>
<?php if($screen==='pin'):?><label>PIN<input type="password" name="secret" inputmode="numeric" pattern="[0-9]{8,12}" maxlength="12" autocomplete="current-password" required></label>
<?php else:?><label><?=$screen==='setup'?'Current password, temporary password or current PIN':'Password or temporary password'?><input type="password" name="secret" maxlength="128" autocomplete="current-password" required></label><?php endif;?>
<?php if($screen==='setup'):?><label>New PIN (8–12 digits)<input type="password" name="pin" inputmode="numeric" pattern="[0-9]{8,12}" maxlength="12" autocomplete="new-password" required></label><label>Confirm new PIN<input type="password" name="confirmation" inputmode="numeric" pattern="[0-9]{8,12}" maxlength="12" autocomplete="new-password" required></label><?php endif;?>
<?php if($error!==''):?><p class="form-error" role="alert"><?=tl_h($error)?></p><?php endif;?>
<button type="submit" class="primary"><?=$screen==='setup'?'Save PIN':'Sign in'?></button></form>
<p><?php if($screen!=='pin'):?><a href="login.php">Back to PIN sign-in</a><?php else:?><a href="login.php?mode=setup">Set up or change my PIN</a> · <a href="login.php?mode=email">Use email and password</a><?php endif;?></p></main></body></html>
