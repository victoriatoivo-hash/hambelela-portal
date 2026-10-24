<?php
declare(strict_types=1);

/** Only unposted product lines can enter the missing-item review. Saved history wins over UI filters. */
function si_recovery_pending_sql(string $invoice='i'): string
{
    return "EXISTS(SELECT 1 FROM cw_supplier_invoice_lines r WHERE r.supplier_invoice_id=$invoice.id AND r.active=1 AND r.review_status='needs_review' AND r.item_type<>'additional_cost' AND NOT EXISTS(SELECT 1 FROM cw_cost_product_sources s WHERE s.supplier_invoice_line_id=r.id))";
}
function si_recovery_lines(PDO $pdo,int $id): array
{
    $q=$pdo->prepare("SELECT l.* FROM cw_supplier_invoice_lines l WHERE l.supplier_invoice_id=? AND l.active=1 AND l.review_status='needs_review' AND l.item_type<>'additional_cost' AND NOT EXISTS(SELECT 1 FROM cw_cost_product_sources s WHERE s.supplier_invoice_line_id=l.id) AND NOT EXISTS(SELECT 1 FROM cw_transport_product_allocations t WHERE t.supplier_invoice_line_id=l.id) ORDER BY l.id");
    $q->execute([$id]);return $q->fetchAll();
}
function si_recovery_key(string $text): string
{
    $text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
    return preg_replace('/[^\p{L}\p{N}]+/u','',trim($text));
}
function si_recovery_token(array $invoice,array $lines): string
{
    return hash('sha256',json_encode([$invoice,$lines]));
}

/** Reuse stable line IDs, including lines removed during partial review; never change posted lines. */
function si_recover_invoice(PDO $pdo,int $id,array $extracted): array
{
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT * FROM cw_supplier_invoices WHERE id=? AND approval_status<>'archived' FOR UPDATE");$q->execute([$id]);$invoice=$q->fetch();
        if(!$invoice)throw new DomainException('The original invoice is no longer available for review.');
        $q=$pdo->prepare("SELECT l.*,(SELECT COUNT(*) FROM cw_cost_product_sources s WHERE s.supplier_invoice_line_id=l.id)+(SELECT COUNT(*) FROM cw_transport_product_allocations t WHERE t.supplier_invoice_line_id=l.id)+(SELECT COUNT(*) FROM cw_product_matches m WHERE m.supplier_invoice_line_id=l.id) reference_count FROM cw_supplier_invoice_lines l WHERE supplier_invoice_id=? ORDER BY id FOR UPDATE");$q->execute([$id]);$saved=$q->fetchAll();
        $added=0;$restored=0;$skipped=0;$ambiguous=0;$used=[];
        foreach($extracted as $item){
            $name=trim((string)($item['name']??''));if($name==='')continue;
            $sku=trim((string)($item['supplier_sku']??$item['sku']??''));$key=si_recovery_key($name);$matches=[];
            foreach($saved as $line){
                if(($sku!=='' && si_recovery_key($sku)===si_recovery_key((string)($line['supplier_sku']??''))) || in_array($key,[si_recovery_key((string)$line['raw_description']),si_recovery_key((string)$line['product_description'])],true))$matches[]=$line;
            }
            if($matches){
                // Never guess between repeated descriptions/SKUs or differing invoice lines.
                if(count($matches)>1 || isset($used[$key])){$ambiguous++;continue;}
                $line=$matches[0];$used[$key]=true;
                if(!(int)$line['active'] && !(int)$line['reference_count']){
                    $pdo->prepare("UPDATE cw_supplier_invoice_lines SET active=1,review_status='needs_review' WHERE id=?")->execute([$line['id']]);
                    cw_supplier_cost_audit($pdo,'supplier_invoice_line',(int)$line['id'],'missing_item_restored',$line,['invoice_id'=>$id]);$restored++;
                }else $skipped++;
                continue;
            }
            $quantity=cw_decimal($item['quantity']??null);$unitPrice=cw_decimal($item['unit_price']??null);$total=cw_decimal($item['line_total']??null);
            if($total===null && $quantity!==null && $unitPrice!==null)$total=number_format((float)$quantity*(float)$unitPrice,6,'.','');
            $q=$pdo->prepare("INSERT INTO cw_supplier_invoice_lines(supplier_invoice_id,raw_description,product_description,supplier_sku,product_category,quantity,purchase_unit,unit_price,line_subtotal,line_total,item_type,match_status,review_status,extraction_confidence) VALUES(?,?,?,?,?,?,?,?,?,?,'unclassified','unmatched','needs_review','low')");
            $q->execute([$id,$name,$name,$sku,'Other',$quantity,(string)($item['unit']??''),$unitPrice,$total,$total]);$lineId=(int)$pdo->lastInsertId();
            $saved[]=['id'=>$lineId,'raw_description'=>$name,'product_description'=>$name,'supplier_sku'=>$sku,'active'=>1,'reference_count'=>0];$used[$key]=true;$added++;
            cw_supplier_cost_audit($pdo,'supplier_invoice_line',$lineId,'missing_item_extracted',null,['invoice_id'=>$id,'description'=>$name]);
        }
        $pending=si_recovery_lines($pdo,$id);
        $message='Existing invoice recognised. '.$added.' missing items extracted; '.$restored.' previously omitted items restored; '.$skipped.' existing items kept unchanged. '.count($pending).' items awaiting review.';
        if($ambiguous)$message.=' '.$ambiguous.' repeated/ambiguous lines were not added automatically. Check the original invoice before proceeding.';
        if(!$extracted)$message.=' Extraction returned no items; review the original file and the saved pending items. This does not confirm that the invoice is complete.';
        $pdo->prepare('UPDATE cw_supplier_invoices SET extraction_message=?,updated_at=NOW() WHERE id=?')->execute([substr($message,0,500),$id]);
        cw_supplier_cost_audit($pdo,'supplier_invoice',$id,'missing_items_reviewed',null,['added'=>$added,'restored'=>$restored,'skipped'=>$skipped,'ambiguous'=>$ambiguous]);
        $pdo->commit();return ['ok'=>true,'id'=>$id,'reused'=>true,'missing_only'=>$invoice['approval_status']==='approved','pending_count'=>count($pending),'message'=>$message];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Approve only selected missing lines. No invoice metadata, freight allocations or old costs change. */
function si_save_recovered(PDO $pdo,array $body): array
{
    $id=(int)($body['id']??0);$approve=!empty($body['approve']);$pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT * FROM cw_supplier_invoices WHERE id=? AND approval_status='approved' FOR UPDATE");$q->execute([$id]);$invoice=$q->fetch();
        if(!$invoice)throw new DomainException('Reopen the original invoice before reviewing missing items.');
        $pending=si_recovery_lines($pdo,$id);$allowed=array_map('intval',array_column($pending,'id'));$ids=[];$lines=(array)($body['lines']??[]);
        if(!hash_equals(si_recovery_token($invoice,$pending),(string)($body['recovery_token']??'')))throw new DomainException('These missing items changed since you opened them. Reopen the review before saving.');
        if(!$lines)throw new DomainException('Select at least one missing item to save.');
        foreach($lines as $line){$lid=(int)($line['id']??0);if(!$lid||!in_array($lid,$allowed,true)||in_array($lid,$ids,true))throw new DomainException('An item was already approved or changed. Reopen the missing-item review; no duplicate was saved.');$ids[]=$lid;}
        if((string)($body['updated_at']??'')!==(string)$invoice['updated_at'])throw new DomainException('This invoice changed. Reopen its missing items before saving.');
        $valid=cw_supplier_save_lines($pdo,$id,$lines,[],$allowed);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        if($approve){
            if($valid!==count($ids))throw new DomainException('Confirm quantities, pack sizes, units and source costs for the selected items.');
            if($invoice['vat_treatment']==='unconfirmed'||($invoice['currency']!=='NAD'&&(!$invoice['fx_confirmed']||(float)$invoice['exchange_rate']<=0)))throw new DomainException('The original invoice currency and VAT must be verified before approval.');
            cw_supplier_resolve_products($pdo,$id,$ids);
            lpc_refresh($pdo,[$id],$ids);
        }else{
            // An approved invoice must not publish a newly saved draft on a later global refresh.
            $pdo->prepare("UPDATE cw_supplier_invoice_lines SET review_status='needs_review' WHERE id IN($marks)")->execute($ids);
        }
        $pdo->prepare('UPDATE cw_supplier_invoices SET updated_at=NOW() WHERE id=?')->execute([$id]);
        cw_supplier_cost_audit($pdo,'supplier_invoice',$id,$approve?'missing_items_approved':'missing_items_saved',null,['line_ids'=>$ids]);
        $pdo->commit();return ['ok'=>true,'id'=>$id,'message'=>$approve?'Selected missing items approved. Existing products and transport allocations were preserved. Set any new item transport individually in the table.':'Missing items saved for review. Existing approved costs are unchanged.'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
