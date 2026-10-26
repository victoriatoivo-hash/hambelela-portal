<?php
declare(strict_types=1);

/** Explicit, shared parent-product identity link. Never sends a WooCommerce write. */
function rpl_parent(array $body): int {return (int)explode(':',(string)($body['website_key']??''))[0];}
function rpl_placeholder(array $body): bool {
    return empty($body['id']) && empty($body['website_key']) && empty($body['manual_name']) && empty($body['packaging_confirmed']) && ($body['size_conversion_id']??'')==='' && ($body['selling_price_inc_vat']??'')==='' && empty($body['packaging_material_id']) && empty($body['packaging_setup_id']) && ($body['packaging_manual_cost']??'')==='';
}
function rpl_preview(PDO $pdo,array $input,array $settings): array {
    $pid=(int)($input['product_id']??0);$parent=(int)($input['website_product_id']??0);$s=rpm_sources($pdo);$p=rpm_find($s['products'],'id',$pid);
    if(!$p||!$p['landed_cost_id'])throw new DomainException('Choose a supplier product with an approved cost source.');
    $sites=array_values(array_filter($s['website'],function($w)use($parent){return (int)$w['product_id']===$parent;}));
    if(!$parent||!$sites)throw new DomainException('Choose a product from the latest website catalogue.');
    if(!empty($p['woo_product_id']) && ((int)$p['woo_product_id']!==$parent || !empty($p['woo_variation_id'])))throw new DomainException('This supplier product already has a different website identity. It cannot be reassigned through ordinary matching.');
    foreach($s['products'] as $other)if((int)$other['id']!==$pid && (int)($other['woo_product_id']??0)===$parent)throw new DomainException('This website product already uses another supplier-cost product. Review that existing link instead of merging histories.');
    $prices=$pdo->query('SELECT * FROM cw_retail_prices WHERE active=1 ORDER BY id')->fetchAll();$relevantPrices=[];
    foreach($prices as $price){
        if((int)$price['woo_product_id']===$parent && (int)$price['product_id']!==$pid)throw new DomainException('A website size already has saved pricing for another cost product. Its history is protected; reconcile that link first.');
        if((int)$price['product_id']===$pid || (int)$price['woo_product_id']===$parent)$relevantPrices[]=$price;
        if((int)$price['product_id']===$pid && !empty($price['woo_product_id']) && (int)$price['woo_product_id']!==$parent)throw new DomainException('This supplier product already has saved sizes linked to another website product. Review those links first.');
        if((int)$price['product_id']===$pid && empty($price['woo_product_id']))throw new DomainException('This supplier product has an existing saved internal size. Use Link Website Variation on that existing row first, then preview the whole-product link. No duplicate will be created.');
    }
    $drafts=[];
    foreach($pdo->query('SELECT * FROM cw_retail_sheet_drafts WHERE active=1 ORDER BY id')->fetchAll() as $draft){
        $b=json_decode($draft['payload_json'],true)?:[];
        if((int)($b['product_id']??0)===$pid && !empty($b['website_key']) && rpl_parent($b)!==$parent)throw new DomainException('An existing supplier row is linked to another website product. Its values and history must be reviewed first.');
        if(rpl_parent($b)!==$parent && !((int)($b['product_id']??0)===$pid && empty($b['website_key'])))continue;
        if(rpl_parent($b)===$parent && !empty($b['product_id']) && (int)$b['product_id']!==$pid)throw new DomainException('A website row is already linked to another supplier product. Review it before applying a shared match.');
        if(empty($b['website_key'])&&!rpl_placeholder($b))throw new DomainException('This product has an internal pricing row with entered values. Link that existing row to its website size first; its values will not be discarded.');
        $drafts[]=$draft;
    }
    $rows=[];$savedRows=rpm_rows($pdo,$s,$settings);unset($s['unlinked'][$parent]); // Explicit preview only; save still requires its token and confirmation.
    foreach($sites as $site){
        $key=$site['product_id'].':'.$site['variation_id'];$size=rpm_site_size($site,rpm_sizes($s,$p));
        if($size===null&&in_array($p['costing_basis'],['count','ready_made'],true))$size=0;
        $body=['product_id'=>$pid,'size_conversion_id'=>$size,'website_key'=>$key,'website_confirmed'=>true,'selling_price_inc_vat'=>$site['active_price_inc_vat'],'packaging_mode'=>'setup'];
        $body=array_merge($body,$site['retained_body']??[],['product_id'=>$pid,'website_key'=>$key]);
        foreach($savedRows as $saved)if(!empty($saved['saved'])&&($saved['website_key']??'')===$key)$body=array_merge($body,$saved);
        foreach($drafts as $draft){$b=json_decode($draft['payload_json'],true)?:[];if(($b['website_key']??'')===$key && (int)$draft['owner_id']===rp_user()['id'])$body=array_merge($b,['product_id'=>$pid,'size_conversion_id'=>($b['size_conversion_id']??'')!==''?$b['size_conversion_id']:$size]);}
        $row=rps_row($body,$s,$settings);
        $rows[]=['website_key'=>$key,'name'=>$site['product_name'],'variation'=>$site['variation_name'],'size'=>$row['size_label']??'Size Required','contents_cost'=>$row['product_cost_per_unit']??null,'working_price'=>$body['selling_price_inc_vat']??null,'packaging_missing'=>($row['packaging_cost_ex_vat']??null)===null,'issues'=>$row['issues']??[]];
    }
    $token=hash('sha256',json_encode([$p,$sites,$s['sizes'],$s['setups'],$s['materials'],$relevantPrices,$drafts,$savedRows,$settings]));
    $websiteText=$sites[0]['product_name'].' '.($sites[0]['category']??'');$supplierText=$p['product_name'].' '.($p['product_category']??'');
    $warning=(bool)preg_match('/fragrance|parfum|perfume/i',$websiteText)!==(bool)preg_match('/fragrance|parfum|perfume/i',$supplierText)?'Possible product-type mismatch: fragrance and essential oil are not interchangeable. Check the product and grade carefully.':'';
    return ['ok'=>true,'product_id'=>$pid,'website_product_id'=>$parent,'supplier_product'=>$p['product_name'],'short_code'=>$p['short_code'],'supplier'=>$p['supplier_name'],'website_product'=>$sites[0]['product_name'],'cost_per_base'=>$p['landed_cost_per_base'],'base_unit'=>$p['base_unit'],'rows'=>$rows,'token'=>$token,'drafts'=>$drafts,'warning'=>$warning];
}

/** Audited undo of a whole-product match; purchase and price versions are immutable. */
function rpl_unlink_preview(PDO $pdo,array $input,array $settings):array {
    $pid=(int)($input['product_id']??0);$parent=(int)($input['website_product_id']??0);$s=rpm_sources($pdo);$p=rpm_find($s['products'],'id',$pid);
    if(!$p||!$parent||(int)($p['woo_product_id']??0)!==$parent||!empty($p['woo_variation_id']))throw new DomainException('Choose the currently confirmed whole-product supplier link. Reload if it has changed.');
    $sites=array_values(array_filter($s['website'],function($w)use($parent){return (int)$w['product_id']===$parent;}));
    $q=$pdo->prepare('SELECT * FROM cw_retail_prices WHERE active=1 AND woo_product_id=? ORDER BY id');$q->execute([$parent]);$prices=$q->fetchAll();$bodies=[];
    foreach($prices as $price){
        if((int)$price['product_id']!==$pid)throw new DomainException('This website product has conflicting saved supplier identities. No records changed; review the conflicting prices first.');
        $q=$pdo->prepare('SELECT * FROM cw_retail_price_versions WHERE retail_price_id=? AND version_no=?');$q->execute([$price['id'],$price['current_version']]);$v=$q->fetch();
        if(!$v)throw new DomainException('A pricing version is missing. No link will be removed.');
        $source=json_decode($v['source_json'],true)?:[];$key=$parent.':'.($price['woo_variation_id']??0);
        $bodies[$key]=rps_body(array_merge($source,$price,['id'=>0,'version'=>0,'product_id'=>0,'manual_basis'=>$p['costing_basis'],'website_origin'=>true,'website_key'=>$key,'selling_price_inc_vat'=>$v['selling_price_inc_vat'],'source_token'=>'']));
    }
    $drafts=[];foreach($pdo->query('SELECT * FROM cw_retail_sheet_drafts WHERE active=1 ORDER BY id')->fetchAll() as $draft){$b=json_decode($draft['payload_json'],true)?:[];if(rpl_parent($b)===$parent){if(!empty($b['product_id'])&&(int)$b['product_id']!==$pid)throw new DomainException('A draft uses another supplier identity. Resolve the conflicting draft first.');$drafts[]=$draft;}}
    $rows=[];foreach($sites as $site){$key=$parent.':'.$site['variation_id'];$body=$bodies[$key]??($site['retained_body']??[]);foreach($drafts as $draft){$b=json_decode($draft['payload_json'],true)?:[];if(($b['website_key']??'')===$key&&(int)$draft['owner_id']===rp_user()['id'])$body=$b;}$rows[]=['website_key'=>$key,'variation'=>$site['variation_name'],'working_price'=>$body['selling_price_inc_vat']??$site['active_price_inc_vat']];}
    $token=hash('sha256',json_encode([$p,$sites,$prices,$bodies,$drafts]));
    return ['ok'=>true,'product_id'=>$pid,'website_product_id'=>$parent,'supplier_product'=>$p['product_name'],'website_product'=>$sites[0]['product_name']??('Website product #'.$parent),'rows'=>$rows,'token'=>$token,'product'=>$p,'prices'=>$prices,'drafts'=>$drafts,'retained_bodies'=>$bodies];
}
function rpl_unlink(PDO $pdo,array $input,array $settings):array {
    if(empty($input['confirmed']))throw new DomainException('Confirm removal after reviewing the affected website sizes.');
    $reason=trim((string)($input['reason']??''));if($reason===''||strlen($reason)>1000)throw new DomainException('Enter a short reason for correcting this supplier link.');
    $pdo->beginTransaction();try{
        $pdo->query("SELECT setting_value FROM cw_settings WHERE setting_key='retail_low_margin_threshold' FOR UPDATE")->fetch();
        $q=$pdo->prepare('SELECT id FROM cw_cost_products WHERE id=? FOR UPDATE');$q->execute([(int)($input['product_id']??0)]);
        $plan=rpl_unlink_preview($pdo,$input,$settings);
        if(!hash_equals($plan['token'],(string)($input['token']??'')))throw new DomainException('The link or pricing changed. Preview the removal again.');
        $actor=rp_user();$pid=$plan['product_id'];$parent=$plan['website_product_id'];
        $pdo->prepare('UPDATE cw_cost_products SET woo_product_id=NULL,woo_variation_id=NULL WHERE id=?')->execute([$pid]);
        foreach($plan['prices'] as $price){
            $pdo->prepare('UPDATE cw_retail_prices SET active=0 WHERE id=?')->execute([$price['id']]);
            $pdo->prepare('INSERT INTO cw_retail_price_audit(retail_price_id,action_key,before_json,after_json,actor_id,actor_name) VALUES(?,?,?,?,?,?)')->execute([$price['id'],'supplier_link_removed',json_encode($price),json_encode(['reason'=>$reason,'website_product_id'=>$parent,'history_preserved'=>true]),$actor['id'],$actor['name']]);
        }
        foreach($plan['drafts'] as $draft){
            $before=json_decode($draft['payload_json'],true)?:[];$body=array_merge($before,['product_id'=>0,'id'=>0,'version'=>0,'manual_basis'=>$plan['product']['costing_basis'],'website_origin'=>true,'source_token'=>'']);
            $event=['action'=>'supplier_cost_unlinked','at'=>gmdate('c'),'actor_id'=>$actor['id'],'reason'=>$reason,'before'=>$before,'body'=>$body];
            $pdo->prepare("UPDATE cw_retail_sheet_drafts SET payload_json=?,pricing_id=NULL,state_key='draft',note_text='Supplier Cost Missing — select the correct supplier link.',revision=revision+1,mutation_id='',mutation_hash='',response_json=NULL,history_json=JSON_ARRAY_APPEND(COALESCE(history_json,'[]'),'$',JSON_EXTRACT(?,'$')) WHERE id=?")->execute([json_encode($body),json_encode($event),$draft['id']]);
        }
        cw_supplier_cost_audit($pdo,'cost_product',$pid,'website_cost_unlinked',['product'=>$plan['product'],'prices'=>$plan['prices']],['website_product_id'=>$parent,'reason'=>$reason,'retained_bodies'=>$plan['retained_bodies'],'variation_keys'=>array_column($plan['rows'],'website_key')]);
        $pdo->commit();return ['ok'=>true,'message'=>'Supplier link removed. Working prices and history are preserved. Select the correct supplier link to restore current costs and margins.'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function rpl_save(PDO $pdo,array $input,array $settings): array {
    if(empty($input['confirmed']))throw new DomainException('Confirm that the supplier material and website product are the same product and grade.');
    $pdo->beginTransaction();
    try{
        $pdo->query("SELECT setting_value FROM cw_settings WHERE setting_key='retail_low_margin_threshold' FOR UPDATE")->fetch();
        $q=$pdo->prepare('SELECT * FROM cw_cost_products WHERE id=? FOR UPDATE');$q->execute([(int)($input['product_id']??0)]);$before=$q->fetch();
        $plan=rpl_preview($pdo,$input,$settings);
        if(!hash_equals($plan['token'],(string)($input['token']??'')))throw new DomainException('The catalogue, pricing or cost source changed. Preview the match again before confirming.');
        $pid=$plan['product_id'];$parent=$plan['website_product_id'];
        $pdo->prepare('UPDATE cw_cost_products SET woo_product_id=?,woo_variation_id=0 WHERE id=?')->execute([$parent,$pid]);
        $sites=rpm_sources($pdo);$actor=rp_user();
        foreach($plan['drafts'] as $draft){
            $b=json_decode($draft['payload_json'],true)?:[];$archive=empty($b['website_key']);
            if(!$archive){$b['product_id']=$pid;$b['website_confirmed']=true;$b['source_token']='';
                if(($b['size_conversion_id']??'')===''){foreach($sites['website'] as $site)if($site['product_id'].':'.$site['variation_id']===$b['website_key'])$b['size_conversion_id']=rpm_site_size($site,rpm_sizes($sites,$before));}
            }
            $event=['action'=>'supplier_cost_linked','at'=>gmdate('c'),'actor_id'=>$actor['id'],'before'=>json_decode($draft['payload_json'],true),'body'=>$b,'archived_placeholder'=>$archive];
            $pdo->prepare("UPDATE cw_retail_sheet_drafts SET payload_json=?,revision=revision+1,active=?,mutation_id='',mutation_hash='',response_json=NULL,history_json=JSON_ARRAY_APPEND(COALESCE(history_json,'[]'),'$',JSON_EXTRACT(?,'$')) WHERE id=?")->execute([json_encode($b),$archive?0:1,json_encode($event),$draft['id']]);
        }
        cw_supplier_cost_audit($pdo,'cost_product',$pid,'website_cost_linked',$before,['website_product_id'=>$parent,'variation_keys'=>array_column($plan['rows'],'website_key')]);
        $pdo->commit();return ['ok'=>true,'message'=>'Supplier cost linked to the website product and its sizes. Website prices and historical pricing were not changed.'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
