<?php
declare(strict_types=1);
require __DIR__.'/product-cost-linking.php';
// All writes below are to the disposable CI database created by the base suite.
$shapeParent=888001;$shapeIds=[101=>'Square Mold',102=>'Heart Shaped Mold',103=>'Circular Mold',104=>'Oval Shaped Mold'];$shapeProducts=[];
foreach($shapeIds as $vid=>$name){
    $cost=40+($vid-100)*2.5;
    $shapeProducts[$vid]=ins($pdo,'cw_cost_products',['product_name'=>$name.' supplier','normalized_name'=>'shape'.$vid,'short_code'=>'SHAPE'.$vid,'costing_basis'=>'count','base_unit'=>'unit','woo_sku'=>'SHARED']);
    $spid=$shapeProducts[$vid];$source=ins($pdo,'cw_cost_product_sources',['product_id'=>$spid,'source_type'=>'manual','cost_date'=>'2026-10-01','total_purchased_quantity'=>1,'total_supplier_cost'=>$cost]);
    ins($pdo,'cw_landed_product_costs',['product_id'=>$spid,'product_source_id'=>$source,'costing_basis'=>'count','base_unit'=>'unit','base_quantity'=>1,'supplier_cost_used'=>$cost,'supplier_cost_per_base'=>$cost,'landed_cost_per_base'=>$cost,'cost_date'=>'2026-10-01','status_key'=>'complete','calculation_hash'=>hash('sha256','shape'.$vid)]);
    ins($pdo,'cw_product_snapshots',['product_id'=>$shapeParent,'variation_id'=>$vid,'product_name'=>'Silicone Molds','variation_name'=>$name,'product_type'=>'variation','sku'=>'SHARED','sync_batch_id'=>$batch,'active_price_inc_vat'=>150]);
    rps_save($pdo,['row_key'=>'shape-website-'.$vid,'revision'=>0,'mutation_id'=>bin2hex(random_bytes(16)),'body'=>['website_origin'=>true,'manual_name'=>'Silicone Molds','manual_basis'=>'count','product_id'=>0,'size_conversion_id'=>'','website_key'=>$shapeParent.':'.$vid,'website_confirmed'=>true,'selling_price_inc_vat'=>150+$vid,'packaging_mode'=>'none','packaging_confirmed'=>true,'effective_date'=>rpm_today()]],$settings);
}
$snapshot=$pdo->query('SELECT * FROM cw_product_snapshots ORDER BY id')->fetchAll();$versions=$pdo->query('SELECT * FROM cw_retail_price_versions ORDER BY id')->fetchAll();
$oval=['product_id'=>$shapeProducts[104],'website_product_id'=>$shapeParent,'website_variation_id'=>104];
blocked(fn()=>rpl_preview($pdo,array_merge($oval,['website_variation_id'=>99999]),$settings),'Foreign variation accepted');
blocked(fn()=>rpl_preview($pdo,array_merge($oval,['website_variation_id'=>'']),$settings),'Empty scope silently became all variations');
$ovalPlan=rpl_preview($pdo,$oval,$settings);check(count($ovalPlan['rows'])===1&&$ovalPlan['rows'][0]['variation']==='Oval Shaped Mold','Oval-only preview');
blocked(fn()=>rpl_save($pdo,array_merge($oval,['website_variation_id'=>0,'token'=>$ovalPlan['token'],'confirmed'=>true]),$settings),'Single-variation token used for all shapes');
$siblings=$pdo->query("SELECT * FROM cw_retail_sheet_drafts WHERE row_key IN ('shape-website-101','shape-website-102','shape-website-103') ORDER BY id")->fetchAll();
rpl_save($pdo,$oval+['token'=>$ovalPlan['token'],'confirmed'=>true],$settings);
check($siblings===$pdo->query("SELECT * FROM cw_retail_sheet_drafts WHERE row_key IN ('shape-website-101','shape-website-102','shape-website-103') ORDER BY id")->fetchAll(),'Sibling drafts untouched');
blocked(fn()=>rpl_save($pdo,$oval+['token'=>$ovalPlan['token'],'confirmed'=>true],$settings),'Repeated click re-applied stale link');
$catalog=rpm_sources($pdo);$ovalProduct=rpm_find($catalog['products'],'id',$shapeProducts[104]);$onlyOval=$catalog;$onlyOval['products']=[$ovalProduct];
check(rpd_match(['product_id'=>$shapeParent,'variation_id'=>101,'sku'=>'SHARED'],$onlyOval)===null,'SKU fallback leaked oval into square');
blocked(fn()=>rpl_preview($pdo,['product_id'=>$shapeProducts[101],'website_product_id'=>$shapeParent],$settings),'Whole-parent match overwrote exact variation');
foreach([101,102,103] as $vid){$in=['product_id'=>$shapeProducts[$vid],'website_product_id'=>$shapeParent,'website_variation_id'=>$vid];$pl=rpl_preview($pdo,$in,$settings);rpl_save($pdo,$in+['token'=>$pl['token'],'confirmed'=>true],$settings);}
$catalog=rpm_sources($pdo);$rows=rpd_merge(rpd_website_rows(rpm_rows($pdo,$catalog,$settings),$catalog,$settings),rps_list($pdo,$catalog,$settings));$shapes=array_values(array_filter($rows,fn($r)=>strpos($r['website_key']??'',$shapeParent.':')===0));
check(count($shapes)===4,'Exactly four website shape rows');
check(!array_filter($rows,fn($r)=>in_array((int)($r['product_id']??0),$shapeProducts,true)&&empty($r['website_key'])),'No duplicate standalone unit row');
foreach($shapes as $r){$vid=(int)explode(':',$r['website_key'])[1];check((int)$r['product_id']===$shapeProducts[$vid],'Independent supplier attribution');near($r['product_cost_per_unit'],40+($vid-100)*2.5,'Correct individual unit cost');near($r['selling_price_inc_vat'],150+$vid,'Working prices preserved');check($r['size_label']===$shapeIds[$vid],'Shape name displayed instead of indistinguishable unit');}
check($snapshot===$pdo->query('SELECT * FROM cw_product_snapshots ORDER BY id')->fetchAll(),'Website price and stock unchanged');
check($versions===$pdo->query('SELECT * FROM cw_retail_price_versions ORDER BY id')->fetchAll(),'Historical financial versions unchanged');
$staleBody=['website_origin'=>true,'manual_basis'=>'count','product_id'=>0,'size_conversion_id'=>0,'website_key'=>$shapeParent.':104','website_confirmed'=>true,'selling_price_inc_vat'=>254];
blocked(fn()=>rps_save($pdo,['row_key'=>'shape-stale-new-row','revision'=>0,'mutation_id'=>bin2hex(random_bytes(16)),'body'=>$staleBody],$settings),'Stale autosave detached exact variation source');
$othersBefore=$pdo->query("SELECT * FROM cw_retail_sheet_drafts WHERE row_key IN ('shape-website-101','shape-website-102','shape-website-103') ORDER BY id")->fetchAll();
$undo=rpl_unlink_preview($pdo,$oval,$settings);check(count($undo['rows'])===1,'Removal is single variation');rpl_unlink($pdo,$oval+['token'=>$undo['token'],'confirmed'=>true,'reason'=>'Wrong oval fixture'],$settings);
check($othersBefore===$pdo->query("SELECT * FROM cw_retail_sheet_drafts WHERE row_key IN ('shape-website-101','shape-website-102','shape-website-103') ORDER BY id")->fetchAll(),'Removal preserves siblings byte-for-byte');
$catalog=rpm_sources($pdo);check(empty($catalog['unlinked'][$shapeParent])&&!empty($catalog['unlinked_variations'][$shapeParent.':104']),'Removal scoped to exact website key');
foreach($catalog['website'] as $site)if((int)$site['product_id']===$shapeParent){$p=rpd_match($site,$catalog);check((int)$site['variation_id']===104?$p===null:(int)$p['id']===$shapeProducts[(int)$site['variation_id']],'Other shapes remain matched after oval removal');}
$pl=rpl_preview($pdo,$oval,$settings);rpl_save($pdo,$oval+['token'=>$pl['token'],'confirmed'=>true],$settings);
echo "Variation linking: independent shapes, selected preview, scope token, no duplicates, prices/history preserved, scoped removal/relink and stale autosave PASS\n";
