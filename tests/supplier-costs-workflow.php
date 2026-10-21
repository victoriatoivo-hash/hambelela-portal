<?php
declare(strict_types=1);
define('BASE_PATH',dirname(__DIR__));
function current_user(): array { return ['id'=>91,'name'=>'Costing test owner']; }
require BASE_PATH.'/shared/cost-workbook.php';
function expect($condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$split=cw_shipping_allocation(3000,[10=>100,20=>50]);
expect($split===[10=>2000.0,20=>1000.0],'100kg/50kg freight allocation');
expect(array_sum(cw_shipping_allocation(10,[1=>1,2=>1,3=>1]))===10.0,'Rounding must reconcile exactly');
expect(abs(cw_shipping_allocation(2000,[1=>100])[1]/100-20)<0.000001,'Freight per kg');
foreach ([[1=>100,2=>null],[1=>1,2=>0],[1=>-1],[]] as $bad) {
    try { cw_shipping_allocation(20,$bad); throw new RuntimeException('Missing weight accepted'); } catch(DomainException $expected) {}
}
expect(lpc_source_cost(['currency'=>'NAD','vat_treatment'=>'exclusive','line_subtotal'=>10000])===10000.0,'Ex-VAT source cost');
expect(lpc_source_cost(['currency'=>'USD','vat_treatment'=>'exclusive','line_subtotal'=>100,'exchange_rate'=>18])===null,'Unconfirmed FX must not produce NAD');
expect(lpc_source_cost(['currency'=>'USD','vat_treatment'=>'exclusive','line_subtotal'=>100,'exchange_rate'=>18,'fx_confirmed'=>1])===1800.0,'Confirmed FX');
expect(lpc_source_cost(['currency'=>'NAD','vat_treatment'=>'unconfirmed','line_subtotal'=>100])===null,'Unconfirmed VAT');
if (!getenv('CW_TEST_DSN')) { echo "Supplier cost mathematical/FX safeguards passed; database suite requires CW_TEST_DSN.\n"; exit; }
$pdo=new PDO(getenv('CW_TEST_DSN'),getenv('CW_TEST_USER')?:'root',getenv('CW_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
// CI-only disposable database. Never run this fixture against the portal database.
expect($pdo->query('SELECT DATABASE()')->fetchColumn()==='cw_supplier_test','Refuse non-test database');
cw_install_schema($pdo);
$pdo->exec("INSERT INTO cw_suppliers(name,normalized_name,supplier_type) VALUES('Test Supplier','testsupplier','Raw Materials')");
$supplier=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO cw_supplier_invoices(supplier_id,supplier_name,invoice_number,invoice_date,original_filename,stored_file,file_type,currency,vat_treatment,approval_status) VALUES(?,'Test Supplier','TEST-1','2026-10-06','','','','NAD','exclusive','draft')")->execute([$supplier]);
$invoice=(int)$pdo->lastInsertId();
$line=['product_description'=>'Test Castor Oil','quantity'=>100,'pack_size'=>1,'purchase_unit'=>'kg','line_subtotal'=>10000,'vat_amount'=>0,'shipping_weight_kg'=>100,'confirm_new_product'=>true];
$pdo->beginTransaction();cw_supplier_save_lines($pdo,$invoice,[$line]);$pdo->commit();
$id=(int)$pdo->query('SELECT id FROM cw_supplier_invoice_lines')->fetchColumn();
$line['id']=$id;unset($line['confirm_new_product']);
$pdo->beginTransaction();cw_supplier_save_lines($pdo,$invoice,[$line]);$pdo->commit();
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_supplier_invoice_lines')->fetchColumn()===1,'Save must preserve stable line');
expect((int)$pdo->query('SELECT id FROM cw_supplier_invoice_lines')->fetchColumn()===$id,'Line ID changed');
try {$pdo->beginTransaction();cw_supplier_save_lines($pdo,$invoice,[]);throw new RuntimeException('Omission accepted');}catch(DomainException $expected){$pdo->rollBack();}
$pdo->prepare("UPDATE cw_supplier_invoices SET approval_status='approved' WHERE id=?")->execute([$invoice]);
lpc_refresh($pdo);
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_cost_products')->fetchColumn()===1,'Canonical product created once');
$transport=['courier_name'=>'Test Courier','invoice_number'=>'FREIGHT-1','transport_date'=>'2026-10-06','currency'=>'NAD','transport_amount'=>2000,'vat_amount'=>0,'vat_treatment'=>'exempt','allocation_method'=>'by_weight','allocations'=>[['supplier_invoice_id'=>$invoice]]];
$saved=cw_transport_save($pdo,$transport);$transport['id']=$saved['id'];
$cost=$pdo->query('SELECT * FROM cw_landed_product_costs ORDER BY id DESC LIMIT 1')->fetch();
expect((float)$cost['supplier_cost_per_base']===100.0 && (float)$cost['transport_cost_per_base']===20.0 && (float)$cost['landed_cost_per_base']===120.0,'100/20/120 example');
$allocationId=(int)$pdo->query('SELECT id FROM cw_transport_product_allocations')->fetchColumn();
$count=(int)$pdo->query('SELECT COUNT(*) FROM cw_landed_product_costs')->fetchColumn();
cw_transport_save($pdo,$transport);lpc_refresh($pdo);
expect((int)$pdo->query('SELECT id FROM cw_transport_product_allocations')->fetchColumn()===$allocationId,'Transport reference must remain stable');
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_landed_product_costs')->fetchColumn()===$count,'Repeated refresh must not duplicate snapshots');
$pdo->prepare('UPDATE cw_supplier_invoice_lines SET line_subtotal=11000,line_total=11000 WHERE id=?')->execute([$id]);lpc_refresh($pdo,[$invoice]);
$pdo->prepare('UPDATE cw_supplier_invoice_lines SET line_subtotal=10000,line_total=10000 WHERE id=?')->execute([$id]);lpc_refresh($pdo,[$invoice]);
expect((float)$pdo->query('SELECT supplier_cost_per_base FROM cw_landed_product_costs ORDER BY id DESC LIMIT 1')->fetchColumn()===100.0,'Reverting a source cost must create a new current revision, not leave an old latest value');
$pdo->prepare('UPDATE cw_supplier_invoice_lines SET shipping_weight_kg=NULL WHERE id=?')->execute([$id]);
try {cw_transport_save($pdo,$transport);throw new RuntimeException('Missing weight silently allocated');}catch(DomainException $expected){}
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_transport_product_allocations')->fetchColumn()===1,'Rejected save must retain freight');
// Exercise the actual list handler SQL, not a separately reimplemented query.
class SupplierTestReply extends RuntimeException { public $payload; public function __construct(array $payload){$this->payload=$payload;} }
function si_reply(array $payload,int $status=200): void { throw new SupplierTestReply($payload); }
function si_date($value): ?string { $d=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$value);return $d&&$d->format('Y-m-d')===$value?$value:null; }
$api=file_get_contents(BASE_PATH.'/apps/cost-manager/supplier-invoices-api.php');
$begin=strpos($api," if(in_array(\$action,['list','selection'],true)){");
$end=strpos($api," if(\$action==='products'){",$begin);
expect($begin!==false&&$end!==false,'List handler test boundaries');
eval('function supplier_test_list(PDO $pdo,string $action){'.substr($api,$begin,$end-$begin).'}');
$_GET=[];
try {supplier_test_list($pdo,'list');throw new RuntimeException('No list response');}catch(SupplierTestReply $response){
 expect($response->payload['total']===1,'One current row per canonical product');
 expect((float)$response->payload['rows'][0]['landed_cost_per_base']===120.0,'Current sheet shows calculated cost');
}
$_GET=['from'=>'2026-10-07'];
try {supplier_test_list($pdo,'list');throw new RuntimeException('No filtered list response');}catch(SupplierTestReply $response){expect($response->payload['total']===0,'Effective date filter applies to selected current source');}
echo "Supplier costs integration: stable IDs, canonical products, audit, freight, FX, rollback and idempotency passed.\n";
