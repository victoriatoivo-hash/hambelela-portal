<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/shared/delivery/DriverOrderDetailsData.php';
use Hambelela\Delivery\DriverOrderDetailsData as D;
$checks=0;
function check($ok,string $why):void {global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
$cash=D::recordedPayment(['declared_cents'=>17000,'expected_cents'=>15900,'declared_method'=>'Cash','declaration_review'=>true,'note'=>'Keep 11 for next order']);
check($cash['method']==='Cash'&&$cash['amount']==='N$170.00'&&$cash['difference']==='N$11.00'&&$cash['review'],'Full cash amount with variance is retained');
check($cash['note']==='Keep 11 for next order','Saved driver note retained');
foreach(['eft'=>'Bank transfer (EFT)','mobile'=>'Mobile payment','other'=>'Other','already_paid'=>'Already paid — no collection','no_payment'=>'No payment received'] as $key=>$label){$r=D::recordedPayment(['declared_cents'=>0,'declared_method'=>$key]);check($r['method']===$label&&$r['amount']==='N$0.00','Method '.$key);}
check(D::recordedPayment(['declared_cents'=>4000,'expected_cents'=>4000,'declared_method'=>'cash'])['difference']===null,'Exact payment has no variance');
check(D::recordedPayment(['declared_cents'=>2000,'expected_cents'=>4000,'declared_method'=>'cash'])['difference']==='-N$20.00','Short payment preserved');
foreach([[],['declared_cents'=>null,'declared_method'=>'cash'],['declared_cents'=>'17000','declared_method'=>'cash'],['declared_cents'=>-1,'declared_method'=>'cash'],['declared_cents'=>17000]] as $invalid)check(D::recordedPayment($invalid)===null,'Missing/invalid historical data is not invented');
$timeline=new ReflectionMethod(D::class,'timeline');$timeline->setAccessible(true);
$events=[];foreach([['arranged',[]],['delivered',['delivery_payment_confirmed'=>true,'declared_cents'=>17000,'expected_cents'=>15900,'declared_method'=>'cash','declaration_review'=>true,'note'=>'<script>unsafe</script>']],['reconciled',[]]] as $i=>$pair)$events[]=['id'=>$i+1,'event_type'=>$pair[0],'employee_id'=>3,'metadata_json'=>json_encode($pair[1]),'created_at'=>'2026-11-21 12:00:00'];
$rows=$timeline->invoke(null,$events,['stage'=>'done','displayStatus'=>'Completed'],3,1,'2026-11-21');
check($rows[1]['recordedPayment']['amount']==='N$170.00','Completion event supplies payment');
check($rows[0]['recordedPayment']===null&&$rows[2]['recordedPayment']===null,'No payment attached to other events');
check(count($timeline->invoke(null,$events,['stage'=>'done','displayStatus'=>'Completed'],3,3,'2026-11-21'))===1,'Earlier assignment cutoff respected');
define('BASE_PATH',dirname(__DIR__));$h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');$icon=static fn($s)=>'';$base='';$local=true;$assignmentId=1;$driverId=3;
$data=['orderNumber'=>'Order TEST','delivery'=>['stage'=>'done','label'=>'Completed','lastStageChangedAt'=>null,'reviewReason'=>null,'address'=>['area'=>'Test','street'=>'Practice address'],'navigationAvailable'=>false], 'customer'=>['initials'=>'T','displayName'=>'Practice','phone'=>null], 'payment'=>['verified'=>false,'label'=>'Review'], 'unreadNotifications'=>0,'itemsNotice'=>'Practice','items'=>[],'timeline'=>$rows];
ob_start();require BASE_PATH.'/shared/delivery/order-details/Content.php';$html=ob_get_clean();
check(strpos($html,'Payment method recorded: Cash')!==false&&strpos($html,'Amount received by driver: N$170.00')!==false,'Template shows method and amount');
check(strpos($html,'&lt;script&gt;unsafe&lt;/script&gt;')!==false&&strpos($html,'<script>unsafe')===false,'Driver note escaped');
check(strpos($html,'payment needs reconciliation')!==false,'Declaration never displayed as verified settlement');
echo "PASS $checks handover payment details checks\n";
