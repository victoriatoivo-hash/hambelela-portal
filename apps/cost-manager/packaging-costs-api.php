<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/cost-workbook.php';
require_once BASE_PATH.'/shared/accounts-input-vat.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');
function pc_reply(array $v,int $s=200):void {http_response_code($s);echo json_encode($v,JSON_UNESCAPED_SLASHES);exit;}
function pc_date($v):string {$v=(string)$v;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new DomainException('Enter a valid effective date.');return $v;}
function pc_audit(PDO $pdo,string $type,int $id,string $action,$before,$after):void {$u=cw_user();$pdo->prepare('INSERT INTO cw_packaging_audit(entity_type,entity_id,action_key,before_json,after_json,actor_id,actor_name) VALUES(?,?,?,?,?,?,?)')->execute([$type,$id,$action,$before===null?null:json_encode($before),$after===null?null:json_encode($after),$u['id'],$u['name']]);}
function pc_fetch(PDO $pdo,string $sql,array $params=[]):?array {$q=$pdo->prepare($sql);$q->execute($params);return $q->fetch()?:null;}
function pc_insert(PDO $pdo,string $table,array $values):int {$cols=array_keys($values);$pdo->prepare('INSERT INTO '.$table.'('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($values));return(int)$pdo->lastInsertId();}
function pc_items(PDO $pdo,string $date):array {
 $items=$pdo->query('SELECT i.*,t.name packaging_type FROM cw_packaging_items i JOIN cw_packaging_types t ON t.id=i.packaging_type_id ORDER BY i.item_name')->fetchAll();
 foreach($items as &$i){$c=cw_packaging_cost($pdo,(int)$i['id'],$date);$i['cost']=$c;$i['status_key']=$c['status_key']??'price_required';if($c){$i['supplier_name']=$c['supplier_name']??$i['supplier_name'];$i['supplier_sku']=$c['supplier_sku']??$i['supplier_sku'];}}unset($i);return $items;
}
function pc_freight(PDO $pdo,array $b,int $previous):array {
 $id=(int)($b['transport_id']??0);if(!$id)return ['freight_ex'=>0,'freight_inc'=>0,'transport_id'=>null,'transport_evidence'=>null];
 $t=pc_fetch($pdo,'SELECT * FROM cw_transport_records WHERE id=? FOR UPDATE',[$id]);
 if(!$t||$t['allocation_status']==='archived'||$t['vat_treatment']==='unconfirmed')throw new DomainException('Choose an active transport record with confirmed VAT.');
 $rate=$t['currency']==='NAD'?1:(!empty($t['fx_confirmed'])?(float)$t['exchange_rate']:0);
 if($rate<=0)throw new DomainException('Confirm the transport exchange rate in Transport Costs first.');
 $method=(string)($b['freight_method']??'manual');
 if($method==='weight'){
  $weight=(float)($b['shipping_weight']??0);$total=(float)($b['shipment_weight']??0);
  if($weight<=0||$total<$weight||!is_finite($weight)||!is_finite($total))throw new DomainException('Confirm material and total shipment weights. Never substitute volume for weight.');
  $amount=round((float)$t['allocable_amount']*$weight/$total,2);
 }else{
  if(empty($b['freight_confirmed'])||!is_numeric($b['freight_amount']??null))throw new DomainException('Confirm the allocated freight amount in the transport source currency.');
  $amount=round((float)$b['freight_amount'],2);
 }
 if(!is_finite($amount)||$amount<0)throw new DomainException('Invalid freight allocation.');
 $q=$pdo->prepare('SELECT COALESCE(SUM(COALESCE(override_amount,calculated_amount)),0) FROM cw_transport_allocations WHERE transport_id=?');$q->execute([$id]);$used=(float)$q->fetchColumn();
 $q=$pdo->prepare('SELECT c.transport_evidence FROM cw_packaging_item_costs c WHERE c.transport_id=? AND c.id<>? AND NOT EXISTS(SELECT 1 FROM cw_packaging_item_costs n WHERE n.supersedes_cost_id=c.id)');$q->execute([$id,$previous]);
 foreach($q->fetchAll() as $c){$e=json_decode($c['transport_evidence']??'{}',true);$used+=(float)($e['source_amount']??0);}
 if(round($used+$amount,2)>round((float)$t['allocable_amount'],2))throw new DomainException('Freight exceeds the unallocated transport balance. Existing supplier and packaging allocations are protected.');
 $e=['transport_id'=>$id,'reference'=>$t['invoice_number'],'currency'=>$t['currency'],'exchange_rate'=>$rate,'source_amount'=>$amount,'allocation_method'=>$method,'shipping_weight'=>$b['shipping_weight']??null,'shipment_weight'=>$b['shipment_weight']??null,'transport_updated_at'=>$t['updated_at']];
 $net=$amount*$rate;$gross=$net;
 if($t['vat_treatment']==='recoverable'&&(float)$t['subtotal_ex_vat']>0)$gross=$net*(1+(float)$t['vat_amount']/(float)$t['subtotal_ex_vat']);
 return ['freight_ex'=>$net,'freight_inc'=>$gross,'transport_id'=>$id,'transport_evidence'=>json_encode($e)];
}
function pc_save_item(PDO $pdo,array $b,float $vatRate):array {
 $name=trim((string)($b['item_name']??''));$spec=trim((string)($b['specification']??''));$type=(int)($b['packaging_type_id']??0);$id=(int)($b['id']??0);$date=pc_date($b['effective_date']??'');
 if($name===''||strlen($name)>190||strlen($spec)>190)throw new DomainException('Enter a material name and valid specification.');
 $pdo->beginTransaction();
 // Serialize writes for a type, including first-time material creation and request retries.
 $typeRow=pc_fetch($pdo,'SELECT * FROM cw_packaging_types WHERE id=? AND active=1 FOR UPDATE',[$type]);if(!$typeRow)throw new DomainException('Choose a packaging type.');
 $key=(string)($b['request_key']??'');if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/',$key))throw new DomainException('Reload the form before saving.');
 $retry=pc_fetch($pdo,'SELECT packaging_item_id FROM cw_packaging_item_costs WHERE request_key=?',[$key]);if($retry){$pdo->commit();return ['id'=>(int)$retry['packaging_item_id'],'message'=>'This save was already recorded.'];}
 $before=$id?pc_fetch($pdo,'SELECT * FROM cw_packaging_items WHERE id=? FOR UPDATE',[$id]):null;if($id&&!$before)throw new DomainException('Material not found.');
 $dup=pc_fetch($pdo,'SELECT id FROM cw_packaging_items WHERE LOWER(TRIM(item_name))=LOWER(?) AND LOWER(TRIM(specification))=LOWER(?) AND packaging_type_id=? AND id<>? LIMIT 1',[$name,$spec,$type,$id]);if($dup)throw new DomainException('This material already exists. Edit its cost rather than creating another material.');
 $prior=$id?pc_fetch($pdo,'SELECT * FROM cw_packaging_item_costs WHERE packaging_item_id=? ORDER BY id DESC LIMIT 1',[$id]):null;
 if($prior&&(int)($b['latest_cost_id']??0)!==(int)$prior['id'])throw new DomainException('This material changed since you opened it. Reload before saving.');
 $c=cw_packaging_calculate($b,$typeRow['name'],$vatRate);$u=cw_user();$supplier=trim((string)($b['supplier_name']??''));$sku=trim((string)($b['supplier_sku']??''));$notes=trim((string)($b['notes']??''));
 if($id)$pdo->prepare('UPDATE cw_packaging_items SET item_name=?,specification=?,packaging_type_id=?,supplier_name=?,supplier_sku=?,notes=?,updated_by=?,updated_by_name=? WHERE id=?')->execute([$name,$spec,$type,$supplier,$sku,$notes,$u['id'],$u['name'],$id]);
 else $id=pc_insert($pdo,'cw_packaging_items',['item_name'=>$name,'specification'=>$spec,'packaging_type_id'=>$type,'supplier_name'=>$supplier,'supplier_sku'=>$sku,'notes'=>$notes,'created_by'=>$u['id'],'created_by_name'=>$u['name']]);
 $previous=empty($b['new_purchase'])?(int)($prior['id']??0):0;
 $freight=pc_freight($pdo,$b,$previous);
 $v=array_merge($c,$freight,['packaging_item_id'=>$id,'effective_date'=>$date,'purchase_quantity'=>$b['purchase_quantity'],'purchase_unit'=>trim((string)($b['purchase_unit']??'each')),'units_per_pack'=>$b['units_per_pack'],'amount_entered'=>$b['amount_entered'],'cost_basis'=>$b['cost_basis'],'vat_treatment'=>$b['vat_treatment'],'vat_rate'=>$vatRate,'supplier_name'=>$supplier,'supplier_sku'=>$sku,'currency'=>strtoupper($b['currency']??'NAD'),'fx_confirmed'=>1,'tax_recoverable'=>!empty($b['tax_recoverable'])?1:0,'printed_label'=>!empty($b['printed_label'])?1:0,'supersedes_cost_id'=>$previous?:null,'request_key'=>$key,'created_by'=>$u['id'],'created_by_name'=>$u['name']]);
 $cost=pc_insert($pdo,'cw_packaging_item_costs',$v);pc_audit($pdo,'item',$id,$before?'cost_version_added':'created',['material'=>$before,'cost'=>$prior],['material'=>['name'=>$name,'specification'=>$spec],'cost'=>$v,'cost_id'=>$cost]);$pdo->commit();return ['id'=>$id,'message'=>'Material saved. Previous costs and freight evidence remain in history.'];
}
function pc_save_setup(PDO $pdo,array $b):array {
 $id=(int)($b['id']??0);$name=trim((string)($b['setup_name']??''));$size=(int)($b['size_conversion_id']??0);$scope=(string)($b['scope_type']??'default');$ref=trim((string)($b['scope_reference']??''));$date=pc_date($b['effective_date']??'');
 if(!$name||!in_array($scope,['default','category','product'],true)||($scope!=='default'&&$ref===''))throw new DomainException('Confirm setup name, size and scope.');
 $pdo->beginTransaction();if(!pc_fetch($pdo,'SELECT id FROM cw_size_conversions WHERE id=? AND active=1 FOR UPDATE',[$size]))throw new DomainException('Choose an active Size Conversion.');
 $before=$id?pc_fetch($pdo,'SELECT * FROM cw_packaging_setups WHERE id=? FOR UPDATE',[$id]):null;if($id&&!$before)throw new DomainException('Setup not found.');
 if($before&&pc_fetch($pdo,'SELECT id FROM cw_packaging_setups WHERE supersedes_setup_id=?',[$id]))throw new DomainException('A newer version exists. Open that version before editing.');
 $ref=$scope==='default'?'':$ref;
 if(!$id&&pc_fetch($pdo,'SELECT id FROM cw_packaging_setups WHERE size_conversion_id=? AND scope_type=? AND scope_reference=? AND active=1',[$size,$scope,$ref]))throw new DomainException('A setup already exists for this size and scope. Edit it to add a version.');
 $u=cw_user();$new=pc_insert($pdo,'cw_packaging_setups',['setup_name'=>$name,'size_conversion_id'=>$size,'scope_type'=>$scope,'scope_reference'=>$ref,'scope_label'=>$scope==='default'?'Global default':trim((string)($b['scope_label']??$ref)),'effective_date'=>$date,'supersedes_setup_id'=>$id?:null,'created_by'=>$u['id'],'created_by_name'=>$u['name']]);
 $rows=(array)($b['components']??[]);if(!$rows)throw new DomainException('Add at least one component.');$seen=[];
 foreach($rows as $order=>$r){$item=(int)($r['packaging_item_id']??0);$q=(float)($r['quantity']??0);$w=(float)($r['waste_percent']??0);
  if(!$item||isset($seen[$item])||$q<=0||!is_finite($q)||$w<0||$w>1000)throw new DomainException('Components must be unique with a positive quantity and valid waste percentage.');$seen[$item]=true;
  $c=cw_packaging_cost($pdo,$item,$date);if(!$c||!$c['active']||$c['landed_ex']===null)throw new DomainException('Every component needs a confirmed cost and print yield on the setup date.');
  $m=$q*(1+$w/100);pc_insert($pdo,'cw_packaging_setup_components',['packaging_setup_id'=>$new,'packaging_item_id'=>$item,'packaging_cost_id'=>$c['id'],'quantity'=>$q,'waste_percent'=>$w,'unit_cost_ex_snapshot'=>$c['landed_ex'],'unit_cost_inc_snapshot'=>$c['landed_inc'],'component_cost_ex'=>$m*$c['landed_ex'],'component_cost_inc'=>$m*$c['landed_inc'],'display_order'=>$order]);
 }
 $cost=cw_packaging_setup_cost($pdo,$new,$date);if(!$cost['complete'])throw new DomainException(implode(' ',$cost['missing']));
 pc_audit($pdo,'setup',$new,$id?'version_created':'created',$before,$cost);$pdo->commit();return ['id'=>$new,'message'=>'Size setup saved with component snapshots. Earlier versions are preserved.'];
}
try {
 $pdo=db();cw_install_schema($pdo);$action=(string)($_GET['action']??'bootstrap');$today=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');$date=pc_date($_GET['date']??$today);
 if(in_array($action,['save-item','save-setup','toggle'],true)){if($_SERVER['REQUEST_METHOD']!=='POST')pc_reply(['error'=>'POST required.'],405);cw_require_csrf();}
 $b=json_decode((string)file_get_contents('php://input'),true)?:[];
 if($action==='bootstrap'){
  $items=pc_items($pdo,$date);$setups=cw_packaging_current_setups($pdo,$date);$ready=array_values(array_filter($setups,function($s){return $s['complete'];}));$missing=count(array_filter($items,function($i){return $i['active']&&$i['status_key']!=='ready';}))+count($setups)-count($ready);
  $sizes=$pdo->query('SELECT id,label,measurement_type,base_value,base_unit FROM cw_size_conversions WHERE active=1 ORDER BY measurement_type,base_value')->fetchAll();$sizeNames=array_column($sizes,'label','id');foreach($setups as &$s)$s['size_label']=$sizeNames[$s['size_conversion_id']]??'';unset($s);
  foreach($items as &$i){$q=$pdo->prepare('SELECT MAX(id) FROM cw_packaging_item_costs WHERE packaging_item_id=?');$q->execute([$i['id']]);$i['latest_cost_id']=(int)$q->fetchColumn();}unset($i);
  pc_reply(['items'=>$items,'setups'=>$setups,'sizes'=>$sizes,'types'=>$pdo->query('SELECT id,name FROM cw_packaging_types WHERE active=1 ORDER BY name')->fetchAll(),'transports'=>$pdo->query("SELECT id,courier_name,invoice_number,currency,allocable_amount,exchange_rate,fx_confirmed,vat_treatment FROM cw_transport_records WHERE allocation_status<>'archived' ORDER BY transport_date DESC,id DESC")->fetchAll(),'vat_rate'=>accounts_standard_vat_rate(),'date'=>$date,'summary'=>['items'=>count(array_filter($items,function($i){return $i['active'];})),'setups'=>count($setups),'missing'=>$missing,'average'=>$ready?array_sum(array_column($ready,'cost_ex_vat'))/count($ready):null]]);
 }
 if($action==='preview'){$id=(int)($_GET['id']??0);pc_reply(cw_packaging_setup_cost($pdo,$id,$date));}
 if($action==='history'){
  $id=(int)($_GET['id']??0);$type=($_GET['type']??'item')==='setup'?'setup':'item';
  $q=$pdo->prepare('SELECT * FROM cw_packaging_audit WHERE entity_type=? AND entity_id=? ORDER BY id DESC');$q->execute([$type,$id]);$audit=$q->fetchAll();
  $q=$pdo->prepare($type==='item'?'SELECT * FROM cw_packaging_item_costs WHERE packaging_item_id=? ORDER BY effective_date DESC,id DESC':'SELECT * FROM cw_packaging_setups WHERE id=?');$q->execute([$id]);$versions=$q->fetchAll();
  if($type==='setup'&&$versions){$first=$versions[0];while(!empty($first['supersedes_setup_id'])){$first=pc_fetch($pdo,'SELECT * FROM cw_packaging_setups WHERE id=?',[$first['supersedes_setup_id']]);if(!$first)break;$versions[]=$first;}}
  pc_reply(['versions'=>$versions,'audit'=>$audit]);
 }
 if($action==='save-item')pc_reply(pc_save_item($pdo,$b,accounts_standard_vat_rate()));
 if($action==='save-setup')pc_reply(pc_save_setup($pdo,$b));
 if($action==='toggle'){
  $type=$b['type']??'';if(!in_array($type,['item','setup'],true))throw new DomainException('Invalid record type.');$id=(int)($b['id']??0);$table=$type==='item'?'cw_packaging_items':'cw_packaging_setups';$pdo->beginTransaction();$before=pc_fetch($pdo,"SELECT * FROM $table WHERE id=? FOR UPDATE",[$id]);if(!$before)throw new DomainException('Record not found.');$active=!empty($b['active'])?1:0;
  $pdo->prepare("UPDATE $table SET active=? WHERE id=?")->execute([$active,$id]);pc_audit($pdo,$type,$id,$active?'reactivated':'deactivated',$before,['active'=>$active]);$pdo->commit();pc_reply(['ok'=>true]);
 }
 pc_reply(['error'=>'Unknown action.'],404);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();pc_reply(['error'=>$e->getMessage()],400);}

