<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/accounts-paye.php';
paye_require_access();
paye_schema_ready();
header('Cache-Control: no-store');
function paye_reply(array $data, int $status = 200): void { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
try {
 $action=(string)($_REQUEST['action']??'list'); $month=import_vat_month((string)($_REQUEST['month']??date('Y-m')));
 if($action==='export'){
  if(!accounts_can('paye.export'))throw new RuntimeException('You cannot export PAYE records.'); $data=paye_payload($month);
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="paye-'.$month.'.csv"'); $out=fopen('php://output','w');
  fputcsv($out,['PAYE Period','Tax Year','Tax Period','Return ID','EFT Reference','Return Status','Submitted Date','Assessed','Paid','Payment Date','Outstanding','Due Date','Status']);
  foreach($data['records']as$row)fputcsv($out,[$row['accounting_period'],$row['tax_year'],$row['tax_period'],$row['return_id'],$row['eft_reference'],$row['return_status'],$row['submitted_date'],$row['assessed'],$row['paid'],$row['payment_date'],$row['outstanding'],$row['due_date'],$row['status']]); fclose($out); exit;
 }
 if($_SERVER['REQUEST_METHOD']==='POST'){
  paye_verify((string)($_POST['csrf']??''));
  if($action==='save_return'){if(!accounts_can('paye.manage_returns'))throw new RuntimeException('You cannot update PAYE return status.');paye_reply(['ok'=>true,'message'=>'PAYE return status saved.','data'=>paye_save_return($_POST)]);}
  if($action!=='upload_statement')throw new RuntimeException('Unsupported PAYE action.'); if(!accounts_can('paye.upload_statement'))throw new RuntimeException('You cannot upload PAYE statements.');
  if(!isset($_FILES['statement'])||!is_uploaded_file((string)$_FILES['statement']['tmp_name']))throw new RuntimeException('Select a NamRA PAYE statement to upload.'); $file=$_FILES['statement'];
  if((int)$file['error']!==UPLOAD_ERR_OK)throw new RuntimeException('The PAYE statement did not upload successfully.'); $tmp=(string)$file['tmp_name']; $size=(int)$file['size']; if($size<=0||$size>30*1024*1024)throw new RuntimeException('Statements must be no larger than 30 MB.');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp)?:''; $allowed=['application/pdf'=>'pdf','text/csv'=>'csv','text/plain'=>'csv','application/vnd.ms-excel'=>'csv']; if(!isset($allowed[$mime]))throw new RuntimeException('Upload a machine-readable PDF or CSV NamRA PAYE statement.');
  $hash=hash_file('sha256',$tmp); $find=db()->prepare('SELECT id FROM accounts_paye_statements WHERE sha256=?'); $find->execute([$hash]); $existingStatementId=(int)($find->fetchColumn()?:0);
  $stored=''; $path=$tmp; if(!$existingStatementId){$dir=BASE_PATH.'/uploads/paye-statements';if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Protected PAYE statement storage is unavailable.');$stored=bin2hex(random_bytes(24)).'.'.$allowed[$mime];$path=$dir.'/'.$stored;if(!move_uploaded_file($tmp,$path))throw new RuntimeException('The PAYE statement could not be stored.');}
  try{
   $engine='csv';
   if($allowed[$mime]==='pdf'){$extracted=import_vat_extract_pdf_text($path);if(!preg_match('/\b(PAYE|Employee(?:\x{2019}|\x{2018}|\x{0027})?s? Tax|ETX)\b/iu',$extracted['text']))throw new RuntimeException('This PDF does not identify a PAYE or Employee Tax account. No records were posted.');$rows=import_vat_namra_text_rows($extracted['text']);$engine=$extracted['engine'];}
   else{$rows=import_vat_csv_rows($path);$rows=array_values(array_filter($rows,function($row){return preg_match('/\b(PAYE|Employee(?:\x{2019}|\x{2018}|\x{0027})?s? Tax|ETX)\b/iu',(string)$row['tax_type']);}));if(!$rows)throw new RuntimeException('This CSV does not contain PAYE or Employee Tax rows. No records were posted.');}
   if(!$rows)throw new RuntimeException('No PAYE transaction rows were detected. No records were posted.'); $user=current_user(); db()->beginTransaction();
   if($existingStatementId){$statementId=$existingStatementId;db()->prepare('UPDATE accounts_paye_statements SET parse_message=?,rows_detected=?,status=\'processed\' WHERE id=?')->execute([count($rows).' PAYE transaction rows reprocessed using '.$engine.'. Return and payment codes were rematched by Tax Year + Tax Period.',count($rows),$statementId]);}else{db()->prepare('INSERT INTO accounts_paye_statements(original_filename,stored_filename,mime_type,file_size,sha256,statement_period,status,parse_message,rows_detected,uploaded_by,uploaded_by_name)VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([mb_substr(basename((string)$file['name']),0,255),$stored,$mime,$size,$hash,$_POST['statement_period']??null,'processed',count($rows).' PAYE transaction rows extracted using '.$engine.'. Penalties and interest are excluded from principal payable.',count($rows),(int)$user['id'],(string)$user['name']]);$statementId=(int)db()->lastInsertId();}
   $insert=db()->prepare('INSERT IGNORE INTO accounts_paye_rows(statement_id,source_row_number,tax_year,tax_period,accounting_period,due_date,action_date,reference,transaction_type,classification,amount,source_hash,source_json)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'); $saved=0;
   foreach($rows as$row){$period=import_vat_tax_period_month((string)$row['tax_year'],(string)$row['tax_period']);if(!$period)continue;$classification=paye_classification((string)$row['transaction_type'],(string)$row['classification']);$insert->execute([$statementId,$row['source_row_number'],$row['tax_year'],$row['tax_period'],$period,paye_due_date($period,$row['due_date']?:null),$row['action_date'],$row['doc_number']?:$row['reference'],$row['transaction_type'],$classification,abs((float)$row['transaction_amount']),$row['source_hash'],$row['source_json']]);$saved+=$insert->rowCount();}
   if(!$saved&&!$existingStatementId)throw new RuntimeException('No new valid PAYE rows were available after period and duplicate validation.'); paye_audit($statementId,$existingStatementId?'statement_reprocessed':'statement_processed',['rows_added'=>$saved,'rows_detected'=>count($rows),'extractor'=>$engine,'penalty_interest_excluded'=>true]); db()->commit(); paye_reply(['ok'=>true,'message'=>($existingStatementId?'Statement reprocessed. ':'').$saved.' new PAYE rows added and all returns/payments rematched by period.','data'=>paye_payload($month)]);
  }catch(Throwable$error){if(db()->inTransaction())db()->rollBack();@unlink($path);throw$error;}
 }
 paye_reply(['ok'=>true,'data'=>paye_payload($month)]);
}catch(Throwable$error){paye_reply(['ok'=>false,'message'=>$error->getMessage()],422);}
