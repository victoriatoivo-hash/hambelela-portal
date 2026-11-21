<?php
if(PHP_SAPI!=='cli')exit(1);
define('BASE_PATH',dirname(__DIR__,2));define('BASE_URL','');date_default_timezone_set('Africa/Windhoek');
require BASE_PATH.'/shared/delivery/OwnerDriverView.php';
use Hambelela\Delivery\OwnerDriverView;
function current_role_key(){return 'owner_admin';}
$_SESSION=['user'=>['id'=>1,'role_key'=>'owner_admin'],'csrf'=>'OWNER_SECRET','driver_profile_csrf'=>'OWNER_SECRET','driver_history_csrf'=>'OWNER_SECRET','cash_handover_csrf'=>'OWNER_SECRET','dispatch_chat_csrf'=>'OWNER_SECRET'];
$session=$_SESSION;$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
require __DIR__.'/fixture.php';owner_view_fixture($db);
$n=0;function ok($v,$name){global $n;if(!$v)throw new RuntimeException('FAIL '.$name);$n++;echo 'PASS '.$name,"\n";}
function denied($fn,$name){try{$fn();throw new LogicException('Unexpected allowed request: '.$name);}catch(RuntimeException $e){ok(in_array($e->getCode(),[403,404,405,422],true),$name);}}
$tables=['delivery_jobs','delivery_events','delivery_receipts','delivery_outbox','notifications','notification_recipients','delivery_chat_threads','delivery_chat_messages','delivery_chat_receipts','delivery_chat_audit','delivery_driver_handovers','ops_orders','order_payment_allocations','delivery_errands','delivery_errand_events','notification_preferences'];$before=[];foreach($tables as $t)$before[$t]=$db->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll();
foreach([2,3,4,5,999] as $id)denied(fn()=>new OwnerDriverView($db,$id),'non-owner '.$id);
$v=new OwnerDriverView($db,1);$drivers=$v->drivers();ok(count($drivers)>0,'active drivers');foreach([0,1,2,5,999] as $id)denied(fn()=>$v->authorize($id),'reject invalid/inactive/non-driver '.$id);
$d=$v->summary(3);ok($d['driver']['id']===3&&$d['driver']['duty']===null,'selected driver and no invented duty');
foreach(['home','deliveries','profile','cash','map','messages','notifications','history','errands'] as $op){$r=$v->read(3,$op);ok(is_array($r),'actual '.$op.' read');}
foreach(['complete','send','markAll','receipt','driver_confirm','sound','app_open','reassign','logout'] as $op)denied(fn()=>$v->read(3,$op),'reject command '.$op);
denied(fn()=>$v->read(3,'profile',['driver'=>4]),'reject parameter impersonation');
$jobs=$db->query('SELECT id,driver_employee_id FROM delivery_jobs ORDER BY id')->fetchAll();$job=null;foreach($jobs as $r){if((int)$r['driver_employee_id']===3&&!$job)$job=(int)$r['id'];if((int)$r['driver_employee_id']!==3)denied(fn()=>$v->job(3,(int)$r['id']),'cross-driver job '.$r['id']);}
$job=$v->read(3,'deliveries')['deliveries'][0]['assignmentId'];
foreach(OwnerDriverView::SCREENS as $screen){$html=$v->render(3,$screen,in_array($screen,['details','proof','issues'],true)?$job:null);ok(strpos($html,'ho-driver-dashboard')!==false,'shared screen '.$screen);ok(strpos($html,'OWNER_SECRET')===false,'no owner CSRF in '.$screen);ok($_SESSION===$session,'owner session unchanged '.$screen);}
foreach($tables as $t)ok($db->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll()===$before[$t],'no business writes '.$t);
$notes=$v->read(3,'notifications')['items'];if($notes){$target=$v->read(3,'notification_target',['id'=>$notes[0]['id']]);ok(in_array($target['screen'],OwnerDriverView::SCREENS,true),'notification read-only destination');denied(fn()=>$v->read(4,'notification_target',['id'=>$notes[0]['id']]),'cross-driver notification denied');}
denied(fn()=>$v->job(4,$job),'cross-driver delivery denied');
denied(fn()=>$v->read(4,'details',['id'=>$job]),'cross-driver details denied');
if($d['proof']){
 $p=$d['proof'];$file=$v->evidence(3,$p['id'],$p['key']);ok(is_file($file['path']),'authorized private evidence');
 denied(fn()=>$v->evidence(4,$p['id'],$p['key']),'cross-driver evidence denied');
 denied(fn()=>$v->evidence(3,$p['id'],str_repeat('0',48)),'forged evidence denied');
}
// Test revocation in connection-local table shadows, never changing a persistent employee.
$employees=$db->query('SELECT * FROM ops_employees')->fetchAll();$db->exec('CREATE TEMPORARY TABLE viewer_employees LIKE ops_employees');$db->exec('ALTER TABLE viewer_employees RENAME TO ops_employees');
$keys=array_keys($employees[0]);$insert=$db->prepare('INSERT INTO ops_employees (`'.implode('`,`',$keys).'`) VALUES('.implode(',',array_fill(0,count($keys),'?')).')');foreach($employees as $employee)$insert->execute(array_values($employee));
$db->exec("UPDATE ops_employees SET status='inactive' WHERE id=3");denied(fn()=>$v->read(3,'home'),'driver revocation takes effect');
$db->exec("UPDATE ops_employees SET status='active' WHERE id=3");$db->exec("UPDATE ops_employees SET status='inactive' WHERE id=1");denied(fn()=>$v->read(3,'home'),'owner revocation takes effect');
$db->exec("UPDATE ops_employees SET status='active' WHERE id=1");
foreach($tables as $t)ok($db->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll()===$before[$t],'extended reads unchanged '.$t);
$audit=$db->query("SELECT response_json FROM delivery_requests WHERE actor_key='owner-view:1' AND action='owner_view_access' ORDER BY created_at DESC LIMIT 1")->fetchColumn();ok(json_decode($audit,true)['actor']===1,'owner access audited independently');
echo 'TOTAL '.$n." passed\n";
