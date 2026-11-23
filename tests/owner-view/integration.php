<?php
// Focused final cross-page checks. Local synthetic database and temporary writes only.
if(PHP_SAPI!=='cli')exit(1);
define('BASE_PATH',dirname(__DIR__,2));define('BASE_URL','');
require BASE_PATH.'/shared/delivery/OwnerDriverView.php';
require BASE_PATH.'/shared/delivery/OwnerOperations.php';
use Hambelela\Delivery\{OwnerProofViewer,OwnerDriverView,OwnerOperations,DriverRecordedHandover};
$connect=static fn()=>new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db=$connect();$n=0;function verify($v,$label){global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo 'PASS '.$label,"\n";}
$_SESSION=['user'=>['id'=>1,'role_key'=>'owner_admin'],'delivery_front_csrf'=>'local-test-only'];
$session=$_SESSION;$tables=['delivery_jobs','delivery_events','delivery_receipts','ops_orders','order_payment_allocations','delivery_driver_handovers','delivery_owner_proof_actions','delivery_outbox','notification_recipients'];$before=[];
foreach($tables as $t)$before[$t]=$db->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll();
foreach(['delivery_owner_proof_actions','delivery_outbox'] as $t){$ddl=$db->query('SHOW CREATE TABLE '.$t)->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*FOREIGN KEY[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\s*\) ENGINE/',"\n) ENGINE",$ddl);$db->exec($ddl);}
$viewer=new OwnerProofViewer($db,1);$ops=new OwnerOperations($db,1);$data=$viewer->read(16);$id=16;$base='';$endpoint='/apps/delivery/delivery-details.php?delivery_id=16';
ob_start();require BASE_PATH.'/shared/delivery/OwnerProofContent.php';$html=ob_get_clean();
verify(strpos($html,'/apps/delivery/driver-operations.php')!==false,'proof breadcrumb returns to new dashboard');
verify(strpos($html,'view-as-driver.php?driver=3&amp;screen=details&amp;id=16')!==false,'proof opens selected driver and canonical delivery');
verify(strpos($html,'/apps/delivery/driver-view.php')===false,'old viewer link retired in proof page');
$row=static function()use($ops){return $ops->read(['ids'=>'16'])['deliveries']['rows'][0];};
verify($row()['proof_url']===$endpoint,'dashboard opens same canonical proof record');
$uuid=static fn()=>bin2hex(random_bytes(4)).'-aaaa-4aaa-8aaa-'.bin2hex(random_bytes(6));
$viewer->action(16,['action'=>'review','body'=>'Local synthetic review exception for fixture evidence.','request_id'=>$uuid(),'version'=>$data['version']]);
verify($row()['proof']==='Reviewed','saved review reflected in dashboard');
$a=$viewer->action(16,['action'=>'discrepancy','reason'=>'payment','body'=>'Synthetic integration discrepancy only.','request_id'=>$uuid(),'version'=>$data['version']]);
verify($row()['proof']==='Discrepancy','saved discrepancy reflected in dashboard');
verify((int)$db->query('SELECT COUNT(*) FROM delivery_outbox')->fetchColumn()>0,'discrepancy enters existing alert outbox');
$viewer->action(16,['action'=>'resolve','related_id'=>$a['id'],'body'=>'Synthetic fixture discrepancy resolved.','request_id'=>$uuid(),'version'=>$data['version']]);
verify($row()['proof']==='Reviewed','resolution restores current evidence review status');
$phone=new OwnerDriverView($db,1);$cash=$phone->read(3,'cash');$real=(new DriverRecordedHandover($db))->summary(3);
verify($cash['handover_due_minor']===$real['handover_due_minor'],'owner phone uses existing driver cash projection');
$book=(new DriverRecordedHandover($db))->bookkeeping();
foreach($cash['handovers'] as $h){if($h['state']!=='driver_recorded')continue;$match=array_values(array_filter($book,static fn($b)=>$b['id']===$h['id']));verify(count($match)===1&&$match[0]['declared_minor']===$h['declared_minor'],'driver handover and bookkeeping share recorded amount');}
$content=file_get_contents(BASE_PATH.'/shared/delivery/OwnerOperationsContent.php');
verify(strpos($content,'/apps/operations/bookkeeping.php\"><?=$icon(\'cash\')?>Cash Handover Records')!==false||preg_match('~/apps/operations/bookkeeping\.php[^\n]*Cash Handover Records~',$content),'cash shortcut opens existing bookkeeping list');
$phone->read(3,'notifications');verify($_SESSION===$session,'owner identity unchanged by linked reads');
$fresh=$connect();foreach($before as $t=>$rows)verify($rows===$fresh->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll(),'permanent records unchanged: '.$t);
echo "TOTAL $n integration checks passed\n";
