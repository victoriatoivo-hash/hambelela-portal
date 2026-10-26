<?php
declare(strict_types=1);
define('BASE_PATH',dirname(__DIR__));
function current_user():array{return ['id'=>94,'name'=>'Disposable matching owner'];}
require BASE_PATH.'/shared/cost-workbook.php';
require BASE_PATH.'/shared/cost-workbook-pricing-master.php';
require BASE_PATH.'/shared/cost-workbook-pricing-autosave.php';
require BASE_PATH.'/shared/cost-workbook-pricing-discovery.php';
require_once BASE_PATH.'/shared/cost-workbook-website-pricing.php';
require BASE_PATH.'/shared/cost-workbook-cost-linking.php';
$api=file_get_contents(BASE_PATH.'/apps/cost-manager/retail-pricing-api.php');$start=strpos($api,'function rp_reply');$end=strpos($api,'try{$pdo=db()',$start);eval(substr($api,$start,$end-$start));
function check($v,string $m):void{if(!$v)throw new RuntimeException($m);}
function near($a,$b,string $m):void{check($a!==null&&abs($a-$b)<.00001,$m);}
function ins(PDO $pdo,string $table,array $data):int{$pdo->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')')->execute(array_values($data));return(int)$pdo->lastInsertId();}
function blocked(callable $f,string $message):void{try{$f();}catch(DomainException $e){return;}throw new RuntimeException($message);}
if(!getenv('CW_TEST_DSN'))exit;
$pdo=new PDO(getenv('CW_TEST_DSN'),getenv('CW_TEST_USER')?:'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);check($pdo->query('SELECT DATABASE()')->fetchColumn()==='cw_supplier_test','Disposable database only');cw_install_schema($pdo);rps_install($pdo);
$settings=['retail_vat_rate'=>15,'retail_low_margin_threshold'=>20];
$pid=ins($pdo,'cw_cost_products',['product_name'=>'Beeswax supplier fixture','normalized_name'=>'matchingfixture','short_code'=>'MATCH01','product_category'=>'Waxes','costing_basis'=>'weight','base_unit'=>'kg']);
$src=ins($pdo,'cw_cost_product_sources',['product_id'=>$pid,'source_type'=>'manual','cost_date'=>'2026-10-01','total_purchased_quantity'=>1,'total_supplier_cost'=>180]);
ins($pdo,'cw_landed_product_costs',['product_id'=>$pid,'product_source_id'=>$src,'costing_basis'=>'weight','base_unit'=>'kg','base_quantity'=>1,'supplier_cost_used'=>180,'transport_cost_used'=>20,'supplier_cost_per_base'=>180,'transport_cost_per_base'=>20,'landed_cost_per_base'=>200,'cost_date'=>'2026-10-01','status_key'=>'complete','calculation_hash'=>hash('sha256','matching fixture')]);
$batch=ins($pdo,'cw_sync_batches',['sync_uuid'=>'cost-linking-fixture','status'=>'completed','is_successful_snapshot'=>1,'completed_at'=>'2026-10-24 09:00:00']);
$parent=777001;$ids=[];
foreach([250,500] as $g){$q=$pdo->prepare("SELECT id FROM cw_size_conversions WHERE active=1 AND measurement_type='weight' AND base_value=? LIMIT 1");$q->execute([$g/1000]);$ids[$g]=(int)$q->fetchColumn();check($ids[$g]>0,'Size fixture available');}
foreach([250,500,999] as $g)ins($pdo,'cw_product_snapshots',['product_id'=>$parent,'variation_id'=>$g,'product_name'=>'White beeswax website fixture','variation_name'=>$g===999?'Unknown custom size':$g.'g','product_type'=>'variation','sync_batch_id'=>$batch,'stock_quantity'=>10,'manage_stock'=>1,'active_price_inc_vat'=>115]);
$draft=['row_key'=>'matching-web-draft','revision'=>0,'mutation_id'=>bin2hex(random_bytes(16)),'body'=>['website_origin'=>true,'manual_name'=>'White beeswax website fixture','manual_code'=>'WEB250','manual_category'=>'Natural Waxes','product_id'=>0,'size_conversion_id'=>$ids[250],'website_key'=>$parent.':250','website_confirmed'=>true,'selling_price_inc_vat'=>155,'packaging_mode'=>'manual','packaging_manual_cost'=>3,'packaging_confirmed'=>true,'effective_date'=>'2026-10-24']];
$old=rps_save($pdo,$draft,$settings);check($old['state']==='draft','Website-only row stays a draft');
$placeholder=['row_key'=>'matching-placeholder','revision'=>0,'mutation_id'=>bin2hex(random_bytes(16)),'body'=>['product_id'=>$pid,'size_conversion_id'=>'','selling_price_inc_vat'=>'','effective_date'=>'2026-10-24']];rps_save($pdo,$placeholder,$settings);
$siteBefore=$pdo->query('SELECT * FROM cw_product_snapshots ORDER BY id')->fetchAll();$historyBefore=$pdo->query('SELECT * FROM cw_retail_price_versions ORDER BY id')->fetchAll();$sourcesBefore=$pdo->query('SELECT * FROM cw_cost_product_sources ORDER BY id')->fetchAll();
$input=['product_id'=>$pid,'website_product_id'=>$parent];$plan=rpl_preview($pdo,$input,$settings);check(count($plan['rows'])===3,'All parent sizes previewed');
near($plan['rows'][0]['contents_cost'],50,'250g cost scales correctly');near($plan['rows'][1]['contents_cost'],100,'500g cost scales correctly');near($plan['rows'][0]['working_price'],155,'Existing draft price retained');check(!$plan['rows'][0]['packaging_missing'],'Existing manual packaging retained');check($plan['rows'][2]['contents_cost']===null,'Unknown size flagged, not invented');
blocked(fn()=>rpl_save($pdo,$input+['token'=>$plan['token']],$settings),'Unconfirmed match accepted');
blocked(fn()=>rpl_save($pdo,$input+['token'=>'stale','confirmed'=>true],$settings),'Stale preview accepted');
rpl_save($pdo,$input+['token'=>$plan['token'],'confirmed'=>true],$settings);
check($siteBefore===$pdo->query('SELECT * FROM cw_product_snapshots ORDER BY id')->fetchAll(),'Website data unchanged');check($historyBefore===$pdo->query('SELECT * FROM cw_retail_price_versions ORDER BY id')->fetchAll(),'Historical prices unchanged');check($sourcesBefore===$pdo->query('SELECT * FROM cw_cost_product_sources ORDER BY id')->fetchAll(),'Supplier purchase history unchanged');
$product=$pdo->query('SELECT * FROM cw_cost_products WHERE id='.$pid)->fetch();check((int)$product['woo_product_id']===$parent&&$product['product_name']==='Beeswax supplier fixture'&&$product['short_code']==='MATCH01','Canonical identity preserved with shared parent link');
$entries=rps_list($pdo,rpm_sources($pdo),$settings);$entry=array_values(array_filter($entries,fn($e)=>$e['row_key']==='matching-web-draft'))[0];check((int)$entry['body']['product_id']===$pid&&$entry['revision']===2,'Same draft linked and revision bumped');near($entry['body']['selling_price_inc_vat'],155,'Working price preserved');near($entry['row']['product_cost_per_unit'],50,'Cost flows after reload');near($entry['row']['total_cost_ex_vat'],53,'Packaging included once');near($entry['row']['profit_per_unit'],155/1.15-53,'Profit recalculated with VAT');
check(!array_filter($entries,fn($e)=>$e['row_key']==='matching-placeholder'),'Empty source placeholder archived');
$events=json_decode($pdo->query("SELECT history_json FROM cw_retail_sheet_drafts WHERE owner_id=94 AND row_key='matching-web-draft'")->fetchColumn(),true);check(count($events)===2&&$events[1]['action']==='supplier_cost_linked','Draft audit preserved');
blocked(fn()=>rpl_save($pdo,$input+['token'=>$plan['token'],'confirmed'=>true],$settings),'Old double-click applied twice');
$stale=$draft;$stale['revision']=1;$stale['mutation_id']=bin2hex(random_bytes(16));blocked(fn()=>rps_save($pdo,$stale,$settings),'Stale tab unlinked draft');
$stale['row_key']='matching-stale-500';$stale['revision']=0;$stale['body']['website_key']=$parent.':500';blocked(fn()=>rps_save($pdo,$stale,$settings),'Stale new draft bypassed explicit link');
$catalog=rpm_sources($pdo);$rows=rpd_merge(rpd_website_rows(rpm_rows($pdo,$catalog,$settings),$catalog,$settings),rps_list($pdo,$catalog,$settings));$linked=array_values(array_filter($rows,fn($r)=>strpos($r['website_key']??'',$parent.':')===0));check(count($linked)===3,'Exactly one row per website variation');foreach($linked as $r)check($r['product_name']==='White beeswax website fixture','Consistent website names group sizes');
// A confirmed website-origin row can save a financial version without changing its name.
$save=['row_key'=>$entry['row_key'],'revision'=>$entry['revision'],'mutation_id'=>bin2hex(random_bytes(16)),'body'=>$entry['body']];$save['body']['selling_price_inc_vat']=160;$save['body']['source_token']=$entry['row']['source_token'];$priced=rps_save($pdo,$save,$settings);check($priced['state']==='priced','Linked website-origin draft can become a priced row');check($priced['body']['manual_name']==='White beeswax website fixture','Display identity retained');
// Saved working price, not website price, is used when no owner draft remains.
$pdo->exec("UPDATE cw_retail_sheet_drafts SET active=0 WHERE owner_id=94 AND row_key='matching-web-draft'");$again=rpl_preview($pdo,$input,$settings);near($again['rows'][0]['working_price'],160,'Preview retains saved working price');
$other=ins($pdo,'cw_cost_products',['product_name'=>'Conflicting supplier fixture','normalized_name'=>'othermatching','short_code'=>'MATCH02','costing_basis'=>'weight','base_unit'=>'kg','woo_product_id'=>$parent]);blocked(fn()=>rpl_preview($pdo,$input,$settings),'Conflicting parent association accepted');$pdo->prepare('UPDATE cw_cost_products SET active=0 WHERE id=?')->execute([$other]);
$nonempty=$placeholder;$nonempty['row_key']='matching-entered-internal';$nonempty['mutation_id']=bin2hex(random_bytes(16));$nonempty['body']['selling_price_inc_vat']=99;rps_save($pdo,$nonempty,$settings);blocked(fn()=>rpl_preview($pdo,$input,$settings),'Entered standalone draft silently discarded');
check($siteBefore===$pdo->query('SELECT * FROM cw_product_snapshots ORDER BY id')->fetchAll(),'No WooCommerce prices or stock writes');

// Undo is previewed, race guarded, audited, and never deletes historical prices.
$versionsBeforeUndo=$pdo->query('SELECT * FROM cw_retail_price_versions ORDER BY id')->fetchAll();
$undo=rpl_unlink_preview($pdo,$input,$settings);near($undo['rows'][0]['working_price'],160,'Removal preview retains confirmed working price');
blocked(fn()=>rpl_unlink($pdo,$input+['confirmed'=>true,'token'=>'stale','reason'=>'Wrong grade'],$settings),'Stale removal accepted');
blocked(fn()=>rpl_unlink($pdo,$input+['confirmed'=>true,'token'=>$undo['token'],'reason'=>''],$settings),'Removal without reason accepted');
rpl_unlink($pdo,$input+['confirmed'=>true,'token'=>$undo['token'],'reason'=>'Wrong product/grade fixture'],$settings);
blocked(fn()=>rpl_unlink($pdo,$input+['confirmed'=>true,'token'=>$undo['token'],'reason'=>'Retry'],$settings),'Repeated removal accepted');
check($versionsBeforeUndo===$pdo->query('SELECT * FROM cw_retail_price_versions ORDER BY id')->fetchAll(),'Historical versions unchanged by removal');
check($sourcesBefore===$pdo->query('SELECT * FROM cw_cost_product_sources ORDER BY id')->fetchAll(),'Invoice and cost sources unchanged by removal');
$catalog=rpm_sources($pdo);$rows=rpd_merge(rpd_website_rows(rpm_rows($pdo,$catalog,$settings),$catalog,$settings),rps_list($pdo,$catalog,$settings));
$unlinked=array_values(array_filter($rows,fn($r)=>($r['website_key']??'')===$parent.':250'));check(count($unlinked)===1,'One website row after removal');near($unlinked[0]['selling_price_inc_vat'],160,'Saved price retained after refresh without an active owner draft');check(empty($unlinked[0]['product_id'])&&$unlinked[0]['product_cost_per_unit']===null&&$unlinked[0]['profit_per_unit']===null,'Wrong costs and margins cleared');
$skuSite=$catalog['website'][0];$skuSite['sku']='MATCH01';check(rpd_match($skuSite,$catalog)===null,'SKU cannot resurrect explicitly removed link');
$staleSaved=$save;$staleSaved['row_key']='stale-unlink-newkey';$staleSaved['revision']=0;$staleSaved['mutation_id']=bin2hex(random_bytes(16));$staleSaved['body']=$priced['body'];blocked(fn()=>rps_save($pdo,$staleSaved,$settings),'Stale saved row resurrected archived pricing');
$staleSaved['body']['id']=0;blocked(fn()=>rps_save($pdo,$staleSaved,$settings),'Stale unsaved tab restored removed supplier');
$pdo->exec("UPDATE cw_retail_sheet_drafts SET active=0 WHERE row_key='matching-entered-internal'");
$relink=rpl_preview($pdo,$input,$settings);near($relink['rows'][0]['working_price'],160,'Relinking preview retains internal price');rpl_save($pdo,$input+['token'=>$relink['token'],'confirmed'=>true],$settings);
$catalog=rpm_sources($pdo);$rows=rpd_website_rows(rpm_rows($pdo,$catalog,$settings),$catalog,$settings);$relinked=array_values(array_filter($rows,fn($r)=>($r['website_key']??'')===$parent.':250'));near($relinked[0]['selling_price_inc_vat'],160,'Working price retained after re-link');near($relinked[0]['product_cost_per_unit'],50,'Costs recalculated after new explicit confirmation');
check($siteBefore===$pdo->query('SELECT * FROM cw_product_snapshots ORDER BY id')->fetchAll(),'Undo and relink never write website prices or stock');
$body=$relinked[0]['sheet_body'];$body['source_token']=$relinked[0]['source_token'];$body['effective_date']=rpm_today();$resaved=rps_save($pdo,['row_key'=>'relinked-confirmed-price','revision'=>0,'mutation_id'=>bin2hex(random_bytes(16)),'body'=>$body],$settings);check($resaved['state']==='priced','Reconfirmed identity can save despite historical unique key');check((int)$resaved['body']['id']===(int)$priced['body']['id'],'Original financial identity reused with a new version');
$pdo->prepare('UPDATE cw_product_snapshots SET product_name=? WHERE product_id=?')->execute(['Beeswax Fragrance',$parent]);$warning=rpl_preview($pdo,$input,$settings);check(strpos($warning['warning'],'mismatch')!==false,'Fragrance grade mismatch warned');

// Volume sizes keep their label and packaging after removal, including other owners' drafts.
$vpid=ins($pdo,'cw_cost_products',['product_name'=>'Essential oil fixture','normalized_name'=>'oilundofixture','short_code'=>'OILUNDO','costing_basis'=>'volume','base_unit'=>'L','woo_product_id'=>777002]);
$vsrc=ins($pdo,'cw_cost_product_sources',['product_id'=>$vpid,'source_type'=>'manual','cost_date'=>'2026-10-01','total_purchased_quantity'=>1,'total_supplier_cost'=>200]);
ins($pdo,'cw_landed_product_costs',['product_id'=>$vpid,'product_source_id'=>$vsrc,'costing_basis'=>'volume','base_unit'=>'L','base_quantity'=>1,'supplier_cost_used'=>200,'supplier_cost_per_base'=>200,'landed_cost_per_base'=>200,'cost_date'=>'2026-10-01','status_key'=>'complete','calculation_hash'=>hash('sha256','volume undo fixture')]);
ins($pdo,'cw_product_snapshots',['product_id'=>777002,'variation_id'=>50,'product_name'=>'Oil website','variation_name'=>'50ml','product_type'=>'variation','sync_batch_id'=>$batch,'active_price_inc_vat'=>100]);
$sid=(int)$pdo->query("SELECT id FROM cw_size_conversions WHERE active=1 AND measurement_type='volume' AND base_value=0.05 LIMIT 1")->fetchColumn();check($sid>0,'50ml fixture exists');
$vb=['product_id'=>$vpid,'website_key'=>'777002:50','website_confirmed'=>true,'website_origin'=>true,'size_conversion_id'=>$sid,'packaging_mode'=>'manual','packaging_manual_cost'=>5.89,'packaging_confirmed'=>true,'selling_price_inc_vat'=>110,'effective_date'=>rpm_today()];$vb['source_token']=rpm_preview(rpm_sources($pdo),$settings,$vb)['source_token'];
$vr=rps_save($pdo,['row_key'=>'volume-undo-draft','revision'=>0,'mutation_id'=>bin2hex(random_bytes(16)),'body'=>$vb],$settings);check($vr['state']==='priced','Volume price saved');
ins($pdo,'cw_retail_sheet_drafts',['owner_id'=>95,'row_key'=>'other-owner-volume','payload_json'=>json_encode(array_merge($vb,['selling_price_inc_vat'=>125])),'history_json'=>'[]']);
$vu=['product_id'=>$vpid,'website_product_id'=>777002];$vp=rpl_unlink_preview($pdo,$vu,$settings);rpl_unlink($pdo,$vu+['token'=>$vp['token'],'confirmed'=>true,'reason'=>'Volume fixture correction'],$settings);
$s=rpm_sources($pdo);$entries=rps_list($pdo,$s,$settings);$vrow=array_values(array_filter($entries,fn($e)=>$e['row_key']==='volume-undo-draft'))[0]['row'];check(strpos($vrow['size_label'],'50')!==false,'Volume size not lost on removal');near($vrow['packaging_cost_ex_vat'],5.89,'Packaging kept when volume source removed');check($vrow['profit_per_unit']===null,'No profit computed from packaging alone');
$otherBody=json_decode($pdo->query("SELECT payload_json FROM cw_retail_sheet_drafts WHERE owner_id=95 AND row_key='other-owner-volume'")->fetchColumn(),true);near($otherBody['selling_price_inc_vat'],125,'Other owner working price retained');check(empty($otherBody['product_id'])&&$otherBody['manual_basis']==='volume','Other owner wrong source detached');
// Regression: an internal 500ml price must coexist with website-only 50ml/100ml sizes.
$fpid=ins($pdo,'cw_cost_products',['product_name'=>'Lemongrass fragrance fixture','normalized_name'=>'fragranceseparatesizes','short_code'=>'LFTEST','costing_basis'=>'volume','base_unit'=>'L']);
$fsrc=ins($pdo,'cw_cost_product_sources',['product_id'=>$fpid,'source_type'=>'manual','cost_date'=>'2026-10-01','total_purchased_quantity'=>1,'total_supplier_cost'=>461.8]);
ins($pdo,'cw_landed_product_costs',['product_id'=>$fpid,'product_source_id'=>$fsrc,'costing_basis'=>'volume','base_unit'=>'L','base_quantity'=>1,'supplier_cost_used'=>461.8,'supplier_cost_per_base'=>461.8,'landed_cost_per_base'=>461.8,'cost_date'=>'2026-10-01','status_key'=>'complete','calculation_hash'=>hash('sha256','distinct internal size fixture')]);
$fids=[];foreach([50,100,500] as $ml){$q=$pdo->prepare("SELECT id FROM cw_size_conversions WHERE active=1 AND measurement_type='volume' AND base_value=? LIMIT 1");$q->execute([$ml/1000]);$fids[$ml]=(int)$q->fetchColumn();check($fids[$ml]>0,'Volume fixture available');}
foreach([50=>110,100=>210] as $ml=>$price){
    ins($pdo,'cw_product_snapshots',['product_id'=>777003,'variation_id'=>$ml,'product_name'=>'Lemongrass Fragrance fixture','variation_name'=>$ml.'ml','product_type'=>'variation','sync_batch_id'=>$batch,'active_price_inc_vat'=>$price]);
    rps_save($pdo,['row_key'=>'fragrance-web-'.$ml,'revision'=>0,'mutation_id'=>bin2hex(random_bytes(16)),'body'=>['website_origin'=>true,'manual_name'=>'Lemongrass Fragrance fixture','manual_basis'=>'volume','product_id'=>0,'size_conversion_id'=>$fids[$ml],'website_key'=>'777003:'.$ml,'website_confirmed'=>true,'selling_price_inc_vat'=>$price,'packaging_mode'=>'manual','packaging_manual_cost'=>$ml===50?5.89:4.76,'packaging_confirmed'=>true,'effective_date'=>rpm_today()]],$settings);
}
$fb=['product_id'=>$fpid,'size_conversion_id'=>$fids[500],'website_key'=>'','selling_price_inc_vat'=>650,'packaging_mode'=>'manual','packaging_manual_cost'=>8.19,'packaging_confirmed'=>true,'effective_date'=>rpm_today()];
$fb['source_token']=rpm_preview(rpm_sources($pdo),$settings,$fb)['source_token'];
$fr=rps_save($pdo,['row_key'=>'fragrance-internal-500','revision'=>0,'mutation_id'=>bin2hex(random_bytes(16)),'body'=>$fb],$settings);check($fr['state']==='priced','Internal 500ml is a saved financial row');
$fi=['product_id'=>$fpid,'website_product_id'=>777003];$fp=rpl_preview($pdo,$fi,$settings);check(count($fp['rows'])===2,'Only website sizes previewed');near($fp['rows'][0]['contents_cost'],23.09,'50ml scaled contents');near($fp['rows'][1]['contents_cost'],46.18,'100ml scaled contents');
$priceBefore=$pdo->query('SELECT * FROM cw_retail_prices WHERE id='.(int)$fr['body']['id'])->fetch();
$draftBefore=$pdo->query("SELECT * FROM cw_retail_sheet_drafts WHERE row_key='fragrance-internal-500'")->fetch();
$allVersionsBefore=$pdo->query('SELECT * FROM cw_retail_price_versions ORDER BY id')->fetchAll();$allSitesBefore=$pdo->query('SELECT * FROM cw_product_snapshots ORDER BY id')->fetchAll();
// An overlapping draft still blocks, and changes to preserved drafts invalidate previews.
$overlap=ins($pdo,'cw_retail_sheet_drafts',['owner_id'=>95,'row_key'=>'fragrance-overlap','payload_json'=>json_encode(array_merge($fb,['size_conversion_id'=>$fids[50]])),'history_json'=>'[]']);
blocked(fn()=>rpl_preview($pdo,$fi,$settings),'Overlapping internal size accepted');$pdo->exec('UPDATE cw_retail_sheet_drafts SET active=0 WHERE id='.$overlap);
$pdo->exec("UPDATE cw_retail_sheet_drafts SET revision=revision+1 WHERE row_key='fragrance-internal-500'");
blocked(fn()=>rpl_save($pdo,$fi+['token'=>$fp['token'],'confirmed'=>true],$settings),'Preserved internal draft change did not invalidate preview');
$draftBefore=$pdo->query("SELECT * FROM cw_retail_sheet_drafts WHERE row_key='fragrance-internal-500'")->fetch();$fp=rpl_preview($pdo,$fi,$settings);
rpl_save($pdo,$fi+['token'=>$fp['token'],'confirmed'=>true],$settings);
check($priceBefore===$pdo->query('SELECT * FROM cw_retail_prices WHERE id='.(int)$fr['body']['id'])->fetch(),'Internal saved price unchanged byte-for-byte');
check($draftBefore===$pdo->query("SELECT * FROM cw_retail_sheet_drafts WHERE row_key='fragrance-internal-500'")->fetch(),'Internal draft unchanged byte-for-byte');
check($allVersionsBefore===$pdo->query('SELECT * FROM cw_retail_price_versions ORDER BY id')->fetchAll(),'All financial versions unchanged by matching');
check($allSitesBefore===$pdo->query('SELECT * FROM cw_product_snapshots ORDER BY id')->fetchAll(),'All website data unchanged');
$fc=rpm_sources($pdo);$frows=rpd_merge(rpd_website_rows(rpm_rows($pdo,$fc,$settings),$fc,$settings),rps_list($pdo,$fc,$settings));$frows=array_values(array_filter($frows,fn($r)=>(int)($r['product_id']??0)===$fpid));check(count($frows)===3,'Exactly three distinct sizes after reload');
$internal=array_values(array_filter($frows,fn($r)=>empty($r['website_key'])));check(count($internal)===1,'500ml remains standalone');near($internal[0]['selling_price_inc_vat'],650,'500ml working price retained');near($internal[0]['packaging_cost_ex_vat'],8.19,'500ml packaging retained');near($internal[0]['product_cost_per_unit'],230.9,'500ml contents retained');
foreach([50=>110,100=>210] as $ml=>$price){$r=array_values(array_filter($frows,fn($r)=>($r['website_key']??'')==='777003:'.$ml));check(count($r)===1,'One row per website size');near($r[0]['selling_price_inc_vat'],$price,'Website row working price retained');near($r[0]['total_cost_ex_vat'],461.8*$ml/1000+($ml===50?5.89:4.76),'Website total includes existing packaging exactly once');}
// A real overlapping saved size also blocks, independently of sheet drafts.
$pdo->exec('UPDATE cw_retail_prices SET size_conversion_id='.$fids[50].' WHERE id='.(int)$fr['body']['id']);blocked(fn()=>rpl_preview($pdo,$fi,$settings),'Overlapping saved internal size accepted');
$pdo->exec('UPDATE cw_retail_prices SET size_conversion_id='.$fids[500].' WHERE id='.(int)$fr['body']['id']);
echo "Supplier matching: separate internal sizes retained, overlapping sizes blocked, preview races, sizes, costs, drafts, history, duplicate protection and no website writes PASS\n";
