<?php
declare(strict_types=1);
define('BASE_PATH',dirname(__DIR__));
require BASE_PATH.'/shared/cost-workbook.php';
require BASE_PATH.'/shared/cost-workbook-pricing-master.php';
function near($actual,$expected,$message){if($actual===null||abs($actual-$expected)>0.0000001)throw new RuntimeException($message.': '.var_export($actual,true));}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE cw_packaging_types(id INTEGER PRIMARY KEY,name TEXT);CREATE TABLE cw_packaging_items(id INTEGER PRIMARY KEY,item_name TEXT,specification TEXT,active INTEGER,packaging_type_id INTEGER);CREATE TABLE cw_packaging_item_costs(id INTEGER PRIMARY KEY,packaging_item_id INTEGER,effective_date TEXT,status_key TEXT,usable_units REAL,purchase_quantity REAL,purchase_unit TEXT,print_yield REAL,printed_label INTEGER,unit_cost_ex_vat REAL,unit_cost_inc_vat REAL,freight_ex REAL,freight_inc REAL);CREATE TABLE cw_packaging_setups(id INTEGER PRIMARY KEY,effective_date TEXT);CREATE TABLE cw_packaging_setup_components(id INTEGER PRIMARY KEY,packaging_setup_id INTEGER,packaging_item_id INTEGER,quantity REAL,waste_percent REAL,display_order INTEGER)');
$types=['Bottle','Pouch','Tub','Other','Cap','Lid','Label','Ribbon','Pump','Dropper','Seal','Jar','Tube','Box','Bag'];
foreach($types as $n=>$type){$id=$n+1;$pdo->prepare('INSERT INTO cw_packaging_types VALUES(?,?)')->execute([$id,$type]);$pdo->prepare('INSERT INTO cw_packaging_items VALUES(?,?,?,?,?)')->execute([$id,$type,'',1,$id]);$pdo->prepare('INSERT INTO cw_packaging_item_costs VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$id,'2026-10-01','ready',1,1,'each',100,0,8.19,9.4185,0,0]);}
$before=$pdo->query('SELECT * FROM cw_packaging_item_costs')->fetchAll();
foreach($types as $n=>$type){$cost=cw_packaging_cost($pdo,$n+1,'2026-11-09');$expected=in_array($type,cw_packaging_label_policy()['container_types'],true)?0.45:0;near($cost['landed_ex'],8.19+$expected,$type.' net');near($cost['landed_inc'],9.4185+$expected*1.15,$type.' gross');near($cost['unit_cost_ex_vat'],8.19,'Supplier cost unchanged');near(cw_packaging_cost($pdo,$n+1,'2026-11-08')['landed_ex'],8.19,'Historical cost unchanged');near(cw_packaging_cost($pdo,$n+1,'2026-11-09')['landed_ex'],$cost['landed_ex'],'Repeat safe');}
$pdo->exec("INSERT INTO cw_packaging_setups VALUES(1,'2026-10-01');INSERT INTO cw_packaging_setup_components VALUES(1,1,1,1,0,1),(2,1,5,1,0,2)");
$setup=cw_packaging_setup_cost($pdo,1,'2026-11-09');near($setup['cost_ex_vat'],16.83,'Bottle and cap: label once');near($setup['label_cost_ex_vat'],.45,'Separate label allowance');
$pdo->exec('UPDATE cw_packaging_setup_components SET waste_percent=10 WHERE id=1');near(cw_packaging_setup_cost($pdo,1,'2026-11-09')['cost_ex_vat'],17.649,'Existing waste plus one label');
$pdo->exec('INSERT INTO cw_packaging_setup_components VALUES(3,1,7,1,0,3)');$explicit=cw_packaging_setup_cost($pdo,1,'2026-11-09');near($explicit['label_cost_ex_vat'],0,'Explicit label not double counted');near($explicit['cost_ex_vat'],25.389,'Explicit label cost preserved');
$pdo->exec("UPDATE cw_packaging_item_costs SET status_key='price_required' WHERE packaging_item_id=7");if(cw_packaging_setup_cost($pdo,1,'2026-11-09')['cost_ex_vat']!==null)throw new RuntimeException('Missing label cost hidden');
$pdo->exec("UPDATE cw_packaging_item_costs SET status_key='ready' WHERE packaging_item_id=7");
if($pdo->query('SELECT * FROM cw_packaging_item_costs')->fetchAll()!==$before)throw new RuntimeException('Saved source history was modified');
$p=['id'=>1,'product_name'=>'Example','short_code'=>'EX','product_category'=>'Oils','costing_basis'=>'volume','base_unit'=>'L','landed_cost_id'=>1,'landed_status'=>'complete','landed_cost_per_base'=>80,'supplier_name'=>'Supplier','cost_date'=>'2026-10-01','supplier_cost_per_base'=>80,'transport_cost_per_base'=>0];
$material=cw_packaging_cost($pdo,1,'2026-11-09');
$sources=['products'=>[$p],'sizes'=>[['id'=>1,'label'=>'500ml','measurement_type'=>'volume','base_value'=>.5,'base_unit'=>'L']],'setups'=>[],'materials'=>[['id'=>1,'item_name'=>'Bottle','cost_id'=>1,'cost_ex_vat'=>$material['landed_ex'],'cost_inc_vat'=>$material['landed_inc']]],'website'=>[],'batch'=>1,'lastSync'=>null];
$body=['product_id'=>1,'size_conversion_id'=>1,'packaging_mode'=>'material','packaging_material_id'=>1,'selling_price_inc_vat'=>115];
$r=rpm_preview($sources,[],$body);near($r['packaging_cost_ex_vat'],8.64,'Pricing sheet packaging');near($r['total_cost_ex_vat'],48.64,'Pricing sheet final cost');near($r['profit_per_unit'],51.36,'Pricing sheet profit');near($r['selling_price_inc_vat'],115,'Selling price unchanged');
$body['packaging_mode']='none';$body['packaging_confirmed']=1;near(rpm_preview($sources,[],$body)['packaging_cost_ex_vat'],0,'No packaging stays zero');
$body['packaging_mode']='manual';$body['packaging_manual_cost']=2;near(rpm_preview($sources,[],$body)['packaging_cost_ex_vat'],2,'Manual override preserved');
echo "PASS: container types, closures, VAT, history, retries, setup label deduplication, waste, missing costs, Product Pricing totals/profit, manual/no-packaging and selling prices.\n";
