<?php
declare(strict_types=1);

// Internal sheet drafts are separate from approved financial versions and website data.
function rps_install(PDO $pdo):void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_retail_sheet_drafts (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_id BIGINT NOT NULL, row_key VARCHAR(80) NOT NULL,
      revision INT UNSIGNED NOT NULL DEFAULT 0, pricing_id BIGINT NULL,
      state_key VARCHAR(20) NOT NULL DEFAULT 'draft', payload_json LONGTEXT NOT NULL,
      mutation_id VARCHAR(80) NOT NULL DEFAULT '', mutation_hash CHAR(64) NOT NULL DEFAULT '',
      response_json LONGTEXT NULL, note_text TEXT NULL, active TINYINT NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY owner_row (owner_id,row_key), KEY owner_active (owner_id,active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function rps_body(array $input):array {
    $fields=['manual_name','manual_code','manual_category','manual_basis','id','version','product_id','size_conversion_id','packaging_setup_id','packaging_material_id','packaging_mode','packaging_manual_cost','packaging_confirmed','website_key','selling_price_inc_vat','vat_treatment','vat_rate','effective_date','source_token','website_confirmed'];
    $body=[];foreach($fields as $name)if(array_key_exists($name,$input)){if(!is_scalar($input[$name])&&$input[$name]!==null)throw new DomainException('Invalid cell value.');if(strlen((string)$input[$name])>500)throw new DomainException('Cell value is too long.');$body[$name]=$input[$name];}
    return $body;
}
function rps_row(array $body,array $sources,array $settings):array {
    $p=rpm_find($sources['products'],'id',(int)($body['product_id']??0));
    $row=['product_id'=>(int)($body['product_id']??0),'product_name'=>$p['product_name']??'','short_code'=>$p['short_code']??'','category'=>$p['product_category']??'','basis'=>$p['costing_basis']??'','status'=>'Draft','issues'=>[]];
    try{$row=array_merge($row,rpm_preview($sources,$settings,$body));}catch(DomainException $e){$row['issues']=[$e->getMessage()];}
    return array_merge($row,['id'=>(int)($body['id']??0),'version'=>(int)($body['version']??0),'saved'=>!empty($body['id']),'effective_date'=>$body['effective_date']??rpm_today()]);
}
function rps_list(PDO $pdo,array $sources,array $settings):array {
    $q=$pdo->prepare('SELECT * FROM cw_retail_sheet_drafts WHERE owner_id=? AND active=1 ORDER BY id');$q->execute([rp_user()['id']]);$out=[];
    foreach($q->fetchAll() as $entry){$body=json_decode($entry['payload_json'],true)?:[];$out[]=['row_key'=>$entry['row_key'],'revision'=>(int)$entry['revision'],'state'=>$entry['state_key'],'body'=>$body,'row'=>rps_row($body,$sources,$settings),'note'=>$entry['note_text']];}return $out;
}
function rps_save(PDO $pdo,array $input,array $settings):array {
    $owner=rp_user()['id'];$key=(string)($input['row_key']??'');$mutation=(string)($input['mutation_id']??'');
    if(!preg_match('/^[a-zA-Z0-9-]{8,80}$/',$key)||!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$mutation))throw new DomainException('Missing stable row or save identifier.');
    $body=rps_body((array)($input['body']??[]));$hash=hash('sha256',json_encode([$body,!empty($input['archive'])]));$pdo->beginTransaction();
    try{
        // Serialize pricing claims using the same lock as the existing version writer.
        $pdo->query("SELECT setting_value FROM cw_settings WHERE setting_key='retail_low_margin_threshold' FOR UPDATE")->fetch();
        $q=$pdo->prepare('SELECT * FROM cw_retail_sheet_drafts WHERE owner_id=? AND row_key=? FOR UPDATE');$q->execute([$owner,$key]);$existing=$q->fetch();
        if($existing&&$existing['mutation_id']===$mutation){if($existing['mutation_hash']!==$hash)throw new DomainException('Save identifier was reused with different values.');$result=json_decode($existing['response_json'],true);$pdo->commit();return $result;}
        if((int)($input['revision']??0)!==(int)($existing['revision']??0))throw new DomainException('This row changed in another tab. Your edits are retained; reload the latest saved row before retrying.');
        if($existing&&!$existing['active'])throw new DomainException('This draft was removed in another tab.');
        $prior=$existing?json_decode($existing['payload_json'],true):[];
        if(!empty($body['website_key'])&&empty($input['archive'])){
            $q=$pdo->prepare("SELECT row_key FROM cw_retail_sheet_drafts WHERE owner_id=? AND active=1 AND row_key<>? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.website_key'))=? LIMIT 1");
            $q->execute([$owner,$key,$body['website_key']]);if($q->fetchColumn())throw new DomainException('This website variation already has a workbook row. Open that row instead; your edits have been retained.');
            $q=$pdo->prepare('SELECT id FROM cw_retail_prices WHERE active=1 AND woo_product_id=? AND COALESCE(woo_variation_id,0)=? AND id<>?');
            $parts=explode(':',(string)$body['website_key']);$q->execute([(int)($parts[0]??0),(int)($parts[1]??0),(int)($body['id']??0)]);if($q->fetchColumn())throw new DomainException('This website variation already belongs to another saved pricing record.');
        }
        if(!empty($prior['id'])&&((int)($body['id']??0)!==(int)$prior['id']||(int)($body['product_id']??0)!==(int)$prior['product_id']||(string)($body['size_conversion_id']??'')!==(string)$prior['size_conversion_id']))throw new DomainException('Saved product and size identity cannot be reassigned. Add another size instead.');
        if(!empty($input['archive'])&&!empty($body['id']))throw new DomainException('A saved pricing record cannot be removed as a draft.');
        $sources=rpm_sources($pdo);$row=rps_row($body,$sources,$settings);$state='draft';$note='Draft saved. Complete product, size, costs, selling price and VAT to include it in pricing reports.';
        $ready=empty($body['manual_name'])&&!empty($body['product_id'])&&isset($body['size_conversion_id'])&&$body['size_conversion_id']!==''&&($row['total_cost_ex_vat']??null)!==null&&($row['selling_price_inc_vat']??0)>0&&in_array($body['vat_treatment']??'',['standard','zero','exempt'],true);
        if($ready&&empty($input['archive'])){
            $financial=$body;$financial['request_id']='sheet-'.$mutation;
            try{$saved=rpm_save($pdo,$financial,$settings);$body['id']=$saved['id'];$body['version']=$saved['version']??$body['version'];$row['id']=$body['id'];$row['version']=$body['version'];$row['saved']=true;$state='priced';$note='';}
            catch(DomainException $e){$note='Draft saved; pricing update needs review: '.$e->getMessage();}
        }
        $revision=(int)($existing['revision']??0)+1;$result=['ok'=>true,'row_key'=>$key,'revision'=>$revision,'state'=>$state,'body'=>$body,'row'=>$row,'note'=>$note,'archived'=>!empty($input['archive'])];
        $values=[$revision,!empty($body['id'])?(int)$body['id']:null,$state,json_encode($body),$mutation,$hash,json_encode($result),$note,empty($input['archive'])?1:0];
        if($existing){$values[]=$existing['id'];$pdo->prepare('UPDATE cw_retail_sheet_drafts SET revision=?,pricing_id=?,state_key=?,payload_json=?,mutation_id=?,mutation_hash=?,response_json=?,note_text=?,active=? WHERE id=?')->execute($values);}
        else{$pdo->prepare('INSERT INTO cw_retail_sheet_drafts(revision,pricing_id,state_key,payload_json,mutation_id,mutation_hash,response_json,note_text,active,owner_id,row_key) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($values,[$owner,$key]));}
        $pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
