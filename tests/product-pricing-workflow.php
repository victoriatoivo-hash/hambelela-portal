<?php
declare(strict_types=1);
define('BASE_PATH',dirname(__DIR__));
function current_user():array{return ['id'=>93,'name'=>'Pricing test owner'];}
require BASE_PATH.'/shared/cost-workbook.php';
require BASE_PATH.'/shared/cost-workbook-pricing-master.php';
$api=file_get_contents(BASE_PATH.'/apps/cost-manager/retail-pricing-api.php');$start=strpos($api,'function rp_reply');$end=strpos($api,'try{$pdo=db()',$start);eval(substr($api,$start,$end-$start));
function check($v,string $m):void{if(!$v)throw new RuntimeException($m);}
function near($a,$b,string $m):void{check(abs($a-$b)<.00001,$m);}
$settings=['retail_vat_rate'=>15,'retail_low_margin_threshold'=>20];
$p=['id'=>1,'product_name'=>'Unrefined Shea Butter','short_code'=>'USB01','product_category'=>'Butters','costing_basis'=>'weight','base_unit'=>'kg','landed_cost_id'=>1,'landed_status'=>'complete','landed_cost_per_base'=>100,'supplier_cost_per_base'=>90,'transport_cost_per_base'=>10,'supplier_name'=>'Fixture','cost_date'=>'2026-10-01'];
$s=['products'=>[$p],'sizes'=>[],'setups'=>[],'website'=>[],'batch'=>1,'lastSync'=>'2026-10-21 10:00:00'];
foreach([.25,.5,1]as $i=>$base){$id=$i+1;$s['sizes'][]=['id'=>$id,'label'=>($base*1000).'g','base_value'=>$base,'base_unit'=>'kg','measurement_type'=>'weight'];$s['setups'][]=['id'=>$id,'size_conversion_id'=>$id,'scope_type'=>'default','setup_name'=>'Size packaging','cost_ex_vat'=>2+$i,'cost_inc_vat'=>(2+$i)*1.15,'complete'=>true];$s['website'][]=['product_id'=>100,'variation_id'=>$id,'variation_name'=>($base*1000).'g','attributes'=>'{}','product_name'=>'Shea','sku'=>'USB01-'.$id,'active_price_inc_vat'=>115,'manage_stock'=>1,'stock_quantity'=>10];}
foreach([1=>27,2=>53,3=>104]as $id=>$cost){$b=['product_id'=>1,'size_conversion_id'=>$id,'website_key'=>'100:'.$id,'selling_price_inc_vat'=>115,'vat_treatment'=>'standard'];$r=rpm_preview($s,$settings,$b);near($r['total_cost_ex_vat'],$cost,'Independent size packaging');near($r['selling_price_ex_vat'],100,'VAT');near($r['profit_per_unit'],100-$cost,'Profit');near($r['stock_cost'],10*$cost,'Inventory cost');near($r['retail_value'],1150,'Website retail');near($r['projected_profit'],10*(100-$cost),'Projected profit');}
$b=['product_id'=>1,'size_conversion_id'=>2,'website_key'=>'100:2','selling_price_inc_vat'=>210,'vat_treatment'=>'standard'];$r=rpm_preview($s,$settings,$b);near($r['selling_price_ex_vat'],210/1.15,'210 VAT conversion');
$b['vat_treatment']='exempt';near(rpm_preview($s,$settings,$b)['selling_price_ex_vat'],210,'Exempt VAT');
$b['website_key']='100:3';try{rpm_preview($s,$settings,$b);throw new RuntimeException('Wrong size accepted');}catch(DomainException $expected){}
$b['website_key']='100:2';$unknown=$s;$unknown['website'][1]['manage_stock']=0;check(rpm_preview($unknown,$settings,$b)['stock_cost']===null,'Unknown stock not zero');
$missing=$s;$missing['products'][0]['landed_status']='review';check(rpm_preview($missing,$settings,$b)['total_cost_ex_vat']===null,'Missing source not zero');
echo "Pricing three-size calculations, VAT, margins, stock and incomplete sources PASS\n";
if(!getenv('CW_TEST_DSN'))exit;
$pdo=new PDO(getenv('CW_TEST_DSN'),getenv('CW_TEST_USER')?:'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);check($pdo->query('SELECT DATABASE()')->fetchColumn()==='cw_supplier_test','Disposable database required');cw_install_schema($pdo);
function ins(PDO $pdo,string $table,array $data):int{$pdo->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')')->execute(array_values($data));return(int)$pdo->lastInsertId();}
$pid=ins($pdo,'cw_cost_products',['product_name'=>'Pricing Fixture Shea','normalized_name'=>'pricingfixture','short_code'=>'PRTEST','product_category'=>'Butters','costing_basis'=>'weight','base_unit'=>'kg','woo_product_id'=>12345]);
$missingId=ins($pdo,'cw_cost_products',['product_name'=>'Pricing Missing Cost','normalized_name'=>'pricingmissing','short_code'=>'PRMISS','costing_basis'=>'weight','base_unit'=>'kg']);
$source=ins($pdo,'cw_cost_product_sources',['product_id'=>$pid,'source_type'=>'manual','cost_date'=>'2026-10-01','total_purchased_quantity'=>1,'total_supplier_cost'=>90]);
ins($pdo,'cw_landed_product_costs',['product_id'=>$pid,'product_source_id'=>$source,'costing_basis'=>'weight','base_unit'=>'kg','base_quantity'=>1,'supplier_cost_used'=>90,'transport_cost_used'=>10,'supplier_cost_per_base'=>90,'transport_cost_per_base'=>10,'landed_cost_per_base'=>100,'cost_date'=>'2026-10-01','status_key'=>'complete','calculation_hash'=>hash('sha256','pricing fixture')]);
$pack=(int)$pdo->query("SELECT id FROM cw_packaging_items WHERE item_name='Test Pouch' LIMIT 1")->fetchColumn();check($pack>0,'Run packaging workflow fixture first');
$pc=file_get_contents(BASE_PATH.'/apps/cost-manager/packaging-costs-api.php');$a=strpos($pc,'function pc_reply');$z=strpos($pc,"\ntry {",$a);eval(substr($pc,$a,$z-$a));
$batch=ins($pdo,'cw_sync_batches',['sync_uuid'=>'pricing-fixture','status'=>'completed','is_successful_snapshot'=>1,'completed_at'=>'2026-10-21 15:00:00']);
ins($pdo,'cw_product_snapshots',['product_id'=>12345,'variation_id'=>0,'product_name'=>'Shea parent','product_type'=>'variable','sync_batch_id'=>$batch,'stock_quantity'=>999,'manage_stock'=>1]);
$ids=[];
foreach([.25,.5,1]as $i=>$base){$pdo->prepare("INSERT IGNORE INTO cw_size_conversions(label,measurement_type,quantity,unit,base_value,base_unit) VALUES(?,'weight',?,'g',?,'kg')")->execute([($base*1000).'g',$base*1000,$base]);$q=$pdo->prepare("SELECT id FROM cw_size_conversions WHERE measurement_type='weight' AND base_value=? LIMIT 1");$q->execute([$base]);$size=(int)$q->fetchColumn();$ids[]=$size;
pc_save_setup($pdo,['setup_name'=>'Pricing fixture '.$size,'size_conversion_id'=>$size,'scope_type'=>'product','scope_reference'=>(string)$pid,'scope_label'=>'Pricing Fixture Shea','effective_date'=>'2026-10-01','components'=>[['packaging_item_id'=>$pack,'quantity'=>$i+1,'waste_percent'=>0]]]);
ins($pdo,'cw_product_snapshots',['product_id'=>12345,'variation_id'=>$i+1,'product_name'=>'Shea','variation_name'=>($base*1000).'g','product_type'=>'variation','sync_batch_id'=>$batch,'stock_quantity'=>10,'manage_stock'=>1,'active_price_inc_vat'=>115]);
}
$sources=rpm_sources($pdo);check(rpm_find($sources['products'],'id',$missingId)!==null,'Canonical missing-cost product discoverable');check(!array_filter($sources['website'],fn($w)=>(int)$w['product_id']===12345&&(int)$w['variation_id']===0),'Parent excluded');
$records=[];
foreach($ids as $i=>$size){$body=['product_id'=>$pid,'size_conversion_id'=>$size,'website_key'=>'12345:'.($i+1),'website_confirmed'=>1,'selling_price_inc_vat'=>115,'vat_treatment'=>'standard','effective_date'=>'2026-10-21','request_id'=>bin2hex(random_bytes(16))];$preview=rpm_preview($sources,$settings,$body);$body['source_token']=$preview['source_token'];$saved=rpm_save($pdo,$body,$settings);check(rpm_save($pdo,$body,$settings)['replayed'],'Idempotent retry');$records[]=[$body,$saved];}
[$body,$saved]=$records[0];$body['id']=$saved['id'];$body['version']=1;$body['request_id']=bin2hex(random_bytes(16));$body['selling_price_inc_vat']=230;$new=rpm_save($pdo,$body,$settings);check($new['version']===2,'New version saved');
$body['request_id']=bin2hex(random_bytes(16));try{rpm_save($pdo,$body,$settings);throw new RuntimeException('Stale version accepted');}catch(DomainException $expected){}
check((int)$pdo->query('SELECT COUNT(*) FROM cw_retail_price_versions WHERE retail_price_id='.$saved['id'])->fetchColumn()===2,'History retained');
check((int)$pdo->query('SELECT COUNT(*) FROM cw_retail_prices WHERE product_id='.$pid)->fetchColumn()===3,'Three sizes, one master');
check($pdo->query('SELECT short_code FROM cw_cost_products WHERE id='.$pid)->fetchColumn()==='PRTEST','Canonical code preserved');
[$body,$saved]=$records[1];$body['request_id']=bin2hex(random_bytes(16));try{rpm_save($pdo,$body,$settings);throw new RuntimeException('Duplicate row accepted');}catch(DomainException $expected){}
$rows=rpm_rows($pdo,rpm_sources($pdo),$settings);check(count(array_filter($rows,fn($r)=>(int)$r['product_id']===$pid))===3,'No duplicate discovery rows');
echo "Pricing database saves, safe new sizes, source validation, duplicate protection, concurrency, immutable history and canonical identity PASS\n";

