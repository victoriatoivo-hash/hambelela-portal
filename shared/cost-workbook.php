<?php
declare(strict_types=1);

// Canonical supplier/landed-cost engine shared by invoice and costing entry points.
function lpc_norm(string $s):string{$s=strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/',' ',$s)));return trim(preg_replace('/\s+/',' ',$s));}
function lpc_user():array{$u=current_user();return['id'=>(int)($u['id']??0),'name'=>(string)($u['name']??$u['full_name']??'Owner')];}
function lpc_prefix(string $name):string{$ignore=['and','of','the','with'];$words=array_values(array_filter(explode(' ',lpc_norm($name)),function($w)use($ignore){return $w!==''&&!in_array($w,$ignore,true);}));if(count($words)>1){$p='';foreach(array_slice($words,0,3)as$w)$p.=substr($w,0,1);}else{$p=substr((string)($words[0]??'prd'),0,3);}return strtoupper(strlen($p)<2?str_pad($p,2,'X'):$p);}
function lpc_code(PDO $pdo,string $name):string{$p=lpc_prefix($name);$st=$pdo->prepare('SELECT short_code FROM cw_cost_products WHERE short_code LIKE ? ORDER BY id DESC');$st->execute([$p.'%']);$used=array_flip($st->fetchAll(PDO::FETCH_COLUMN));for($n=1;$n<100000;$n++){$c=$p.str_pad((string)$n,3,'0',STR_PAD_LEFT);if(!isset($used[$c]))return$c;}throw new RuntimeException('A unique short code could not be generated.');}
function lpc_basis(array $line):array{$u=(string)($line['base_unit']??$line['purchase_unit']??'');if(in_array($u,['kg','g'],true))return['weight','kg'];if(in_array($u,['L','ml'],true))return['volume','L'];if($u==='unit')return['count','each'];if($u==='pack')return['ready_made','unit'];return[null,null];}
function lpc_quantity(array $line,$basis):float{$q=(float)($line['base_quantity']??0);$u=(string)($line['base_unit']??'');if($q<=0){$q=(float)($line['quantity']??0);$pack=(float)($line['pack_size']??0);if($pack>0)$q*=$pack;$u=(string)($line['purchase_unit']??'');}if($basis==='weight'&&$u==='g')$q/=1000;if($basis==='volume'&&$u==='ml')$q/=1000;return$q;}
function lpc_source_cost(array $line): ?float
{
    $currency = strtoupper((string)($line['currency'] ?? ''));
    $vat = (string)($line['line_vat_treatment'] ?? '');
    if (!in_array($vat,['exclusive','inclusive','exempt','mixed'],true)) $vat=(string)($line['vat_treatment']??'');
    if (!in_array($vat,['exclusive','inclusive','exempt','mixed'],true)) return null;
    // Confirmed ex-VAT source values are never replaced by an arbitrary currency-labelled total.
    $amount = $vat === 'exempt' ? ($line['line_total'] ?? null) : ($line['line_subtotal'] ?? null);
    if ($amount === null || !is_numeric($amount) || (float)$amount < 0) return null;
    if ($currency === 'NAD') return (float)$amount;
    $rate = (float)($line['exchange_rate'] ?? 0);
    if (empty($line['fx_confirmed']) || $rate <= 0) return null;
    return (float)$amount * $rate;
}
function lpc_snapshot(PDO $pdo,int $productId,int $sourceId,?int $supplierId,?int $lineId,?int $transportProductId,string $basis,string $unit,float $qty,float $supplier,float $transport,string $currency,string $date,string $status): bool
{
    if ($qty<=0 || !is_finite($qty)) throw new DomainException('A positive purchased base quantity is required.');
    $q=$pdo->prepare('SELECT * FROM cw_landed_product_costs WHERE product_source_id=? ORDER BY calculation_version DESC,id DESC LIMIT 1 FOR UPDATE');
    $q->execute([$sourceId]);$previous=$q->fetch();
    $signature=function(array $r):string {return implode('|',[(int)$r['product_id'],(int)$r['product_source_id'],(int)($r['supplier_id']??0),(int)($r['supplier_invoice_line_id']??0),(int)($r['transport_product_allocation_id']??0),$r['costing_basis'],$r['base_unit'],sprintf('%.8F',$r['base_quantity']),sprintf('%.8F',$r['supplier_cost_used']),sprintf('%.8F',$r['transport_cost_used']),$r['currency'],$r['cost_date'],$r['status_key']]);};
    $values=['product_id'=>$productId,'product_source_id'=>$sourceId,'supplier_id'=>$supplierId,'supplier_invoice_line_id'=>$lineId,'transport_product_allocation_id'=>$transportProductId,'costing_basis'=>$basis,'base_unit'=>$unit,'base_quantity'=>$qty,'supplier_cost_used'=>$supplier,'transport_cost_used'=>$transport,'currency'=>$currency,'cost_date'=>$date,'status_key'=>$status];
    if($previous && $signature($previous)===$signature($values))return false;
    $version=$previous?(int)$previous['calculation_version']+1:1;
    $hash=hash('sha256',$signature($values).'|revision:'.$version);
    $actor=lpc_user();
    $pdo->prepare('INSERT INTO cw_landed_product_costs(product_id,product_source_id,supplier_id,supplier_invoice_line_id,transport_product_allocation_id,costing_basis,base_unit,base_quantity,supplier_cost_used,transport_cost_used,supplier_cost_per_base,transport_cost_per_base,landed_cost_per_base,currency,cost_date,status_key,calculation_version,calculation_hash,created_by,created_by_name) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$productId,$sourceId,$supplierId,$lineId,$transportProductId,$basis,$unit,$qty,$supplier,$transport,$supplier/$qty,$transport/$qty,($supplier+$transport)/$qty,$currency,$date,$status,$version,$hash,$actor['id'],$actor['name']]);
    return true;
}
function lpc_refresh(PDO $pdo, array $invoiceIds=[]): array
{
    $owned = !$pdo->inTransaction();
    if ($owned) $pdo->beginTransaction();
    $created = 0; $snapshots = 0;
    try {
        $scope=$invoiceIds?' AND i.id IN('.implode(',',array_map('intval',$invoiceIds)).')':'';
        $lines = $pdo->query("SELECT l.*,i.supplier_id,i.supplier_name,i.invoice_number,i.invoice_date,i.currency,i.exchange_rate,i.fx_confirmed,i.vat_treatment,i.approval_status FROM cw_supplier_invoice_lines l JOIN cw_supplier_invoices i ON i.id=l.supplier_invoice_id WHERE i.approval_status='approved' AND l.active=1 AND l.review_status='valid' AND l.item_type NOT IN('additional_cost','unclassified')".$scope." ORDER BY i.invoice_date,l.id FOR UPDATE")->fetchAll();
        foreach ($lines as $line) {
            $name = trim((string)$line['product_description']);
            if ($name === '') continue;
            [$basis,$unit] = lpc_basis($line);
            $qty = lpc_quantity($line,$basis);
            $cost = lpc_source_cost($line);
            $sourceCost=(float)($line['vat_treatment']==='exempt'?($line['line_total']??0):($line['line_subtotal']??0));
            $srcQuery = $pdo->prepare('SELECT * FROM cw_cost_product_sources WHERE supplier_invoice_line_id=? FOR UPDATE');
            $srcQuery->execute([(int)$line['id']]); $source = $srcQuery->fetch();
            $pid = (int)($line['cost_product_id'] ?? ($source['product_id'] ?? 0));
            if (!$pid) {
                $find = $pdo->prepare('SELECT id FROM cw_cost_products WHERE active=1 AND ((woo_product_id IS NOT NULL AND woo_product_id=? AND COALESCE(woo_variation_id,0)=?) OR normalized_name=?) ORDER BY id');
                $find->execute([(int)($line['woo_product_id']??0),(int)($line['woo_variation_id']??0),lpc_norm($name)]);
                $matches = $find->fetchAll(PDO::FETCH_COLUMN);
                if (count($matches)>1) throw new DomainException('Confirm the canonical product for '.$name.'; several products match.');
                $pid = (int)($matches[0] ?? 0);
            }
            if (!$pid) {
                $u = lpc_user();
                $sku = '';
                if ((int)($line['woo_product_id']??0)>0) {
                    $woo = $pdo->prepare('SELECT sku FROM cw_product_snapshots WHERE product_id=? AND variation_id=? ORDER BY sync_batch_id DESC LIMIT 1');
                    $woo->execute([(int)$line['woo_product_id'],(int)($line['woo_variation_id']??0)]); $sku=(string)$woo->fetchColumn();
                }
                $pdo->prepare('INSERT INTO cw_cost_products(product_name,normalized_name,short_code,woo_product_id,woo_variation_id,woo_sku,product_category,costing_basis,base_unit,created_by,created_by_name) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$name,lpc_norm($name),lpc_code($pdo,$name),(int)($line['woo_product_id']??0)?:null,(int)($line['woo_variation_id']??0)?:null,$sku,$line['product_category']??'Other',$basis,$unit,$u['id'],$u['name']]);
                $pid=(int)$pdo->lastInsertId(); $created++;
            }
            $product = $pdo->prepare('SELECT id,costing_basis,base_unit FROM cw_cost_products WHERE id=? AND active=1 FOR UPDATE');
            $product->execute([$pid]); $p=$product->fetch();
            if (!$p || ($p['base_unit'] && $unit!==$p['base_unit'])) throw new DomainException('The product costing unit does not match this purchase. Confirm the product before saving.');
            $pdo->prepare('UPDATE cw_supplier_invoice_lines SET cost_product_id=? WHERE id=?')->execute([$pid,$line['id']]);
            $sourceId=(int)($source['id']??0);
            $date=(string)$line['invoice_date'];
            if (!$date) throw new DomainException('Approved purchases require an effective date.');
            if (!$sourceId) {
                $u=lpc_user();
                $pdo->prepare("INSERT INTO cw_cost_product_sources(product_id,supplier_id,supplier_name,supplier_invoice_line_id,source_type,cost_date,total_purchased_quantity,total_supplier_cost,currency,vat_treatment,created_by,created_by_name) VALUES(?,?,?,?,'invoice',?,?,?,?,?,?,?)")->execute([$pid,$line['supplier_id']?:null,$line['supplier_name'],$line['id'],$date,$qty,$sourceCost,$line['currency'],$line['vat_treatment'],$u['id'],$u['name']]);
                $sourceId=(int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE cw_cost_product_sources SET product_id=?,supplier_id=?,supplier_name=?,cost_date=?,total_purchased_quantity=?,total_supplier_cost=?,currency=?,vat_treatment=? WHERE id=?')->execute([$pid,$line['supplier_id']?:null,$line['supplier_name'],$date,$qty,$sourceCost,$line['currency'],$line['vat_treatment'],$sourceId]);
            }
            $ta=$pdo->prepare("SELECT pa.id,COALESCE(pa.override_amount,pa.calculated_amount) amount,t.currency,t.exchange_rate,t.fx_confirmed,t.vat_treatment,t.allocation_status FROM cw_transport_product_allocations pa JOIN cw_transport_allocations a ON a.id=pa.transport_allocation_id JOIN cw_transport_records t ON t.id=a.transport_id WHERE pa.supplier_invoice_line_id=? AND t.allocation_status<>'archived' ORDER BY pa.id");
            $ta->execute([$line['id']]); $transportRows=$ta->fetchAll();
            $transport=0; $transportId=null; $status=$transportRows?'complete':'transport_not_allocated';
            foreach ($transportRows as $tr) {
                $transportId=(int)$tr['id'];
                if ($tr['allocation_status']==='missing_weight') $status='shipping_weight_required';
                if ($tr['vat_treatment']==='unconfirmed') $status='vat_review_required';
                $rate=strtoupper($tr['currency'])==='NAD'?1:(empty($tr['fx_confirmed'])?0:(float)$tr['exchange_rate']);
                if ($rate<=0) $status='exchange_rate_required';
                else $transport+=(float)$tr['amount']*$rate;
            }
            if ($cost===null) $status=strtoupper($line['currency'])!=='NAD'?'exchange_rate_required':'vat_review_required';
            if (!$basis || !$unit || $qty<=0) continue;
            // Review snapshots retain the source and explicitly prevent presenting unverified costs as ready.
            $snapshots+=lpc_snapshot($pdo,$pid,$sourceId,(int)$line['supplier_id']?:null,(int)$line['id'],$transportId,$basis,$unit,$qty,$cost??0,$transport,'NAD',$date,$status)?1:0;
        }
        if ($owned) $pdo->commit();
        return ['products_created'=>$created,'snapshots_created'=>$snapshots,'lines_reviewed'=>count($lines)];
    } catch (Throwable $e) {
        if ($owned && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function cw_install_schema(PDO $pdo): void
{
    $version = 0;
    try {
        $version = (int) $pdo->query("SELECT setting_value FROM cw_settings WHERE setting_key='schema_version'")->fetchColumn();
    } catch (Throwable $e) {
        $version = 0;
    }
    if ($version < 1) {
        $sql = file_get_contents(BASE_PATH . '/apps/cost-manager/cost-workbook-migration.sql');
        if ($sql === false) throw new RuntimeException('Cost Workbook migration file is unavailable.');
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
            $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
            if ($statement !== '') $pdo->exec($statement);
        }
        $version = 2;
    }
    if ($version < 2) { cw_upgrade_sync_schema_v2($pdo); $version = 2; }
    if ($version < 3) { cw_upgrade_phase2_schema_v3($pdo); $version = 3; }
    if ($version < 4) { cw_upgrade_size_conversion_schema_v4($pdo); $version = 4; }
    if ($version < 5) { cw_upgrade_supplier_invoice_schema_v5($pdo); $version = 5; }
    if ($version < 6) { cw_upgrade_transport_schema_v6($pdo); $version = 6; }
    if ($version < 7) { cw_upgrade_packaging_schema_v7($pdo); $version = 7; }
    if ($version < 8) { cw_upgrade_landed_product_schema_v8($pdo); $version = 8; }
    if ($version < 9) { cw_upgrade_formulation_schema_v9($pdo); $version = 9; }
    if ($version < 10) { cw_upgrade_wholesale_pricing_schema_v10($pdo); $version = 10; }
    if ($version < 11) { cw_upgrade_retail_pricing_schema_v11($pdo); $version = 11; }
    if ($version < 12) cw_upgrade_supplier_costs_schema_v12($pdo);
    if ($version < 13) cw_upgrade_packaging_schema_v13($pdo);
}

function cw_upgrade_packaging_schema_v13(PDO $pdo): void
{
    $columns=[
        'cw_packaging_items'=>['specification'=>"VARCHAR(190) NOT NULL DEFAULT ''"],
        'cw_packaging_item_costs'=>[
            'supplier_name'=>"VARCHAR(190) NULL",'supplier_sku'=>"VARCHAR(120) NULL",
            'units_per_pack'=>'DECIMAL(18,6) NOT NULL DEFAULT 1','usable_units'=>'DECIMAL(18,6) NULL',
            'print_yield'=>'DECIMAL(18,6) NULL','printed_label'=>'TINYINT NOT NULL DEFAULT 0',
            'currency'=>"CHAR(3) NOT NULL DEFAULT 'NAD'",'exchange_rate'=>'DECIMAL(18,8) NOT NULL DEFAULT 1',
            'fx_confirmed'=>'TINYINT NOT NULL DEFAULT 1','tax_recoverable'=>'TINYINT NOT NULL DEFAULT 1',
            'freight_ex'=>'DECIMAL(18,8) NOT NULL DEFAULT 0','freight_inc'=>'DECIMAL(18,8) NOT NULL DEFAULT 0',
            'transport_id'=>'BIGINT UNSIGNED NULL','transport_evidence'=>'JSON NULL',
            'supersedes_cost_id'=>'BIGINT UNSIGNED NULL','status_key'=>"VARCHAR(40) NOT NULL DEFAULT 'ready'",
            'request_key'=>'VARCHAR(64) NULL'
        ],
        'cw_packaging_setups'=>['supersedes_setup_id'=>'BIGINT UNSIGNED NULL'],
    ];
    foreach($columns as $table=>$fields)foreach($fields as $column=>$definition)if(!cw_column_exists($pdo,$table,$column))$pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    $seed=$pdo->prepare('INSERT IGNORE INTO cw_packaging_types(name) VALUES(?)');
    foreach(['Tub','Lid','Ribbon'] as $name)$seed->execute([$name]);
    $pdo->exec("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','13','system') ON DUPLICATE KEY UPDATE setting_value='13',updated_by_name='system'");
}

/** Purchase packages are not usable units; a ribbon unit is one printed-label equivalent. */
function cw_packaging_calculate(array $b,string $type,float $vatRate): array
{
    foreach(['purchase_quantity','units_per_pack','amount_entered','exchange_rate'] as $key){
        if(!isset($b[$key])||!is_numeric($b[$key])||!is_finite((float)$b[$key])||(float)$b[$key]<0)throw new DomainException('Enter a valid '.$key.'.');
    }
    $qty=(float)$b['purchase_quantity'];$pack=(float)$b['units_per_pack'];$amount=(float)$b['amount_entered'];
    if($qty<=0||$pack<=0)throw new DomainException('Purchase quantity and units per pack must be greater than zero.');
    $currency=strtoupper((string)($b['currency']??'NAD'));$rate=$currency==='NAD'?1:(float)$b['exchange_rate'];
    if(!preg_match('/^[A-Z]{3}$/',$currency)||$rate<=0||($currency!=='NAD'&&empty($b['fx_confirmed'])))throw new DomainException('Confirm the source currency and exchange rate to NAD.');
    $basis=$b['cost_basis']??'';$vat=$b['vat_treatment']??'';
    if(!in_array($basis,['inclusive','exclusive'],true)||!in_array($vat,['standard','zero_rated','exempt'],true))throw new DomainException('Confirm VAT treatment and cost basis.');
    $factor=1+($vat==='standard'?$vatRate:0)/100;
    $net=($basis==='inclusive'?$amount/$factor:$amount)*$rate;$gross=($basis==='inclusive'?$amount:$amount*$factor)*$rate;
    if(empty($b['tax_recoverable']))$net=$gross;
    $units=$qty*$pack;$status='ready';$yield=null;
    if(strcasecmp($type,'Ribbon')===0){$yield=is_numeric($b['print_yield']??null)?(float)$b['print_yield']:0;if($yield<=0||!is_finite($yield)){$status='print_yield_required';$units=0;}else $units*=$yield;}
    if(!is_finite($units)||$units>1e12)throw new DomainException('Usable quantity is outside the supported range.');
    return ['cost_ex_vat'=>$net,'cost_inc_vat'=>$gross,'usable_units'=>$units?:null,'unit_cost_ex_vat'=>$units?$net/$units:0,'unit_cost_inc_vat'=>$units?$gross/$units:0,'status_key'=>$status,'print_yield'=>$yield,'exchange_rate'=>$rate];
}

function cw_packaging_cost(PDO $pdo,int $item,string $date): ?array
{
    $q=$pdo->prepare('SELECT c.*,i.item_name,i.specification,i.active,t.name packaging_type FROM cw_packaging_items i JOIN cw_packaging_types t ON t.id=i.packaging_type_id JOIN cw_packaging_item_costs c ON c.packaging_item_id=i.id WHERE i.id=? AND c.effective_date<=? ORDER BY c.effective_date DESC,c.id DESC LIMIT 1');$q->execute([$item,$date]);$c=$q->fetch();
    if(!$c)return null;
    if($c['packaging_type']==='Ribbon'&&(float)($c['print_yield']??0)<=0)$c['status_key']='print_yield_required';
    if($c['usable_units']===null&&!in_array(strtolower(trim($c['purchase_unit'])),['each','unit','units','piece','pieces'],true)&&$c['status_key']==='ready')$c['status_key']='usable_quantity_required';
    $units=$c['usable_units']===null?(float)$c['purchase_quantity']:(float)$c['usable_units'];
    if($c['status_key']!=='ready'||$units<=0){$c['landed_ex']=$c['landed_inc']=null;return $c;}
    $c['freight_unit_ex']=(float)$c['freight_ex']/$units;$c['freight_unit_inc']=(float)$c['freight_inc']/$units;
    $c['landed_ex']=(float)$c['unit_cost_ex_vat']+$c['freight_unit_ex'];$c['landed_inc']=(float)$c['unit_cost_inc_vat']+$c['freight_unit_inc'];return $c;
}

function cw_packaging_setup_cost(PDO $pdo,int $setup,string $date): array
{
    $q=$pdo->prepare('SELECT * FROM cw_packaging_setups WHERE id=? AND effective_date<=?');$q->execute([$setup,$date]);$s=$q->fetch();if(!$s)throw new DomainException('Packaging setup is not effective on this date.');
    $q=$pdo->prepare('SELECT * FROM cw_packaging_setup_components WHERE packaging_setup_id=? ORDER BY display_order,id');$q->execute([$setup]);$rows=$q->fetchAll();
    $ex=0;$inc=0;$supplier=0;$freight=0;$waste=0;$missing=[];$printed=false;$ribbon=false;
    foreach($rows as &$r){$c=cw_packaging_cost($pdo,(int)$r['packaging_item_id'],$date);$r['material']=$c;$m=(float)$r['quantity']*(1+(float)$r['waste_percent']/100);
        if(!$c||!$c['active']||$c['landed_ex']===null){$missing[]=$c['item_name']??('Material '.$r['packaging_item_id']);continue;}
        $printed=$printed||($c['packaging_type']==='Label'&&!empty($c['printed_label']));$ribbon=$ribbon||$c['packaging_type']==='Ribbon';
        $r['current_ex']=$m*$c['landed_ex'];$r['current_inc']=$m*$c['landed_inc'];$ex+=$r['current_ex'];$inc+=$r['current_inc'];
        $supplier+=(float)$r['quantity']*(float)$c['unit_cost_ex_vat'];$freight+=(float)$r['quantity']*$c['freight_unit_ex'];$waste+=(float)$r['quantity']*(float)$r['waste_percent']/100*$c['landed_ex'];
    }unset($r);
    if(!$rows)$missing[]='Components required';if($printed&&$ribbon)$missing[]='Printed labels already include printing; remove duplicate ribbon or use unprinted labels.';
    return array_merge($s,['components'=>$rows,'component_count'=>count($rows),'complete'=>!$missing,'missing'=>$missing,'cost_ex_vat'=>$missing?null:$ex,'cost_inc_vat'=>$missing?null:$inc,'supplier_cost'=>$supplier,'freight_cost'=>$freight,'waste_cost'=>$waste]);
}

function cw_packaging_current_setups(PDO $pdo,string $date): array
{
    $q=$pdo->prepare("SELECT s.id FROM cw_packaging_setups s WHERE s.active=1 AND s.effective_date<=? AND NOT EXISTS(SELECT 1 FROM cw_packaging_setups n WHERE n.size_conversion_id=s.size_conversion_id AND n.scope_type=s.scope_type AND n.scope_reference=s.scope_reference AND n.effective_date<=? AND (n.effective_date>s.effective_date OR(n.effective_date=s.effective_date AND n.id>s.id))) ORDER BY FIELD(s.scope_type,'product','category','default'),s.setup_name");$q->execute([$date,$date]);$out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$out[]=cw_packaging_setup_cost($pdo,(int)$id,$date);return $out;
}

function cw_upgrade_supplier_costs_schema_v12(PDO $pdo): void
{
    // Extend canonical records; do not create a second product or costing ledger.
    $columns = [
        'cw_supplier_invoice_lines' => [
            'cost_product_id' => 'BIGINT UNSIGNED NULL',
            'shipping_weight_kg' => 'DECIMAL(18,6) NULL',
            'active' => 'TINYINT(1) NOT NULL DEFAULT 1',
        ],
        'cw_supplier_invoices' => [
            'entry_source' => "VARCHAR(16) NOT NULL DEFAULT 'uploaded'",
            'fx_confirmed' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
        'cw_transport_records' => [
            'exchange_rate' => 'DECIMAL(18,8) NULL',
            'fx_confirmed' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
    ];
    foreach ($columns as $table => $fields) {
        foreach ($fields as $column => $definition) {
            if (!cw_column_exists($pdo, $table, $column)) $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }
    $pdo->exec("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','12','system') ON DUPLICATE KEY UPDATE setting_value='12',updated_by_name='system'");
}

/** Monetary allocation in integer cents. Missing weights never receive a fabricated zero. */
function cw_shipping_allocation(float $amount, array $weights): array
{
    if (!is_finite($amount) || $amount < 0 || !$weights) throw new DomainException('Confirm the shipping amount and eligible products.');
    foreach ($weights as $weight) if (!is_numeric($weight) || !is_finite((float)$weight) || (float)$weight <= 0) throw new DomainException('Shipping Weight Required: confirm every product shipping weight. Litres and units are not kilograms.');
    $total = array_sum($weights);
    $cents = (int)round($amount * 100);
    $allocations = []; $remainders = []; $used = 0;
    foreach ($weights as $id => $weight) {
        $exact = $cents * ((float)$weight / $total);
        $floor = (int)floor($exact);
        $allocations[$id] = $floor;
        $remainders[$id] = $exact - $floor;
        $used += $floor;
    }
    arsort($remainders, SORT_NUMERIC);
    foreach ($remainders as $id => $fraction) {
        if ($used >= $cents) break;
        $allocations[$id]++; $used++;
    }
    return array_map(function($value) { return (float)($value / 100); }, $allocations);
}

function cw_supplier_cost_audit(PDO $pdo, string $entity, int $id, string $action, $before, $after): void
{
    $actor = cw_user();
    $pdo->prepare('INSERT INTO cw_cost_audit_events(entity_type,entity_id,action_key,before_json,after_json,actor_id,actor_name) VALUES(?,?,?,?,?,?,?)')->execute([$entity,$id,$action,$before === null ? null : json_encode($before),$after === null ? null : json_encode($after),$actor['id'],$actor['name']]);
}

/** Update a purchase without replacing line IDs or deleting downstream references. Caller owns transaction. */
function cw_supplier_save_lines(PDO $pdo, int $invoiceId, array $submitted, array $reallocatedTransportIds=[]): int
{
    if (!$pdo->inTransaction()) throw new LogicException('Invoice line updates require a transaction.');
    $query = $pdo->prepare('SELECT * FROM cw_supplier_invoice_lines WHERE supplier_invoice_id=? AND active=1 ORDER BY id FOR UPDATE');
    $query->execute([$invoiceId]);
    $existing = [];
    foreach ($query->fetchAll() as $line) $existing[(int)$line['id']] = $line;
    $seen = []; $valid = 0;
    foreach ($submitted as $line) {
        if (!is_array($line)) throw new DomainException('Invalid invoice line.');
        $id = (int)($line['id'] ?? 0);
        if ($id && (!isset($existing[$id]) || isset($seen[$id]))) throw new DomainException('An invoice line is duplicated or does not belong to this purchase. Refresh before saving.');
        if ($id) $seen[$id] = true;
        $name = trim((string)($line['product_description'] ?? ''));
        if ($name === '') throw new DomainException('Every purchase line needs a product name.');
        if (strlen($name) > 255) throw new DomainException('Product names must be 255 bytes or shorter.');
        $quantity = cw_decimal($line['quantity'] ?? null);
        $pack = cw_decimal($line['pack_size'] ?? null);
        $subtotal = cw_decimal($line['line_subtotal'] ?? null);
        $vat = cw_decimal($line['vat_amount'] ?? null);
        foreach ([$quantity,$pack,$subtotal,$vat] as $value) if ($value !== null && (float)$value < 0) throw new DomainException('Quantities and costs cannot be negative.');
        $unit = trim((string)($line['purchase_unit'] ?? ''));
        $base = null; $baseUnit = null;
        if ((float)$quantity > 0 && (float)$pack > 0 && in_array($unit,['g','kg','ml','L','unit'],true)) {
            $base = (float)$quantity * (float)$pack / (in_array($unit,['g','ml'],true) ? 1000 : 1);
            $baseUnit = ['g'=>'kg','kg'=>'kg','ml'=>'L','L'=>'L','unit'=>'unit'][$unit];
        }
        $status = $base > 0 && $subtotal !== null ? 'valid' : 'needs_review';
        if ($status === 'valid') $valid++;
        $shippingWeight = cw_decimal($line['shipping_weight_kg'] ?? ($existing[$id]['shipping_weight_kg'] ?? null));
        if ($shippingWeight !== null && (float)$shippingWeight <= 0) throw new DomainException('Confirmed shipping weight must be positive.');
        if ($id && (float)$shippingWeight!==(float)($existing[$id]['shipping_weight_kg']??0)) {
            $q=$pdo->prepare("SELECT DISTINCT a.transport_id FROM cw_transport_product_allocations p JOIN cw_transport_allocations a ON a.id=p.transport_allocation_id JOIN cw_transport_records t ON t.id=a.transport_id WHERE p.supplier_invoice_line_id=? AND t.allocation_status<>'archived'");$q->execute([$id]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $transportId)if(!in_array((int)$transportId,$reallocatedTransportIds,true))throw new DomainException('This weight is used by existing freight. Open Transport Costs to revise the shipment and preview its allocation before changing the weight.');
        }
        $values = [$name,trim((string)($line['supplier_sku'] ?? '')),trim((string)($line['product_category'] ?? 'Other')),$quantity,$pack,$unit,$base,$baseUnit,$subtotal,$vat,$subtotal === null ? null : (float)$subtotal + (float)$vat,$base > 0 && $subtotal !== null ? (float)$subtotal/$base : null,trim((string)($line['line_notes'] ?? '')),$status];
        if ($id) {
            // Deliberately leave raw extraction, Woo matches, classifications and transport IDs intact.
            $pdo->prepare('UPDATE cw_supplier_invoice_lines SET product_description=?,supplier_sku=?,product_category=?,quantity=?,pack_size=?,purchase_unit=?,base_quantity=?,base_unit=?,line_subtotal=?,vat_amount=?,line_total=?,base_unit_cost=?,line_notes=?,review_status=? WHERE id=? AND supplier_invoice_id=?')->execute(array_merge($values,[$id,$invoiceId]));
            cw_supplier_cost_audit($pdo,'supplier_invoice_line',$id,'updated',$existing[$id],$line);
        } else {
            $pdo->prepare('INSERT INTO cw_supplier_invoice_lines(product_description,supplier_sku,product_category,quantity,pack_size,purchase_unit,base_quantity,base_unit,line_subtotal,vat_amount,line_total,base_unit_cost,line_notes,review_status,supplier_invoice_id,raw_description) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($values,[$invoiceId,$name]));
            $id = (int)$pdo->lastInsertId();
            cw_supplier_cost_audit($pdo,'supplier_invoice_line',$id,'created',null,$line);
        }
        $pdo->prepare('UPDATE cw_supplier_invoice_lines SET shipping_weight_kg=? WHERE id=?')->execute([$shippingWeight,$id]);
        if (!empty($line['cost_product_id'])) {
            $q=$pdo->prepare('SELECT * FROM cw_cost_products WHERE id=? AND active=1 FOR UPDATE'); $q->execute([(int)$line['cost_product_id']]); $p=$q->fetch();
            if (!$p) throw new DomainException('Select an active Cost Workbook product.');
            $expected=$baseUnit==='unit'?'each':$baseUnit;
            if ($p['base_unit'] && $p['base_unit']!==$expected) throw new DomainException('The purchase unit differs from the selected product costing unit.');
            $pdo->prepare("UPDATE cw_supplier_invoice_lines SET cost_product_id=?,woo_product_id=?,woo_variation_id=?,match_status='confirmed',item_type=? WHERE id=?")->execute([$p['id'],$p['woo_product_id'],$p['woo_variation_id'],$p['woo_product_id']?'website_product':'supplier_raw_material',$id]);
        } elseif (!empty($line['woo_product_id'])) {
            $q=$pdo->prepare('SELECT product_id,variation_id,sku FROM cw_product_snapshots WHERE product_id=? AND variation_id=? AND sync_batch_id=(SELECT id FROM cw_sync_batches WHERE is_successful_snapshot=1 ORDER BY completed_at DESC,id DESC LIMIT 1) LIMIT 1');
            $q->execute([(int)$line['woo_product_id'],(int)($line['woo_variation_id']??0)]);$website=$q->fetch();
            if(!$website)throw new DomainException('The selected WooCommerce product is no longer in the verified catalogue snapshot.');
            $pdo->prepare("UPDATE cw_supplier_invoice_lines SET woo_product_id=?,woo_variation_id=?,item_type=?,match_status='confirmed' WHERE id=?")->execute([$website['product_id'],$website['variation_id'],$website['variation_id']?'website_variation':'website_product',$id]);
        } elseif (!empty($line['confirm_new_product'])) {
            $q=$pdo->prepare('SELECT id FROM cw_cost_products WHERE normalized_name=? OR (woo_sku<>\'\' AND woo_sku=?)'); $q->execute([lpc_norm($name),(string)($line['supplier_sku']??'')]);
            if ($q->fetchColumn()) throw new DomainException('Product already exists. Select the existing product and Update Cost from This Purchase.');
            $q=$pdo->prepare("SELECT product_id FROM cw_product_snapshots WHERE sync_batch_id=(SELECT id FROM cw_sync_batches WHERE is_successful_snapshot=1 ORDER BY completed_at DESC,id DESC LIMIT 1) AND (LOWER(TRIM(product_name))=LOWER(TRIM(?)) OR (sku<>'' AND sku=?)) LIMIT 1");$q->execute([$name,(string)($line['supplier_sku']??'')]);
            if($q->fetchColumn())throw new DomainException('This product already exists in WooCommerce. Select its catalogue match so the existing SKU is reused.');
            $pdo->prepare("UPDATE cw_supplier_invoice_lines SET item_type='supplier_raw_material',match_status='confirmed' WHERE id=?")->execute([$id]);
        }
    }
    // Omitted unreferenced draft lines are archived, never destroyed; all references remain protected.
    foreach ($existing as $id => $line) {
        if (!isset($seen[$id])) {
            $q=$pdo->prepare('SELECT approval_status FROM cw_supplier_invoices WHERE id=?');$q->execute([$invoiceId]);$draft=$q->fetchColumn()==='draft';
            $q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM cw_cost_product_sources WHERE supplier_invoice_line_id=?)+(SELECT COUNT(*) FROM cw_transport_product_allocations WHERE supplier_invoice_line_id=?)+(SELECT COUNT(*) FROM cw_product_matches WHERE supplier_invoice_line_id=?)');$q->execute([$id,$id,$id]);
            if(!$draft || (int)$q->fetchColumn()>0 || !$submitted)throw new DomainException('Approved or referenced lines cannot be removed. Keep them in the review to preserve their history.');
            $pdo->prepare('UPDATE cw_supplier_invoice_lines SET active=0 WHERE id=?')->execute([$id]);
            cw_supplier_cost_audit($pdo,'supplier_invoice_line',$id,'draft_line_archived',$line,null);
        }
    }
    return $valid;
}

function cw_packaging_freight_claimed(PDO $pdo,int $transport): float
{
    $q=$pdo->prepare('SELECT c.transport_evidence FROM cw_packaging_item_costs c WHERE c.transport_id=? AND NOT EXISTS(SELECT 1 FROM cw_packaging_item_costs n WHERE n.supersedes_cost_id=c.id)');$q->execute([$transport]);$sum=0;foreach($q->fetchAll() as $c){$e=json_decode($c['transport_evidence']??'{}',true);$sum+=(float)($e['source_amount']??0);}return round($sum,2);
}

function cw_transport_save(PDO $pdo, array $body): array
{
    $owned = !$pdo->inTransaction();
    if ($owned) $pdo->beginTransaction();
    try {
        $id=(int)($body['id']??0); $actor=cw_user();
        $name=trim((string)($body['courier_name']??''));
        $reference=trim((string)($body['invoice_number']??''));
        $date=(string)($body['transport_date']??'');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if ($name==='' || $reference==='' || !$parsed || $parsed->format('Y-m-d')!==$date) throw new DomainException('Confirm courier, transport reference and date.');
        $currency=strtoupper(trim((string)($body['currency']??'NAD')));
        if (!preg_match('/^[A-Z]{3}$/',$currency)) throw new DomainException('Confirm shipping currency.');
        $amount=cw_decimal($body['transport_amount']??null); $vat=cw_decimal($body['vat_amount']??0); $other=cw_decimal($body['non_transport_charges']??0);
        if ($amount===null || $vat===null || $other===null || min((float)$amount,(float)$vat,(float)$other)<0) throw new DomainException('Enter valid non-negative shipping amounts.');
        $treatment=(string)($body['vat_treatment']??'unconfirmed');
        if (!in_array($treatment,['recoverable','non_recoverable','exempt','unconfirmed'],true)) throw new DomainException('Confirm shipping VAT treatment.');
        $including=($body['amount_basis']??'excluding')==='including';
        $net=(float)$amount-($including && $treatment==='recoverable'?(float)$vat:0);
        $allocable=$net-(float)$other;
        if ($allocable<0) throw new DomainException('VAT and non-transport charges exceed the transport amount.');
        $method=(string)($body['allocation_method']??'by_weight');
        if (!in_array($method,['by_weight','percentage','manual_amount','courier_lines','equal_split'],true)) throw new DomainException('Invalid allocation method.');
        if ($method!=='by_weight' && empty($body['confirm_alternative'])) throw new DomainException('Explicitly confirm the alternative freight allocation method.');
        $before=null; $previousProducts=[];
        if ($id) {
            $q=$pdo->prepare('SELECT * FROM cw_transport_records WHERE id=? FOR UPDATE'); $q->execute([$id]); $before=$q->fetch();
            if (!$before || $before['allocation_status']==='archived') throw new DomainException('Transport record is unavailable.');
            $q=$pdo->prepare('SELECT p.* FROM cw_transport_product_allocations p JOIN cw_transport_allocations a ON a.id=p.transport_allocation_id WHERE a.transport_id=? ORDER BY p.id');$q->execute([$id]);$previousProducts=$q->fetchAll();
        }
        $claimed=$id?cw_packaging_freight_claimed($pdo,$id):0;
        if($claimed>0 && ($currency!==$before['currency'] || $treatment!==$before['vat_treatment'] || abs((float)($body['exchange_rate']??1)-(float)($before['exchange_rate']??1))>.00000001))throw new DomainException('Packaging allocations already use this VAT/currency basis. Review their source evidence before changing it.');
        $budget=$allocable-$claimed;if($budget<-.001)throw new DomainException('Transport amount is below its existing packaging allocations.');
        $links=(array)($body['allocations']??[]); $weights=[]; $invoiceIds=[]; $lineGroups=[];
        foreach ($links as $link) {
            $invoiceId=(int)($link['supplier_invoice_id']??0);
            if ($invoiceId<1 || isset($invoiceIds[$invoiceId])) throw new DomainException('Select each supplier invoice only once.');
            $invoiceIds[$invoiceId]=true;
            $q=$pdo->prepare("SELECT id FROM cw_supplier_invoices WHERE id=? AND approval_status<>'archived' FOR UPDATE"); $q->execute([$invoiceId]);
            if (!$q->fetchColumn()) throw new DomainException('Linked supplier invoice is unavailable.');
            $q=$pdo->prepare('SELECT * FROM cw_supplier_invoice_lines WHERE supplier_invoice_id=? AND active=1 ORDER BY id FOR UPDATE'); $q->execute([$invoiceId]);
            $productRows=$q->fetchAll(); if (!$productRows) throw new DomainException('The linked purchase has no eligible products.');
            foreach ($productRows as &$productLine) {
                $key=(int)$productLine['id'];
                if (array_key_exists($key,(array)($body['product_weights']??[]))) {
                    $confirmed=cw_decimal($body['product_weights'][$key]);
                    if ($confirmed===null || (float)$confirmed<=0) throw new DomainException('Confirm a positive shipping weight for each product.');
                    cw_supplier_cost_audit($pdo,'supplier_invoice_line',$key,'shipping_weight_confirmed',['shipping_weight_kg'=>$productLine['shipping_weight_kg']],['shipping_weight_kg'=>$confirmed]);
                    $pdo->prepare('UPDATE cw_supplier_invoice_lines SET shipping_weight_kg=? WHERE id=?')->execute([$confirmed,$key]);
                    $productLine['shipping_weight_kg']=$confirmed;
                }
            }
            unset($productLine);
            $lineGroups[$invoiceId]=$productRows;
            $weights[$invoiceId]=0;
            foreach ($productRows as $line) {
                $weight=$line['shipping_weight_kg'];
                if ($method==='by_weight' && ($weight===null || (float)$weight<=0)) throw new DomainException('Shipping Weight Required for '.$line['product_description'].'. Confirm the actual weight before allocating.');
                $weights[$invoiceId]+=(float)$weight;
            }
        }
        $shares=[];
        if ($links) {
            if ($method==='by_weight') $shares=cw_shipping_allocation($budget,$weights);
            elseif ($method==='equal_split') $shares=cw_shipping_allocation($budget,array_fill_keys(array_keys($invoiceIds),1));
            else {
                $totalPercent=0;
                foreach ($links as $link) {
                    $value=cw_decimal($link['value']??null);
                    if ($value===null || (float)$value<0) throw new DomainException('Confirm every allocation amount.');
                    $totalPercent+=(float)$value;
                    $shares[(int)$link['supplier_invoice_id']]=round($method==='percentage'?$budget*(float)$value/100:(float)$value,2);
                }
                if ($method==='percentage') {
                    if (abs($totalPercent-100)>.000001) throw new DomainException('Allocation percentages must total 100%.');
                    $positive=[]; foreach ($links as $link) if ((float)$link['value']>0) $positive[(int)$link['supplier_invoice_id']]=(float)$link['value'];
                    $shares=cw_shipping_allocation($budget,$positive)+$shares;
                }
            }
        }
        if (array_sum($shares)>$budget+.001) throw new DomainException('Allocated freight exceeds available freight after packaging allocations.');
        $rate=$currency==='NAD'?1:cw_decimal($body['exchange_rate']??null);
        $fx=$currency==='NAD' || (!empty($body['fx_confirmed']) && (float)$rate>0);
        $values=[$name,$reference,$date,$currency,$net,$vat,$including?$amount:(float)$amount+(float)$vat,$other,$treatment,$allocable,array_sum($weights)?:null,'Product shipping weights',$method,trim((string)($body['notes']??'')),$rate,$fx?1:0,$actor['id'],$actor['name']];
        if ($id) $pdo->prepare('UPDATE cw_transport_records SET courier_name=?,invoice_number=?,transport_date=?,currency=?,subtotal_ex_vat=?,vat_amount=?,total_inc_vat=?,non_transport_charges=?,vat_treatment=?,allocable_amount=?,actual_weight_kg=?,weight_source=?,allocation_method=?,notes=?,exchange_rate=?,fx_confirmed=?,updated_by=?,updated_by_name=? WHERE id=?')->execute(array_merge($values,[$id]));
        else {
            $pdo->prepare("INSERT INTO cw_transport_records(courier_name,invoice_number,transport_date,currency,subtotal_ex_vat,vat_amount,total_inc_vat,non_transport_charges,vat_treatment,allocable_amount,actual_weight_kg,weight_source,allocation_method,notes,exchange_rate,fx_confirmed,created_by,created_by_name,entry_source) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'manual')")->execute($values);
            $id=(int)$pdo->lastInsertId();
        }
        $q=$pdo->prepare('SELECT * FROM cw_transport_allocations WHERE transport_id=? FOR UPDATE'); $q->execute([$id]); $oldAllocations=$q->fetchAll();
        foreach ($oldAllocations as $allocation) if (!isset($invoiceIds[(int)$allocation['supplier_invoice_id']])) throw new DomainException('Keep existing shipment links; removing them would invalidate cost history. Archive a superseded shipment instead.');
        foreach ($shares as $invoiceId=>$share) {
            $pdo->prepare("INSERT INTO cw_transport_allocations(transport_id,supplier_invoice_id,allocation_method,weight_kg_snapshot,weight_status,calculated_amount,calculation_date) VALUES(?,?,?,?,'actual',?,NOW()) ON DUPLICATE KEY UPDATE allocation_method=VALUES(allocation_method),weight_kg_snapshot=VALUES(weight_kg_snapshot),weight_status=VALUES(weight_status),calculated_amount=VALUES(calculated_amount),calculation_date=NOW()")->execute([$id,$invoiceId,$method,$weights[$invoiceId]?:null,$share]);
            $q=$pdo->prepare('SELECT id FROM cw_transport_allocations WHERE transport_id=? AND supplier_invoice_id=?'); $q->execute([$id,$invoiceId]); $aid=(int)$q->fetchColumn();
            $productWeights=[];
            foreach ($lineGroups[$invoiceId] as $line) {
                if ($method==='equal_split') $productWeights[(int)$line['id']]=1;
                else $productWeights[(int)$line['id']]=$line['shipping_weight_kg'];
            }
            // Non-weight invoice splitting still requires confirmed product weights, unless equal split was explicitly authorised.
            $productShares=cw_shipping_allocation((float)$share,$productWeights);
            foreach ($lineGroups[$invoiceId] as $line) {
                $piece=$productShares[(int)$line['id']]; $base=(float)$line['base_quantity'];
                if ($base<=0) throw new DomainException('Confirm purchased base quantity before allocating freight.');
                $pdo->prepare('INSERT INTO cw_transport_product_allocations(transport_allocation_id,supplier_invoice_line_id,quantity_snapshot,pack_size_snapshot,base_quantity_snapshot,base_unit_snapshot,shipping_weight_kg_snapshot,calculated_amount,transport_cost_per_base) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE quantity_snapshot=VALUES(quantity_snapshot),pack_size_snapshot=VALUES(pack_size_snapshot),base_quantity_snapshot=VALUES(base_quantity_snapshot),base_unit_snapshot=VALUES(base_unit_snapshot),shipping_weight_kg_snapshot=VALUES(shipping_weight_kg_snapshot),calculated_amount=VALUES(calculated_amount),transport_cost_per_base=VALUES(transport_cost_per_base)')->execute([$aid,$line['id'],$line['quantity'],$line['pack_size'],$line['base_quantity'],$line['base_unit'],$line['shipping_weight_kg'],$piece,$piece/$base]);
            }
        }
        $allocated=array_sum($shares)+$claimed; $remaining=round($allocable-$allocated,2);
        $status=!$shares&&!$claimed?'unlinked':($remaining<.005 && $fx && $treatment!=='unconfirmed'?'fully_allocated':'partial');
        $pdo->prepare('UPDATE cw_transport_records SET allocation_status=? WHERE id=?')->execute([$status,$id]);
        $pdo->prepare('INSERT INTO cw_transport_audit(transport_id,action_key,before_json,after_json,actor_id,actor_name) VALUES(?,?,?,?,?,?)')->execute([$id,'allocation_saved',json_encode(['record'=>$before,'allocations'=>$oldAllocations,'product_allocations'=>$previousProducts]),json_encode(['request'=>$body,'allocated'=>$allocated,'remaining'=>$remaining]),$actor['id'],$actor['name']]);
        lpc_refresh($pdo,array_keys($invoiceIds));
        if ($owned) $pdo->commit();
        return ['ok'=>true,'id'=>$id,'allocated'=>$allocated,'unallocated'=>$remaining,'status'=>$status,'message'=>'Transport allocation saved.'];
    } catch (Throwable $e) {
        if ($owned && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function cw_upgrade_retail_pricing_schema_v11(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_retail_prices (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT UNSIGNED NOT NULL,
        size_conversion_id BIGINT UNSIGNED NOT NULL,
        packaging_setup_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        woo_product_id BIGINT UNSIGNED NULL,
        woo_variation_id BIGINT UNSIGNED NULL,
        current_version INT UNSIGNED NOT NULL DEFAULT 1,
        effective_date DATE NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by BIGINT NULL, updated_by_name VARCHAR(190) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cw_retail_product_size(product_id,size_conversion_id,packaging_setup_id),
        KEY idx_cw_retail_website(woo_product_id,woo_variation_id,active),
        KEY idx_cw_retail_effective(effective_date,active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_retail_price_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        retail_price_id BIGINT UNSIGNED NOT NULL,
        version_no INT UNSIGNED NOT NULL,
        landed_cost_id BIGINT UNSIGNED NOT NULL,
        landed_cost_per_base DECIMAL(18,10) NOT NULL,
        conversion_base_value DECIMAL(18,8) NOT NULL,
        packaging_cost_ex_vat DECIMAL(18,8) NOT NULL DEFAULT 0,
        packaging_cost_inc_vat DECIMAL(18,8) NOT NULL DEFAULT 0,
        total_cost_ex_vat DECIMAL(18,8) NOT NULL,
        total_cost_inc_vat DECIMAL(18,8) NOT NULL,
        vat_rate DECIMAL(7,4) NOT NULL DEFAULT 15,
        selling_price_inc_vat DECIMAL(18,8) NOT NULL,
        selling_price_ex_vat DECIMAL(18,8) NOT NULL,
        profit_per_unit DECIMAL(18,8) NOT NULL,
        markup_percent DECIMAL(9,4) NOT NULL,
        margin_percent DECIMAL(9,4) NOT NULL,
        website_price_inc_vat DECIMAL(18,8) NULL,
        website_stock_quantity DECIMAL(18,3) NULL,
        effective_date DATE NOT NULL,
        source_json JSON NOT NULL,
        created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cw_retail_version(retail_price_id,version_no),
        KEY idx_cw_retail_version_date(retail_price_id,effective_date,version_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_retail_price_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        retail_price_id BIGINT UNSIGNED NOT NULL,
        action_key VARCHAR(60) NOT NULL,
        before_json JSON NULL,
        after_json JSON NULL,
        actor_id BIGINT NULL, actor_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cw_retail_audit(retail_price_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['retail_vat_rate'=>'15','retail_low_margin_threshold'=>'20'] as $key=>$value) {
        $pdo->prepare("INSERT IGNORE INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES(?,?,'system')")->execute([$key,$value]);
    }
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','11','system') ON DUPLICATE KEY UPDATE setting_value='11',updated_by_name='system'")->execute();
}

function cw_upgrade_wholesale_pricing_schema_v10(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_wholesale_prices (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_type ENUM('purchased','manufactured') NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        product_name VARCHAR(190) NOT NULL,
        short_code VARCHAR(40) NOT NULL DEFAULT '',
        category VARCHAR(120) NOT NULL DEFAULT '',
        wholesale_size_label VARCHAR(100) NOT NULL,
        size_conversion_id BIGINT UNSIGNED NULL,
        quantity DECIMAL(18,6) NOT NULL,
        unit VARCHAR(30) NOT NULL,
        moq DECIMAL(18,6) NOT NULL DEFAULT 1,
        status_key VARCHAR(40) NOT NULL DEFAULT 'draft',
        current_version INT UNSIGNED NOT NULL DEFAULT 1,
        effective_date DATE NOT NULL,
        expiry_date DATE NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by BIGINT NULL, updated_by_name VARCHAR(190) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cw_wholesale_product(product_type,product_id,active), KEY idx_cw_wholesale_current(status_key,effective_date,expiry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_wholesale_price_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        wholesale_price_id BIGINT UNSIGNED NOT NULL,
        version_no INT UNSIGNED NOT NULL,
        version_status ENUM('draft','ready','active','future','superseded','archived') NOT NULL DEFAULT 'draft',
        cost_source_type ENUM('landed','formulation') NOT NULL,
        cost_source_id BIGINT UNSIGNED NOT NULL,
        cost_source_version VARCHAR(40) NOT NULL DEFAULT '',
        source_cost_date DATE NULL,
        base_cost DECIMAL(18,8) NOT NULL,
        product_content_cost DECIMAL(18,8) NOT NULL,
        packaging_source ENUM('setup','manual','none','required') NOT NULL DEFAULT 'required',
        packaging_setup_id BIGINT UNSIGNED NULL,
        packaging_cost_used DECIMAL(18,8) NOT NULL DEFAULT 0,
        pricing_method ENUM('margin','profit','manual') NOT NULL DEFAULT 'margin',
        target_margin DECIMAL(9,4) NULL,
        desired_profit DECIMAL(18,8) NULL,
        manual_price DECIMAL(18,8) NULL,
        total_cost_ex_vat DECIMAL(18,8) NOT NULL,
        unrounded_price_ex_vat DECIMAL(18,8) NOT NULL,
        price_ex_vat DECIMAL(18,8) NOT NULL,
        output_vat DECIMAL(18,8) NOT NULL,
        price_inc_vat DECIMAL(18,8) NOT NULL,
        profit DECIMAL(18,8) NOT NULL,
        margin_percent DECIMAL(9,4) NOT NULL,
        markup_percent DECIMAL(9,4) NOT NULL,
        minimum_price_ex_vat DECIMAL(18,8) NOT NULL,
        below_minimum_reason VARCHAR(500) NULL,
        effective_date DATE NOT NULL, expiry_date DATE NULL,
        payload_json JSON NOT NULL, tiers_json JSON NOT NULL,
        created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cw_wholesale_version(wholesale_price_id,version_no), KEY idx_cw_wholesale_history(wholesale_price_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_wholesale_price_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, wholesale_price_id BIGINT UNSIGNED NOT NULL,
        action_key VARCHAR(60) NOT NULL, before_json JSON NULL, after_json JSON NULL,
        actor_id BIGINT NULL, actor_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cw_wholesale_audit(wholesale_price_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['wholesale_vat_rate'=>'15','wholesale_minimum_margin'=>'20','wholesale_default_margin'=>'35','wholesale_rounding_rule'=>'0.50'] as $key=>$value) {
        $pdo->prepare("INSERT IGNORE INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES(?,?,'system')")->execute([$key,$value]);
    }
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','10','system') ON DUPLICATE KEY UPDATE setting_value='10',updated_by_name='system'")->execute();
}

function cw_upgrade_formulation_schema_v9(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_formulations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        formulation_name VARCHAR(190) NOT NULL,
        formulation_code VARCHAR(40) NOT NULL,
        category VARCHAR(120) NOT NULL DEFAULT '',
        formulation_type ENUM('weight','volume','count','mixed') NOT NULL DEFAULT 'weight',
        status_key ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
        current_version INT UNSIGNED NOT NULL DEFAULT 1,
        effective_date DATE NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by BIGINT NULL, updated_by_name VARCHAR(190) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cw_formulation_code(formulation_code), KEY idx_cw_formulation_list(active,status_key,formulation_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_formulation_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        formulation_id BIGINT UNSIGNED NOT NULL,
        version_no INT UNSIGNED NOT NULL,
        version_status ENUM('draft','approved','superseded') NOT NULL DEFAULT 'draft',
        effective_date DATE NULL,
        mode_key ENUM('quantity','percentage') NOT NULL DEFAULT 'quantity',
        reference_batch_size DECIMAL(18,6) NOT NULL DEFAULT 1,
        reference_batch_unit VARCHAR(20) NOT NULL DEFAULT 'kg',
        expected_yield DECIMAL(18,6) NOT NULL DEFAULT 1,
        yield_unit VARCHAR(20) NOT NULL DEFAULT 'kg',
        loss_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
        vat_rate DECIMAL(7,4) NOT NULL DEFAULT 15,
        formula_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        header_json JSON NOT NULL, ingredients_json JSON NOT NULL, production_json JSON NOT NULL,
        batches_json JSON NOT NULL, selling_sizes_json JSON NOT NULL, totals_json JSON NOT NULL,
        created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cw_formulation_version(formulation_id,version_no), KEY idx_cw_formulation_history(formulation_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_formulation_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, formulation_id BIGINT UNSIGNED NOT NULL,
        action_key VARCHAR(60) NOT NULL, before_json JSON NULL, after_json JSON NULL,
        actor_id BIGINT NULL, actor_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cw_formulation_audit(formulation_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['formulation_margin_defaults'=>'35,45,55','formulation_rounding_rule'=>'0.50','formulation_vat_rate'=>'15'] as $key=>$value) {
        $pdo->prepare("INSERT IGNORE INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES(?,?,'system')")->execute([$key,$value]);
    }
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','9','system') ON DUPLICATE KEY UPDATE setting_value='9',updated_by_name='system'")->execute();
}

function cw_upgrade_landed_product_schema_v8(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_cost_products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_name VARCHAR(255) NOT NULL,normalized_name VARCHAR(255) NOT NULL,short_code VARCHAR(24) NOT NULL,woo_product_id BIGINT UNSIGNED NULL,woo_variation_id BIGINT UNSIGNED NULL,woo_sku VARCHAR(100) NOT NULL DEFAULT '',product_category VARCHAR(190) NOT NULL DEFAULT '',costing_basis ENUM('weight','volume','count','ready_made') NULL,base_unit ENUM('kg','L','each','unit') NULL,preferred_supplier_id BIGINT UNSIGNED NULL,notes TEXT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_by BIGINT NULL,updated_by_name VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_cost_product_code(short_code),KEY idx_cw_cost_product_name(normalized_name,active),KEY idx_cw_cost_product_woo(woo_product_id,woo_variation_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_cost_product_sources (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_id BIGINT UNSIGNED NOT NULL,supplier_id BIGINT UNSIGNED NULL,supplier_name VARCHAR(190) NOT NULL DEFAULT '',supplier_invoice_line_id BIGINT UNSIGNED NULL,manual_reference VARCHAR(120) NULL,source_type ENUM('invoice','manual') NOT NULL DEFAULT 'invoice',cost_date DATE NOT NULL,total_purchased_quantity DECIMAL(18,8) NOT NULL,total_supplier_cost DECIMAL(18,8) NOT NULL,currency CHAR(3) NOT NULL DEFAULT 'NAD',vat_treatment VARCHAR(30) NOT NULL DEFAULT 'unconfirmed',active TINYINT(1) NOT NULL DEFAULT 1,notes TEXT NULL,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_cost_product_invoice_line(supplier_invoice_line_id),KEY idx_cw_cost_product_source(product_id,supplier_id,cost_date,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_landed_product_costs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_id BIGINT UNSIGNED NOT NULL,product_source_id BIGINT UNSIGNED NOT NULL,supplier_id BIGINT UNSIGNED NULL,supplier_invoice_line_id BIGINT UNSIGNED NULL,transport_product_allocation_id BIGINT UNSIGNED NULL,costing_basis ENUM('weight','volume','count','ready_made') NOT NULL,base_unit ENUM('kg','L','each','unit') NOT NULL,base_quantity DECIMAL(18,8) NOT NULL,supplier_cost_used DECIMAL(18,8) NOT NULL,transport_cost_used DECIMAL(18,8) NOT NULL DEFAULT 0,supplier_cost_per_base DECIMAL(18,10) NOT NULL,transport_cost_per_base DECIMAL(18,10) NOT NULL DEFAULT 0,landed_cost_per_base DECIMAL(18,10) NOT NULL,currency CHAR(3) NOT NULL DEFAULT 'NAD',cost_date DATE NOT NULL,status_key VARCHAR(50) NOT NULL,calculation_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,calculation_hash CHAR(64) NOT NULL,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_landed_product_hash(calculation_hash),KEY idx_cw_landed_product_current(product_id,supplier_id,cost_date,id),KEY idx_cw_landed_product_source(product_source_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_landed_product_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(30) NOT NULL,entity_id BIGINT UNSIGNED NOT NULL,action_key VARCHAR(60) NOT NULL,before_json JSON NULL,after_json JSON NULL,actor_id BIGINT NULL,actor_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_landed_audit(entity_type,entity_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','8','system') ON DUPLICATE KEY UPDATE setting_value='8',updated_by_name='system'")->execute();
}

function cw_upgrade_packaging_schema_v7(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_types (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_packaging_type(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $seed=$pdo->prepare("INSERT IGNORE INTO cw_packaging_types(name) VALUES(?)");
    foreach(['Bottle','Jar','Pouch','Tube','Cap','Pump','Dropper','Label','Box','Bag','Seal','Other'] as $name)$seed->execute([$name]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_name VARCHAR(190) NOT NULL,packaging_type_id BIGINT UNSIGNED NOT NULL,supplier_name VARCHAR(190) NOT NULL DEFAULT '',supplier_sku VARCHAR(120) NOT NULL DEFAULT '',notes TEXT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_by BIGINT NULL,updated_by_name VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_cw_packaging_item(active,item_name),KEY idx_cw_packaging_item_type(packaging_type_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_item_costs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,packaging_item_id BIGINT UNSIGNED NOT NULL,effective_date DATE NOT NULL,purchase_quantity DECIMAL(18,6) NOT NULL,purchase_unit VARCHAR(30) NOT NULL,cost_basis ENUM('inclusive','exclusive') NOT NULL DEFAULT 'inclusive',amount_entered DECIMAL(18,6) NOT NULL,vat_rate DECIMAL(7,4) NOT NULL DEFAULT 15,vat_treatment ENUM('standard','zero_rated','exempt') NOT NULL DEFAULT 'standard',cost_ex_vat DECIMAL(18,8) NOT NULL,cost_inc_vat DECIMAL(18,8) NOT NULL,unit_cost_ex_vat DECIMAL(18,10) NOT NULL,unit_cost_inc_vat DECIMAL(18,10) NOT NULL,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_packaging_cost(packaging_item_id,effective_date,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_setups (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,setup_name VARCHAR(190) NOT NULL,size_conversion_id BIGINT UNSIGNED NOT NULL,scope_type ENUM('default','category','product') NOT NULL DEFAULT 'default',scope_reference VARCHAR(190) NOT NULL DEFAULT '',scope_label VARCHAR(190) NOT NULL DEFAULT '',effective_date DATE NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_by BIGINT NULL,updated_by_name VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_cw_packaging_setup(active,size_conversion_id,effective_date),KEY idx_cw_packaging_scope(scope_type,scope_reference,size_conversion_id,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_setup_components (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,packaging_setup_id BIGINT UNSIGNED NOT NULL,packaging_item_id BIGINT UNSIGNED NOT NULL,packaging_cost_id BIGINT UNSIGNED NOT NULL,quantity DECIMAL(18,6) NOT NULL,waste_percent DECIMAL(9,4) NOT NULL DEFAULT 0,unit_cost_ex_snapshot DECIMAL(18,10) NOT NULL,unit_cost_inc_snapshot DECIMAL(18,10) NOT NULL,component_cost_ex DECIMAL(18,8) NOT NULL,component_cost_inc DECIMAL(18,8) NOT NULL,display_order INT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_packaging_component(packaging_setup_id),KEY idx_cw_packaging_component_item(packaging_item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type ENUM('item','setup') NOT NULL,entity_id BIGINT UNSIGNED NOT NULL,action_key VARCHAR(60) NOT NULL,before_json JSON NULL,after_json JSON NULL,actor_id BIGINT NULL,actor_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_packaging_audit(entity_type,entity_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','7','system') ON DUPLICATE KEY UPDATE setting_value='7',updated_by_name='system'")->execute();
}

function cw_upgrade_transport_schema_v6(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_transport_records (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entry_source ENUM('uploaded','manual') NOT NULL DEFAULT 'manual',courier_supplier_id BIGINT UNSIGNED NULL,courier_name VARCHAR(190) NOT NULL DEFAULT '',invoice_number VARCHAR(120) NOT NULL DEFAULT '',transport_date DATE NULL,uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,currency CHAR(3) NOT NULL DEFAULT 'NAD',subtotal_ex_vat DECIMAL(14,2) NULL,vat_amount DECIMAL(14,2) NULL,total_inc_vat DECIMAL(14,2) NULL,discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,non_transport_charges DECIMAL(14,2) NOT NULL DEFAULT 0,vat_treatment ENUM('recoverable','non_recoverable','exempt','unconfirmed') NOT NULL DEFAULT 'unconfirmed',allocable_amount DECIMAL(14,2) NOT NULL DEFAULT 0,actual_weight_kg DECIMAL(18,6) NULL,estimated_weight_kg DECIMAL(18,6) NULL,weight_source ENUM('Courier invoice','Waybill','Supplier invoice','Product shipping weights','Manual estimate','Unknown') NOT NULL DEFAULT 'Unknown',waybill_references TEXT NULL,notes TEXT NULL,original_filename VARCHAR(255) NULL,stored_file VARCHAR(500) NULL,file_type VARCHAR(100) NULL,file_hash CHAR(64) NULL,extraction_status ENUM('not_started','extracting','complete','manual_review','failed') NOT NULL DEFAULT 'not_started',extraction_message VARCHAR(500) NULL,allocation_method ENUM('by_weight','courier_lines','manual_amount','percentage','equal_split','mixed') NOT NULL DEFAULT 'by_weight',allocation_status ENUM('unlinked','partial','fully_allocated','missing_weight','archived') NOT NULL DEFAULT 'unlinked',created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_by BIGINT NULL,updated_by_name VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_cw_transport_date(transport_date,allocation_status),KEY idx_cw_transport_courier(courier_name,invoice_number),KEY idx_cw_transport_hash(file_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_transport_shipment_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,transport_id BIGINT UNSIGNED NOT NULL,line_reference VARCHAR(190) NULL,description VARCHAR(500) NULL,supplier_name VARCHAR(190) NULL,waybill_number VARCHAR(120) NULL,purchase_order_reference VARCHAR(120) NULL,line_amount DECIMAL(14,2) NULL,net_weight_kg DECIMAL(18,6) NULL,gross_weight_kg DECIMAL(18,6) NULL,volumetric_weight_kg DECIMAL(18,6) NULL,chargeable_weight_kg DECIMAL(18,6) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_transport_line(transport_id),CONSTRAINT fk_cw_transport_line FOREIGN KEY(transport_id) REFERENCES cw_transport_records(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_transport_allocations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,transport_id BIGINT UNSIGNED NOT NULL,supplier_invoice_id BIGINT UNSIGNED NOT NULL,shipment_line_id BIGINT UNSIGNED NULL,allocation_method VARCHAR(30) NOT NULL,weight_kg_snapshot DECIMAL(18,6) NULL,weight_status ENUM('actual','estimated','missing') NOT NULL DEFAULT 'missing',allocation_percentage DECIMAL(9,6) NULL,calculated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,override_amount DECIMAL(14,2) NULL,override_reason VARCHAR(500) NULL,calculation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_transport_invoice(transport_id,supplier_invoice_id),KEY idx_cw_transport_supplier_invoice(supplier_invoice_id),CONSTRAINT fk_cw_transport_allocation FOREIGN KEY(transport_id) REFERENCES cw_transport_records(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_transport_product_allocations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,transport_allocation_id BIGINT UNSIGNED NOT NULL,supplier_invoice_line_id BIGINT UNSIGNED NOT NULL,quantity_snapshot DECIMAL(18,6) NULL,pack_size_snapshot DECIMAL(18,6) NULL,base_quantity_snapshot DECIMAL(18,6) NULL,base_unit_snapshot VARCHAR(20) NULL,shipping_weight_kg_snapshot DECIMAL(18,6) NULL,calculated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,override_amount DECIMAL(14,2) NULL,override_reason VARCHAR(500) NULL,transport_cost_per_base DECIMAL(18,6) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_transport_product(transport_allocation_id,supplier_invoice_line_id),CONSTRAINT fk_cw_transport_product_allocation FOREIGN KEY(transport_allocation_id) REFERENCES cw_transport_allocations(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_transport_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,transport_id BIGINT UNSIGNED NOT NULL,action_key VARCHAR(50) NOT NULL,before_json JSON NULL,after_json JSON NULL,actor_id BIGINT NULL,actor_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_transport_audit(transport_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','6','system') ON DUPLICATE KEY UPDATE setting_value='6',updated_by_name='system'")->execute();
}

function cw_upgrade_supplier_invoice_schema_v5(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_suppliers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(190) NOT NULL,normalized_name VARCHAR(190) NOT NULL,supplier_type ENUM('Raw Materials','Packaging','Equipment & Accessories','Freight & Logistics','Services','Mixed','Uncategorised') NOT NULL DEFAULT 'Uncategorised',type_confirmed TINYINT(1) NOT NULL DEFAULT 0,active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_supplier_normalized(normalized_name),KEY idx_cw_supplier_type(supplier_type,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $invoiceColumns=[
      'supplier_type'=>"ADD COLUMN supplier_type VARCHAR(60) NOT NULL DEFAULT 'Uncategorised' AFTER supplier_name",
      'invoice_type'=>"ADD COLUMN invoice_type VARCHAR(60) NOT NULL DEFAULT 'Mixed' AFTER supplier_type",
      'file_hash'=>"ADD COLUMN file_hash CHAR(64) NULL AFTER file_type",
      'extraction_confidence'=>"ADD COLUMN extraction_confidence VARCHAR(20) NULL AFTER extraction_status",
      'extraction_message'=>"ADD COLUMN extraction_message VARCHAR(500) NULL AFTER extraction_confidence",
      'discount_amount'=>"ADD COLUMN discount_amount DECIMAL(14,2) NULL AFTER invoice_total",
      'shipping_amount'=>"ADD COLUMN shipping_amount DECIMAL(14,2) NULL AFTER discount_amount",
      'purchase_order_number'=>"ADD COLUMN purchase_order_number VARCHAR(100) NULL AFTER shipping_amount",
      'last_edited_by'=>"ADD COLUMN last_edited_by BIGINT NULL AFTER notes",
      'last_edited_by_name'=>"ADD COLUMN last_edited_by_name VARCHAR(190) NULL AFTER last_edited_by",
    ];
    foreach($invoiceColumns as $column=>$definition)if(!cw_column_exists($pdo,'cw_supplier_invoices',$column))$pdo->exec('ALTER TABLE cw_supplier_invoices '.$definition);
    $lineColumns=[
      'product_category'=>"ADD COLUMN product_category VARCHAR(100) NOT NULL DEFAULT 'Other' AFTER supplier_sku",
      'line_notes'=>"ADD COLUMN line_notes VARCHAR(500) NULL AFTER owner_corrections",
      'extraction_confidence'=>"ADD COLUMN extraction_confidence VARCHAR(20) NULL AFTER line_notes",
    ];
    foreach($lineColumns as $column=>$definition)if(!cw_column_exists($pdo,'cw_supplier_invoice_lines',$column))$pdo->exec('ALTER TABLE cw_supplier_invoice_lines '.$definition);
    try{$pdo->exec('ALTER TABLE cw_supplier_invoices ADD UNIQUE KEY uq_cw_invoice_file_hash(file_hash)');}catch(Throwable $e){}
    $pdo->exec("INSERT IGNORE INTO cw_suppliers(name,normalized_name,supplier_type,type_confirmed) SELECT supplier_name,LOWER(REPLACE(REPLACE(REPLACE(TRIM(supplier_name),'.',''),',',''),' ','')),COALESCE(NULLIF(supplier_type,''),'Uncategorised'),0 FROM cw_supplier_invoices WHERE supplier_name<>''");
    $pdo->exec("UPDATE cw_supplier_invoices i JOIN cw_suppliers s ON s.normalized_name=LOWER(REPLACE(REPLACE(REPLACE(TRIM(i.supplier_name),'.',''),',',''),' ','')) SET i.supplier_id=s.id WHERE i.supplier_id IS NULL");
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','5','system') ON DUPLICATE KEY UPDATE setting_value='5',updated_by_name='system'")->execute();
}

function cw_upgrade_size_conversion_schema_v4(PDO $pdo): void
{
    require_once BASE_PATH.'/shared/cost-workbook-size-conversions.php';
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_size_conversions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,label VARCHAR(60) NOT NULL,measurement_type ENUM('volume','weight') NOT NULL,quantity DECIMAL(18,6) NOT NULL,unit ENUM('ml','L','g','kg') NOT NULL,base_value DECIMAL(18,6) NOT NULL,base_unit ENUM('L','kg') NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',updated_by BIGINT NULL,updated_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_size_conversion_base (measurement_type,base_value),KEY idx_cw_size_conversion_list (measurement_type,active,base_value)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_size_conversion_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversion_id BIGINT UNSIGNED NOT NULL,action_key ENUM('created','updated') NOT NULL,before_json JSON NULL,after_json JSON NOT NULL,actor_id BIGINT NULL,actor_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_size_conversion_audit (conversion_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $seed=$pdo->prepare("INSERT IGNORE INTO cw_size_conversions(label,measurement_type,quantity,unit,base_value,base_unit,created_by_name,updated_by_name) VALUES(?,?,?,?,?,?,'system','system')");
    foreach(cw_default_size_conversions() as $row)$seed->execute([$row['label'],$row['measurement_type'],$row['quantity'],$row['unit'],$row['base_value'],$row['base_unit']]);
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','4','system') ON DUPLICATE KEY UPDATE setting_value='4',updated_by_name='system'")->execute();
}

function cw_upgrade_phase2_schema_v3(PDO $pdo): void
{
    $lineColumns = [
        'discount_type' => "ADD COLUMN discount_type ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed' AFTER discount",
        'discount_value' => 'ADD COLUMN discount_value DECIMAL(14,4) NULL AFTER discount_type',
        'vat_rate' => 'ADD COLUMN vat_rate DECIMAL(7,4) NULL AFTER discount_value',
        'line_vat_treatment' => "ADD COLUMN line_vat_treatment ENUM('unconfirmed','inclusive','exclusive','exempt') NULL AFTER vat_rate",
        'calculated_vat_amount' => 'ADD COLUMN calculated_vat_amount DECIMAL(14,2) NULL AFTER line_vat_treatment',
        'vat_override_amount' => 'ADD COLUMN vat_override_amount DECIMAL(14,2) NULL AFTER calculated_vat_amount',
        'vat_override_reason' => 'ADD COLUMN vat_override_reason VARCHAR(500) NULL AFTER vat_override_amount',
        'vat_overridden_by' => 'ADD COLUMN vat_overridden_by BIGINT NULL AFTER vat_override_reason',
        'vat_overridden_by_name' => 'ADD COLUMN vat_overridden_by_name VARCHAR(190) NULL AFTER vat_overridden_by',
        'vat_overridden_at' => 'ADD COLUMN vat_overridden_at DATETIME NULL AFTER vat_overridden_by_name',
        'vat_source' => "ADD COLUMN vat_source ENUM('automatic','override','legacy_manual') NOT NULL DEFAULT 'legacy_manual' AFTER vat_overridden_at",
        'purchase_cost_in_landed' => 'ADD COLUMN purchase_cost_in_landed DECIMAL(14,2) NULL AFTER vat_source',
        'base_unit_cost' => 'ADD COLUMN base_unit_cost DECIMAL(18,6) NULL AFTER purchase_cost_in_landed',
        'calculation_version' => 'ADD COLUMN calculation_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER base_unit_cost',
    ];
    foreach ($lineColumns as $column => $definition) {
        if (!cw_column_exists($pdo, 'cw_supplier_invoice_lines', $column)) $pdo->exec('ALTER TABLE cw_supplier_invoice_lines ' . $definition);
    }
    $sql = file_get_contents(BASE_PATH . '/apps/cost-manager/cost-workbook-phase2-migration.sql');
    if ($sql === false) throw new RuntimeException('Cost Workbook Phase 2 migration file is unavailable.');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
        $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
        if ($statement !== '') $pdo->exec($statement);
    }
    $pdo->exec("UPDATE cw_supplier_invoice_lines SET discount_type='fixed',discount_value=COALESCE(discount,0),calculated_vat_amount=COALESCE(vat_amount,0),vat_source='legacy_manual' WHERE discount_value IS NULL");
    $stmt = $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','3','system') ON DUPLICATE KEY UPDATE setting_value='3'");
    $stmt->execute();
}

const CW_SYNC_STALE_SECONDS = 600; // Ten minutes: safely above normal batch time, short enough for abandoned-tab recovery.
const CW_SYNC_LOCK_NAME = 'hambelela_cost_workbook_website_sync';
// WooCommerce v3 returns the complete product payload. Keep reads small and give
// this administrator-triggered snapshot enough time for occasional slow pages.
const CW_SYNC_BATCH_SIZE = 10;
const CW_SYNC_READ_ATTEMPTS = 2;
const CW_SYNC_READ_TIMEOUT = 25;
const CW_SYNC_FIELDS = 'id,name,type,sku,categories,attributes,regular_price,sale_price,price,stock_quantity,stock_status,manage_stock,status,permalink,description,short_description,images,meta_data';

function cw_sync_wc_get(string $path, array $query): array
{
    $lastError = null;
    for ($attempt = 1; $attempt <= CW_SYNC_READ_ATTEMPTS; $attempt++) {
        try { return wc_get($path, $query, CW_SYNC_READ_TIMEOUT); }
        catch (Throwable $e) { $lastError = $e; if ($attempt < CW_SYNC_READ_ATTEMPTS) usleep(250000); }
    }
    throw new RuntimeException('Website catalogue read failed after a bounded retry.', 0, $lastError);
}

function cw_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function cw_upgrade_sync_schema_v2(PDO $pdo): void
{
    $columns = [
        'sync_uuid' => "ADD COLUMN sync_uuid CHAR(36) NULL AFTER id",
        'queued_at' => "ADD COLUMN queued_at DATETIME NULL AFTER started_by_name",
        'heartbeat_at' => "ADD COLUMN heartbeat_at DATETIME NULL AFTER started_at",
        'updated_at' => "ADD COLUMN updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER heartbeat_at",
        'failed_at' => "ADD COLUMN failed_at DATETIME NULL AFTER completed_at",
        'recovered_at' => "ADD COLUMN recovered_at DATETIME NULL AFTER failed_at",
        'cancelled_at' => "ADD COLUMN cancelled_at DATETIME NULL AFTER recovered_at",
        'current_batch' => "ADD COLUMN current_batch INT NOT NULL DEFAULT 0 AFTER next_page",
        'current_offset' => "ADD COLUMN current_offset INT NOT NULL DEFAULT 0 AFTER current_batch",
        'expected_total' => "ADD COLUMN expected_total INT NULL AFTER total_products",
        'processed_count' => "ADD COLUMN processed_count INT NOT NULL DEFAULT 0 AFTER expected_total",
        'failure_reason' => "ADD COLUMN failure_reason VARCHAR(500) NULL AFTER error_message",
        'recovery_count' => "ADD COLUMN recovery_count INT NOT NULL DEFAULT 0 AFTER failure_reason",
        'recovery_reason' => "ADD COLUMN recovery_reason VARCHAR(500) NULL AFTER recovery_count",
        'recovered_by' => "ADD COLUMN recovered_by BIGINT NULL AFTER recovery_reason",
        'recovered_by_name' => "ADD COLUMN recovered_by_name VARCHAR(190) NOT NULL DEFAULT '' AFTER recovered_by",
        'is_successful_snapshot' => "ADD COLUMN is_successful_snapshot TINYINT(1) NOT NULL DEFAULT 0 AFTER recovered_by_name",
        'previous_successful_batch_id' => "ADD COLUMN previous_successful_batch_id BIGINT UNSIGNED NULL AFTER is_successful_snapshot",
        'last_batch_started_at' => "ADD COLUMN last_batch_started_at DATETIME NULL AFTER previous_successful_batch_id",
    ];
    foreach ($columns as $column => $sql) {
        if (!cw_column_exists($pdo, 'cw_sync_batches', $column)) $pdo->exec('ALTER TABLE cw_sync_batches ' . $sql);
    }
    $pdo->exec("ALTER TABLE cw_sync_batches MODIFY status ENUM('queued','running','complete','completed','failed','stale','recovered','cancelled') NOT NULL DEFAULT 'queued'");
    $pdo->exec("UPDATE cw_sync_batches SET status='completed' WHERE status='complete'");
    $pdo->exec("UPDATE cw_sync_batches SET sync_uuid=CONCAT('legacy-',id), queued_at=COALESCE(queued_at,started_at), heartbeat_at=COALESCE(heartbeat_at,updated_at,started_at), processed_count=COALESCE(processed_count,success_count), current_batch=GREATEST(current_batch,next_page-1) WHERE sync_uuid IS NULL OR sync_uuid=''");
    $pdo->exec('ALTER TABLE cw_sync_batches MODIFY sync_uuid CHAR(36) NOT NULL');
    $pdo->exec("UPDATE cw_sync_batches SET is_successful_snapshot=0");
    $pdo->exec("UPDATE cw_sync_batches SET is_successful_snapshot=1 WHERE id=(SELECT selected.id FROM (SELECT id FROM cw_sync_batches WHERE status='completed' ORDER BY completed_at DESC,id DESC LIMIT 1) selected)");
    try { $pdo->exec('ALTER TABLE cw_sync_batches ADD UNIQUE KEY uniq_cw_sync_uuid (sync_uuid)'); } catch (Throwable $e) { /* Idempotent upgrade. */ }
    try { $pdo->exec('ALTER TABLE cw_sync_batches ADD KEY idx_cw_sync_status (status,heartbeat_at)'); } catch (Throwable $e) { /* Idempotent upgrade. */ }
    try { $pdo->exec('ALTER TABLE cw_sync_batches ADD KEY idx_cw_sync_successful (is_successful_snapshot,completed_at)'); } catch (Throwable $e) { /* Idempotent upgrade. */ }
    $stmt = $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','2','system') ON DUPLICATE KEY UPDATE setting_value='2'");
    $stmt->execute();
}

function cw_sync_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function cw_sync_timestamp(array $sync): ?string
{
    foreach (['heartbeat_at', 'updated_at', 'started_at'] as $key) if (!empty($sync[$key])) return (string) $sync[$key];
    return null;
}

function cw_sync_is_stale(array $sync, ?int $now = null): bool
{
    if (($sync['status'] ?? '') !== 'running') return false;
    $timestamp = cw_sync_timestamp($sync);
    if ($timestamp === null) return true;
    $parsed = strtotime($timestamp . ' UTC');
    return $parsed === false || (($now ?? time()) - $parsed) > CW_SYNC_STALE_SECONDS;
}

function cw_sync_acquire_lock(PDO $pdo, string $suffix = '', int $timeout = 5): string
{
    $name = CW_SYNC_LOCK_NAME . $suffix;
    $stmt = $pdo->prepare('SELECT GET_LOCK(?,?)');
    $stmt->execute([$name, $timeout]);
    if ((int) $stmt->fetchColumn() !== 1) throw new RuntimeException('Website synchronization is busy. Please try again shortly.');
    return $name;
}

function cw_sync_release_lock(PDO $pdo, string $name): void
{
    try { $stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$name]); } catch (Throwable $e) { /* Connection close also releases advisory locks. */ }
}

function cw_sync_public(array $sync): array
{
    return [
        'id'=>(int)$sync['id'],'sync_uuid'=>(string)($sync['sync_uuid']??''),'status'=>(string)$sync['status'],
        'started_at'=>$sync['started_at']??null,'heartbeat_at'=>cw_sync_timestamp($sync),'completed_at'=>$sync['completed_at']??null,
        'failed_at'=>$sync['failed_at']??null,'recovered_at'=>$sync['recovered_at']??null,'current_batch'=>(int)($sync['current_batch']??0),
        'current_offset'=>(int)($sync['current_offset']??0),'processed_count'=>(int)($sync['processed_count']??0),
        'success_count'=>(int)($sync['success_count']??0),'error_count'=>(int)($sync['error_count']??0),
        'failure_reason'=>$sync['failure_reason']??null,'recovery_reason'=>$sync['recovery_reason']??null,
        'recovery_count'=>(int)($sync['recovery_count']??0),'recovered_by_name'=>$sync['recovered_by_name']??null,'is_stale'=>cw_sync_is_stale($sync),
        'is_successful_snapshot'=>(bool)($sync['is_successful_snapshot']??false),
    ];
}

function cw_csrf_token(): string
{
    if (empty($_SESSION['cw_csrf'])) $_SESSION['cw_csrf'] = bin2hex(random_bytes(24));
    return (string) $_SESSION['cw_csrf'];
}

function cw_require_csrf(): void
{
    $token = (string) ($_SERVER['HTTP_X_CW_CSRF'] ?? $_POST['csrf'] ?? '');
    if (!hash_equals(cw_csrf_token(), $token)) throw new RuntimeException('Your session token expired. Refresh and try again.');
}

function cw_require_admin(): void
{
    if (!user_has_role('owner_admin')) {
        http_response_code(403);
        throw new RuntimeException('Owner/Admin permission is required.');
    }
}

function cw_request_period_bounds(): ?array
{
    if (!isset($_GET['year']) && !isset($_GET['month'])) return null;
    $yearRaw=(string)($_GET['year']??'');$monthRaw=(string)($_GET['month']??'');
    $year=preg_match('/^\d{4}$/',$yearRaw)?(int)$yearRaw:0;$month=preg_match('/^\d{1,2}$/',$monthRaw)?(int)$monthRaw:0;
    if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) throw new DomainException('Invalid Cost Workbook period.');
    $start = sprintf('%04d-%02d-01', $year, $month);
    return [$start, (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d')];
}

function cw_user(): array
{
    $u = current_user();
    return ['id' => isset($u['id']) ? (int) $u['id'] : null, 'name' => (string) ($u['name'] ?? 'Unknown')];
}

function cw_decimal($value): ?string
{
    if ($value === null || $value === '') return null;
    $value = str_replace([',', ' '], '', (string) $value);
    if (!is_numeric($value)) return null;
    return number_format((float) $value, 6, '.', '');
}

function cw_nonnegative_amount_cents($value, string $label): int
{
    $raw = trim((string) $value);
    if ($raw === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $raw)) {
        throw new InvalidArgumentException($label . ' must be a valid non-negative number.');
    }
    return (int) round((float) $raw * 100, 0, PHP_ROUND_HALF_UP);
}

function cw_calculate_invoice_line($quantity, $unitPrice, $discount, $vatAmount, string $vatTreatment): array
{
    $quantityRaw = trim((string) $quantity);
    if ($quantityRaw === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $quantityRaw) || (float) $quantityRaw <= 0) {
        throw new InvalidArgumentException('Quantity must be greater than zero.');
    }
    $unitPriceRaw = trim((string) $unitPrice);
    if ($unitPriceRaw === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $unitPriceRaw)) {
        throw new InvalidArgumentException('Unit cost must be a valid non-negative number.');
    }
    $grossCents = (int) round((float) $quantityRaw * (float) $unitPriceRaw * 100, 0, PHP_ROUND_HALF_UP);
    $discountCents = cw_nonnegative_amount_cents($discount === null || $discount === '' ? '0' : $discount, 'Discount');
    if ($discountCents > $grossCents) {
        throw new InvalidArgumentException('Discount cannot exceed the gross line amount.');
    }
    $vatCents = cw_nonnegative_amount_cents($vatAmount === null || $vatAmount === '' ? '0' : $vatAmount, 'VAT amount');
    $discountedCents = $grossCents - $discountCents;
    if ($vatTreatment === 'inclusive') {
        if ($vatCents > $discountedCents) throw new InvalidArgumentException('VAT amount cannot exceed the discounted VAT-inclusive line amount.');
        $subtotalCents = $discountedCents - $vatCents;
        $totalCents = $discountedCents;
    } elseif ($vatTreatment === 'exempt') {
        if ($vatCents !== 0) throw new InvalidArgumentException('VAT amount must be zero for VAT-exempt invoices.');
        $subtotalCents = $discountedCents;
        $totalCents = $discountedCents;
    } else {
        $subtotalCents = $discountedCents;
        $totalCents = $discountedCents + $vatCents;
    }
    $money = static function (int $cents): string { return number_format($cents / 100, 2, '.', ''); };
    return [
        'gross' => $money($grossCents),
        'discount' => $money($discountCents),
        'line_subtotal' => $money($subtotalCents),
        'vat_amount' => $money($vatCents),
        'line_total' => $money($totalCents),
    ];
}

function cw_normalize_quantity($quantity, string $unit, $packSize = null): array
{
    $q = cw_decimal($quantity);
    if ($q === null || (float) $q <= 0) return [null, null, 'Quantity must be greater than zero.'];
    $u = strtolower(trim($unit));
    $map = ['kg' => ['g', 1000], 'g' => ['g', 1], 'l' => ['ml', 1000], 'ml' => ['ml', 1], 'unit' => ['unit', 1]];
    if (isset($map[$u])) return [number_format((float) $q * $map[$u][1], 6, '.', ''), $map[$u][0], null];
    if ($u === 'pack') {
        $size = cw_decimal($packSize);
        if ($size === null || (float) $size <= 0) return [null, 'pack', 'Pack size must be confirmed before conversion.'];
        return [number_format((float) $q * (float) $size, 6, '.', ''), 'unit', null];
    }
    return [null, null, 'Unit is not recognized.'];
}

function cw_calculate($priceIncVat, $cost, $vatRate, $targetMargin = null): array
{
    $price = cw_decimal($priceIncVat); $unitCost = cw_decimal($cost); $vat = cw_decimal($vatRate); $target = cw_decimal($targetMargin);
    $out = ['selling_ex_vat'=>null,'output_vat'=>null,'gross_profit'=>null,'gross_margin'=>null,'markup'=>null,'recommended_price_inc_vat'=>null,'status'=>'Missing price'];
    $vatBasisPoints = $vat === null ? null : (int) round((float) $vat * 100);
    if ($price === null || $vatBasisPoints === null || 10000 + $vatBasisPoints <= 0) return $out;
    $priceCents = (int) round((float) $price * 100);
    $sellingExVatCents = cw_round_divide($priceCents * 10000, 10000 + $vatBasisPoints);
    $out['selling_ex_vat'] = $sellingExVatCents / 100;
    $out['output_vat'] = ($priceCents - $sellingExVatCents) / 100;
    if ($unitCost === null) { $out['status'] = 'Missing cost'; return $out; }
    $costCents = (int) round((float) $unitCost * 100);
    $profitCents = $sellingExVatCents - $costCents;
    $out['gross_profit'] = $profitCents / 100;
    $out['gross_margin'] = $sellingExVatCents !== 0 ? cw_round_divide($profitCents * 10000, $sellingExVatCents) / 100 : null;
    $out['markup'] = $costCents !== 0 ? cw_round_divide($profitCents * 10000, $costCents) / 100 : null;
    $targetBasisPoints = $target === null ? null : (int) round((float) $target * 100);
    if ($targetBasisPoints !== null && $targetBasisPoints < 10000) {
        $out['recommended_price_inc_vat'] = cw_round_divide($costCents * (10000 + $vatBasisPoints), 10000 - $targetBasisPoints) / 100;
    }
    $out['status'] = $profitCents < 0 ? 'Below cost' : ($targetBasisPoints !== null && $out['gross_margin'] * 100 < $targetBasisPoints ? 'Below target' : ($targetBasisPoints !== null && (int) round($out['gross_margin'] * 100) === $targetBasisPoints ? 'At target' : 'Healthy margin'));
    return $out;
}

function cw_round_divide(int $numerator, int $denominator): int
{
    if ($denominator === 0) throw new InvalidArgumentException('Cannot divide by zero.');
    $sign = (($numerator < 0) !== ($denominator < 0)) ? -1 : 1;
    return $sign * intdiv(abs($numerator) + intdiv(abs($denominator), 2), abs($denominator));
}
