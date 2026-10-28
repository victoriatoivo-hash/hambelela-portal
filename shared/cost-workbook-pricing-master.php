<?php
declare(strict_types=1);
require_once __DIR__.'/cost-workbook-website-pricing.php';

function rpm_apply_vat(array $body):array { return array_merge($body,cwp_vat([],[])); }

function rpm_today():string{return (new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');}
function rpm_sources(PDO $pdo):array {
    $today=rpm_today();
    $q=$pdo->prepare("SELECT p.*,c.id landed_cost_id,c.landed_cost_per_base,c.supplier_cost_per_base,c.transport_cost_per_base,c.cost_date,c.status_key landed_status,s.supplier_name,s.id source_id FROM cw_cost_products p LEFT JOIN cw_landed_product_costs c ON c.id=(SELECT c2.id FROM cw_landed_product_costs c2 JOIN cw_cost_product_sources s2 ON s2.id=c2.product_source_id LEFT JOIN cw_supplier_invoice_lines l ON l.id=s2.supplier_invoice_line_id LEFT JOIN cw_supplier_invoices i ON i.id=l.supplier_invoice_id WHERE c2.product_id=p.id AND s2.active=1 AND (l.id IS NULL OR (l.active=1 AND i.approval_status='approved')) AND c2.id=(SELECT x.id FROM cw_landed_product_costs x WHERE x.product_source_id=c2.product_source_id ORDER BY x.calculation_version DESC,x.id DESC LIMIT 1) AND c2.cost_date<=? ORDER BY c2.cost_date DESC,c2.id DESC LIMIT 1) LEFT JOIN cw_cost_product_sources s ON s.id=c.product_source_id WHERE p.active=1 ORDER BY p.product_name");$q->execute([$today]);$products=$q->fetchAll();
    $references=$pdo->query("SELECT s.product_id,s.manual_reference,l.supplier_sku FROM cw_cost_product_sources s LEFT JOIN cw_supplier_invoice_lines l ON l.id=s.supplier_invoice_line_id WHERE s.active=1")->fetchAll();
    foreach($products as &$product){$product['supplier_codes']=array_values(array_filter(array_column(array_filter($references,function($r)use($product){return (int)$r['product_id']===(int)$product['id'];}),'supplier_sku')));$product['supplier_references']=implode(' ',array_map(function($r){return trim(($r['manual_reference']??'').' '.($r['supplier_sku']??''));},array_filter($references,function($r)use($product){return (int)$r['product_id']===(int)$product['id'];})));}unset($product);
    $sizes=$pdo->query('SELECT * FROM cw_size_conversions WHERE active=1 ORDER BY measurement_type,base_value')->fetchAll();
    $materials=[];foreach($pdo->query('SELECT i.*,t.name packaging_type FROM cw_packaging_items i JOIN cw_packaging_types t ON t.id=i.packaging_type_id WHERE i.active=1 ORDER BY i.item_name')->fetchAll() as $item){$cost=cw_packaging_cost($pdo,(int)$item['id'],$today);$materials[]=array_merge($item,['cost_id'=>$cost['id']??null,'cost_ex_vat'=>$cost['landed_ex']??null,'cost_inc_vat'=>$cost['landed_inc']??null,'effective_date'=>$cost['effective_date']??null]);}
    $setups=cw_packaging_current_setups($pdo,$today);$batch=rp_latest_snapshot_id($pdo);$website=[];$lastSync=null;
    if($batch){$q=$pdo->prepare("SELECT * FROM cw_product_snapshots WHERE sync_batch_id=? AND (variation_id>0 OR product_type<>'variable') ORDER BY product_name,variation_id");$q->execute([$batch]);$website=$q->fetchAll();$q=$pdo->prepare('SELECT completed_at FROM cw_sync_batches WHERE id=?');$q->execute([$batch]);$lastSync=$q->fetchColumn();}
    // Explicit removals outrank SKU suggestions. Keep confirmed working values available
    // to every owner without rewriting the historical financial versions.
    $unlinked=[];$retained=[];
    foreach($pdo->query("SELECT after_json FROM cw_cost_audit_events WHERE entity_type='cost_product' AND action_key='website_cost_unlinked' ORDER BY id")->fetchAll() as $event){
        $a=json_decode($event['after_json'],true)?:[];$parent=(int)($a['website_product_id']??0);
        if($parent){$unlinked[$parent]=true;foreach(($a['retained_bodies']??[]) as $key=>$body)$retained[$key]=$body;}
    }
    foreach($website as &$site){$key=$site['product_id'].':'.$site['variation_id'];if(isset($retained[$key]))$site['retained_body']=$retained[$key];}unset($site);
    return compact('products','sizes','setups','materials','website','batch','lastSync','unlinked');
}
function rpm_find(array $rows,string $key,$value):?array{foreach($rows as $row)if((string)($row[$key]??'')===(string)$value)return $row;return null;}
function rpm_sizes(array $sources,array $product):array {
    return array_values(array_filter($sources['sizes'],function($s)use($product){return ($s['measurement_type']===$product['costing_basis'])||($product['costing_basis']==='ready_made'&&$s['measurement_type']==='count');}));
}
function rpm_setup(array $sources,array $product,int $sizeId):?array {
    $matches=array_values(array_filter($sources['setups'],function($s)use($product,$sizeId){return (int)$s['size_conversion_id']===$sizeId&&rp_setup_applies($s,$product);}));
    usort($matches,function($a,$b){$rank=['product'=>0,'category'=>1,'default'=>2];return ($rank[$a['scope_type']]??0)<=>($rank[$b['scope_type']]??0);});
    return $matches[0]??null;
}
function rpm_site_size(array $site,array $sizes):?int {
    $text=strtolower((string)$site['variation_name'].' '.(string)$site['attributes']);$found=[];
    preg_match_all('/(?<![\d.])(\d+(?:\.\d+)?)\s*(kg|g|ml|l)(?![a-z])/i',$text,$tokens,PREG_SET_ORDER);
    foreach($tokens as $t){$unit=strtolower($t[2]);$basis=in_array($unit,['g','kg'],true)?'weight':'volume';$base=(float)$t[1]/(in_array($unit,['g','ml'],true)?1000:1);foreach($sizes as $s)if($s['measurement_type']===$basis&&abs((float)$s['base_value']-$base)<0.000001)$found[(int)$s['id']]=true;}
    return count($found)===1?(int)array_key_first($found):null;
}
function rpm_preview(array $sources,array $settings,array $b):array {
    $b=rpm_apply_vat($b);
    $p=rpm_find($sources['products'],'id',$b['product_id']??0);
    $parent=(int)explode(':',(string)($b['website_key']??''))[0];
    if($p&&!empty($sources['unlinked'][$parent])&&(int)($p['woo_product_id']??0)!==$parent)throw new DomainException('This supplier link was removed. Select and confirm the correct supplier link before using its cost.');
    if(!$p){
        if(!empty($b['product_id']))throw new DomainException('This supplier product is no longer available. Choose a current cost source.');
        $p=['id'=>0,'product_name'=>trim((string)($b['manual_name']??'')),'short_code'=>trim((string)($b['manual_code']??'')),'product_category'=>trim((string)($b['manual_category']??'')),'costing_basis'=>$b['manual_basis']??'weight','base_unit'=>'','landed_cost_id'=>null,'landed_status'=>'unmatched','landed_cost_per_base'=>null,'supplier_name'=>'','cost_date'=>null,'supplier_cost_per_base'=>null,'transport_cost_per_base'=>null];
    }
    $size=rpm_find(rpm_sizes($sources,$p),'id',$b['size_conversion_id']??'');
    if(!$size&&($b['size_conversion_id']??null)!==null&&$b['size_conversion_id']!==''&&in_array($p['costing_basis'],['count','ready_made'],true)&&(int)$b['size_conversion_id']===0)$size=['id'=>0,'label'=>'1 unit','base_value'=>1,'base_unit'=>$p['base_unit'],'measurement_type'=>'count'];
    $packMode=(string)($b['packaging_mode']??'setup');
    if(!in_array($packMode,['setup','material','manual','none'],true))throw new DomainException('Choose a valid packaging source.');
    $setup=null;if($size&&$packMode==='setup'){$setup=!empty($b['packaging_setup_id'])?rpm_find($sources['setups'],'id',$b['packaging_setup_id']):rpm_setup($sources,$p,(int)$size['id']);if(!empty($b['packaging_setup_id'])&&!$setup)throw new DomainException('This packaging setup is no longer available. Choose a current setup.');if($setup&&((int)$setup['size_conversion_id']!==(int)$size['id']||!rp_setup_applies($setup,$p)))throw new DomainException('Packaging must belong to the selected product and size.');}
    $manual=$b['packaging_manual_cost']??null;$confirmed=!empty($b['packaging_confirmed']);
    $material=$packMode==='material'&&$size?rpm_find($sources['materials']??[],'id',$b['packaging_material_id']??0):null;
    if($packMode==='manual'&&$manual!==''&&$manual!==null&&(!is_numeric($manual)||!is_finite((float)$manual)||(float)$manual<0))throw new DomainException('Enter a valid non-negative packaging cost excluding VAT.');
    $pack=$packMode==='setup'?($setup&&!empty($setup['complete'])?(float)$setup['cost_ex_vat']:null):($packMode==='material'?($material['cost_ex_vat']??null):($packMode==='manual'?(is_numeric($manual)?(float)$manual:null):($confirmed?0.0:null)));
    $packInc=$packMode==='setup'?($setup['cost_inc_vat']??null):($packMode==='material'?($material['cost_inc_vat']??null):$pack);
    $packLabel=$packMode==='manual'?($confirmed?'Confirmed manual packaging':'Manual packaging estimate'):($packMode==='none'?'Packaging not applicable':($packMode==='material'?($material['item_name']??'Packaging material unavailable'):($setup['setup_name']??'Packaging required')));
    $site=null;foreach($sources['website']as $w)if($w['product_id'].':'.$w['variation_id']===($b['website_key']??'')){$site=$w;break;}
    if(!empty($b['website_key'])&&!$site)throw new DomainException('The selected variation is not in the latest successful website snapshot.');
    if($site&&$size){$matched=rpm_site_size($site,$sources['sizes']);if($matched!==null&&$matched!==(int)$size['id'])throw new DomainException('This website variation has a different size. Select the exact matching variation.');}
    $selling=$b['selling_price_inc_vat']??($site['active_price_inc_vat']??null);$selling=is_numeric($selling)&&(float)$selling>=0?(float)$selling:null;
    $vat=is_numeric($b['vat_rate']??null)?(float)$b['vat_rate']:(float)($settings['retail_vat_rate']??15);
    $treatment=(string)($b['vat_treatment']??'');if(in_array($treatment,['zero','exempt'],true))$vat=0;
    if($vat<0||$vat>100)throw new DomainException('Enter a valid VAT rate.');
    $issues=[];if(!$p['landed_cost_id'])$issues[]='Cost Not Matched';elseif($p['landed_status']!=='complete')$issues[]='Transport / Source Review';
    if(!$size)$issues[]='Size Required';if($pack===null)$issues[]='Packaging Required';
    if($packMode==='manual'&&!$confirmed)$issues[]='Packaging Estimate';
    if(!$treatment)$issues[]='Confirm VAT Treatment';if($selling===null||$selling<=0)$issues[]='Selling Price Required';
    $contents=$size&&$p['landed_status']==='complete'?(float)$p['landed_cost_per_base']*(float)$size['base_value']:null;
    $complete=$contents!==null&&$pack!==null&&(in_array($packMode,['setup','material'],true)||$confirmed);
    $known=$contents!==null||$pack!==null?(float)$contents+(float)$pack:null;
    $total=$complete?$known:null;$priceEx=$selling===null||!in_array($treatment,['standard','zero','exempt'],true)?null:$selling/(1+$vat/100);
    $profit=$priceEx!==null&&$known>0?$priceEx-$known:($complete&&$known===0.0&&$priceEx!==null?$priceEx:null);
    $margin=$profit!==null&&$priceEx>0?$profit/$priceEx*100:null;$markup=$profit!==null&&$known>0?$profit/$known*100:null;
    $removed=empty($p['id'])&&!empty($sources['unlinked'][$parent]);
    if($removed){$profit=$margin=$markup=null;$issues[]='Supplier Cost Missing — link removed';}
    if($profit!==null&&$profit<0)$issues[]='Loss-Making';elseif($margin!==null&&$margin<(float)($settings['retail_low_margin_threshold']??20))$issues[]='Low Margin';
    $stock=$site&&!empty($site['manage_stock'])&&is_numeric($site['stock_quantity'])?(float)$site['stock_quantity']:null;
    $webPrice=$site&&is_numeric($site['active_price_inc_vat'])?(float)$site['active_price_inc_vat']:null;
    $difference=$selling!==null&&$webPrice!==null&&abs($selling-$webPrice)>0.005;
    $sync=$site['synced_at']??$sources['lastSync'];$outdated=$site&&(!$sync||strtotime((string)$sync)<strtotime(rpm_today().' -1 day'));
    $websiteStatus=!$site?'Not Linked':($stock===null?'Stock Unknown':($outdated?'Sync Outdated':($difference?'Price Difference':'Linked')));
    $pricingStatus=$contents===null?($size?'Costing Required':'Not Started'):($pack===null?'Packaging Required':($packMode==='manual'&&!$confirmed?'Packaging Estimate':($selling===null||$selling<=0||$priceEx===null?'Not Started':($profit<0?'Loss-Making':($margin<(float)($settings['retail_low_margin_threshold']??20)?'Low Margin':'Pricing Complete')))));
    $token=hash('sha256',json_encode([$p,$size,$setup,$material,$site,$sources['batch'],$packMode,$packMode==='manual'?$pack:null,$packMode==='setup'?true:$confirmed,$settings]));
    $extra=['supplier_link_removed'=>$removed,'known_cost_ex_vat'=>$known,'provisional'=>!$complete,'cost_status'=>$contents===null?'Cost Not Matched':'Matched','completion_status'=>$complete&&$priceEx!==null&&$selling>0?'Complete':'Incomplete','website_link_status'=>$site?'Linked':'Not Linked'];
    foreach(['manual_name','manual_code','manual_category','manual_basis'] as $field)$extra[$field]=$b[$field]??'';
    if(!empty($b['manual_name'])){$extra['product_name']=$b['manual_name'];$extra['short_code']=$b['manual_code']??'';$extra['category']=$b['manual_category']??'';}
    // Keep older detailed status values for historical consumers; expose the sheet's clear grouping separately.
    if($pack===null)$issues[]='Missing Packaging Cost';
    if(!$complete&&$profit!==null)$issues[]='Provisional / Incomplete Cost';
    $extra['issues']=$issues;
    if(in_array('Transport / Source Review',$issues,true)||in_array('Packaging Estimate',$issues,true))$extra['completion_status']='Needs Review';
    return $extra + ['product_id'=>(int)$p['id'],'product_name'=>$p['product_name'],'short_code'=>$p['short_code'],'category'=>$p['product_category'],'supplier'=>$p['supplier_name'],'basis'=>$p['costing_basis'],'base_unit'=>$p['base_unit'],'landed_cost_id'=>$p['landed_cost_id'],'cost_date'=>$p['cost_date'],'supplier_cost_per_base'=>$p['supplier_cost_per_base'],'transport_cost_per_base'=>$p['transport_cost_per_base'],'landed_cost_per_base'=>$p['landed_status']==='complete'?$p['landed_cost_per_base']:null,'size_conversion_id'=>$size['id']??null,'size_label'=>$size['label']??'Select Size','conversion_base_value'=>$size['base_value']??null,'packaging_setup_id'=>$setup['id']??0,'packaging_material_id'=>$packMode==='material'?(int)($b['packaging_material_id']??0):0,'packaging_material_cost_id'=>$material['cost_id']??null,'packaging_setup'=>$packLabel,'packaging_mode'=>$packMode,'packaging_manual_cost'=>$packMode==='manual'?$pack:null,'packaging_confirmed'=>in_array($packMode,['setup','material'],true)?$pack!==null:$confirmed,'packaging_cost_ex_vat'=>$pack,'packaging_cost_inc_vat'=>$packInc,'product_cost_per_unit'=>$contents,'total_cost_ex_vat'=>$total,'selling_price_inc_vat'=>$selling,'selling_price_ex_vat'=>$priceEx,'profit_per_unit'=>$profit,'markup_percent'=>$markup,'margin_percent'=>$margin,'vat_rate'=>$vat,'vat_treatment'=>$treatment,'website_key'=>$site?$site['product_id'].':'.$site['variation_id']:'','website_name'=>$site?trim($site['product_name'].' · '.$site['variation_name']):'Not linked to website','website_sku'=>$site['sku']??'','website_price_inc_vat'=>$webPrice,'website_stock_quantity'=>$stock,'website_sync_at'=>$site?$sync:null,'stock_cost'=>$stock!==null&&$total!==null?$stock*$total:null,'retail_value'=>$stock!==null&&$webPrice!==null?$stock*$webPrice:null,'projected_profit'=>$priceEx!==null&&$stock!==null&&$webPrice!==null&&$total!==null?$stock*($webPrice/(1+$vat/100)-$total):null,'source_token'=>$token,'issues'=>$issues,'pricing_status'=>$pricingStatus,'website_status'=>$websiteStatus,'status'=>$pricingStatus,'website_price_differs'=>$difference];
}
function rpm_rows(PDO $pdo,array $s,array $settings):array {
    $q=$pdo->prepare('SELECT r.*,r.id pricing_id,v.selling_price_inc_vat,v.vat_rate,v.source_json,v.total_cost_ex_vat saved_cost FROM cw_retail_prices r JOIN cw_retail_price_versions v ON v.retail_price_id=r.id AND v.version_no=(SELECT v2.version_no FROM cw_retail_price_versions v2 WHERE v2.retail_price_id=r.id AND v2.effective_date<=? ORDER BY v2.effective_date DESC,v2.version_no DESC LIMIT 1) WHERE r.active=1');$q->execute([rpm_today()]);$prices=$q->fetchAll();$rows=[];$covered=[];
    foreach($prices as $price){if(!rpm_find($s['products'],'id',$price['product_id']))continue;$history=json_decode($price['source_json'],true)?:[];$b=$price;$b['website_key']=$price['woo_product_id']?$price['woo_product_id'].':'.($price['woo_variation_id']??0):'';$b['vat_treatment']=$history['vat_treatment']??((float)$price['vat_rate']===0?'zero':'standard');
        foreach(['packaging_mode','packaging_material_id','packaging_manual_cost','packaging_confirmed'] as $field)if(array_key_exists($field,$history))$b[$field]=$history[$field];
        try{$row=rpm_preview($s,$settings,$b);}catch(DomainException $e){$b['website_key']='';$b['packaging_setup_id']=0;$row=rpm_preview($s,$settings,$b);$row['issues'][]=$e->getMessage();}
        $row['id']=(int)$price['pricing_id'];$row['version']=(int)$price['current_version'];$row['saved']=true;$row['effective_date']=$price['effective_date'];$row['cost_changed']=$row['total_cost_ex_vat']!==null&&abs($row['total_cost_ex_vat']-(float)$price['saved_cost'])>0.00001;$rows[]=$row;$covered[$price['product_id'].':'.$price['size_conversion_id']]=true;
    }
    foreach($s['products']as $p){$proposals=[];foreach($s['website']as $site){if(function_exists('rpd_match')&&(($candidate=rpd_match($site,$s))!==null)&&(int)$candidate['id']===(int)$p['id']){$size=rpm_site_size($site,$s['sizes']);if($size===null&&($site['product_type']??'')==='simple'&&in_array($p['costing_basis'],['count','ready_made'],true))$size=0;if($size!==null&&!isset($covered[$p['id'].':'.$size]))$proposals[$size]=['size_conversion_id'=>$size,'website_key'=>isset($proposals[$size])?'':$site['product_id'].':'.$site['variation_id']];}}
        $has=false;foreach($covered as $key=>$v)if(strpos($key,$p['id'].':')===0)$has=true;
        if(!$has&&!$proposals)$proposals[]=['size_conversion_id'=>null,'website_key'=>''];
        foreach($proposals as $b){$b['product_id']=$p['id'];if(!empty($b['website_key']))foreach($s['website'] as $web)if($web['product_id'].':'.$web['variation_id']===$b['website_key']){$b=array_merge($web['retained_body']??[],$b,cwp_vat($web,$settings));$b['website_confirmed']=true;break;}$row=rpm_preview($s,$settings,$b);$row['id']=0;$row['version']=0;$row['saved']=false;$row['effective_date']=rpm_today();$row['cost_changed']=false;if(isset($web['retained_body'])&&!empty($b['website_key']))$row['sheet_body']=$b;$rows[]=$row;}
    }
    $counts=[];foreach($rows as $r)if($r['website_key'])$counts[$r['website_key']]=($counts[$r['website_key']]??0)+1;
    foreach($rows as &$r){if($r['website_key']&&$counts[$r['website_key']]>1){$r['website_status']='Conflicting Website Match';$r['stock_cost']=$r['retail_value']=$r['projected_profit']=null;}}unset($r);return $rows;
}
function rpm_summary(array $rows):array {
    $out=['rows'=>0,'priced'=>0,'attention'=>0,'linked'=>0,'inventory_cost'=>0,'retail_value'=>0,'projected_profit'=>0,'coverage'=>['inventory_cost'=>0,'retail_value'=>0,'projected_profit'=>0]];
    foreach($rows as $r){if($r['saved']&&$r['size_conversion_id']!==null)$out['rows']++;if($r['saved']&&$r['selling_price_inc_vat']>0)$out['priced']++;if($r['issues'])$out['attention']++;if($r['saved']&&$r['website_key'])$out['linked']++;foreach(['inventory_cost'=>'stock_cost','retail_value'=>'retail_value','projected_profit'=>'projected_profit']as $key=>$field)if($r['saved']&&$r[$field]!==null){$out[$key]+=$r[$field];$out['coverage'][$key]++;}}
    return $out;
}
function rpm_save(PDO $pdo,array $b,array $settings):array {
    $b=rpm_apply_vat($b);
    if(!empty($b['manual_name']))throw new DomainException('This manual catalogue entry is saved by sheet autosave. Its identity must not be replaced by the linked supplier product.');
    $pid=(int)($b['product_id']??0);$sizeId=(int)($b['size_conversion_id']??-1);$id=(int)($b['id']??0);$date=(string)($b['effective_date']??'');$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$dt||$dt->format('Y-m-d')!==$date||$date>rpm_today())throw new DomainException('Choose a valid effective date no later than today.');
    $request=(string)($b['request_id']??'');if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$request))throw new DomainException('Missing save request ID.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();try{
        // Existing settings row serializes website identity claims across different products.
        $pdo->query("SELECT setting_value FROM cw_settings WHERE setting_key='retail_low_margin_threshold' FOR UPDATE")->fetch();
        $q=$pdo->prepare("SELECT retail_price_id,after_json FROM cw_retail_price_audit WHERE JSON_UNQUOTE(JSON_EXTRACT(after_json,'$.request_id'))=? ORDER BY id DESC LIMIT 1");$q->execute([$request]);$prior=$q->fetch();
        $fingerprint=hash('sha256',json_encode($b));if($prior){$a=json_decode($prior['after_json'],true);if(($a['fingerprint']??'')!==$fingerprint)throw new DomainException('This request ID was already used.');if($ownsTransaction)$pdo->commit();return ['ok'=>true,'id'=>(int)$prior['retail_price_id'],'version'=>(int)$a['version'],'replayed'=>true];}
        $q=$pdo->prepare('SELECT * FROM cw_retail_prices WHERE product_id=? AND size_conversion_id=? AND active=1 FOR UPDATE');$q->execute([$pid,$sizeId]);$targets=$q->fetchAll();$current=$targets[0]??null;
        if(count($targets)>1)throw new DomainException('Several existing pricing rows use this size. Review their history before saving.');
        if($current&&($id!==(int)$current['id']))throw new DomainException('This product-size already exists. Open existing pricing record #'.$current['id'].'.');
        if($id&&!$current)throw new DomainException('Changing size requires Save as New Size. The original record is preserved.');
        if($current&&(int)($b['version']??0)!==(int)$current['current_version'])throw new DomainException('This price was updated elsewhere. Reload and review the latest version.');
        $s=rpm_sources($pdo);$r=rpm_preview($s,$settings,$b);
        if(!hash_equals($r['source_token'],(string)($b['source_token']??'')))throw new DomainException('Source costs, packaging or website data changed. Refresh the preview and review before saving.');
        if($r['total_cost_ex_vat']===null)throw new DomainException('Complete the approved source and packaging costs before saving a pricing version.');
        if($r['selling_price_inc_vat']===null||$r['selling_price_inc_vat']<=0)throw new DomainException('Enter a valid selling price.');
        if(!in_array($r['vat_treatment'],['standard','zero','exempt'],true))throw new DomainException('Confirm the applicable VAT treatment.');
        $wp=0;$wv=0;if($r['website_key']){if(empty($b['website_confirmed']))throw new DomainException('Confirm the exact website variation and size.');[$wp,$wv]=array_map('intval',explode(':',$r['website_key']));$q=$pdo->prepare('SELECT id FROM cw_retail_prices WHERE woo_product_id=? AND COALESCE(woo_variation_id,0)=? AND active=1 AND id<>?');$q->execute([$wp,$wv,$id]);if($q->fetchColumn())throw new DomainException('This website variation is already assigned to another pricing record.');}
        $u=rp_user();$setupId=(int)$r['packaging_setup_id'];
        // Re-use an audited removed identity instead of violating its original unique
        // product/size/setup key. Append a new version; never rewrite prior versions.
        if(!$current){$q=$pdo->prepare("SELECT r.* FROM cw_retail_prices r WHERE r.product_id=? AND r.size_conversion_id=? AND r.packaging_setup_id=? AND r.active=0 AND EXISTS(SELECT 1 FROM cw_retail_price_audit a WHERE a.retail_price_id=r.id AND a.action_key='supplier_link_removed') FOR UPDATE");$q->execute([$pid,$sizeId,$setupId]);$archived=$q->fetch();if($archived){$current=$archived;$id=(int)$archived['id'];}}
        $version=$current?(int)$current['current_version']+1:1;
        if($current){$pdo->prepare('UPDATE cw_retail_prices SET active=1,packaging_setup_id=?,woo_product_id=?,woo_variation_id=?,current_version=?,effective_date=?,updated_by=?,updated_by_name=? WHERE id=?')->execute([$setupId,$wp?:null,$wv?:null,$version,$date,$u['id'],$u['name'],$id]);}
        else{$pdo->prepare('INSERT INTO cw_retail_prices(product_id,size_conversion_id,packaging_setup_id,woo_product_id,woo_variation_id,current_version,effective_date,created_by,created_by_name,updated_by,updated_by_name) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$pid,$sizeId,$setupId,$wp?:null,$wv?:null,$version,$date,$u['id'],$u['name'],$u['id'],$u['name']]);$id=(int)$pdo->lastInsertId();}
        $p=rpm_find($s['products'],'id',$pid);$setup=rpm_find($s['setups'],'id',$setupId);$source=['product'=>$p,'size'=>rpm_find($s['sizes'],'id',$sizeId),'packaging'=>$setup,'packaging_material_id'=>$r['packaging_material_id'],'packaging_material'=>rpm_find($s['materials']??[],'id',$r['packaging_material_id']),'packaging_mode'=>$r['packaging_mode'],'packaging_manual_cost'=>$r['packaging_manual_cost'],'packaging_confirmed'=>$r['packaging_confirmed'],'pricing_status'=>$r['pricing_status'],'website_key'=>$r['website_key'],'website_sync_at'=>$r['website_sync_at'],'vat_treatment'=>$r['vat_treatment']];
        $pdo->prepare('INSERT INTO cw_retail_price_versions(retail_price_id,version_no,landed_cost_id,landed_cost_per_base,conversion_base_value,packaging_cost_ex_vat,packaging_cost_inc_vat,total_cost_ex_vat,total_cost_inc_vat,vat_rate,selling_price_inc_vat,selling_price_ex_vat,profit_per_unit,markup_percent,margin_percent,website_price_inc_vat,website_stock_quantity,effective_date,source_json,created_by,created_by_name) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$version,$r['landed_cost_id'],$r['landed_cost_per_base'],$r['conversion_base_value'],$r['packaging_cost_ex_vat'],$r['packaging_cost_inc_vat'],$r['total_cost_ex_vat'],$r['product_cost_per_unit']*(1+$r['vat_rate']/100)+$r['packaging_cost_inc_vat'],$r['vat_rate'],$r['selling_price_inc_vat'],$r['selling_price_ex_vat'],$r['profit_per_unit'],$r['markup_percent']??0,$r['margin_percent']??0,$r['website_price_inc_vat'],$r['website_stock_quantity'],$date,json_encode($source),$u['id'],$u['name']]);
        $pdo->prepare('INSERT INTO cw_retail_price_audit(retail_price_id,action_key,before_json,after_json,actor_id,actor_name) VALUES(?,?,?,?,?,?)')->execute([$id,$current?'updated':'created',$current?json_encode($current):null,json_encode(['request_id'=>$request,'fingerprint'=>$fingerprint,'row'=>$r,'version'=>$version]),$u['id'],$u['name']]);if($ownsTransaction)$pdo->commit();return ['ok'=>true,'id'=>$id,'version'=>$version];
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
