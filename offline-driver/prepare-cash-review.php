<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);define('BASE_PATH',dirname(__DIR__));
require BASE_PATH.'/shared/delivery/CashHandover.php';
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='driver_review')throw new RuntimeException('Local review database only.');
if(!\Hambelela\Delivery\CashHandover::ready($db))$db->exec(file_get_contents(BASE_PATH.'/database/delivery/011-cash-handover-proposed.sql'));
echo "Local Phase11 tables ready. No existing receipts or payments modified.\n";
// Optional explicitly synthetic UI fixtures, through existing quote/collect services.
if(($argv[1]??'')==='fixtures'){
 define('BASE_URL','');require BASE_PATH.'/offline-driver/local.php';date_default_timezone_set('Africa/Windhoek');
 foreach(['A','B'] as $suffix){$ref='LAB-CASH11-'.$suffix;$s=$db->prepare('SELECT id FROM ops_orders WHERE order_number=?');$s->execute([$ref]);if($s->fetchColumn())continue;
  $id=(int)$db->query('SELECT MAX(id)+1 FROM ops_orders')->fetchColumn();$db->prepare("INSERT INTO ops_orders(id,order_number,woo_order_id,total_amount,payment_status,payment_method,customer_name,customer_contact,notes,fulfilment_mode,status) VALUES(?,?,?,120,'unpaid','Cash',?,'','Synthetic cash handover review only','delivery','in_progress')")->execute([$id,$ref,$id,'Practice customer '.$suffix]);$db->prepare("INSERT INTO ops_order_items(order_id,product_name,quantity) VALUES(?,'Synthetic practice parcel',1)")->execute([$id]);arrange_test($db,$id);
  $q=$db->prepare('SELECT id FROM delivery_jobs WHERE order_id=? ORDER BY id DESC LIMIT 1');$q->execute([$id]);$job=(int)$q->fetchColumn();$core=core_test($db);$driver=\Hambelela\Delivery\EmployeeDriverSession::actor($db,3);$core->command($driver,['action'=>'start','id'=>$job,'uuid'=>uuid_test()]);
  $svc=new \Hambelela\Delivery\DeliveryService($db,['cash','eft'],fn($db,$j)=>(new \Hambelela\Delivery\OrderBalanceAdapter)->read($db,$j));$svc->collect($driver,$job,uuid_test(),['order_goods'=>$suffix==='A'?8000:2001,'delivery_fee'=>$suffix==='A'?4000:0,'partner_cod'=>0],'cash');
 }echo "Two isolated synthetic cash orders prepared through the existing collection service.\n";
}
