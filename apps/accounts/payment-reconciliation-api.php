<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/apps/operations/operations.php';
require_once BASE_PATH.'/shared/reconciliation/Reconciliation.php';
require_once BASE_PATH.'/shared/reconciliation/FnbTerminalReport.php';
require_once BASE_PATH.'/shared/reconciliation/BankStatementCsv.php';
require_login();header('Cache-Control: no-store');
$service=new \Hambelela\Accounts\Reconciliation(db());$employee=(int)(current_user()['id']??0);
function pr_reply(array $payload,int $status=200):void{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($payload,JSON_THROW_ON_ERROR);exit;}
try{
 $service->owner($employee);$_SESSION['payment_reconciliation_csrf']??=bin2hex(random_bytes(32));$action=(string)($_GET['action']??'list');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['payment_reconciliation_csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))throw new DomainException('Session verification failed. Refresh this page.');
  $body=$_POST?:json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
  if(!is_array($body))throw new DomainException('Invalid request.');
  if($action==='import-preview'){
   $text=(string)($body['text']??'');if(strlen($text)>5000000)throw new DomainException('Maximum statement size is 5 MB.');
   $kind=(string)($body['kind']??'');
   if(isset($_FILES['statement'])){
    $file=$_FILES['statement'];if($file['error']!==UPLOAD_ERR_OK||$file['size']>5000000||!is_uploaded_file($file['tmp_name']))throw new DomainException('Upload a statement smaller than 5 MB.');
    if($kind==='terminal'){
     if(file_get_contents($file['tmp_name'],false,null,0,5)!=='%PDF-')throw new DomainException('Select an FNB terminal PDF report.');
     $autoload=dirname(BASE_PATH).'/vendor/autoload.php';if(is_file($autoload))require_once $autoload;
     if(class_exists('Smalot\\PdfParser\\Parser')){$parser=new \Smalot\PdfParser\Parser();$text=$parser->parseFile($file['tmp_name'])->getText();}
     else {require_once BASE_PATH.'/shared/pdf-extractor.php';$extracted=extract_pdf_text($file['tmp_name']);if(!$extracted['available'])throw new DomainException('PDF extraction is unavailable on this server. Nothing imported.');$text=$extracted['text'];}
    }else $text=file_get_contents($file['tmp_name']);
   }
   if($kind==='terminal'){$report=\Hambelela\Accounts\FnbTerminalReport::parse($text);}
   elseif($kind==='bank'){$method=(string)($body['method']??'');if(!isset(ops_payment_method_map()[$method])||$method==='cash')throw new DomainException('Select the actual bank payment method.');$report=\Hambelela\Accounts\BankStatementCsv::parse($text,(string)($body['account']??''),$method);}
   else throw new DomainException('Unsupported statement format.');
   $token=bin2hex(random_bytes(24));$_SESSION['payment_reconciliation_import']=['token'=>$token,'report'=>$report,'kind'=>$kind,'expires'=>time()+600,'employee'=>$employee];
   pr_reply(['token'=>$token,'count'=>count($report['transactions']),'total_cents'=>array_sum(array_column($report['transactions'],'amount_cents')),'settlement'=>$kind==='terminal'?'Terminal approval only — not settled':'Bank credit evidence — not automatically matched','rows'=>array_slice($report['transactions'],0,20)]);
  }
  if($action==='import-save'){$pending=$_SESSION['payment_reconciliation_import']??null;if(!$pending||$pending['expires']<time()||$pending['employee']!==$employee||!hash_equals($pending['token'],(string)($body['token']??'')))throw new DomainException('Import preview expired. Preview again.');$result=$pending['kind']==='terminal'?$service->importTerminal($employee,$pending['report']):$service->importBank($employee,$pending['report']);unset($_SESSION['payment_reconciliation_import']);pr_reply($result);}
  if($action==='bulk'){if(!is_array($body['orders']??null)||count($body['orders'])>50)throw new DomainException('Select up to 50 orders.');$results=[];foreach(array_unique(array_map('intval',$body['orders'])) as $id){try{$service->act($employee,'confirm',['order'=>$id]);$results[]=['id'=>$id,'ok'=>true];}catch(DomainException $e){$results[]=['id'=>$id,'ok'=>false,'error'=>$e->getMessage()];}}pr_reply(['results'=>$results]);}
  $service->act($employee,$action,$body);pr_reply(['ok'=>true]);
 }
 if($action==='detail')pr_reply($service->detail((int)($_GET['id']??0)));
 $data=$service->listing((string)($_GET['from']??date('Y-m-01')),(string)($_GET['to']??date('Y-m-d')));
 pr_reply($data+['csrf'=>$_SESSION['payment_reconciliation_csrf'],'methods'=>ops_payment_method_map()]);
}catch(DomainException $e){pr_reply(['error'=>$e->getMessage()],403);}catch(Throwable $e){error_log('Payment reconciliation: '.$e->getMessage());pr_reply(['error'=>'Reconciliation data is unavailable. Nothing was confirmed.'],503);}
