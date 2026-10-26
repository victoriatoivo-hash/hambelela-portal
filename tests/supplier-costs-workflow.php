<?php
declare(strict_types=1);
define('BASE_PATH',dirname(__DIR__));
function current_user(): array { return ['id'=>91,'name'=>'Costing test owner']; }
require BASE_PATH.'/shared/cost-workbook.php';
require BASE_PATH.'/shared/cost-workbook-invoice-recovery.php';
require BASE_PATH.'/shared/cost-workbook-categories.php';
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
$pdo->beginTransaction();
$pdo->exec("INSERT INTO cw_sync_batches(sync_uuid,status,is_successful_snapshot,completed_at) VALUES(UUID(),'completed',1,NOW())");
$categoryBatch=(int)$pdo->lastInsertId();
$categoryInsert=$pdo->prepare("INSERT INTO cw_product_snapshots(product_id,variation_id,product_name,product_type,category,sync_batch_id) VALUES(?,0,?,'simple',?,?)");
$categoryInsert->execute([99001,'Category fixture','Natural Clays, Essential Oils',$categoryBatch]);
$categoryInsert->execute([99002,'Category fixture 2','Natural Clays, Botanical Extracts, Clays &amp; Charcoals',$categoryBatch]);
$pdo->exec("INSERT INTO cw_sync_batches(sync_uuid,status,is_successful_snapshot,completed_at) VALUES(UUID(),'failed',0,NOW())");
$categoryInsert->execute([99003,'Incomplete catalogue','Failed-only category',(int)$pdo->lastInsertId()]);
$categories=cw_supplier_category_choices($pdo);
expect(in_array('Natural Clays',$categories,true)&&in_array('Botanical Extracts',$categories,true),'Website category names merged');
expect(in_array('Clays',$categories,true)&&in_array('Plant Butters',$categories,true),'Workbook categories retained');
expect(count(array_keys($categories,'Natural Clays',true))===1,'Repeated website category deduplicated');
expect(in_array('Clays & Charcoals',$categories,true)&&!in_array('Clays &amp; Charcoals',$categories,true),'Website HTML entities display as readable category names');
expect(!in_array('Failed-only category',$categories,true),'Failed catalogue ignored');
$pdo->rollBack();
$pdo->exec("INSERT INTO cw_suppliers(name,normalized_name,supplier_type) VALUES('Test Supplier','testsupplier','Raw Materials')");
$supplier=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO cw_supplier_invoices(supplier_id,supplier_name,invoice_number,invoice_date,original_filename,stored_file,file_type,currency,vat_treatment,approval_status) VALUES(?,'Test Supplier','TEST-1','2026-10-06','','','','NAD','exclusive','draft')")->execute([$supplier]);
$invoice=(int)$pdo->lastInsertId();
$line=['product_description'=>'Test Castor Oil','quantity'=>100,'pack_size'=>1,'purchase_unit'=>'kg','line_subtotal'=>10000,'vat_amount'=>0,'shipping_weight_kg'=>100,'confirm_new_product'=>true];
$pdo->beginTransaction();cw_supplier_save_lines($pdo,$invoice,[$line]);$pdo->commit();
$id=(int)$pdo->query('SELECT id FROM cw_supplier_invoice_lines')->fetchColumn();
$line['id']=$id;unset($line['confirm_new_product']);
// Fractional purchased amounts persist independently of shipment weight.
foreach ([[1,0.450,'L',0.450,'L'],[1,450,'ml',0.450,'L'],[1,450,'g',0.450,'kg'],[5,0.450,'L',2.250,'L']] as $case) {
    $pdo->beginTransaction();
    $fraction=array_merge($line,['quantity'=>$case[0],'pack_size'=>$case[1],'purchase_unit'=>$case[2],'line_subtotal'=>641,'shipping_weight_kg'=>2]);
    cw_supplier_save_lines($pdo,$invoice,[$fraction]);
    $stored=$pdo->query('SELECT * FROM cw_supplier_invoice_lines WHERE id='.$id)->fetch();
    expect(abs((float)$stored['base_quantity']-$case[3])<0.000001,'Fractional amount retained on reload');
    expect($stored['base_unit']===$case[4],'Correct normalized unit');
    expect(abs((float)$stored['base_unit_cost']-641/$case[3])<0.00001,'Source rate uses purchased amount, not freight weight');
    expect((float)$stored['shipping_weight_kg']===2.0,'Shipping weight stays separate');
    $pdo->rollBack();
}
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
$productId=(int)$pdo->query('SELECT id FROM cw_cost_products LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO cw_supplier_invoices(supplier_id,supplier_name,invoice_number,invoice_date,original_filename,stored_file,file_type,currency,vat_treatment,approval_status) VALUES(?,'Test Supplier','OLDER-LATE-UPLOAD','2026-10-01','','','','NAD','exclusive','draft')")->execute([$supplier]);
$older=(int)$pdo->lastInsertId();
$olderLine=$line;unset($olderLine['id']);$olderLine['cost_product_id']=$productId;$olderLine['line_subtotal']=20000;
$pdo->beginTransaction();cw_supplier_save_lines($pdo,$older,[$olderLine]);$pdo->prepare("UPDATE cw_supplier_invoices SET approval_status='approved' WHERE id=?")->execute([$older]);lpc_refresh($pdo,[$older]);$pdo->commit();
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_cost_products')->fetchColumn()===1,'New purchase reuses canonical product');
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_cost_product_sources')->fetchColumn()===2,'Every purchase retained in source history');
$pdo->exec("INSERT INTO cw_suppliers(name,normalized_name,supplier_type) VALUES('Corrected Supplier','correctedsupplier','Raw Materials')");$correctedSupplier=(int)$pdo->lastInsertId();
$pdo->prepare("UPDATE cw_supplier_invoices SET supplier_id=?,supplier_name='Corrected Supplier' WHERE id=?")->execute([$correctedSupplier,$invoice]);lpc_refresh($pdo,[$invoice]);
expect((int)$pdo->query('SELECT supplier_id FROM cw_landed_product_costs ORDER BY id DESC LIMIT 1')->fetchColumn()===$correctedSupplier,'Supplier correction needs a new snapshot even if the amount is unchanged');
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
// Test the same sheet implementation used by the authenticated API.
$helpers=substr($api,strpos($api,'function si_normalize'),strpos($api,'try{')-strpos($api,'function si_normalize'));
$helpers=preg_replace('/function si_date\\(.*?\\n/','',$helpers);
eval($helpers);
require BASE_PATH.'/shared/cost-workbook-supplier-sheet.php';
$lineId=$id;
$source=(int)$pdo->query("SELECT id FROM cw_cost_product_sources WHERE supplier_invoice_line_id=$lineId")->fetchColumn();
$product=(int)$pdo->query("SELECT product_id FROM cw_cost_product_sources WHERE id=$source")->fetchColumn();
$state=si_sheet_state($pdo,$product,$source);
$originalSnapshot=$pdo->query("SELECT * FROM cw_landed_product_costs WHERE product_source_id=$source ORDER BY id LIMIT 1")->fetch();
$allocationCount=(int)$pdo->query('SELECT COUNT(*) FROM cw_transport_product_allocations')->fetchColumn();
$save=function($field,$value,$version=null,$request=null)use($pdo,$product,$source){return si_sheet_save($pdo,['product_id'=>$product,'source_id'=>$source,'field'=>$field,'value'=>$value,'version'=>$version??si_sheet_state($pdo,$product,$source)['version'],'request_id'=>$request??bin2hex(random_bytes(16))]);};
$result=$save('quantity','125',$state['version'],'test-quantity-request-0001');
expect((float)$result['rows'][0]['supplier_cost_per_base']===80.0,'125kg source correction recalculates supplier/base');
expect((float)$result['rows'][0]['transport_cost_per_base']===16.0,'Existing freight divided by corrected quantity');
expect((float)$result['rows'][0]['landed_cost_per_base']===96.0,'Landed per-base recalculates');
$revisions=(int)$pdo->query('SELECT COUNT(*) FROM cw_landed_product_costs')->fetchColumn();
$save('quantity','125',$state['version'],'test-quantity-request-0001');
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_landed_product_costs')->fetchColumn()===$revisions,'Retry cannot duplicate versions');
try{$save('quantity','130',$state['version']);throw new RuntimeException('Stale edit accepted');}catch(DomainException $expected){expect(strpos($expected->getMessage(),'updated elsewhere')!==false,'Explicit conflict feedback');}
$save('line_subtotal','12500');
expect((float)si_sheet_row(si_sheet_state($pdo,$product,$source))['supplier_cost_per_base']===100.0,'Source price correction recalculates');
expect($originalSnapshot===$pdo->query("SELECT * FROM cw_landed_product_costs WHERE id=".$originalSnapshot['id'])->fetch(),'Historical snapshot unchanged');
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_transport_product_allocations')->fetchColumn()===$allocationCount,'Freight allocations unchanged');
expect((int)$pdo->query("SELECT id FROM cw_supplier_invoice_lines WHERE id=$lineId")->fetchColumn()===$lineId,'Stable invoice line ID');
$save('invoice_date','2026-10-05');expect(si_sheet_row(si_sheet_state($pdo,$product,$source))['invoice_date']==='2026-10-05','Effective date correction');
$save('product_category','Clays');expect(si_sheet_row(si_sheet_state($pdo,$product,$source))['product_category']==='Clays','Clay category saves through inline editor');
$save('product_category','Other');$save('short_code','SHEET001');$save('product_name','Corrected test product');
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_cost_products')->fetchColumn()===1,'No duplicate master product');
$version=si_sheet_state($pdo,$product,$source)['version'];$save('invoice_number','CORRECTED');
try{$save('product_name','Stale name',$version);throw new RuntimeException('Cross-field stale edit accepted');}catch(DomainException $expected){}
foreach([['quantity','0'],['line_subtotal','-1'],['purchase_unit','L'],['product_category','Invalid'],['invoice_date','2026-02-31']] as $invalid){try{$save($invalid[0],$invalid[1]);throw new RuntimeException('Invalid edit accepted');}catch(DomainException $expected){}}
// A verified unit-entry correction is distinct from a density conversion.
$correctUnit=function($unit,$extra=[])use($pdo,$product,$source){return si_sheet_save($pdo,array_merge(['product_id'=>$product,'source_id'=>$source,'field'=>'purchase_unit','value'=>$unit,'version'=>si_sheet_state($pdo,$product,$source)['version'],'request_id'=>bin2hex(random_bytes(16)),'unit_correction_confirmed'=>true],$extra));};
// The earlier fixture deliberately has two purchases. Verify the guard, then
// exclude that disposable older purchase while testing a single-source correction.
try{$correctUnit('L');throw new RuntimeException('Existing second purchase ignored');}catch(DomainException $expected){expect(strpos($expected->getMessage(),'other active purchases')!==false,'Existing multi-purchase guard');}
$pdo->prepare('UPDATE cw_cost_product_sources SET active=0 WHERE supplier_invoice_line_id IN (SELECT id FROM cw_supplier_invoice_lines WHERE supplier_invoice_id=?)')->execute([$older]);
$pdo->prepare('UPDATE cw_supplier_invoice_lines SET active=0 WHERE supplier_invoice_id=?')->execute([$older]);
$unitBefore=si_sheet_state($pdo,$product,$source);$unitHistory=$pdo->query('SELECT * FROM cw_landed_product_costs ORDER BY id')->fetchAll();$unitFreight=$pdo->query('SELECT * FROM cw_transport_product_allocations ORDER BY id')->fetchAll();
$unitRequest=bin2hex(random_bytes(16));$corrected=$correctUnit('L',['request_id'=>$unitRequest]);$unitAfter=si_sheet_state($pdo,$product,$source);
expect($unitAfter['p']['costing_basis']==='volume'&&$unitAfter['p']['base_unit']==='L'&&$unitAfter['l']['purchase_unit']==='L','Confirmed kg entry error changes canonical and purchase units together');
foreach(['quantity','pack_size','line_subtotal','line_total','shipping_weight_kg'] as $key)expect((float)$unitBefore['l'][$key]===(float)$unitAfter['l'][$key],'Unit correction preserves '.$key);
expect((float)$unitAfter['c']['supplier_cost_per_base']===100.0&&(float)$unitAfter['c']['transport_cost_used']===(float)$unitBefore['c']['transport_cost_used'],'Correct litre rates and unchanged freight total');
foreach($unitHistory as $snapshot)expect($snapshot===$pdo->query('SELECT * FROM cw_landed_product_costs WHERE id='.(int)$snapshot['id'])->fetch(),'Original kg history immutable');
expect($unitFreight===$pdo->query('SELECT * FROM cw_transport_product_allocations ORDER BY id')->fetchAll(),'Unit correction does not reallocate freight');
$unitCount=(int)$pdo->query('SELECT COUNT(*) FROM cw_landed_product_costs')->fetchColumn();$correctUnit('L',['request_id'=>$unitRequest,'version'=>$unitBefore['version']]);expect($unitCount===(int)$pdo->query('SELECT COUNT(*) FROM cw_landed_product_costs')->fetchColumn(),'Confirmed correction retry idempotent');
try{$correctUnit('kg',['version'=>$unitBefore['version']]);throw new RuntimeException('Stale unit correction accepted');}catch(DomainException $expected){}
$pdo->prepare("INSERT INTO cw_cost_product_sources(product_id,source_type,total_purchased_quantity,total_supplier_cost,currency,cost_date) VALUES(?,'manual',1,10,'NAD','2026-10-06')")->execute([$product]);$otherUnitSource=(int)$pdo->lastInsertId();
try{$correctUnit('kg');throw new RuntimeException('Other source silently reinterpreted');}catch(DomainException $expected){expect(strpos($expected->getMessage(),'other active purchases')!==false,'Multiple-source correction requires review');}
$pdo->prepare('DELETE FROM cw_cost_product_sources WHERE id=?')->execute([$otherUnitSource]);$correctUnit('kg');
echo "Confirmed purchase unit correction: stable quantity/totals/weight, litre basis, history, retries, stale edits and multiple-source guard passed.\n";
// Product-local transport: do not revise or redistribute the invoice's freight.
$existing=$pdo->query("SELECT * FROM cw_supplier_invoice_lines WHERE id=$lineId")->fetch();
$second=$line;unset($second['id']);$second['product_description']='Independent freight neighbour';$second['confirm_new_product']=true;
$pdo->beginTransaction();cw_supplier_save_lines($pdo,$invoice,[$existing,$second]);lpc_refresh($pdo,[$invoice]);$pdo->commit();
$neighbour=$pdo->query("SELECT * FROM cw_cost_product_sources WHERE supplier_invoice_line_id<>$lineId AND product_id<>$product ORDER BY id DESC LIMIT 1")->fetch();
$neighbourBefore=si_sheet_state($pdo,(int)$neighbour['product_id'],(int)$neighbour['id']);
$allocationsBefore=$pdo->query('SELECT * FROM cw_transport_product_allocations ORDER BY id')->fetchAll();
$historyBefore=$pdo->query("SELECT * FROM cw_landed_product_costs WHERE product_source_id=$source ORDER BY id")->fetchAll();
$priorVersion=si_sheet_state($pdo,$product,$source)['version'];
$result=$save('transport_cost_per_base','34',$priorVersion,'individual-transport-0001');
expect(count($result['rows'])===1,'Only edited product returned');
expect((float)$result['rows'][0]['transport_cost_per_base']===34.0,'34 NAD per kg is not split');
expect((float)$result['rows'][0]['landed_cost_per_base']===134.0,'Manual freight replaces rather than adds existing allocation');
expect((float)$result['rows'][0]['transport_cost_used']===4250.0,'125kg purchase uses 34 per kg');
expect($neighbourBefore===si_sheet_state($pdo,(int)$neighbour['product_id'],(int)$neighbour['id']),'Neighbour source, audit and snapshot unchanged');
// A cross-basis correction must preserve a manual freight total, even when base magnitude changes.
$correctUnit('ml');$manualUnit=si_sheet_state($pdo,$product,$source);
expect(abs((float)$manualUnit['c']['transport_cost_used']-4250)<0.000001&&abs((float)$manualUnit['s']['manual_transport_per_base']-34000)<0.000001,'Unit correction preserves manual freight total, not an incompatible old per-base rate');
$correctUnit('kg');
$pdo->prepare('UPDATE cw_cost_product_sources SET active=1 WHERE supplier_invoice_line_id IN (SELECT id FROM cw_supplier_invoice_lines WHERE supplier_invoice_id=?)')->execute([$older]);
$pdo->prepare('UPDATE cw_supplier_invoice_lines SET active=1 WHERE supplier_invoice_id=?')->execute([$older]);
$count=(int)$pdo->query('SELECT COUNT(*) FROM cw_landed_product_costs')->fetchColumn();
$save('transport_cost_per_base','34',$priorVersion,'individual-transport-0001');
expect($count===(int)$pdo->query('SELECT COUNT(*) FROM cw_landed_product_costs')->fetchColumn(),'Transport retry idempotent');
lpc_refresh($pdo,[$invoice]);
expect((float)si_sheet_row(si_sheet_state($pdo,$product,$source))['transport_cost_per_base']===34.0,'Manual amount survives refresh/recalculation');
$save('quantity','100');
expect((float)si_sheet_row(si_sheet_state($pdo,$product,$source))['transport_cost_per_base']===34.0,'Manual per-base amount survives quantity correction');
$save('transport_cost_per_base','0');
expect((float)si_sheet_row(si_sheet_state($pdo,$product,$source))['transport_cost_per_base']===0.0,'Explicit zero allowed');
$save('transport_cost_per_base','34.12345678');lpc_refresh($pdo,[$invoice]);
expect(abs((float)si_sheet_row(si_sheet_state($pdo,$product,$source))['transport_cost_per_base']-34.12345678)<0.00000001,'Eight decimal precision retained');
foreach(['-1','','NaN','100000000000000'] as $bad){try{$save('transport_cost_per_base',$bad);throw new RuntimeException('Bad transport accepted');}catch(DomainException $expected){}}
expect($allocationsBefore===$pdo->query('SELECT * FROM cw_transport_product_allocations ORDER BY id')->fetchAll(),'Shipment allocations never rewritten');
foreach($historyBefore as $historical)expect($historical===$pdo->query('SELECT * FROM cw_landed_product_costs WHERE id='.(int)$historical['id'])->fetch(),'Historical cost unchanged');
echo "Individual transport: isolated edits, replacement not double-counting, precision, zero, retries, refresh and history passed.\n";
echo "Spreadsheet integration: source revisions, concurrency, retries, validation, stable identities and freight preservation passed.\\n";
echo "Supplier costs integration: stable IDs, canonical products, audit, freight, FX, rollback and idempotency passed.\n";

// Re-upload a partially approved invoice, using disposable records only.
$pdo->prepare("INSERT INTO cw_supplier_invoices(supplier_id,supplier_name,invoice_number,invoice_date,original_filename,stored_file,file_type,file_hash,currency,vat_treatment,approval_status,transport_treatment) VALUES(?,'Recovery Supplier','RECOVERY-1','2026-10-06','fixture.pdf','fixture.pdf','application/pdf',?,'NAD','exclusive','draft','pending')")->execute([$supplier,hash('sha256','recovery fixture')]);$recovery=(int)$pdo->lastInsertId();
$old=['product_description'=>'Recovery Existing Herb','quantity'=>2,'pack_size'=>1,'purchase_unit'=>'kg','line_subtotal'=>200,'vat_amount'=>0,'confirm_new_product'=>1];
$pdo->beginTransaction();cw_supplier_save_lines($pdo,$recovery,[$old]);cw_supplier_resolve_products($pdo,$recovery);$pdo->prepare("UPDATE cw_supplier_invoices SET approval_status='approved' WHERE id=?")->execute([$recovery]);lpc_refresh($pdo,[$recovery]);$pdo->commit();
$oldLine=$pdo->query('SELECT * FROM cw_supplier_invoice_lines WHERE supplier_invoice_id='.$recovery)->fetch();
$pdo->prepare('UPDATE cw_cost_product_sources SET manual_transport_per_base=34 WHERE supplier_invoice_line_id=?')->execute([$oldLine['id']]);lpc_refresh($pdo,[$recovery]);
$pdo->prepare("INSERT INTO cw_supplier_invoices(supplier_id,supplier_name,invoice_number,invoice_date,original_filename,stored_file,file_type,currency,vat_treatment,approval_status) VALUES(?,'Recovery Supplier','MANUAL-RECOVERY','2026-10-07','','','','NAD','exclusive','draft')")->execute([$supplier]);$manualRecovery=(int)$pdo->lastInsertId();
$manualLine=$old;$manualLine['product_description']='Recovery Manual Herb';$pdo->beginTransaction();cw_supplier_save_lines($pdo,$manualRecovery,[$manualLine]);cw_supplier_resolve_products($pdo,$manualRecovery);$pdo->prepare("UPDATE cw_supplier_invoices SET approval_status='approved' WHERE id=?")->execute([$manualRecovery]);lpc_refresh($pdo,[$manualRecovery]);$pdo->commit();
$oldSources=$pdo->query('SELECT * FROM cw_cost_product_sources ORDER BY id')->fetchAll();$oldHistory=$pdo->query('SELECT * FROM cw_landed_product_costs ORDER BY id')->fetchAll();$oldAllocations=$pdo->query('SELECT * FROM cw_transport_product_allocations ORDER BY id')->fetchAll();
$pdo->prepare("INSERT INTO cw_supplier_invoice_lines(supplier_invoice_id,raw_description,product_description,active,review_status) VALUES(?,'Recovery Omitted Herb','Recovery Omitted Herb',0,'needs_review')")->execute([$recovery]);$omitted=(int)$pdo->lastInsertId();
$extracted=[['name'=>'Recovery Existing Herb','quantity'=>2,'unit'=>'kg','line_total'=>200],['name'=>'Recovery Omitted Herb','quantity'=>1,'unit'=>'kg','line_total'=>90],['name'=>'Recovery New Herb','quantity'=>1,'unit'=>'kg','line_total'=>75],['name'=>'RECOVERY MANUAL HERB','quantity'=>2,'unit'=>'kg','line_total'=>200]];
$invoiceCount=(int)$pdo->query('SELECT COUNT(*) FROM cw_supplier_invoices')->fetchColumn();
$result=si_recover_invoice($pdo,$recovery,$extracted);expect($result['reused']&&$result['missing_only']&&$result['pending_count']===2,'Missing review reuses approved invoice');
expect(strpos($result['message'],'Already listed under another saved purchase')!==false,'Previously manually captured product excluded from missing items');
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_supplier_invoices')->fetchColumn()===$invoiceCount,'Reupload never creates a second invoice');
$pending=si_recovery_lines($pdo,$recovery);expect((int)$pending[0]['id']===$omitted,'Previously omitted line restored with stable ID');
si_recover_invoice($pdo,$recovery,$extracted);expect(count(si_recovery_lines($pdo,$recovery))===2,'Retry cannot duplicate missing items');
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_supplier_invoice_lines WHERE supplier_invoice_id='.$recovery)->fetchColumn()===3,'Only missing extracted product inserted');
$makeBody=function()use($pdo,$recovery){$inv=$pdo->query('SELECT * FROM cw_supplier_invoices WHERE id='.$recovery)->fetch();$pending=si_recovery_lines($pdo,$recovery);return ['id'=>$recovery,'updated_at'=>$inv['updated_at'],'recovery_token'=>si_recovery_token($inv,$pending),'lines'=>array_map(function($l){return array_merge($l,['quantity'=>1,'pack_size'=>1,'purchase_unit'=>'kg','line_subtotal'=>75,'vat_amount'=>0,'confirm_new_product'=>1]);},$pending)];};
$body=$makeBody();$body['lines']=[$body['lines'][0]];si_save_recovered($pdo,$body);lpc_refresh($pdo,[$recovery]);
expect($oldSources===$pdo->query('SELECT * FROM cw_cost_product_sources ORDER BY id')->fetchAll(),'Draft recovery cannot publish through global refresh');
try{si_save_recovered($pdo,$body);throw new RuntimeException('Stale recovery accepted');}catch(DomainException $expected){}
$body=$makeBody();$body['lines']=[$body['lines'][0]];$body['approve']=true;
$bad=$body;$bad['lines'][0]['id']=$oldLine['id'];try{si_save_recovered($pdo,$bad);throw new RuntimeException('Posted line accepted');}catch(DomainException $expected){}
si_save_recovered($pdo,$body);expect(count(si_recovery_lines($pdo,$recovery))===1,'Unselected items remain pending');
try{si_save_recovered($pdo,$body);throw new RuntimeException('Approval retry duplicated');}catch(DomainException $expected){}
foreach($oldSources as $sourceRow)expect($sourceRow===$pdo->query('SELECT * FROM cw_cost_product_sources WHERE id='.(int)$sourceRow['id'])->fetch(),'Existing source untouched by recovery');
foreach($oldHistory as $historyRow)expect($historyRow===$pdo->query('SELECT * FROM cw_landed_product_costs WHERE id='.(int)$historyRow['id'])->fetch(),'Historical snapshot unchanged by recovery');
expect($oldAllocations===$pdo->query('SELECT * FROM cw_transport_product_allocations ORDER BY id')->fetchAll(),'Recovery must never redistribute freight');
$body=$makeBody();$body['approve']=true;si_save_recovered($pdo,$body);expect(!si_recovery_lines($pdo,$recovery),'All selected missing items approved');
si_recover_invoice($pdo,$recovery,$extracted);expect(!si_recovery_lines($pdo,$recovery),'Fully captured invoice remains captured after reupload');
expect((int)$pdo->query('SELECT COUNT(*) FROM cw_cost_product_sources WHERE supplier_invoice_line_id IN(SELECT id FROM cw_supplier_invoice_lines WHERE supplier_invoice_id='.$recovery.')')->fetchColumn()===3,'Exactly one source per invoice item');
echo "Invoice recovery: missing-only extraction, archived line restoration, stable IDs, retry, selected approval, saved drafts, stale review, protected history and freight passed.\n";
