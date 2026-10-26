<?php
declare(strict_types=1);
require_once __DIR__.'/cost-workbook-categories.php';

// Source corrections only. Existing invoice files, line IDs and landed snapshots remain intact.
function si_sheet_state(PDO $pdo,int $pid,int $sid,bool $lock=false):array {
    $get=function(string $table,int $id)use($pdo,$lock):array {
        $q=$pdo->prepare("SELECT * FROM $table WHERE id=?".($lock?' FOR UPDATE':''));$q->execute([$id]);return $q->fetch()?:[];
    };
    $p=$get('cw_cost_products',$pid);$s=$get('cw_cost_product_sources',$sid);
    if(!$p||empty($p['active'])||!$s||empty($s['active'])||(int)$s['product_id']!==$pid)throw new DomainException('This product source is no longer available. Refresh the table.');
    $l=$get('cw_supplier_invoice_lines',(int)($s['supplier_invoice_line_id']??0));
    $i=$get('cw_supplier_invoices',(int)($l['supplier_invoice_id']??0));
    if($l&&(empty($l['active'])||($i['approval_status']??'')!=='approved'))throw new DomainException('This source is no longer an approved purchase.');
    $sp=$get('cw_suppliers',(int)($s['supplier_id']??0));
    $q=$pdo->prepare('SELECT * FROM cw_landed_product_costs WHERE product_source_id=? ORDER BY calculation_version DESC,id DESC LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([$sid]);$c=$q->fetch();
    if(!$c)throw new DomainException('No approved costing snapshot is available.');
    $q=$pdo->prepare("SELECT MAX(id) FROM cw_cost_audit_events WHERE (entity_type='cost_product' AND entity_id=?) OR (entity_type='supplier_invoice' AND entity_id=?) OR (entity_type='supplier_invoice_line' AND entity_id=?)");$q->execute([$pid,(int)($i['id']??0),(int)($l['id']??0)]);
    $state=compact('p','s','l','i','sp','c');$state['audit']=$q->fetchColumn();
    $state['version']=hash('sha256',json_encode($state));return $state;
}
function si_sheet_row(array $a):array {
    extract($a);
    $r=$c+['product_id'=>$p['id']];
    $r=array_merge($r,['product_name'=>$p['product_name'],'short_code'=>$p['short_code'],'woo_sku'=>$p['woo_sku'],'product_category'=>$p['product_category'],'supplier_name'=>$s['supplier_name'],'supplier_type'=>$i['supplier_type']??($sp['supplier_type']??'Uncategorised'),'supplier_id'=>$s['supplier_id'],'quantity'=>$s['total_purchased_quantity'],'source_type'=>$s['source_type'],'invoice_number'=>$i['invoice_number']??$s['manual_reference'],'supplier_invoice_id'=>$i['id']??null,'updated_at'=>$i['updated_at']??null,'uploaded_at'=>$i['uploaded_at']??$s['created_at'],'invoice_date'=>$i['invoice_date']??$s['cost_date'],'line_id'=>$l['id']??null,'purchase_quantity'=>$l['quantity']??$s['total_purchased_quantity'],'source_cost'=>$l['line_subtotal']??$s['total_supplier_cost'],'source_currency'=>$i['currency']??$s['currency'],'purchase_unit'=>$l['purchase_unit']??null,'pack_size'=>$l['pack_size']??null,'shipping_weight_kg'=>$l['shipping_weight_kg']??null,'version'=>$version]);
    if(in_array($r['status_key'],['exchange_rate_required','vat_review_required'],true))$r['supplier_cost_per_base']=null;
    if($r['status_key']!=='complete'){$r['transport_cost_per_base']=null;$r['landed_cost_per_base']=null;}return $r;
}
function si_sheet_save(PDO $pdo,array $b):array {
    $pid=(int)($b['product_id']??0);$sid=(int)($b['source_id']??0);$field=(string)($b['field']??'');$value=trim((string)($b['value']??''));
    $allowed=['invoice_date','supplier_name','supplier_type','invoice_number','product_name','short_code','product_category','quantity','purchase_unit','line_subtotal','transport_cost_per_base'];
    if(!in_array($field,$allowed,true))throw new DomainException('This field is calculated or cannot be edited inline.');
    $request=(string)($b['request_id']??'');if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$request))throw new DomainException('Missing correction request ID.');
    if(strlen($value)>255)throw new DomainException('Use no more than 255 characters.');
    $fingerprint=hash('sha256',json_encode([$pid,$sid,$field,$value]));
    $pdo->beginTransaction();
    try {
        $pdo->query("SELECT setting_key FROM cw_settings WHERE setting_key='schema_version' FOR UPDATE")->fetchColumn();
        $a=si_sheet_state($pdo,$pid,$sid,true);extract($a);
        $q=$pdo->prepare("SELECT after_json FROM cw_cost_audit_events WHERE entity_type='cost_product' AND entity_id=? AND action_key='sheet_committed' AND JSON_UNQUOTE(JSON_EXTRACT(after_json,'$.request_id'))=? ORDER BY id DESC LIMIT 1");$q->execute([$pid,$request]);$previous=$q->fetchColumn();
        if($previous){$previous=json_decode($previous,true);if(($previous['fingerprint']??'')!==$fingerprint)throw new DomainException('This request was already used for a different correction.');$pdo->commit();return ['ok'=>true,'rows'=>[si_sheet_row($a)],'replayed'=>true];}
        if(!hash_equals($version,(string)($b['version']??'')))throw new DomainException('This record was updated elsewhere. Review the latest value before saving.');
        $r=si_sheet_row($a);$initial=$field==='quantity'?$r['purchase_quantity']:($field==='line_subtotal'?$r['source_cost']:$r[$field]);
        $numeric=in_array($field,['quantity','line_subtotal','transport_cost_per_base'],true);
        if($numeric){
            $n=$field==='transport_cost_per_base'?(preg_match('/^\d+(?:\.\d{1,8})?$/',$value)?$value:null):cw_decimal($value);
            if($n===null||!is_finite((float)$n)||(float)$n<0||($field==='quantity'&&(float)$n<=0))throw new DomainException('Enter a positive quantity or a non-negative cost (transport supports up to 8 decimals).');$value=$n;
        }
        if(($numeric&&$initial!==null&&(float)$initial===(float)$value&&($field!=='transport_cost_per_base'||isset($s['manual_transport_per_base'])))||(!$numeric&&(string)$initial===$value)){$pdo->commit();return ['ok'=>true,'rows'=>[$r],'unchanged'=>true];}
        $u=cw_user();
        if($field==='transport_cost_per_base'){
            if((float)$value>9999999999.99999999||(float)$value*(float)$c['base_quantity']>9999999999.99999999)throw new DomainException('Transport amount is too large.');
            $pdo->prepare('UPDATE cw_cost_product_sources SET manual_transport_per_base=? WHERE id=? AND product_id=?')->execute([$value,$sid,$pid]);
            $status=in_array($c['status_key'],['exchange_rate_required','vat_review_required'],true)?$c['status_key']:'complete';
            if($l){
                $sourceLine=array_merge($l,['currency'=>$i['currency'],'exchange_rate'=>$i['exchange_rate'],'fx_confirmed'=>$i['fx_confirmed'],'vat_treatment'=>$i['vat_treatment']]);
                $cost=lpc_source_cost($sourceLine);
                $status=$cost!==null?'complete':($i['currency']!=='NAD'?'exchange_rate_required':'vat_review_required');
            }
            lpc_snapshot($pdo,$pid,$sid,(int)$s['supplier_id']?:null,$l?(int)$l['id']:null,null,$c['costing_basis'],$c['base_unit'],(float)$c['base_quantity'],(float)$c['supplier_cost_used'],(float)$value*(float)$c['base_quantity'],'NAD',$c['cost_date'],$status);
        } elseif(in_array($field,['product_name','short_code','product_category'],true)){
            if($value==='')throw new DomainException('Enter a value.');
            if($field==='product_category'&&!in_array($value,cw_supplier_category_choices($pdo),true))throw new DomainException('Select a valid category.');
            if($field==='short_code'){
                if($p['woo_sku']!=='')throw new DomainException('The verified WooCommerce SKU is protected. Use invoice review for product matching.');
                if(strlen($value)>50)throw new DomainException('Use a reference of 50 characters or fewer.');
                $q=$pdo->prepare('SELECT id FROM cw_cost_products WHERE id<>? AND (LOWER(short_code)=LOWER(?) OR LOWER(woo_sku)=LOWER(?)) LIMIT 1');$q->execute([$pid,$value,$value]);if($q->fetchColumn())throw new DomainException('This SKU/reference matches another product. Review the product match; products will not be merged.');
            }
            if($field==='product_name'){$q=$pdo->prepare('SELECT id FROM cw_cost_products WHERE normalized_name=? AND id<>? LIMIT 1');$q->execute([lpc_norm($value),$pid]);if($q->fetchColumn())throw new DomainException('This name matches an existing product. Review the product match; products will not be merged.');}
            $pdo->prepare("UPDATE cw_cost_products SET $field=?,updated_by=?,updated_by_name=? WHERE id=?")->execute([$value,$u['id'],$u['name'],$pid]);
            if($field==='product_name')$pdo->prepare('UPDATE cw_cost_products SET normalized_name=? WHERE id=?')->execute([lpc_norm($value),$pid]);
        } elseif($l) {
            if(in_array($field,['quantity','line_subtotal','purchase_unit'],true)){
                $next=$l;$next[$field]=$value;
                if($field==='purchase_unit'&&!in_array($value,['g','kg','ml','L','unit'],true))throw new DomainException('Select a valid purchase unit.');
                [$qty,$unit,$error]=si_base($pdo,$next['quantity'],$next['pack_size'],$next['purchase_unit']);
                if($error)throw new DomainException($error);
                $next['base_quantity']=$qty;$next['base_unit']=$unit;
                [, $canonical]=lpc_basis($next);if($canonical!==$p['base_unit'])throw new DomainException('This changes the product costing unit. Review the purchase and product match instead.');
                if($field==='line_subtotal'&&(float)$l['line_subtotal']>0)$next['vat_amount']=round((float)$value*(float)($l['vat_amount']??0)/(float)$l['line_subtotal'],2);
                $next['line_total']=(float)$next['line_subtotal']+(float)($next['vat_amount']??0);
                $pdo->prepare('UPDATE cw_supplier_invoice_lines SET quantity=?,purchase_unit=?,base_quantity=?,base_unit=?,line_subtotal=?,vat_amount=?,line_total=?,base_unit_cost=? WHERE id=? AND supplier_invoice_id=?')->execute([$next['quantity'],$next['purchase_unit'],$qty,$unit,$next['line_subtotal'],$next['vat_amount'],$next['line_total'],(float)$next['line_subtotal']/(float)$qty,$l['id'],$i['id']]);
                cw_supplier_cost_audit($pdo,'supplier_invoice_line',(int)$l['id'],'sheet_corrected',$l,$next);
            } else {
                $next=$i;
                if($field==='supplier_name'){$q=$pdo->prepare('SELECT * FROM cw_suppliers WHERE name=? AND active=1');$q->execute([$value]);$supplier=$q->fetch();if(!$supplier)throw new DomainException('Select an existing supplier. Add new suppliers through invoice review.');$next['supplier_id']=$supplier['id'];$next['supplier_name']=$supplier['name'];$next['supplier_type']=$supplier['supplier_type'];}
                elseif($field==='supplier_type'){if(!in_array($value,si_types(true),true))throw new DomainException('Select a valid supplier type.');$next[$field]=$value;}
                elseif($field==='invoice_date'){if(!si_date($value))throw new DomainException('Select a valid invoice date.');$next[$field]=$value;}
                else {if($value==='')throw new DomainException('Enter an invoice number.');$next[$field]=$value;}
                $q=$pdo->prepare("SELECT id FROM cw_supplier_invoices WHERE id<>? AND supplier_id=? AND invoice_date=? AND LOWER(TRIM(invoice_number))=LOWER(TRIM(?)) AND approval_status<>'archived'");$q->execute([$i['id'],$next['supplier_id'],$next['invoice_date'],$next['invoice_number']]);if($q->fetchColumn())throw new DomainException('This matches another supplier invoice. Review before changing it.');
                $pdo->prepare('UPDATE cw_supplier_invoices SET supplier_id=?,supplier_name=?,supplier_type=?,invoice_number=?,invoice_date=? WHERE id=?')->execute([$next['supplier_id'],$next['supplier_name'],$next['supplier_type'],$next['invoice_number'],$next['invoice_date'],$i['id']]);
                cw_supplier_cost_audit($pdo,'supplier_invoice',(int)$i['id'],'sheet_corrected',$i,$next);
            }
            $pdo->prepare('UPDATE cw_supplier_invoices SET last_edited_by=?,last_edited_by_name=?,updated_at=NOW() WHERE id=?')->execute([$u['id'],$u['name'],$i['id']]);
            lpc_refresh($pdo,[(int)$i['id']]);
        } else {
            if($s['currency']!=='NAD'||$field==='purchase_unit')throw new DomainException('Use source review to confirm the currency or purchase unit.');
            $next=$s;
            if($field==='quantity')$next['total_purchased_quantity']=$value;
            elseif($field==='line_subtotal')$next['total_supplier_cost']=$value;
            elseif($field==='invoice_number')$next['manual_reference']=$value;
            elseif($field==='invoice_date'){if(!si_date($value))throw new DomainException('Select a valid invoice date.');$next['cost_date']=$value;}
            elseif($field==='supplier_name'){$q=$pdo->prepare('SELECT id,name FROM cw_suppliers WHERE name=? AND active=1');$q->execute([$value]);$supplier=$q->fetch();if(!$supplier)throw new DomainException('Select an existing supplier.');$next['supplier_id']=$supplier['id'];$next['supplier_name']=$supplier['name'];}
            else {if(!in_array($value,si_types(true),true))throw new DomainException('Select a valid supplier type.');$pdo->prepare('UPDATE cw_suppliers SET supplier_type=?,type_confirmed=1 WHERE id=?')->execute([$value,$s['supplier_id']]);cw_supplier_cost_audit($pdo,'supplier',(int)$s['supplier_id'],'type_corrected',$sp,['supplier_type'=>$value]);}
            $pdo->prepare('UPDATE cw_cost_product_sources SET supplier_id=?,supplier_name=?,manual_reference=?,cost_date=?,total_purchased_quantity=?,total_supplier_cost=? WHERE id=?')->execute([$next['supplier_id'],$next['supplier_name'],$next['manual_reference'],$next['cost_date'],$next['total_purchased_quantity'],$next['total_supplier_cost'],$sid]);
            $transport=isset($s['manual_transport_per_base'])?(float)$s['manual_transport_per_base']*(float)$next['total_purchased_quantity']:(float)$c['transport_cost_used'];
            lpc_snapshot($pdo,$pid,$sid,(int)$next['supplier_id']?:null,null,null,$c['costing_basis'],$c['base_unit'],(float)$next['total_purchased_quantity'],(float)$next['total_supplier_cost'],$transport,'NAD',$next['cost_date'],$c['status_key']);
        }
        cw_supplier_cost_audit($pdo,'cost_product',$pid,'sheet_committed',$a,['field'=>$field,'value'=>$value,'request_id'=>$request,'fingerprint'=>$fingerprint]);
        $ids=[['product_id'=>$pid,'id'=>$sid]];
        if($i&&$field!=='transport_cost_per_base'){$q=$pdo->prepare('SELECT s.product_id,s.id FROM cw_cost_product_sources s JOIN cw_supplier_invoice_lines l ON l.id=s.supplier_invoice_line_id WHERE l.supplier_invoice_id=? AND s.active=1 AND l.active=1');$q->execute([$i['id']]);$ids=$q->fetchAll();}
        $rows=[];foreach($ids as $id)$rows[]=si_sheet_row(si_sheet_state($pdo,(int)$id['product_id'],(int)$id['id']));
        $pdo->commit();return ['ok'=>true,'rows'=>$rows];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
