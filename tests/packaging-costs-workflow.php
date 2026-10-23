<?php
declare(strict_types=1);
define('BASE_PATH',dirname(__DIR__));
function current_user():array{return ['id'=>92,'name'=>'Packaging Test Owner'];}
require BASE_PATH.'/shared/cost-workbook.php';
function check($v,string $m):void{if(!$v)throw new RuntimeException($m);}
function near($a,$b,string $m):void{check(abs($a-$b)<.000001,$m);}
$base=['purchase_quantity'=>10,'units_per_pack'=>100,'amount_entered'=>1500,'exchange_rate'=>1,'currency'=>'NAD','cost_basis'=>'exclusive','vat_treatment'=>'exempt','tax_recoverable'=>1];
$c=cw_packaging_calculate($base,'Pouch',15);near($c['usable_units'],1000,'10 cartons of 100');near($c['unit_cost_ex_vat'],1.5,'Cost per pouch');
$r=cw_packaging_calculate(array_merge($base,['purchase_quantity'=>1,'units_per_pack'=>1,'amount_entered'=>150,'print_yield'=>1000]),'Ribbon',15);near($r['unit_cost_ex_vat'],.15,'Ribbon cost per printable label');
check(cw_packaging_calculate($base,'Ribbon',15)['status_key']==='print_yield_required','Missing ribbon yield cannot be priced as a whole ribbon');
$vat=cw_packaging_calculate(array_merge($base,['amount_entered'=>1150,'cost_basis'=>'inclusive','vat_treatment'=>'standard']),'Bottle',15);near($vat['cost_ex_vat'],1000,'Recoverable VAT removed');
near(cw_packaging_calculate(array_merge($base,['amount_entered'=>1150,'cost_basis'=>'inclusive','vat_treatment'=>'standard','tax_recoverable'=>0]),'Bottle',15)['cost_ex_vat'],1150,'Nonrecoverable VAT retained');
try{cw_packaging_calculate(array_merge($base,['currency'=>'ZAR']),'Bottle',15);throw new RuntimeException('Unconfirmed FX accepted');}catch(DomainException $ok){}
if(!getenv('CW_TEST_DSN')){echo "Packaging unit, yield and tax calculations passed.\n";exit;}
$pdo=new PDO(getenv('CW_TEST_DSN'),getenv('CW_TEST_USER')?:'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
check($pdo->query('SELECT DATABASE()')->fetchColumn()==='cw_supplier_test','Only disposable test database permitted');cw_install_schema($pdo);
$api=file_get_contents(BASE_PATH.'/apps/cost-manager/packaging-costs-api.php');$start=strpos($api,'function pc_reply');$end=strpos($api,"\ntry {",$start);eval(substr($api,$start,$end-$start));
$types=$pdo->query('SELECT id,name FROM cw_packaging_types')->fetchAll(PDO::FETCH_KEY_PAIR);$types=array_flip($types);
pc_install_landed($pdo);pc_install_landed($pdo);
function material(array $over=[]):array{global $pdo,$base,$types;return array_merge($base,['packaging_type_id'=>$types['Pouch'],'item_name'=>'Test Pouch','specification'=>'500ml','supplier_name'=>'Test Supplier','supplier_sku'=>'TEST-P','purchase_unit'=>'box','effective_date'=>'2026-10-01','request_key'=>bin2hex(random_bytes(16))],$over);}
$b=material();$a=pc_save_item($pdo,$b,15);$id=$a['id'];$cost=cw_packaging_cost($pdo,$id,'2026-10-08');near($cost['landed_ex'],1.5,'Stored unit cost');
$retry=pc_save_item($pdo,$b,15);check($retry['id']===$id,'Idempotent save');check((int)$pdo->query('SELECT COUNT(*) FROM cw_packaging_item_costs WHERE packaging_item_id='.$id)->fetchColumn()===1,'No duplicate cost on retry');
try{pc_save_item($pdo,material(),15);throw new RuntimeException('Duplicate master accepted');}catch(DomainException $ok){if($pdo->inTransaction())$pdo->rollBack();}
$transport=pc_insert($pdo,'cw_transport_records',['courier_name'=>'Packaging Test Courier','invoice_number'=>'PK-TEST','transport_date'=>'2026-10-01','currency'=>'NAD','subtotal_ex_vat'=>200,'total_inc_vat'=>200,'vat_treatment'=>'exempt','allocable_amount'=>200,'exchange_rate'=>1,'fx_confirmed'=>1]);
$b=material(['id'=>$id,'latest_cost_id'=>$cost['id'],'transport_id'=>$transport,'freight_method'=>'weight','shipping_weight'=>10,'shipment_weight'=>10]);pc_save_item($pdo,$b,15);$cost=cw_packaging_cost($pdo,$id,'2026-10-08');near($cost['freight_unit_ex'],.2,'Freight per pouch');near($cost['landed_ex'],1.7,'Landed pouch cost');near(cw_packaging_freight_claimed($pdo,$transport),200,'Reserved canonical freight');
try{pc_save_item($pdo,material(['item_name'=>'Other Pouch','transport_id'=>$transport,'freight_amount'=>1,'freight_confirmed'=>1]),15);throw new RuntimeException('Duplicate freight accepted');}catch(DomainException $ok){if($pdo->inTransaction())$pdo->rollBack();}
$ids=[];
foreach([['Tub',4.2],['Lid',1.1],['Label',.55],['Ribbon',150]] as $row){$x=pc_save_item($pdo,material(['item_name'=>'Test '.$row[0],'packaging_type_id'=>$types[$row[0]],'purchase_quantity'=>1,'units_per_pack'=>1,'amount_entered'=>$row[1],'print_yield'=>$row[0]==='Ribbon'?1000:null]),15);$ids[]=$x['id'];}
$pdo->exec("INSERT IGNORE INTO cw_size_conversions(label,measurement_type,quantity,unit,base_value,base_unit) VALUES('500g','weight',500,'g',.5,'kg')");$size=(int)$pdo->query("SELECT id FROM cw_size_conversions WHERE measurement_type='weight' AND base_value=.5")->fetchColumn();
$s=['setup_name'=>'Test Butter Tub','size_conversion_id'=>$size,'scope_type'=>'default','effective_date'=>'2026-10-02','components'=>array_map(function($id){return ['packaging_item_id'=>$id,'quantity'=>1,'waste_percent'=>0];},$ids)];
$setup=pc_save_setup($pdo,$s);$cal=cw_packaging_setup_cost($pdo,$setup['id'],'2026-10-08');near($cal['cost_ex_vat'],6,'Complete finished butter packaging');
$s['id']=$setup['id'];$s['components'][0]['waste_percent']=10;$s['effective_date']='2026-10-09';$version=pc_save_setup($pdo,$s);near(cw_packaging_setup_cost($pdo,$version['id'],'2026-10-10')['cost_ex_vat'],6.42,'Waste included');
check((int)$pdo->query('SELECT COUNT(*) FROM cw_packaging_setup_components WHERE packaging_setup_id='.$setup['id'])->fetchColumn()===4,'Prior setup components preserved');
near(cw_packaging_setup_cost($pdo,$setup['id'],'2026-10-08')['cost_ex_vat'],6,'Historical effective costs preserved');
$label=cw_packaging_cost($pdo,$ids[2],'2026-10-08');pc_save_item($pdo,material(['id'=>$ids[2],'latest_cost_id'=>$label['id'],'item_name'=>'Test Label','packaging_type_id'=>$types['Label'],'purchase_quantity'=>1,'units_per_pack'=>1,'amount_entered'=>.55,'printed_label'=>1]),15);
check(!cw_packaging_setup_cost($pdo,$version['id'],'2026-10-10')['complete'],'Printed label and ribbon double count rejected');
$roundTransport=pc_insert($pdo,'cw_transport_records',['courier_name'=>'Rounding Test','invoice_number'=>'ROUND-10','transport_date'=>'2026-10-01','currency'=>'NAD','subtotal_ex_vat'=>10,'total_inc_vat'=>10,'vat_treatment'=>'exempt','allocable_amount'=>10,'exchange_rate'=>1,'fx_confirmed'=>1]);
foreach([1,2,3] as $n)pc_save_item($pdo,material(['item_name'=>'Rounding Material '.$n,'transport_id'=>$roundTransport,'freight_method'=>'weight','shipping_weight'=>1,'shipment_weight'=>3]),15);
near(cw_packaging_freight_claimed($pdo,$roundTransport),10,'Final shared freight share reconciles rounding exactly');
function rp_num($v):float{return is_numeric($v)?(float)$v:0;}
$retail=file_get_contents(BASE_PATH.'/apps/cost-manager/retail-pricing-api.php');$start=strpos($retail,'function rp_calculation');$end=strpos($retail,'function rp_sources',$start);eval(substr($retail,$start,$end-$start));
$finished=rp_calculation(['landed_cost_per_base'=>10],['base_value'=>.5],['cost_ex_vat'=>6,'cost_inc_vat'=>6],20,0);near($finished['total_cost_ex_vat'],11,'Product Pricing includes packaging once');
foreach(['10ml Amber Glass Bottle + Black Dropper Cap'=>4.88,'50ml Amber Glass Bottle + Black Dropper Cap'=>5.89,'100ml Amber Glass Bottle + Black Cap'=>4.76,'500ml Amber Glass Bottle + Black Cap'=>19.85,'250ml PET Tall Bottle + White Cap'=>7.32,'500ml PET Boston Bottle + White Cap'=>8.19] as $name=>$amount){
 $body=material(['item_name'=>$name,'packaging_type_id'=>$types['Bottle'],'purchase_quantity'=>1,'units_per_pack'=>1,'amount_entered'=>$amount,'vat_treatment'=>'provisional_landed','tax_recoverable'=>0,'effective_date'=>'2026-10-23']);
 $saved=pc_save_item($pdo,$body,15);$c=cw_packaging_cost($pdo,$saved['id'],'2026-10-23');near($c['landed_ex'],$amount,'Bundled landed cost unchanged');near($c['landed_inc'],$amount,'No invented VAT');near($c['freight_unit_ex'],0,'No duplicate freight');check($c['vat_treatment']==='provisional_landed','Preserve provisional classification');
 check(cw_packaging_cost($pdo,$saved['id'],'2026-10-22')===null,'No historical changes');check(pc_save_item($pdo,$body,15)['id']===$saved['id'],'Bundle retry idempotent');
 $setup=pc_save_setup($pdo,['setup_name'=>$name,'size_conversion_id'=>$size,'scope_type'=>'product','scope_reference'=>'bundle-'.$saved['id'],'effective_date'=>'2026-10-23','components'=>[['packaging_item_id'=>$saved['id'],'quantity'=>1,'waste_percent'=>0]]]);
 $cost=cw_packaging_setup_cost($pdo,$setup['id'],'2026-10-23');near($cost['cost_ex_vat'],$amount,'Selectable setup includes bundle once');
 near(rp_calculation(['landed_cost_per_base'=>10],['base_value'=>.5],$cost,30,0)['total_cost_ex_vat'],5+$amount,'Pricing adds exact bundle cost');
 try{pc_save_item($pdo,array_merge($body,['transport_id'=>$transport,'request_key'=>bin2hex(random_bytes(16))]),15);throw new RuntimeException('Duplicate freight accepted for landed bundle');}catch(DomainException $ok){if($pdo->inTransaction())$pdo->rollBack();}
}
$catalog=['items'=>[]];
// Synthetic fixtures only: the owner's supplier catalog remains local pending approval.
for($n=0;$n<20;$n++)$catalog['items'][]=['item_name'=>$n===0?'Test Pouch':'Synthetic packaging '.$n,'packaging_type'=>'Pouch','supplier_name'=>'Synthetic Supplier '.($n%4),'supplier_sku'=>'SYN-'.$n.($n%2?' + SYN-CAP-'.$n:''),'unit_cost_ex_vat'=>number_format($n+0.1234,4,'.',''),'includes_carton_and_transport'=>$n>=14];
foreach($catalog['items'] as $entry){
 $existing=pc_fetch($pdo,'SELECT id FROM cw_packaging_items WHERE item_name=?',[$entry['item_name']]);$id=(int)($existing['id']??0);
 $prior=$id?pc_fetch($pdo,'SELECT * FROM cw_packaging_item_costs WHERE packaging_item_id=? ORDER BY id DESC LIMIT 1',[$id]):null;
 $b=material(['id'=>$id,'latest_cost_id'=>$prior['id']??0,'item_name'=>$entry['item_name'],'packaging_type_id'=>$types[$entry['packaging_type']],'supplier_name'=>$entry['supplier_name'],'supplier_sku'=>$entry['supplier_sku'],'purchase_quantity'=>1,'units_per_pack'=>1,'amount_entered'=>$entry['unit_cost_ex_vat'],'cost_basis'=>'exclusive','vat_treatment'=>$entry['includes_carton_and_transport']?'provisional_landed':'standard','tax_recoverable'=>$entry['includes_carton_and_transport']?0:1,'effective_date'=>'2026-10-24']);
 $result=pc_save_item($pdo,$b,15);if($id)check($result['id']===$id,'Update imported item in place');
 $saved=cw_packaging_cost($pdo,$result['id'],'2026-10-24');near($saved['landed_ex'],(float)$entry['unit_cost_ex_vat'],'Four-decimal net costs preserved');check($saved['supplier_sku']===$entry['supplier_sku'],'Supplier code stored separately');check($saved['supplier_name']===$entry['supplier_name'],'Supplier stored');
 if($prior)check(pc_fetch($pdo,'SELECT * FROM cw_packaging_item_costs WHERE id=?',[$prior['id']])===$prior,'Prior cost row unchanged');
 check((int)pc_fetch($pdo,'SELECT COUNT(*) n FROM cw_packaging_items WHERE item_name=?',[$entry['item_name']])['n']===1,'No duplicate material');
}
echo "Packaging database tests passed: units, freight, idempotency, duplicate prevention, supplier/SKU updates, four-decimal costs, pricing integration and immutable history.\n";

