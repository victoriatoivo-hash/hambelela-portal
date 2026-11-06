<?php
require __DIR__.'/../apps/operations/orders-gateway-sync.php';
$source=file_get_contents(__DIR__.'/../apps/operations/operations.php');
// Exercise the isolated public patch without publishing complete live source files.
foreach (json_decode(file_get_contents(__DIR__.'/../scripts/payment-sync-patches.json'), true)['apps/operations/operations.php'] as $edit) {
 if (strpos($source, $edit['old']) !== false) $source=str_replace($edit['old'], $edit['new'], $source);
 elseif (strpos($source, $edit['new']) === false) throw new Exception('Payment test baseline mismatch');
}
foreach (['ops_website_payment_methods','ops_payment_method_map','ops_normalize_payment_code','ops_payment_label','ops_normalize_website_payment_method','ops_collect_order_payment_text','ops_wc_payment_method','ops_wc_payment_allocations'] as $fn) {
 preg_match('/^function '.$fn.'\(.*?^}/ms', $source, $m); eval($m[0]);
}
$n=0;function check($v,$msg){global $n;$n++;if(!$v)throw new Exception($msg);}
$base=['payment_method'=>'woocommerce_dpo','payment_method_title'=>'Pay by card','status'=>'pending','total'=>'180.00','date_modified_gmt'=>'2026-11-06T10:00:00','meta_data'=>[]];
foreach (['pending','failed','on-hold','cancelled','refunded'] as $status) {
 $o=array_merge($base,['status'=>$status,'date_paid'=>'2026-11-05T10:00:00']);
 check(!ops_wc_dpo_state($o)['paid'],'Unpaid status defeats stale paid date: '.$status);
 check(ops_wc_payment_method($o)==='DPO payment','DPO stays separate from swipe');
}
foreach (['processing','completed'] as $status) {
 $o=array_merge($base,['status'=>$status]);check(!ops_wc_dpo_state($o)['paid'],'Status alone is not payment evidence');
 $o['date_paid_gmt']='2026-11-06T10:00:00';check(ops_wc_dpo_state($o)['paid'],'Dated paid DPO is confirmed');
}
$o=array_merge($base,['payment_method'=>'bacs','payment_method_title'=>'Pay offline']);check(ops_wc_payment_method($o)==='EFT','EFT uses gateway ID');check(ops_wc_dpo_state($o)===null,'EFT is not auto-paid by DPO');
check(ops_normalize_payment_code('DPO payment')==='dpo','Canonical DPO label round trips');
check(ops_normalize_website_payment_method('DPO card payment')==='DPO payment','Specific gateway beats card wording');
$split=array_merge($base,['payment_method'=>'eft','meta_data'=>[['key'=>'_hpos_payment_allocations','value'=>[['method'=>'eft','amount_cents'=>10000],['method'=>'cash','amount_cents'=>8000]]]]]);
check(ops_wc_payment_method($split)==='EFT / Cash','Preserve genuine split tenders');
$split['payment_method']='dpo';check(ops_wc_dpo_state($split)===null,'Mixed DPO not rewritten as single tender');
$notes="Customer note: please call\n[DPO payment] Pending payment.\nShipping address: test";
$notes=ops_dpo_sync_note($notes,'[DPO payment] Failed payment.');check(substr_count($notes,'[DPO payment]')===1 && strpos($notes,'please call')!==false,'Replace status note without duplication or lost customer note');

class FakePaymentDb {function inTransaction(){return true;} function prepare($sql){return new FakeStatement($sql);}}
class FakeStatement {
 private $sql;function __construct($s){$this->sql=$s;}
 function execute($p){global $row,$alloc,$audit;
  if(strpos($this->sql,'DELETE FROM order_payment_allocations')===0)$alloc=[];
  elseif(strpos($this->sql,'INSERT INTO order_payment_allocations ')===0)$alloc=[['payment_method'=>$p[1],'amount_cents'=>$p[2],'transaction_reference'=>$p[3],'source'=>$p[4],'source_version'=>$p[5]]];
  elseif(strpos($this->sql,'INSERT INTO order_payment_allocation_audit')===0)$audit[]=$p;
  elseif(strpos($this->sql,'UPDATE ops_orders SET payment_method = ?, payment_status')===0){
   foreach(['payment_method','payment_status','payment_source','payment_version','portal_paid_confirmed','portal_paid_source','notes'] as $i=>$key)$row[$key]=$p[$i];
  } elseif(strpos($this->sql,'UPDATE ops_orders SET payment_method = ?, notes')===0){$row['payment_method']=$p[0];$row['notes']=$p[1];$row['portal_paid_confirmed']=$p[2];}
  else throw new Exception('Unexpected SQL');
 }
}
function db(){static $db;return $db ?: ($db=new FakePaymentDb);}
function ops_row($sql,$p){return $GLOBALS['row'];}
function ops_rows($sql,$p){return $GLOBALS['alloc'];}
function ops_activity_log(...$args){}
$row=['payment_source'=>'woocommerce','payment_version'=>'','portal_paid_confirmed'=>0,'notes'=>'Customer note: call first'];$alloc=[];$audit=[];
ops_sync_dpo_payment(1,$base);check($row['portal_paid_confirmed']===0 && !$alloc && strpos($row['notes'],'Pending payment')!==false,'Pending first import');
$o=array_merge($base,['status'=>'completed','date_paid_gmt'=>'2026-11-06T10:01:00','date_modified_gmt'=>'2026-11-06T10:01:00','transaction_id'=>'fixture-only']);
ops_sync_dpo_payment(1,$o);check($row['portal_paid_confirmed']===1 && $alloc[0]['payment_method']==='dpo' && $alloc[0]['amount_cents']===18000,'Pending to paid creates only DPO allocation');
$count=count($audit);ops_sync_dpo_payment(1,$o);check(count($audit)===$count && count($alloc)===1,'Repeated sync is idempotent');
ops_sync_dpo_payment(1,$base);check($row['portal_paid_confirmed']===1,'Older pending snapshot does not undo success');
$o['status']='failed';$o['date_modified_gmt']='2026-11-06T10:02:00';ops_sync_dpo_payment(1,$o);
check($row['portal_paid_confirmed']===0 && !$alloc && $row['payment_status']==='unpaid' && strpos($row['notes'],'Failed payment')!==false,'Failed removes stale payment and updates note');
$row['payment_source']='delivery';$saved=$row;$o['status']='completed';ops_sync_dpo_payment(1,$o);check($saved===$row,'Preserve reconciled driver receipts');
$row['payment_source']='order_list';$saved=$row;ops_sync_dpo_payment(1,['payment_method'=>'bacs']);check($saved===$row,'EFT confirmation remains manual');
echo "$n DPO/EFT behavior checks passed.\n";
