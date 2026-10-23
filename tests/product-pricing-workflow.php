<?php
declare(strict_types=1);
define('BASE_PATH',dirname(__DIR__));
function current_user():array{return ['id'=>93,'name'=>'Pricing test owner'];}
require BASE_PATH.'/shared/cost-workbook.php';
require BASE_PATH.'/shared/cost-workbook-pricing-master.php';
require BASE_PATH.'/shared/cost-workbook-pricing-autosave.php';require BASE_PATH.'/shared/cost-workbook-pricing-discovery.php';require BASE_PATH.'/shared/cost-workbook-website-pricing.php';
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
$unconfirmed=$b;$unconfirmed['vat_treatment']='';check(rpm_preview($s,$settings,$unconfirmed)['projected_profit']===null,'Unconfirmed VAT not used for projected profit');
$missing=$s;$missing['products'][0]['landed_status']='review';check(rpm_preview($missing,$settings,$b)['total_cost_ex_vat']===null,'Missing source not zero');
echo "Pricing three-size calculations, VAT, margins, stock and incomplete sources PASS\n";
$manual=['product_id'=>1,'size_conversion_id'=>1,'website_key'=>'','selling_price_inc_vat'=>115,'vat_treatment'=>'standard','packaging_mode'=>'manual','packaging_manual_cost'=>'2.50','packaging_confirmed'=>false];
$r=rpm_preview($s,$settings,$manual);near($r['known_cost_ex_vat'],27.5,'Manual packaging added once');check($r['total_cost_ex_vat']===null,'Estimate excluded from confirmed cost');check($r['status']==='Packaging Estimate','Unconfirmed cost remains an estimate');check($r['website_status']==='Not Linked'&&$r['stock_cost']===null,'Unlinked is not a pricing error or zero stock');
$manual['packaging_confirmed']=true;$r=rpm_preview($s,$settings,$manual);check($r['status']==='Pricing Complete','Unlinked price can be complete');$manual['packaging_manual_cost']=2.5;check(rpm_preview($s,$settings,$manual)['source_token']===$r['source_token'],'Numeric packaging token survives JSON round trip');
$manual['packaging_mode']='none';$manual['packaging_confirmed']=false;check(rpm_preview($s,$settings,$manual)['total_cost_ex_vat']===null,'Unconfirmed no packaging is unknown');$manual['packaging_confirmed']=true;near(rpm_preview($s,$settings,$manual)['total_cost_ex_vat'],25,'Explicit not applicable packaging is zero');
echo "Independent packaging choices, optional website link and status separation PASS\n";
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
$stale=['product_id'=>$pid,'size_conversion_id'=>$ids[0],'website_key'=>'12345:1','website_confirmed'=>1,'selling_price_inc_vat'=>115,'vat_treatment'=>'standard','effective_date'=>'2026-10-21','request_id'=>bin2hex(random_bytes(16)),'source_token'=>'stale'];
try{rpm_save($pdo,$stale,$settings);throw new RuntimeException('Changed source accepted');}catch(DomainException $expected){check(strpos($expected->getMessage(),'Source costs')!==false,'Source conflict surfaced');}
check((int)$pdo->query('SELECT COUNT(*) FROM cw_retail_prices WHERE product_id='.$pid)->fetchColumn()===0,'Stale source rollback');

foreach($ids as $i=>$size){$body=['product_id'=>$pid,'size_conversion_id'=>$size,'website_key'=>'12345:'.($i+1),'website_confirmed'=>1,'selling_price_inc_vat'=>115,'vat_treatment'=>'standard','effective_date'=>'2026-10-21','request_id'=>bin2hex(random_bytes(16))];$preview=rpm_preview($sources,$settings,$body);$body['source_token']=$preview['source_token'];$saved=rpm_save($pdo,$body,$settings);check(rpm_save($pdo,$body,$settings)['replayed'],'Idempotent retry');$records[]=[$body,$saved];}
[$body,$saved]=$records[0];$body['id']=$saved['id'];$body['version']=1;$body['request_id']=bin2hex(random_bytes(16));$body['selling_price_inc_vat']=230;$new=rpm_save($pdo,$body,$settings);check($new['version']===2,'New version saved');
$body['request_id']=bin2hex(random_bytes(16));try{rpm_save($pdo,$body,$settings);throw new RuntimeException('Stale version accepted');}catch(DomainException $expected){}
check((int)$pdo->query('SELECT COUNT(*) FROM cw_retail_price_versions WHERE retail_price_id='.$saved['id'])->fetchColumn()===2,'History retained');
check((int)$pdo->query('SELECT COUNT(*) FROM cw_retail_prices WHERE product_id='.$pid)->fetchColumn()===3,'Three sizes, one master');
check($pdo->query('SELECT short_code FROM cw_cost_products WHERE id='.$pid)->fetchColumn()==='PRTEST','Canonical code preserved');
[$body,$saved]=$records[1];$body['request_id']=bin2hex(random_bytes(16));try{rpm_save($pdo,$body,$settings);throw new RuntimeException('Duplicate row accepted');}catch(DomainException $expected){}
$rows=rpm_rows($pdo,rpm_sources($pdo),$settings);check(count(array_filter($rows,fn($r)=>(int)$r['product_id']===$pid))===3,'No duplicate discovery rows');
echo "Pricing database saves, safe new sizes, source validation, duplicate protection, concurrency, immutable history and canonical identity PASS\n";

// Disposable acceptance fixture only: these are not authorised live selling prices.
$lemon=ins($pdo,'cw_cost_products',['product_name'=>'Lemon Balm fixture','normalized_name'=>'lemonbalmfixture','short_code'=>'LB001','product_category'=>'Dried Herbs','costing_basis'=>'weight','base_unit'=>'kg']);
$src=ins($pdo,'cw_cost_product_sources',['product_id'=>$lemon,'source_type'=>'manual','cost_date'=>'2026-10-01','total_purchased_quantity'=>1,'total_supplier_cost'=>400]);
ins($pdo,'cw_landed_product_costs',['product_id'=>$lemon,'product_source_id'=>$src,'costing_basis'=>'weight','base_unit'=>'kg','base_quantity'=>1,'supplier_cost_used'=>400,'transport_cost_used'=>22.4,'supplier_cost_per_base'=>400,'transport_cost_per_base'=>22.4,'landed_cost_per_base'=>422.4,'cost_date'=>'2026-10-01','status_key'=>'complete','calculation_hash'=>hash('sha256','lemon pricing fixture')]);
$lemonSaved=[];
foreach([50,100,250] as $grams){$pdo->prepare("INSERT IGNORE INTO cw_size_conversions(label,measurement_type,quantity,unit,base_value,base_unit) VALUES(?,'weight',?,'g',?,'kg')")->execute([$grams.'g',$grams,$grams/1000]);$q=$pdo->prepare("SELECT id FROM cw_size_conversions WHERE measurement_type='weight' AND base_value=? LIMIT 1");$q->execute([$grams/1000]);$sid=(int)$q->fetchColumn();
 $b=['product_id'=>$lemon,'size_conversion_id'=>$sid,'website_key'=>'','selling_price_inc_vat'=>$grams*2,'vat_treatment'=>'standard','packaging_mode'=>'manual','packaging_manual_cost'=>3,'packaging_confirmed'=>true,'effective_date'=>'2026-10-21','request_id'=>bin2hex(random_bytes(16))];$p=rpm_preview(rpm_sources($pdo),$settings,$b);$b['source_token']=$p['source_token'];$saved=rpm_save($pdo,$b,$settings);$lemonSaved[$grams]=[$b,$saved];
 near($p['product_cost_per_unit'],422.4*$grams/1000,'Actual size conversion');
}
$lemonRows=array_values(array_filter(rpm_rows($pdo,rpm_sources($pdo),$settings),fn($r)=>$r['product_id']===$lemon));check(count($lemonRows)===3,'Three unlinked sizes persist');foreach($lemonRows as $r){check($r['status']==='Pricing Complete'&&$r['website_status']==='Not Linked','Unlinked rows complete after reload');near($r['packaging_cost_ex_vat'],3,'Manual packaging survives reload');check($r['website_stock_quantity']===null,'Unlinked stock stays unknown');}
$sum=rpm_summary($lemonRows);check($sum['rows']===3&&$sum['priced']===3&&$sum['attention']===0&&$sum['coverage']['inventory_cost']===0,'Unlinked saved widgets');
ins($pdo,'cw_product_snapshots',['product_id'=>98765,'variation_id'=>50,'product_name'=>'Lemon Balm','variation_name'=>'50g','product_type'=>'variation','sync_batch_id'=>$batch,'stock_quantity'=>12,'manage_stock'=>1,'active_price_inc_vat'=>110]);
[$b,$saved]=$lemonSaved[50];$b['id']=$saved['id'];$b['version']=1;$b['website_key']='98765:50';$b['website_confirmed']=true;$b['request_id']=bin2hex(random_bytes(16));$b['source_token']=rpm_preview(rpm_sources($pdo),$settings,$b)['source_token'];rpm_save($pdo,$b,$settings);
$lemonRows=array_values(array_filter(rpm_rows($pdo,rpm_sources($pdo),$settings),fn($r)=>$r['product_id']===$lemon));$linked=array_values(array_filter($lemonRows,fn($r)=>$r['website_key']!==''));check(count($linked)===1,'Only selected size linked');near($linked[0]['selling_price_inc_vat'],100,'Workbook price retained');near($linked[0]['retail_value'],1320,'Actual website price used for valuation');check($linked[0]['website_price_differs'],'Price difference flagged');
echo "Lemon Balm fixture: 50g/100g/250g unlinked save, reload, packaging, canonical identity, widgets and optional 50g website link PASS\n";

rps_install($pdo);
$websiteBefore=$pdo->query('SELECT * FROM cw_product_snapshots ORDER BY id')->fetchAll();
$countBefore=(int)$pdo->query('SELECT COUNT(*) FROM cw_retail_price_versions')->fetchColumn();
$draft=['row_key'=>'test-sheet-'.bin2hex(random_bytes(8)),'revision'=>0,'mutation_id'=>bin2hex(random_bytes(16)),'body'=>['product_id'=>$lemon,'size_conversion_id'=>'','selling_price_inc_vat'=>'','vat_treatment'=>'','effective_date'=>'2026-10-23']];
$partial=rps_save($pdo,$draft,$settings);check($partial['state']==='draft'&&$partial['revision']===1,'Incomplete row persists as draft');
check((int)$pdo->query('SELECT COUNT(*) FROM cw_retail_price_versions')->fetchColumn()===$countBefore,'Incomplete draft does not create financial version');
check(json_encode(rps_save($pdo,$draft,$settings))===json_encode($partial),'Lost-response retry idempotent');
$stale=$draft;$stale['mutation_id']=bin2hex(random_bytes(16));$stale['body']['selling_price_inc_vat']=20;
try{rps_save($pdo,$stale,$settings);throw new RuntimeException('Stale draft overwrote newer revision');}catch(DomainException $expected){}
$entries=rps_list($pdo,rpm_sources($pdo),$settings);$restored=array_values(array_filter($entries,fn($r)=>$r['row_key']===$draft['row_key']))[0];check($restored['body']['size_conversion_id']===''&&$restored['body']['selling_price_inc_vat']==='','Blank fields survive reload');
$draft['revision']=1;$draft['mutation_id']=bin2hex(random_bytes(16));[$valid,$priorSaved]=$lemonSaved[100];$valid['id']=$priorSaved['id'];$valid['version']=1;$valid['selling_price_inc_vat']=230;$valid['source_token']=rpm_preview(rpm_sources($pdo),$settings,$valid)['source_token'];$draft['body']=$valid;
$complete=rps_save($pdo,$draft,$settings);check($complete['state']==='priced'&&$complete['body']['version']===2,'Autosave creates audited financial update atomically');
check(json_encode(rps_save($pdo,$draft,$settings))===json_encode($complete),'Complete retry creates no extra version');
$draft['revision']=2;$draft['mutation_id']=bin2hex(random_bytes(16));$draft['body']=$complete['body'];$draft['body']['selling_price_inc_vat']='';$incomplete=rps_save($pdo,$draft,$settings);check($incomplete['state']==='draft','Clearing existing price retains incomplete draft');
$q=$pdo->prepare('SELECT current_version FROM cw_retail_prices WHERE id=?');$q->execute([$priorSaved['id']]);check((int)$q->fetchColumn()===2,'Incomplete correction preserves last valid history');
$duplicate=['row_key'=>'duplicate-'.bin2hex(random_bytes(8)),'revision'=>0,'mutation_id'=>bin2hex(random_bytes(16)),'body'=>$valid];$duplicate['body']['id']=0;$duplicate['body']['version']=0;
$dup=rps_save($pdo,$duplicate,$settings);check($dup['state']==='draft'&&strpos($dup['note'],'already exists')!==false,'Duplicate financial identity retained for review, never duplicated');
check($websiteBefore===$pdo->query('SELECT * FROM cw_product_snapshots ORDER BY id')->fetchAll(),'Autosave never changes website data or prices');
echo "Autosave persistence, partial drafts, reload, revision conflicts, idempotent retries, valid history and no Woo changes PASS\n";

// Website-only and manual rows are editable without manufacturing supplier records.
$catalog=$s;$catalog['products']=[];$catalog['setups']=[];
$catalog['website'][0]['website_tax_status']='taxable';$catalog['website'][0]['website_tax_class']='';
$discovered=rpd_website_rows([],$catalog,$settings);check(count($discovered)===3,'Every unmatched website variation discovered');
check(count(rpd_website_rows($discovered,$catalog,$settings))===3,'Discovery is idempotent');
$unmatched=$discovered[0];check($unmatched['product_id']===0&&$unmatched['website_link_status']==='Linked'&&$unmatched['cost_status']==='Cost Not Matched','Website link is not cost evidence');
near($unmatched['selling_price_ex_vat'],100,'Website default VAT metadata');check($unmatched['profit_per_unit']===null,'No invented zero-cost margin');
$partialSource=$s;$partialSource['setups']=[];$calc=rpm_preview($partialSource,$settings,['product_id'=>1,'size_conversion_id'=>1,'selling_price_inc_vat'=>115,'vat_treatment'=>'standard']);
near($calc['known_cost_ex_vat'],25,'Known contents subtotal');near($calc['profit_per_unit'],75,'Provisional profit');check($calc['provisional']&&$calc['total_cost_ex_vat']===null&&$calc['stock_cost']===null,'Provisional never confirmed valuation');
$named=$catalog;$named['products']=$s['products'];$named['products'][0]['product_name']=$catalog['website'][0]['product_name'];check(rpd_match($catalog['website'][0],$named)===null,'Name alone never matches supplier');
$named['products'][0]['woo_sku']=$catalog['website'][0]['sku'];check(rpd_match($catalog['website'][0],$named)!==null,'Exact SKU and compatible size match');
$named['products'][]=array_merge($named['products'][0],['id'=>2]);check(rpd_match($catalog['website'][0],$named)===null,'Ambiguous code does not auto-match');
$manualRow=['row_key'=>'manual-new-product','revision'=>0,'mutation_id'=>bin2hex(random_bytes(16)),'body'=>['manual_name'=>'Disposable new product','manual_code'=>'NEW-TEST','manual_category'=>'Fixture','manual_basis'=>'weight','product_id'=>0,'size_conversion_id'=>$ids[0],'selling_price_inc_vat'=>115,'vat_treatment'=>'standard','packaging_mode'=>'manual','packaging_manual_cost'=>2,'packaging_confirmed'=>true,'effective_date'=>'2026-10-23']];
$a=rps_save($pdo,$manualRow,$settings);check($a['row']['product_name']==='Disposable new product'&&$a['state']==='draft','Manual product saves without Woo or source');
$manualRow['revision']=$a['revision'];$manualRow['mutation_id']=bin2hex(random_bytes(16));$manualRow['body']=$a['body'];$manualRow['body']['website_key']='12345:999';
// A missing or obsolete website link is retained for review, never published.
$review=rps_save($pdo,$manualRow,$settings);check($review['state']==='draft','Invalid website association does not delete internal row');
check($websiteBefore===$pdo->query('SELECT * FROM cw_product_snapshots ORDER BY id')->fetchAll(),'New workbook paths never write Woo snapshot');
echo "Unmatched catalogue import, independent website/cost matching, provisional margins, manual autosave and no website writes PASS\n";

