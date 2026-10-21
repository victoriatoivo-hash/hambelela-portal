<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/cost-workbook.php';
require_once BASE_PATH.'/shared/openai-extractor.php';
require_once BASE_PATH.'/shared/pdf-extractor.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');

function si_reply(array $data,int $status=200):void{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES);exit;}
function si_body():array{$value=json_decode((string)file_get_contents('php://input'),true);return is_array($value)?$value:[];}
function si_normalize(string $value):string{$value=function_exists('mb_strtolower')?mb_strtolower(trim($value),'UTF-8'):strtolower(trim($value));return preg_replace('/[^a-z0-9]+/','',$value);}
function si_date($value):?string{$value=trim((string)$value);if($value==='')return null;$date=DateTimeImmutable::createFromFormat('Y-m-d',$value);return $date&&$date->format('Y-m-d')===$value?$value:null;}
function si_decimal($value):?string{return cw_decimal($value);}
function si_categories():array{return ['Plant Butters','Carrier Oils','Essential Oils','Hydrosols','Dried Herbs','Powders','Active Ingredients','Preservatives','Emulsifiers','Surfactants','Proteins','Solvents','Waxes','Packaging','Equipment','Accessories','Other'];}
function si_types(bool $supplier=true):array{return $supplier?['Raw Materials','Packaging','Equipment & Accessories','Freight & Logistics','Services','Mixed','Uncategorised']:['Raw Materials','Packaging','Equipment & Accessories','Freight & Logistics','Services','Mixed'];}
function si_category(string $name):string{$n=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);$map=['butter'=>'Plant Butters','essential'=>'Essential Oils','hydrosol'=>'Hydrosols','herb'=>'Dried Herbs','powder'=>'Powders','preserv'=>'Preservatives','emuls'=>'Emulsifiers','surfact'=>'Surfactants','protein'=>'Proteins','solvent'=>'Solvents','wax'=>'Waxes','bottle'=>'Packaging','jar'=>'Packaging','label'=>'Packaging','pouch'=>'Packaging','oil'=>'Carrier Oils'];foreach($map as $needle=>$category)if(strpos($n,$needle)!==false)return $category;return 'Other';}
function si_base(PDO $pdo,$quantity,$packSize,string $unit):array{$q=(float)($quantity?:0);$size=(float)($packSize?:0);if($q<=0)return[null,null,'Needs quantity'];if($size<=0)return[null,null,'Needs size'];$unit=trim($unit);$baseUnit=in_array($unit,['g','kg'],true)?'kg':(in_array($unit,['ml','L'],true)?'L':($unit==='unit'?'unit':null));if($baseUnit===null)return[null,null,'Needs size'];$factor=null;$st=$pdo->prepare('SELECT base_value,base_unit FROM cw_size_conversions WHERE active=1 AND unit=? AND ABS(quantity-?)<0.000001 LIMIT 1');$st->execute([$unit,$size]);$known=$st->fetch();if($known){$factor=(float)$known['base_value'];$baseUnit=(string)$known['base_unit'];}elseif($unit==='g')$factor=$size/1000;elseif($unit==='kg')$factor=$size;elseif($unit==='ml')$factor=$size/1000;elseif($unit==='L')$factor=$size;elseif($unit==='unit')$factor=$size;if($factor===null||$factor<=0)return[null,null,'Needs size'];return[number_format($q*$factor,6,'.',''),$baseUnit,null];}
function si_supplier(PDO $pdo,string $name,string $type='Uncategorised',bool $confirm=false):int{$normalized=si_normalize($name);if($normalized==='')return 0;$st=$pdo->prepare('SELECT id FROM cw_suppliers WHERE normalized_name=?');$st->execute([$normalized]);$id=(int)$st->fetchColumn();if($id){if($confirm)$pdo->prepare('UPDATE cw_suppliers SET name=?,supplier_type=?,type_confirmed=1 WHERE id=?')->execute([$name,$type,$id]);return$id;}$u=cw_user();$pdo->prepare('INSERT INTO cw_suppliers(name,normalized_name,supplier_type,type_confirmed,created_by) VALUES(?,?,?,?,?)')->execute([$name,$normalized,$type,$confirm?1:0,$u['id']]);return(int)$pdo->lastInsertId();}
function si_line_status(array $line):string{if((float)($line['quantity']??0)<=0)return'Needs quantity';if(empty($line['base_quantity']))return'Needs size';if(($line['match_status']??'unmatched')==='unmatched')return'Needs product match';return'Ready';}

try{
 $pdo=db();cw_install_schema($pdo);$action=(string)($_GET['action']??'list');if(in_array($action,['upload','save','inline','archive-products','delete-products','export'],true)&&$_SERVER['REQUEST_METHOD']!=='POST')si_reply(['error'=>'Use POST for this action.'],405);if($_SERVER['REQUEST_METHOD']!=='GET')cw_require_csrf();
 if(in_array($action,['list','selection'],true)){
  $today=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
  // Select the current approved source BEFORE applying date/supplier filters.
  $fromSql=" FROM cw_cost_products p JOIN cw_landed_product_costs c ON c.id=(SELECT c2.id FROM cw_landed_product_costs c2 JOIN cw_cost_product_sources s2 ON s2.id=c2.product_source_id LEFT JOIN cw_supplier_invoice_lines l2 ON l2.id=s2.supplier_invoice_line_id LEFT JOIN cw_supplier_invoices i2 ON i2.id=l2.supplier_invoice_id WHERE c2.product_id=p.id AND s2.active=1 AND c2.cost_date<=? AND (s2.supplier_invoice_line_id IS NULL OR (i2.approval_status='approved' AND l2.active=1)) ORDER BY c2.cost_date DESC,c2.id DESC LIMIT 1) JOIN cw_cost_product_sources s ON s.id=c.product_source_id LEFT JOIN cw_supplier_invoice_lines l ON l.id=c.supplier_invoice_line_id LEFT JOIN cw_supplier_invoices i ON i.id=l.supplier_invoice_id LEFT JOIN cw_suppliers sp ON sp.id=c.supplier_id";
  $where=['p.active=1'];$params=[$today];
  $basis=($_GET['date_basis']??'effective')==='upload'?'DATE(COALESCE(i.uploaded_at,s.created_at))':'c.cost_date';
  foreach(['from'=>'>=','to'=>'<=']as$key=>$op){$v=si_date($_GET[$key]??'');if($v){$where[]=$basis.$op.'?';$params[]=$v;}}
  foreach(['supplier_id'=>'c.supplier_id','supplier_type'=>'sp.supplier_type','category'=>'p.product_category','status'=>'c.status_key']as$key=>$column){$v=trim((string)($_GET[$key]??''));if($v!==''){$where[]=$column.'=?';$params[]=$v;}}
  $search=trim((string)($_GET['q']??''));if($search!==''){$where[]='(p.product_name LIKE ? OR p.short_code LIKE ? OR p.woo_sku LIKE ? OR s.supplier_name LIKE ? OR i.invoice_number LIKE ?)';for($n=0;$n<5;$n++)$params[]='%'.substr($search,0,100).'%';}
  $sql=$fromSql.' WHERE '.implode(' AND ',$where);
  $q=$pdo->prepare('SELECT COUNT(*)'.$sql);$q->execute($params);$total=(int)$q->fetchColumn();
  if($action==='selection'){$q=$pdo->prepare('SELECT p.id'.$sql.' ORDER BY p.id');$q->execute($params);si_reply(['ids'=>$q->fetchAll(PDO::FETCH_COLUMN),'total'=>$total]);}
  $offset=max(0,(int)($_GET['offset']??0));$limit=80;
  $q=$pdo->prepare("SELECT p.id product_id,p.product_name,p.short_code,p.woo_sku,p.product_category,c.*,s.supplier_name,s.total_purchased_quantity quantity,s.source_type,COALESCE(i.invoice_number,s.manual_reference) invoice_number,i.id supplier_invoice_id,i.updated_at,COALESCE(i.uploaded_at,s.created_at) uploaded_at,c.cost_date invoice_date,sp.supplier_type,l.id line_id,l.quantity purchase_quantity,COALESCE(l.line_subtotal,s.total_supplier_cost) source_cost,l.pack_size,l.purchase_unit,l.shipping_weight_kg".$sql." ORDER BY p.product_name,p.id LIMIT $limit OFFSET $offset");$q->execute($params);$rows=$q->fetchAll();
  foreach($rows as &$row){if($row['currency']!=='NAD')$row['status_key']='exchange_rate_required';if(in_array($row['status_key'],['exchange_rate_required','vat_review_required'],true))$row['supplier_cost_per_base']=null;if($row['status_key']!=='complete'){$row['transport_cost_per_base']=null;$row['landed_cost_per_base']=null;}}unset($row);
  $summary=$pdo->prepare("SELECT COUNT(*) products,SUM(c.status_key<>'complete') attention,COUNT(DISTINCT CASE WHEN c.created_at>=? THEN p.id END) updated".$fromSql.' WHERE p.active=1');$summary->execute([substr($today,0,7).'-01',$today]);$stats=$summary->fetch()?:[];
  $stats['suppliers']=(int)$pdo->query('SELECT COUNT(*) FROM cw_suppliers WHERE active=1')->fetchColumn();
  $stats['attention']=(int)($stats['attention']??0)+(int)$pdo->query("SELECT COUNT(DISTINCT COALESCE(CONCAT('product:',l.cost_product_id),CONCAT('line:',l.product_description))) FROM cw_supplier_invoice_lines l JOIN cw_supplier_invoices i ON i.id=l.supplier_invoice_id WHERE i.approval_status='draft' AND l.active=1")->fetchColumn();
  $stats['pending']=(int)$pdo->query("SELECT COUNT(*) FROM cw_supplier_invoices WHERE approval_status='draft'")->fetchColumn();
  $suppliers=$pdo->query('SELECT id,name,supplier_type FROM cw_suppliers WHERE active=1 ORDER BY name')->fetchAll();
  si_reply(['rows'=>$rows,'total'=>$total,'offset'=>$offset,'has_more'=>$offset+count($rows)<$total,'summary'=>$stats,'suppliers'=>$suppliers]);
 }
 if($action==='products'){
  $search='%'.substr(trim((string)($_GET['q']??'')),0,100).'%';
  $q=$pdo->prepare("SELECT id,product_name,short_code,woo_sku,product_category,base_unit,woo_product_id,woo_variation_id FROM cw_cost_products WHERE active=1 AND (product_name LIKE ? OR short_code LIKE ? OR woo_sku LIKE ?) ORDER BY product_name LIMIT 40");$q->execute([$search,$search,$search]);$products=$q->fetchAll();
  $q=$pdo->prepare("SELECT 0 id,CONCAT(w.product_name,IF(w.variation_name<>'',CONCAT(' · ',w.variation_name),'')) product_name,'Website catalogue' short_code,w.sku woo_sku,w.category product_category,NULL base_unit,w.product_id woo_product_id,w.variation_id woo_variation_id FROM cw_product_snapshots w WHERE w.sync_batch_id=(SELECT id FROM cw_sync_batches WHERE is_successful_snapshot=1 ORDER BY completed_at DESC,id DESC LIMIT 1) AND (w.product_name LIKE ? OR w.sku LIKE ?) AND NOT EXISTS(SELECT 1 FROM cw_cost_products p WHERE p.woo_product_id=w.product_id AND COALESCE(p.woo_variation_id,0)=w.variation_id) ORDER BY w.product_name LIMIT 40");$q->execute([$search,$search]);si_reply(['rows'=>array_merge($products,$q->fetchAll())]);
 }
 if($action==='pending'){
  $q=$pdo->query("SELECT id,supplier_name,invoice_number,invoice_date,uploaded_at,extraction_status FROM cw_supplier_invoices WHERE approval_status='draft' ORDER BY uploaded_at DESC,id DESC");si_reply(['rows'=>$q->fetchAll()]);
 }
 if($action==='history'){
  $id=(int)($_GET['product_id']??0);$q=$pdo->prepare('SELECT c.*,s.supplier_name,s.manual_reference,i.invoice_number FROM cw_landed_product_costs c JOIN cw_cost_product_sources s ON s.id=c.product_source_id LEFT JOIN cw_supplier_invoice_lines l ON l.id=c.supplier_invoice_line_id LEFT JOIN cw_supplier_invoices i ON i.id=l.supplier_invoice_id WHERE c.product_id=? ORDER BY c.cost_date DESC,c.id DESC');$q->execute([$id]);$rows=$q->fetchAll();
  $audit=$pdo->prepare("SELECT * FROM cw_cost_audit_events WHERE (entity_type='supplier_invoice_line' AND entity_id IN(SELECT supplier_invoice_line_id FROM cw_cost_product_sources WHERE product_id=?)) OR (entity_type='cost_product' AND entity_id=?) ORDER BY occurred_at DESC,id DESC");$audit->execute([$id,$id]);si_reply(['rows'=>$rows,'audit'=>$audit->fetchAll()]);
 }
 if($action==='inline'){
  cw_require_admin();$b=si_body();$pid=(int)($b['product_id']??0);$sourceId=(int)($b['source_id']??0);$field=(string)($b['field']??'');$value=trim((string)($b['value']??''));
  $allowed=['product_name','product_category','short_code','supplier_name','supplier_type','invoice_number','quantity','line_subtotal'];if(!in_array($field,$allowed,true))throw new DomainException('This field is calculated or cannot be edited inline.');
  $pdo->beginTransaction();$q=$pdo->prepare('SELECT * FROM cw_cost_products WHERE id=? AND active=1 FOR UPDATE');$q->execute([$pid]);$product=$q->fetch();if(!$product)throw new DomainException('Product unavailable.');
  if(in_array($field,['product_name','product_category','short_code'],true)){
   if($value==='')throw new DomainException('Enter a value.');
   if($field==='short_code' && $product['woo_sku']!=='')throw new DomainException('A valid WooCommerce SKU cannot be overwritten. The internal reference remains separate.');
   if($field==='product_name'){$q=$pdo->prepare('SELECT id FROM cw_cost_products WHERE normalized_name=? AND id<>?');$q->execute([lpc_norm($value),$pid]);if($q->fetchColumn())throw new DomainException('Product already exists. Do not merge products by renaming.');}
   $u=cw_user();$pdo->prepare("UPDATE cw_cost_products SET $field=?,updated_by=?,updated_by_name=? WHERE id=?")->execute([$value,$u['id'],$u['name'],$pid]);
   if($field==='product_name')$pdo->prepare('UPDATE cw_cost_products SET normalized_name=? WHERE id=?')->execute([lpc_norm($value),$pid]);
   cw_supplier_cost_audit($pdo,'cost_product',$pid,'inline_updated',$product,[$field=>$value]);
  }else{
   $q=$pdo->prepare('SELECT i.*,l.id line_id FROM cw_cost_product_sources s JOIN cw_supplier_invoice_lines l ON l.id=s.supplier_invoice_line_id JOIN cw_supplier_invoices i ON i.id=l.supplier_invoice_id WHERE s.id=? AND s.product_id=? FOR UPDATE');$q->execute([$sourceId,$pid]);$invoice=$q->fetch();
   if(!$invoice){
    $q=$pdo->prepare("SELECT * FROM cw_cost_product_sources WHERE id=? AND product_id=? AND source_type='manual' AND active=1 FOR UPDATE");$q->execute([$sourceId,$pid]);$source=$q->fetch();
    if(!$source || $source['currency']!=='NAD')throw new DomainException('Open the original source editor to confirm this source and its currency.');
    $q=$pdo->prepare('SELECT * FROM cw_landed_product_costs WHERE product_source_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$q->execute([$sourceId]);$cost=$q->fetch();if(!$cost || (int)($b['cost_id']??0)!==(int)$cost['id'])throw new DomainException('This cost changed. Refresh before editing.');
    $updated=$source;
    if(in_array($field,['quantity','line_subtotal'],true)){$number=si_decimal($value);if($number===null || (float)$number<0 || ($field==='quantity'&&(float)$number<=0))throw new DomainException('Confirm quantity and source cost.');$updated[$field==='quantity'?'total_purchased_quantity':'total_supplier_cost']=$number;}
    elseif($field==='invoice_number')$updated['manual_reference']=$value;
    else{$type=$field==='supplier_type'?$value:'Uncategorised';if(!in_array($type,si_types(true),true))throw new DomainException('Select a valid supplier type.');$updated['supplier_name']=$field==='supplier_name'?$value:$source['supplier_name'];$updated['supplier_id']=si_supplier($pdo,$updated['supplier_name'],$type,true);}
    $pdo->prepare('UPDATE cw_cost_product_sources SET supplier_id=?,supplier_name=?,manual_reference=?,total_purchased_quantity=?,total_supplier_cost=? WHERE id=?')->execute([$updated['supplier_id'],$updated['supplier_name'],$updated['manual_reference'],$updated['total_purchased_quantity'],$updated['total_supplier_cost'],$sourceId]);
    lpc_snapshot($pdo,$pid,$sourceId,(int)$updated['supplier_id']?:null,null,null,$cost['costing_basis'],$cost['base_unit'],(float)$updated['total_purchased_quantity'],(float)$updated['total_supplier_cost'],(float)$cost['transport_cost_used'],'NAD',$source['cost_date'],$cost['status_key']);
    cw_supplier_cost_audit($pdo,'cost_product',$pid,'manual_source_updated',$source,$updated);$pdo->commit();si_reply(['ok'=>true]);
   }
   if((string)($b['updated_at']??'')!==(string)$invoice['updated_at'])throw new DomainException('The source changed. Refresh before editing.');
   if(in_array($field,['quantity','line_subtotal'],true)){
    $number=si_decimal($value);if($number===null || (float)$number<0 || ($field==='quantity' && (float)$number<=0))throw new DomainException('Enter a valid positive quantity or non-negative source cost.');
    $q=$pdo->prepare('SELECT * FROM cw_supplier_invoice_lines WHERE supplier_invoice_id=? AND active=1 ORDER BY id');$q->execute([$invoice['id']]);$lines=$q->fetchAll();
    foreach($lines as &$line)if((int)$line['id']===(int)$invoice['line_id'])$line[$field]=$number;unset($line);
    cw_supplier_save_lines($pdo,(int)$invoice['id'],$lines);
   }else{
    if($value==='')throw new DomainException('Enter a value.');
    if($field==='supplier_type' && !in_array($value,si_types(true),true))throw new DomainException('Select a valid supplier type.');
    $name=$field==='supplier_name'?$value:$invoice['supplier_name'];$type=$field==='supplier_type'?$value:$invoice['supplier_type'];$ref=$field==='invoice_number'?$value:$invoice['invoice_number'];
    $sid=si_supplier($pdo,$name,$type,true);$q=$pdo->prepare("SELECT id FROM cw_supplier_invoices WHERE id<>? AND supplier_id=? AND invoice_date=? AND LOWER(TRIM(invoice_number))=LOWER(TRIM(?)) AND approval_status<>'archived'");$q->execute([$invoice['id'],$sid,$invoice['invoice_date'],$ref]);if($q->fetchColumn())throw new DomainException('Possible duplicate invoice.');
    $pdo->prepare('UPDATE cw_supplier_invoices SET supplier_id=?,supplier_name=?,supplier_type=?,invoice_number=? WHERE id=?')->execute([$sid,$name,$type,$ref,$invoice['id']]);
   }
   $u=cw_user();$pdo->prepare('UPDATE cw_supplier_invoices SET last_edited_by=?,last_edited_by_name=?,updated_at=NOW() WHERE id=?')->execute([$u['id'],$u['name'],$invoice['id']]);
   cw_supplier_cost_audit($pdo,'supplier_invoice',(int)$invoice['id'],'inline_updated',$invoice,[$field=>$value]);
   lpc_refresh($pdo,[(int)$invoice['id']]);
  }
  $pdo->commit();si_reply(['ok'=>true]);
 }
 if(in_array($action,['archive-products','delete-products','export'],true)){
  cw_require_admin();$b=si_body();$ids=array_values(array_unique(array_filter(array_map('intval',(array)($b['ids']??[])),function($id){return $id>0;})));if(!$ids || count($ids)>10000)throw new DomainException('Select between 1 and 10,000 products.');
  $marks=implode(',',array_fill(0,count($ids),'?'));
  if($action==='export'){$today=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');$q=$pdo->prepare("SELECT p.product_name,p.short_code,p.woo_sku,p.product_category,c.currency,c.cost_date,c.base_unit,c.supplier_cost_per_base,c.transport_cost_per_base,c.landed_cost_per_base,c.status_key FROM cw_cost_products p LEFT JOIN cw_landed_product_costs c ON c.id=(SELECT c2.id FROM cw_landed_product_costs c2 JOIN cw_cost_product_sources s ON s.id=c2.product_source_id LEFT JOIN cw_supplier_invoice_lines l ON l.id=s.supplier_invoice_line_id LEFT JOIN cw_supplier_invoices i ON i.id=l.supplier_invoice_id WHERE c2.product_id=p.id AND s.active=1 AND (s.supplier_invoice_line_id IS NULL OR (i.approval_status='approved' AND l.active=1)) AND c2.cost_date<=? ORDER BY c2.cost_date DESC,c2.id DESC LIMIT 1) WHERE p.id IN($marks) ORDER BY p.product_name");$q->execute(array_merge([$today],$ids));$export=$q->fetchAll();foreach($export as &$row){if($row['currency']!=='NAD')$row['status_key']='exchange_rate_required';if($row['status_key']!=='complete'){$row['transport_cost_per_base']=null;$row['landed_cost_per_base']=null;}if(in_array($row['status_key'],['exchange_rate_required','vat_review_required'],true))$row['supplier_cost_per_base']=null;}unset($row);si_reply(['rows'=>$export]);}
  if(empty($b['confirmed']))throw new DomainException('Confirm this bulk action.');
  $pdo->beginTransaction();$q=$pdo->prepare("SELECT * FROM cw_cost_products WHERE id IN($marks) FOR UPDATE");$q->execute($ids);$products=$q->fetchAll();
  if($action==='delete-products'){$q=$pdo->prepare("SELECT COUNT(*) FROM cw_cost_product_sources WHERE product_id IN($marks)");$q->execute($ids);if((int)$q->fetchColumn())throw new DomainException('Approved or referenced product history cannot be deleted. Use Archive instead.');}
  foreach($products as $product){cw_supplier_cost_audit($pdo,'cost_product',(int)$product['id'],$action,$product,null);}
  $pdo->prepare($action==='archive-products'?"UPDATE cw_cost_products SET active=0 WHERE id IN($marks)":"DELETE FROM cw_cost_products WHERE id IN($marks)")->execute($ids);$pdo->commit();si_reply(['ok'=>true,'count'=>count($products)]);
 }
 if($action==='upload'){
  cw_require_admin();if(empty($_FILES['invoice_file'])||!is_uploaded_file((string)$_FILES['invoice_file']['tmp_name']))throw new RuntimeException('Select an invoice file.');$f=$_FILES['invoice_file'];if((int)$f['error']!==UPLOAD_ERR_OK)throw new RuntimeException('The invoice did not upload successfully.');$size=(int)$f['size'];if($size<=0||$size>15*1024*1024)throw new RuntimeException('Invoice files must be 15 MB or smaller.');$ext=strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION));$allowed=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];if(!isset($allowed[$ext]))throw new RuntimeException('Upload a supported PDF, JPG, JPEG or PNG invoice.');$mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);if($mime!==$allowed[$ext])throw new RuntimeException('The file content does not match its extension.');$hash=hash_file('sha256',(string)$f['tmp_name']);$dup=$pdo->prepare("SELECT id FROM cw_supplier_invoices WHERE file_hash=? AND approval_status<>'archived'");$dup->execute([$hash]);if($existing=(int)$dup->fetchColumn())si_reply(['error'=>'Possible duplicate: this exact invoice file already exists.','duplicate_id'=>$existing],409);
  $dir=BASE_PATH.'/uploads/cost-workbook/'.date('Y/m');if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Secure upload storage is unavailable.');$stored=date('YmdHis').'-'.bin2hex(random_bytes(8)).'.'.$ext;$relative='uploads/cost-workbook/'.date('Y/m').'/'.$stored;if(!move_uploaded_file((string)$f['tmp_name'],$dir.'/'.$stored))throw new RuntimeException('The invoice file could not be stored.');$u=cw_user();$pdo->prepare("INSERT INTO cw_supplier_invoices(uploaded_by,uploaded_by_name,original_filename,stored_file,file_type,file_hash,currency,extraction_status,extraction_message) VALUES(?,?,?,?,?,?,'NAD','not_started','Extracting invoice details')")->execute([$u['id'],$u['name'],basename((string)$f['name']),$relative,$mime,$hash]);$id=(int)$pdo->lastInsertId();$data=[];$message='Automatic extraction is unavailable for this file type. Enter the invoice manually.';$confidence='low';$status='manual_review';
  if($ext==='pdf'){$result=openai_extract_pdf($dir.'/'.$stored,basename((string)$f['name']),'supplier','');if($result['ok']){$data=$result['data'];$message=$result['message'];$confidence=(string)($data['confidence']??'medium');$status='complete';}else{$text=extract_pdf_text($dir.'/'.$stored);if(!empty($text['text'])){$data=parse_supplier_invoice_text((string)$text['text']);$message=$result['message'].' '.$text['message'];}$status=$data?'manual_review':'failed';}}
  $supplierName=trim((string)($data['supplier_name']??''));$supplierId=$supplierName!==''?si_supplier($pdo,$supplierName):0;$pdo->prepare('UPDATE cw_supplier_invoices SET supplier_id=?,supplier_name=?,invoice_number=?,invoice_date=?,currency=?,subtotal=?,vat_amount=?,invoice_total=?,extraction_status=?,extraction_confidence=?,extraction_message=? WHERE id=?')->execute([$supplierId?:null,$supplierName,(string)($data['invoice_number']??''),si_date($data['invoice_date']??''),strtoupper(substr((string)($data['currency']??'NAD'),0,3)),si_decimal($data['subtotal']??null),si_decimal($data['vat_amount']??null),si_decimal($data['total']??null),$status,$confidence,substr($message,0,500),$id]);
  $lines=array_merge((array)($data['raw_materials']??[]),(array)($data['packaging']??[]));$insert=$pdo->prepare("INSERT INTO cw_supplier_invoice_lines(supplier_invoice_id,raw_description,product_description,product_category,quantity,purchase_unit,unit_price,line_subtotal,line_total,item_type,match_status,review_status,extraction_confidence) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");foreach($lines as $line){$name=trim((string)($line['name']??''));if($name==='')continue;$quantity=si_decimal($line['quantity']??null);$unit=trim((string)($line['unit']??''));$total=si_decimal($line['line_total']??null);$unitPrice=si_decimal($line['unit_price']??null);if($total===null&&$quantity!==null&&$unitPrice!==null)$total=number_format((float)$quantity*(float)$unitPrice,2,'.','');$insert->execute([$id,$name,$name,si_category($name),$quantity,$unit,$unitPrice,$total,$total,'unclassified','unmatched','needs_review',$confidence]);}
  si_reply(['ok'=>true,'id'=>$id,'message'=>$message,'stage'=>'ready_for_review']);
 }
 if($action==='invoice'){$id=(int)($_GET['id']??0);$st=$pdo->prepare('SELECT * FROM cw_supplier_invoices WHERE id=?');$st->execute([$id]);$invoice=$st->fetch();if(!$invoice)si_reply(['error'=>'Invoice not found.'],404);$st=$pdo->prepare('SELECT * FROM cw_supplier_invoice_lines WHERE supplier_invoice_id=? AND active=1 ORDER BY id');$st->execute([$id]);$suppliers=$pdo->query('SELECT id,name,supplier_type FROM cw_suppliers WHERE active=1 ORDER BY name')->fetchAll();si_reply(['invoice'=>$invoice,'lines'=>$st->fetchAll(),'suppliers'=>$suppliers,'original_url'=>'supplier-invoices-api.php?action=file&id='.$id]);}
 if($action==='save'){
  cw_require_admin(); $b=si_body(); $id=(int)($b['id']??0);
  $supplierName=trim((string)($b['supplier_name']??''));
  $supplierType=(string)($b['supplier_type']??'Uncategorised');
  $invoiceType=(string)($b['invoice_type']??'Mixed');
  $date=si_date($b['invoice_date']??''); $number=trim((string)($b['invoice_number']??''));
  $currency=strtoupper(trim((string)($b['currency']??'NAD')));
  $vat=(string)($b['vat_treatment']??'unconfirmed');
  if($supplierName==='' || !in_array($supplierType,si_types(true),true) || !in_array($invoiceType,si_types(false),true) || !$date || !preg_match('/^[A-Z]{3}$/',$currency)) throw new DomainException('Confirm supplier, type, effective date and currency.');
  if(!in_array($vat,['exclusive','inclusive','mixed','exempt','unconfirmed'],true)) throw new DomainException('Confirm VAT treatment.');
  $lines=(array)($b['lines']??[]); if(!$lines) throw new DomainException('Add at least one purchased product.');
  $approve=!empty($b['approve']); $actor=cw_user(); $pdo->beginTransaction();
  $supplierId=si_supplier($pdo,$supplierName,$supplierType,true);
  $before=null;
  if($id){
   $q=$pdo->prepare("SELECT * FROM cw_supplier_invoices WHERE id=? AND approval_status<>'archived' FOR UPDATE");$q->execute([$id]);$before=$q->fetch();
   if(!$before)throw new DomainException('Purchase not found or archived.');
   if(!isset($b['updated_at']) || (string)$b['updated_at']!==(string)$before['updated_at'])throw new DomainException('This purchase changed since you opened it. Reopen it before saving.');
   if($before['approval_status']==='approved')$approve=true;
  }else{
   $request=trim((string)($b['request_id']??''));
   if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$request))throw new DomainException('Manual entry token is missing. Reopen Add Manually.');
   $hash=hash('sha256','manual:'.$actor['id'].':'.$request);
   $q=$pdo->prepare('SELECT id FROM cw_supplier_invoices WHERE file_hash=? FOR UPDATE');$q->execute([$hash]);
   if($prior=(int)$q->fetchColumn()){$pdo->rollBack();si_reply(['ok'=>true,'id'=>$prior,'message'=>'This purchase was already saved.']);}
   if($number==='')$number='MAN-'.strtoupper(substr($hash,0,16));
   $pdo->prepare("INSERT INTO cw_supplier_invoices(original_filename,stored_file,file_type,file_hash,entry_source,uploaded_by,uploaded_by_name) VALUES('','','',?,'manual',?,?)")->execute([$hash,$actor['id'],$actor['name']]);
   $id=(int)$pdo->lastInsertId();
  }
  if($number==='')throw new DomainException('Invoice reference is required.');
  $dup=$pdo->prepare("SELECT id FROM cw_supplier_invoices WHERE id<>? AND supplier_id=? AND LOWER(TRIM(invoice_number))=LOWER(TRIM(?)) AND invoice_date=? AND approval_status<>'archived' LIMIT 1");
  $dup->execute([$id,$supplierId,$number,$date]);if($dup->fetchColumn())throw new DomainException('Possible duplicate: this supplier, invoice reference and date already exist.');
  $rate=$currency==='NAD'?1:si_decimal($b['exchange_rate']??null);$fx=$currency==='NAD'||(!empty($b['fx_confirmed'])&&(float)$rate>0);
  $pdo->prepare("UPDATE cw_supplier_invoices SET supplier_id=?,supplier_name=?,supplier_type=?,invoice_type=?,invoice_number=?,invoice_date=?,currency=?,exchange_rate=?,fx_confirmed=?,vat_treatment=?,subtotal=?,vat_amount=?,invoice_total=?,shipping_amount=?,review_status='reviewed',last_edited_by=?,last_edited_by_name=? WHERE id=?")->execute([$supplierId,$supplierName,$supplierType,$invoiceType,$number,$date,$currency,$rate?:1,$fx?1:0,$vat,si_decimal($b['subtotal']??($before['subtotal']??null)),si_decimal($b['vat_amount']??($before['vat_amount']??null)),si_decimal($b['invoice_total']??($before['invoice_total']??null)),si_decimal($b['shipping_amount']??($before['shipping_amount']??null)),$actor['id'],$actor['name'],$id]);
  $valid=cw_supplier_save_lines($pdo,$id,$lines,[(int)($b['transport']['id']??0)]);
  if($approve){
   $q=$pdo->prepare("SELECT COUNT(*) FROM cw_supplier_invoice_lines WHERE supplier_invoice_id=? AND active=1 AND (review_status<>'valid' OR match_status<>'confirmed' OR item_type='unclassified')");$q->execute([$id]);
   if((int)$q->fetchColumn())throw new DomainException('Confirm product matching, quantities and source costs before approval.');
   $pdo->prepare("UPDATE cw_supplier_invoices SET approval_status='approved',approved_by=?,approved_by_name=?,approved_at=COALESCE(approved_at,NOW()) WHERE id=?")->execute([$actor['id'],$actor['name'],$id]);
  }
  if(isset($b['transport']) && is_array($b['transport'])){
   $transport=$b['transport'];$transport['allocations']=[['supplier_invoice_id'=>$id]];
   if(!empty($transport['link_existing']) && !empty($transport['id'])){
    $q=$pdo->prepare("SELECT * FROM cw_transport_records WHERE id=? AND allocation_status<>'archived' FOR UPDATE");$q->execute([(int)$transport['id']]);$record=$q->fetch();if(!$record)throw new DomainException('Shipping record is unavailable.');
    $q=$pdo->prepare('SELECT supplier_invoice_id,allocation_percentage,calculated_amount FROM cw_transport_allocations WHERE transport_id=? AND supplier_invoice_id<>?');$q->execute([(int)$transport['id'],$id]);$otherLinks=$q->fetchAll();
    if($otherLinks && empty($transport['confirm_shared']))throw new DomainException('Confirm the shared-shipment allocation preview before linking this purchase.');
    if($otherLinks && $record['allocation_method']!=='by_weight')throw new DomainException('Revise this alternative shared allocation in Transport Costs so its complete amounts can be explicitly confirmed.');
    foreach($otherLinks as $link)$transport['allocations'][]=['supplier_invoice_id'=>$link['supplier_invoice_id'],'value'=>$record['allocation_method']==='percentage'?$link['allocation_percentage']:$link['calculated_amount']];
    foreach(['courier_name','invoice_number','transport_date','currency','exchange_rate','fx_confirmed','vat_treatment','vat_amount','non_transport_charges','allocation_method','notes'] as $key)$transport[$key]=$record[$key];
    $transport['transport_amount']=$record['subtotal_ex_vat'];$transport['amount_basis']='excluding';
   }
   cw_transport_save($pdo,$transport);
  }
  lpc_refresh($pdo,[$id]);
  cw_supplier_cost_audit($pdo,'supplier_invoice',$id,$approve?'approved':'saved',$before,['request'=>$b,'supplier_id'=>$supplierId]);
  $pdo->commit();si_reply(['ok'=>true,'id'=>$id,'valid_lines'=>$valid,'message'=>$approve?'Supplier cost approved. Historical sources are preserved.':'Purchase saved for review.']);
 }
 if($action==='file'){$id=(int)($_GET['id']??0);$st=$pdo->prepare('SELECT original_filename,stored_file,file_type FROM cw_supplier_invoices WHERE id=?');$st->execute([$id]);$file=$st->fetch();$path=$file?BASE_PATH.'/'.$file['stored_file']:'';if(!$file||!is_file($path))throw new RuntimeException('Original invoice file is unavailable.');header_remove('Content-Type');header('Content-Type: '.$file['file_type']);header('Content-Disposition: inline; filename="'.rawurlencode((string)$file['original_filename']).'"');header('X-Content-Type-Options: nosniff');readfile($path);exit;}
 si_reply(['error'=>'Unknown action.'],404);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();si_reply(['error'=>$e->getMessage()],http_response_code()>=400?http_response_code():422);}
