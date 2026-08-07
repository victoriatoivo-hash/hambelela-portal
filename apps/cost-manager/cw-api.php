<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/woocommerce.php';
require_once BASE_PATH . '/shared/cost-workbook.php';
require_role('owner_admin', 'supervisor_manager');
header('Content-Type: application/json; charset=utf-8');

function cw_json(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function cw_body(): array { $raw=file_get_contents('php://input'); $data=json_decode($raw ?: '{}', true); return is_array($data)?$data:[]; }
function cw_money($v): ?string { $d=cw_decimal($v); return $d===null?null:number_format((float)$d,2,'.',''); }

try {
    $pdo=db(); cw_install_schema($pdo); $action=(string)($_GET['action']??'summary');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') cw_require_csrf();

    if ($action === 'summary') {
        $counts=['active_invoices'=>0,'needs_review'=>0,'approved'=>0,'website_items'=>0];
        $counts['active_invoices']=(int)$pdo->query("SELECT COUNT(*) FROM cw_supplier_invoices WHERE approval_status<>'archived'")->fetchColumn();
        $counts['needs_review']=(int)$pdo->query("SELECT COUNT(*) FROM cw_supplier_invoices WHERE approval_status='draft' AND review_status='needs_review'")->fetchColumn();
        $counts['approved']=(int)$pdo->query("SELECT COUNT(*) FROM cw_supplier_invoices WHERE approval_status='approved'")->fetchColumn();
        $counts['website_items']=(int)$pdo->query("SELECT COUNT(*) FROM cw_product_snapshots WHERE sync_batch_id=(SELECT MAX(id) FROM cw_sync_batches WHERE status='complete')")->fetchColumn();
        $invoices=$pdo->query("SELECT i.*, (SELECT COUNT(*) FROM cw_supplier_invoice_lines l WHERE l.supplier_invoice_id=i.id) line_count FROM cw_supplier_invoices i WHERE i.approval_status<>'archived' ORDER BY i.uploaded_at DESC LIMIT 100")->fetchAll();
        $settings=[]; foreach($pdo->query('SELECT setting_key,setting_value FROM cw_settings')->fetchAll() as $r) $settings[$r['setting_key']]=$r['setting_value'];
        $last=$pdo->query("SELECT * FROM cw_sync_batches WHERE status='complete' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
        cw_json(compact('counts','invoices','settings','last'));
    }

    if ($action === 'upload') {
        cw_require_admin();
        if (empty($_FILES['invoice_files'])) throw new RuntimeException('Select at least one invoice file.');
        $allowed=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','csv'=>['text/csv','text/plain','application/csv','application/vnd.ms-excel']];
        $max=15*1024*1024; $files=$_FILES['invoice_files']; $results=[]; $finfo=new finfo(FILEINFO_MIME_TYPE); $user=cw_user();
        $names=is_array($files['name'])?$files['name']:[$files['name']];
        foreach($names as $i=>$name){
            $tmp=is_array($files['tmp_name'])?$files['tmp_name'][$i]:$files['tmp_name']; $size=(int)(is_array($files['size'])?$files['size'][$i]:$files['size']); $error=(int)(is_array($files['error'])?$files['error'][$i]:$files['error']);
            try { if($error!==UPLOAD_ERR_OK) throw new RuntimeException('Upload error '.$error); if($size<=0||$size>$max) throw new RuntimeException('File must be 15 MB or smaller.');
                $ext=strtolower(pathinfo((string)$name,PATHINFO_EXTENSION)); if(!isset($allowed[$ext])) throw new RuntimeException('Unsupported file extension.');
                $mime=(string)$finfo->file($tmp); $mimes=(array)$allowed[$ext]; if(!in_array($mime,$mimes,true)) throw new RuntimeException('File content does not match its extension.');
                $dir=BASE_PATH.'/uploads/cost-workbook/'.date('Y/m'); if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir)) throw new RuntimeException('Secure upload folder is unavailable.');
                $stored=date('YmdHis').'-'.bin2hex(random_bytes(8)).'.'.$ext; $relative='uploads/cost-workbook/'.date('Y/m').'/'.$stored;
                if(!move_uploaded_file($tmp,BASE_PATH.'/'.$relative)) throw new RuntimeException('Could not store the uploaded file.');
                $stmt=$pdo->prepare('INSERT INTO cw_supplier_invoices (uploaded_by,uploaded_by_name,original_filename,stored_file,file_type,currency,extraction_status) VALUES (?,?,?,?,?,\'NAD\',\'manual_review\')');
                $stmt->execute([$user['id'],$user['name'],basename((string)$name),$relative,$mime]); $results[]=['name'=>$name,'ok'=>true,'id'=>(int)$pdo->lastInsertId()];
            } catch(Throwable $e){ $results[]=['name'=>$name,'ok'=>false,'error'=>$e->getMessage()]; }
        } cw_json(['results'=>$results]);
    }

    if ($action === 'invoice') {
        $id=(int)($_GET['id']??0); $st=$pdo->prepare('SELECT * FROM cw_supplier_invoices WHERE id=?'); $st->execute([$id]); $invoice=$st->fetch(); if(!$invoice) cw_json(['error'=>'Invoice not found.'],404);
        $st=$pdo->prepare('SELECT * FROM cw_supplier_invoice_lines WHERE supplier_invoice_id=? ORDER BY id'); $st->execute([$id]); cw_json(['invoice'=>$invoice,'lines'=>$st->fetchAll()]);
    }

    if ($action === 'save-invoice') {
        cw_require_admin(); $b=cw_body(); $id=(int)($b['id']??0); $currency=strtoupper(trim((string)($b['currency']??''))); if(!preg_match('/^[A-Z]{3}$/',$currency)) throw new RuntimeException('Confirm a valid three-letter currency.');
        $vat=(string)($b['vat_treatment']??'unconfirmed'); if(!in_array($vat,['unconfirmed','inclusive','exclusive','exempt','mixed'],true)) $vat='unconfirmed';
        $pdo->beginTransaction(); $st=$pdo->prepare('UPDATE cw_supplier_invoices SET supplier_name=?,invoice_number=?,invoice_date=?,currency=?,exchange_rate=?,vat_treatment=?,subtotal=?,vat_amount=?,invoice_total=?,notes=?,review_status=\'reviewed\' WHERE id=? AND approval_status=\'draft\'');
        $st->execute([trim((string)($b['supplier_name']??'')),trim((string)($b['invoice_number']??''))?:null,($b['invoice_date']??'')?:null,$currency,cw_decimal($b['exchange_rate']??1),$vat,cw_money($b['subtotal']??null),cw_money($b['vat_amount']??null),cw_money($b['invoice_total']??null),trim((string)($b['notes']??''))?:null,$id]);
        $pdo->prepare('DELETE FROM cw_supplier_invoice_lines WHERE supplier_invoice_id=?')->execute([$id]);
        $ins=$pdo->prepare('INSERT INTO cw_supplier_invoice_lines (supplier_invoice_id,raw_description,product_description,supplier_sku,quantity,purchase_unit,pack_size,base_quantity,base_unit,unit_price,line_subtotal,vat_amount,line_total,discount,item_type,review_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach((array)($b['lines']??[]) as $line){ [$base,$baseUnit,$warning]=cw_normalize_quantity($line['quantity']??null,(string)($line['purchase_unit']??''),$line['pack_size']??null); $cost=cw_decimal($line['unit_price']??null); $valid=$warning===null&&$cost!==null&&(float)$cost>=0&&trim((string)($line['product_description']??''))!=='';
            $ins->execute([$id,trim((string)($line['raw_description']??'')),trim((string)($line['product_description']??'')),trim((string)($line['supplier_sku']??''))?:null,cw_decimal($line['quantity']??null),trim((string)($line['purchase_unit']??''))?:null,cw_decimal($line['pack_size']??null),$base,$baseUnit,$cost,cw_money($line['line_subtotal']??null),cw_money($line['vat_amount']??null),cw_money($line['line_total']??null),cw_money($line['discount']??null),(string)($line['item_type']??'unclassified'),$valid?'valid':'invalid']); }
        $dup=$pdo->prepare("SELECT id FROM cw_supplier_invoices WHERE id<>? AND supplier_name=? AND invoice_number=? AND invoice_date=? AND invoice_total<=>? AND approval_status<>'archived' LIMIT 1");
        $dup->execute([$id,trim((string)($b['supplier_name']??'')),trim((string)($b['invoice_number']??'')),($b['invoice_date']??'')?:null,cw_money($b['invoice_total']??null)]); $duplicateId=$dup->fetchColumn() ?: null;
        $pdo->commit(); cw_json(['ok'=>true,'duplicate_invoice_id'=>$duplicateId]);
    }

    if ($action === 'approve') {
        cw_require_admin(); $b=cw_body(); $id=(int)($b['id']??0); $st=$pdo->prepare('SELECT * FROM cw_supplier_invoices WHERE id=?');$st->execute([$id]);$inv=$st->fetch(); if(!$inv)throw new RuntimeException('Invoice not found.');
        if(trim($inv['supplier_name'])===''||empty($inv['invoice_number'])||empty($inv['invoice_date'])||$inv['vat_treatment']==='unconfirmed'||empty($inv['currency'])) throw new RuntimeException('Complete supplier, invoice number, date, currency and VAT treatment before approval.');
        $st=$pdo->prepare("SELECT COUNT(*) FROM cw_supplier_invoice_lines WHERE supplier_invoice_id=? AND review_status<>'valid'");$st->execute([$id]); if((int)$st->fetchColumn()>0)throw new RuntimeException('Every invoice line must have a valid quantity, recognized unit and cost.');
        $st=$pdo->prepare('SELECT COUNT(*) FROM cw_supplier_invoice_lines WHERE supplier_invoice_id=?');$st->execute([$id]);if((int)$st->fetchColumn()===0)throw new RuntimeException('Add at least one valid invoice line.');
        $u=cw_user();$pdo->prepare("UPDATE cw_supplier_invoices SET approval_status='approved',approved_by=?,approved_by_name=?,approved_at=NOW() WHERE id=? AND approval_status='draft'")->execute([$u['id'],$u['name'],$id]);cw_json(['ok'=>true]);
    }

    if ($action === 'archive') { cw_require_admin();$b=cw_body();$pdo->prepare("UPDATE cw_supplier_invoices SET approval_status='archived',archived_at=NOW() WHERE id=?")->execute([(int)($b['id']??0)]);cw_json(['ok'=>true]); }
    if ($action === 'download') { $id=(int)($_GET['id']??0);$st=$pdo->prepare('SELECT original_filename,stored_file,file_type FROM cw_supplier_invoices WHERE id=?');$st->execute([$id]);$f=$st->fetch();$path=$f?BASE_PATH.'/'.$f['stored_file']:'';if(!$f||!is_file($path))throw new RuntimeException('Original file is unavailable.');header_remove('Content-Type');header('Content-Type: '.$f['file_type']);header('Content-Disposition: attachment; filename="'.rawurlencode($f['original_filename']).'"');header('X-Content-Type-Options: nosniff');readfile($path);exit; }

    if ($action === 'settings') { cw_require_admin();$b=cw_body();$allowed=['base_currency','vat_rate','retail_target_margin','wholesale_target_margin','reseller_target_margin','low_margin_threshold','default_allocation_method'];$u=cw_user();$st=$pdo->prepare('INSERT INTO cw_settings(setting_key,setting_value,updated_by,updated_by_name) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_by_name=VALUES(updated_by_name)');foreach($allowed as $k){if(array_key_exists($k,$b))$st->execute([$k,$b[$k]===''?null:(string)$b[$k],$u['id'],$u['name']]);}cw_json(['ok'=>true]); }

    if ($action === 'sync-start') { cw_require_admin();$running=(int)$pdo->query("SELECT COUNT(*) FROM cw_sync_batches WHERE status='running' AND started_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)")->fetchColumn();if($running)throw new RuntimeException('A website sync is already running.');$pdo->exec("UPDATE cw_sync_batches SET status='failed',completed_at=NOW(),error_message='Stale sync lock released' WHERE status='running'");$u=cw_user();$st=$pdo->prepare('INSERT INTO cw_sync_batches(started_by,started_by_name) VALUES(?,?)');$st->execute([$u['id'],$u['name']]);cw_json(['batch_id'=>(int)$pdo->lastInsertId()]); }
    if ($action === 'sync-batch') { cw_require_admin();$b=cw_body();$batch=(int)($b['batch_id']??0);$st=$pdo->prepare("SELECT * FROM cw_sync_batches WHERE id=? AND status='running'");$st->execute([$batch]);$sync=$st->fetch();if(!$sync)throw new RuntimeException('Sync batch is not active.');$page=(int)$sync['next_page'];
        try{$products=wc_get('products',['page'=>$page,'per_page'=>25,'status'=>'any']);$insert=$pdo->prepare('INSERT INTO cw_product_snapshots(product_id,variation_id,parent_product_id,product_name,variation_name,sku,category,product_type,attributes,regular_price_inc_vat,sale_price_inc_vat,active_price_inc_vat,stock_quantity,stock_status,manage_stock,website_status,permalink,sync_batch_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sku=VALUES(sku),active_price_inc_vat=VALUES(active_price_inc_vat),stock_quantity=VALUES(stock_quantity),stock_status=VALUES(stock_status)');$count=0;
            foreach($products as $p){$cats=implode(', ',array_column((array)($p['categories']??[]),'name'));$rows=[[$p,0,'']];if(($p['type']??'')==='variable'){foreach(wc_get('products/'.(int)$p['id'].'/variations',['per_page'=>100,'status'=>'any']) as $v)$rows[]=[$v,(int)$v['id'],implode(' / ',array_column((array)($v['attributes']??[]),'option'))];}
                foreach($rows as [$x,$vid,$vname]){$insert->execute([(int)$p['id'],$vid,$vid?(int)$p['id']:null,(string)($p['name']??''),$vname,trim((string)($x['sku']??''))?:null,$cats,$vid?'variation':(string)($p['type']??'simple'),json_encode($x['attributes']??[]),cw_money($x['regular_price']??null),cw_money($x['sale_price']??null),cw_money($x['price']??null),isset($x['stock_quantity'])?cw_decimal($x['stock_quantity']):null,$x['stock_status']??null,!empty($x['manage_stock'])?1:0,$x['status']??null,$p['permalink']??null,$batch]);$count++;}}
            $done=count($products)<25;if($done){$pdo->prepare("UPDATE cw_sync_batches SET status='complete',completed_at=NOW(),success_count=success_count+?,total_products=total_products+? WHERE id=?")->execute([$count,count($products),$batch]);$pdo->prepare("UPDATE cw_settings SET setting_value=NOW() WHERE setting_key='last_website_sync'")->execute();}else{$pdo->prepare('UPDATE cw_sync_batches SET next_page=next_page+1,success_count=success_count+?,total_products=total_products+? WHERE id=?')->execute([$count,count($products),$batch]);}cw_json(['done'=>$done,'page'=>$page,'items'=>$count]);
        }catch(Throwable $e){$pdo->prepare("UPDATE cw_sync_batches SET status='failed',completed_at=NOW(),error_count=error_count+1,error_message=? WHERE id=?")->execute([$e->getMessage(),$batch]);throw $e;}}

    if ($action === 'products') { $q='%'.trim((string)($_GET['q']??'')).'%';$st=$pdo->prepare("SELECT s.*,CASE WHEN s.sku IS NULL OR s.sku='' THEN 'Missing SKU' WHEN (SELECT COUNT(*) FROM cw_product_snapshots d WHERE d.sync_batch_id=s.sync_batch_id AND d.sku=s.sku)>1 THEN 'Duplicate SKU' WHEN s.active_price_inc_vat IS NULL THEN 'Missing website price' WHEN s.stock_quantity IS NULL THEN 'Missing stock quantity' ELSE 'Awaiting approved cost' END AS warning FROM cw_product_snapshots s WHERE s.sync_batch_id=(SELECT MAX(id) FROM cw_sync_batches WHERE status='complete') AND (s.product_name LIKE ? OR s.variation_name LIKE ? OR s.sku LIKE ?) ORDER BY s.product_name,s.variation_id LIMIT 200");$st->execute([$q,$q,$q]);cw_json(['products'=>$st->fetchAll()]); }
    if ($action === 'match') { cw_require_admin();$b=cw_body();$line=(int)($b['line_id']??0);$type=(string)($b['item_type']??'unclassified');$valid=['supplier_raw_material','website_product','website_variation','packaging','additional_cost'];if(!in_array($type,$valid,true))throw new RuntimeException('Select a valid item classification.');$pid=isset($b['product_id'])?(int)$b['product_id']:null;$vid=isset($b['variation_id'])?(int)$b['variation_id']:null;$u=cw_user();$pdo->beginTransaction();$pdo->prepare('UPDATE cw_supplier_invoice_lines SET item_type=?,woo_product_id=?,woo_variation_id=?,match_status=? WHERE id=?')->execute([$type,$pid?:null,$vid?:null,$pid?'confirmed':'not_applicable',$line]);$pdo->prepare('INSERT INTO cw_product_matches(supplier_invoice_line_id,woo_product_id,woo_variation_id,match_method,match_confidence,confirmed_by,confirmed_by_name) VALUES(?,?,?, ?,100,?,?)')->execute([$line,$pid?:null,$vid?:null,$pid?'manual':'classification',$u['id'],$u['name']]);$pdo->commit();cw_json(['ok'=>true]); }
    cw_json(['error'=>'Unknown action.'],404);
} catch(Throwable $e) { if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack(); cw_json(['error'=>$e->getMessage()],http_response_code()>=400?http_response_code():422); }
