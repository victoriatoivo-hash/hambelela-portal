<?php
declare(strict_types=1);

// Website identities and supplier-cost identities are intentionally independent.
function rpd_match(array $site,array $sources):?array {
    $matches=[];$sku=trim((string)($site['sku']??''));
    foreach($sources['products'] as $p){
        $exactVariation=!empty($p['woo_variation_id'])&&(int)$p['woo_variation_id']===(int)$site['variation_id']&&(int)($p['woo_product_id']??0)===(int)$site['product_id'];
        $parent=!empty($p['woo_product_id'])&&(int)$p['woo_product_id']===(int)$site['product_id']&&empty($p['woo_variation_id']);
        $code=$sku!==''&&in_array(strtolower($sku),array_map('strtolower',array_filter(array_merge([$p['woo_sku']??'',$p['short_code']??''],$p['supplier_codes']??[]))),true);
        if($exactVariation||$parent||$code)$matches[(int)$p['id']]=$p;
    }
    if(count($matches)!==1)return null;
    $p=reset($matches);$size=rpm_site_size($site,rpm_sizes($sources,$p));
    if($size===null&&!in_array($p['costing_basis'],['count','ready_made'],true))return null;
    return $p;
}
function rpd_website_rows(array $rows,array $sources,array $settings):array {
    $covered=[];foreach($rows as $r)if(!empty($r['website_key']))$covered[$r['website_key']]=true;
    foreach($sources['website'] as $site){
        $key=$site['product_id'].':'.$site['variation_id'];if(isset($covered[$key]))continue;
        $p=rpd_match($site,$sources);$sid=rpm_site_size($site,$sources['sizes']);$size=rpm_find($sources['sizes'],'id',$sid??'');
        if($sid===null&&(($p&&in_array($p['costing_basis'],['count','ready_made'],true))||(!$p&&($site['product_type']??'')==='simple')))$sid=0;
        $b=['website_origin'=>true,'product_id'=>$p['id']??0,'size_conversion_id'=>$sid,'website_key'=>$key,'website_confirmed'=>true,'selling_price_inc_vat'=>$site['active_price_inc_vat']??'',
            'manual_name'=>$p?'':$site['product_name'],'manual_code'=>$p?'':($site['sku']??''),'manual_category'=>$p?'':($site['category']??''),'manual_basis'=>$size['measurement_type']??'count',
            'effective_date'=>rpm_today(),'vat_treatment'=>''];
        $b=array_merge($b,cwp_vat($site,$settings));$r=rpm_preview($sources,$settings,$b);$r['client_key']='website-'.$site['product_id'].'-'.$site['variation_id'];$r['sheet_key']=$r['client_key'];
        $r['id']=0;$r['version']=0;$r['saved']=false;$r['sheet_body']=$b;$r['effective_date']=rpm_today();
        if(!$size&&$sid!==0)$r['size_label']=trim((string)($site['variation_name']??''))?:'Select Size';
        $rows[]=$r;$covered[$key]=true;
    }
    return $rows;
}
function rpd_merge(array $rows,array $entries):array {
    foreach($entries as $entry){$r=$entry['row'];$b=$entry['body'];
        $rows=array_values(array_filter($rows,function($old)use($r,$b){
            if(!empty($r['id'])&&(int)$r['id']===(int)($old['id']??0))return false;
            if(!empty($b['website_key'])&&$b['website_key']===($old['website_key']??''))return false;
            return !empty($old['saved'])||empty($r['product_id'])||(int)$r['product_id']!==(int)$old['product_id']||($old['size_conversion_id']!==null&&(string)$old['size_conversion_id']!==(string)($r['size_conversion_id']??''));
        }));$rows[]=$r;
    }return $rows;
}
function rpd_summary(array $rows):array {
    $out=['rows'=>count($rows),'priced'=>0,'attention'=>0,'linked'=>0,'inventory_cost'=>0,'retail_value'=>0,'projected_profit'=>0,'missing_cost_coverage'=>0,'coverage'=>['inventory_cost'=>0,'retail_value'=>0,'projected_profit'=>0]];
    foreach($rows as $r){if(($r['selling_price_inc_vat']??0)>0)$out['priced']++;if(($r['completion_status']??'Incomplete')!=='Complete')$out['attention']++;
        if(!empty($r['website_key'])){$out['linked']++;if(($r['stock_cost']??null)===null)$out['missing_cost_coverage']++;}
        foreach(['inventory_cost'=>'stock_cost','retail_value'=>'retail_value','projected_profit'=>'projected_profit'] as $key=>$field)if(($r[$field]??null)!==null){$out[$key]+=$r[$field];$out['coverage'][$key]++;}
    }return $out;
}
