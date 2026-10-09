<?php
declare(strict_types=1);
require __DIR__.'/../apps/operations/orders-list-query.php';
function ops_normalize_payment_code(string $value): string { return strtolower(str_replace(' ', '_', $value)); }
$db = new PDO(getenv('ORDERS_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=33321;dbname=mysql;charset=utf8mb4', 'root', getenv('ORDERS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
// Temporary tables are connection-local; the test never writes production tables.
$db->exec('CREATE TEMPORARY TABLE ops_orders (id INT PRIMARY KEY,order_number VARCHAR(100),customer_name VARCHAR(190),first_name VARCHAR(100),last_name VARCHAR(100),customer_contact VARCHAR(100),total_amount DECIMAL(12,2),payment_method VARCHAR(100),payment_status VARCHAR(30),status VARCHAR(30),created_at DATETIME,assigned_packer_id INT,fulfilment_mode VARCHAR(30),order_type VARCHAR(30))');
$db->exec('CREATE TEMPORARY TABLE ops_employees (id INT PRIMARY KEY,full_name VARCHAR(190))');
$db->exec('CREATE TEMPORARY TABLE order_payment_allocations (order_id INT,payment_method VARCHAR(30),amount_cents BIGINT,source VARCHAR(30),source_version VARCHAR(80))');
$insert=$db->prepare('INSERT INTO ops_orders VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
for($i=1;$i<=650;$i++) $insert->execute([$i,'WEB-'.(38000+$i),'Victoria Toivo','Victoria','Toivo','081 234-6628','450.00','Cash','paid','new_order','2026-10-01 10:00:00',1,'delivery','delivery']);
$insert->execute([651,'AB-38421','Separate','Victoria','Toivo','081 999 1111','325.00','Cash','partial','in_progress','2026-09-01 10:00:00',2,'collection','collection']);
$insert->execute([652,'AB38421','Literal %_','Other','Person','264 (81) 234 0000','500.00','Cash','unpaid','completed','2026-08-01 10:00:00',null,'courier','courier']);
$db->exec("INSERT INTO ops_employees VALUES(1,'Packer One'),(2,'Packer Two')");
$checks=0;
function check($actual,$expected,string $label):void { global $checks; if($actual!==$expected) throw new RuntimeException($label.': '.json_encode([$actual,$expected])); $checks++; }
function searchRows(string $q, array $filter=[]):array {
    global $db;
    [$where,$params]=ops_list_search($q,"CONCAT_WS(' ',o.customer_name,o.first_name,o.last_name)");
    [$parts,$values]=ops_list_filters($filter,'o.created_at','o.payment_status',1);
    if($where!=='')array_unshift($parts,$where);
    $query=$db->prepare('SELECT o.id FROM ops_orders o LEFT JOIN ops_employees e ON e.id=o.assigned_packer_id'.($parts?' WHERE '.implode(' AND ',$parts):'').' ORDER BY o.id');
    $query->execute(array_merge($params,$values));return array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
}
check(count(searchRows('Vic')),651,'Partial first name across more than 500 orders');
check(count(searchRows('tOi')),651,'Case insensitive partial surname');
check(count(searchRows('  Victoria   To ')),651,'Combined and separately stored names');
check(count(searchRows('081')),651,'Leading zero mobile');
check(count(searchRows('6628')),650,'Partial mobile');
check(count(searchRows('0812346628')),650,'Phone formatting ignored');
check(count(searchRows('+264 81 234 0000')),1,'Formatted international phone');
foreach(['38420','+38420','#38420','Order #38420','INV-38420','WEB-38420'] as $q)check(searchRows($q),[420],$q);
check(searchRows('AB-38421'),[651],'Hyphenated identifiers preserved');
check(searchRows('AB38421'),[652],'Distinct identifiers not collapsed');
check(searchRows('%_'),[652],'SQL wildcard characters treated literally');
check(searchRows("' OR 1=1 --"),[],'Search injection is literal');
check(count(searchRows('Vic',['createdAfter'=>'2026-10-01'])),650,'Date filters retained');
check(searchRows('Vic',['person'=>'Packer Two']),[651],'Person filter');
check(searchRows('Vic',['mode'=>'collection','status'=>'in_progress','minAmount'=>'300','maxAmount'=>'350']),[651],'Combined filters');
check(count(searchRows('Vic',['person'=>'__me__'])),650,'Current employee filter');
check(searchRows('Unknown'),[],'No matches');
$paged=$db->prepare('SELECT id FROM ops_orders ORDER BY id LIMIT 100 OFFSET 600');$paged->execute();
check(count($paged->fetchAll()),52,'Final server page bounded');
$order=['total_amount'=>'450.00','financial_payment_status'=>'paid','status'=>'new_order'];
$payment=['amount_cents'=>45000,'source'=>'pos','source_version'=>'v1'];
check(ops_list_money($order,[$payment]),['total_cents'=>45000,'paid_cents'=>45000,'outstanding_cents'=>0,'payment_verified'=>true],'Includes delivery once');
$split=[$payment,array_replace($payment,['amount_cents'=>12500])];$split[0]['amount_cents']=32500;
check(ops_list_money($order,$split)['paid_cents'],45000,'Split payments counted once');
check(ops_list_money(array_replace($order,['financial_payment_status'=>'partial']),[array_replace($payment,['amount_cents'=>32500])])['outstanding_cents'],12500,'Verified partial outstanding');
check(ops_list_money($order,[])['paid_cents'],null,'Missing records');
check(ops_list_money($order,[array_replace($payment,['source'=>'legacy_paid_order'])])['paid_cents'],null,'Legacy inference is not verified payment evidence');
check(ops_list_money($order,[array_replace($payment,['amount_cents'=>50000])])['paid_cents'],null,'Over-allocation withheld');
check(ops_list_money($order,[array_replace($payment,['amount_cents'=>30000])])['paid_cents'],null,'Settlement mismatch withheld');
check(ops_list_money(array_replace($order,['status'=>'refunded']),[$payment])['paid_cents'],null,'Refund totals withheld');
check(ops_list_money(array_replace($order,['total_amount'=>'450.01']),[])['total_cents'],45001,'Currency cents');
check(ops_list_money(array_replace($order,['total_amount'=>null]),[])['total_cents'],null,'Missing order total not invented');
echo "$checks Orders search, pagination and payment checks passed.\n";
