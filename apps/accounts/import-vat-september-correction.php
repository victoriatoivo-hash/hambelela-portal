<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/accounts-import-vat.php';
require_once BASE_PATH.'/shared/accounts-import-vat-september-correction.php';
require_login();
if (!accounts_is_owner()) { http_response_code(403); exit('Owner access required.'); }
header('Cache-Control: no-store');
$result=null;$error='';
try {
    $statement=iv_sep_rows(db(),'SELECT stored_filename,sha256 FROM accounts_import_vat_statements WHERE id=8')[0]??[];
    $file=BASE_PATH.'/uploads/import-vat-statements/'.basename((string)($statement['stored_filename']??''));
    iv_sep_assert(is_file($file) && hash_file('sha256',$file)===IV_SEP_SHA,'Original statement file does not match the reviewed PDF');
    $mode='preview';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        import_vat_verify((string)($_POST['csrf']??''));
        $mode=(string)($_POST['mode']??'');
    }
    $result=iv_sep_change(db(),$mode,current_user());
}catch(Throwable $e){$error=$e->getMessage();}
$pageTitle='September Import VAT correction';$activeApp='accounts';
$extraStylesheets=[['path'=>'assets/css/accounts.css'],['path'=>'assets/css/accounts-hierarchy.css']];
include BASE_PATH.'/shared/header.php';include BASE_PATH.'/shared/sidebar.php';
function iv_sep_escape($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
?>
<main class="workspace module accounts-page">
<a href="import-vat.php">← Import VAT</a>
<header class="accounts-hero"><div><p class="eyebrow">Owner · audited correction</p><h1>September 2026 Import VAT</h1><p>Statement 8, page 8 · Tax year 2027 / period 7</p></div></header>
<section class="accounts-table-card">
<p>Correct assessed Import VAT and its active payment allocation to <strong>N$11,190.47</strong>. Reverse only the N$3,577.45 PAYE allocation (payment 45) and exclude Employee Tax source rows 1018 and 1021 from Import VAT. Payment 44, the P6 payment, PAYE records and historical returns remain unchanged.</p>
<p>No statement reprocessing, deletion or tax payment is performed. The before/after ledger is retained in the existing audit history. Rollback is blocked if the repaired records subsequently change or a related return is frozen/filed.</p>
<?php if($error): ?><p role="alert"><?=iv_sep_escape($error)?></p><?php endif ?>
<?php if($result): ?>
<h2><?=iv_sep_escape(ucfirst($result['state']))?></h2>
<table><thead><tr><th>Assessed</th><th>Actual active payments</th><th>Outstanding</th></tr></thead><tbody><tr><td>N$<?=iv_sep_escape($result['assessed'])?></td><td>N$<?=iv_sep_escape($result['actual_active_payments'])?></td><td>N$<?=iv_sep_escape($result['outstanding'])?></td></tr></tbody></table>
<?php if(!empty($result['protected'])): ?><details><summary>Protected record verification</summary><table><thead><tr><th>Records</th><th>Count</th><th>Fingerprint</th></tr></thead><tbody><?php foreach($result['protected'] as $name=>$check): ?><tr><td><?=iv_sep_escape($name)?></td><td><?=iv_sep_escape($check['count'])?></td><td><?=iv_sep_escape($check['sha256'])?></td></tr><?php endforeach ?></tbody></table></details><?php endif ?>
<?php if(!empty($result['protected_unchanged'])): ?><p>Verified: PAYE, P6, other periods, credit decisions, snapshots and filing records unchanged.</p><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?=iv_sep_escape(import_vat_csrf())?>"><input type="hidden" name="mode" value="<?=$result['state']==='corrected'?'rollback':'apply'?>"><button class="portal-button portal-button--primary" type="submit"><?=$result['state']==='corrected'?'Roll back this correction':'Apply audited correction'?></button></form>
<?php endif ?>
</section></main>
<?php include BASE_PATH.'/shared/footer.php'; ?>
