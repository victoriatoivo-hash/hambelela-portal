<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/cost-workbook.php';
require_once BASE_PATH.'/shared/cost-workbook-formulation-engine.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');
function fc_reply(array $data,int $status=200):void{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function fc_body():array{$b=json_decode((string)file_get_contents('php://input'),true);if(!is_array($b))throw new DomainException('Invalid request.');return $b;}
function fc_local_timestamp(PDO $pdo,string $value):string{if($value==='')return '';static $offset=null;if($offset===null)$offset=(int)$pdo->query('SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),CURRENT_TIMESTAMP())')->fetchColumn();$d=new DateTimeImmutable($value,new DateTimeZone('UTC'));return $d->modify(sprintf('%+d seconds',-$offset))->setTimezone(new DateTimeZone('Africa/Windhoek'))->format('Y-m-d H:i:s');}
try{
 $pdo=db();cw_install_schema($pdo);$action=(string)($_GET['action']??'bootstrap');
 if(in_array($action,['save','preview','archive'],true)){if($_SERVER['REQUEST_METHOD']!=='POST')throw new DomainException('POST required.');cw_require_csrf();}
 if($action==='bootstrap'){
  $s=fc_sources($pdo);
  $rows=$pdo->query("SELECT f.*,v.version_status,v.header_json,v.totals_json FROM cw_formulations f LEFT JOIN cw_formulation_versions v ON v.formulation_id=f.id AND v.version_no=f.current_version ORDER BY f.created_at DESC,f.id DESC")->fetchAll();
  foreach($rows as &$r){$r['created_at_local']=fc_local_timestamp($pdo,(string)$r['created_at']);$r['header']=json_decode((string)$r['header_json'],true)?:[];$r['totals']=json_decode((string)$r['totals_json'],true)?:[];unset($r['header_json'],$r['totals_json']);}unset($r);
  fc_reply(['ok'=>true,'formulations'=>$rows]+$s);
 }
 if($action==='load'){
  $id=(int)($_GET['id']??0);$q=$pdo->prepare('SELECT * FROM cw_formulations WHERE id=?');$q->execute([$id]);$f=$q->fetch();if(!$f)throw new DomainException('Formulation not found.');
  $version=(int)($_GET['version']??$f['current_version']);$q=$pdo->prepare('SELECT * FROM cw_formulation_versions WHERE formulation_id=? AND version_no=?');$q->execute([$id,$version]);$v=$q->fetch();if(!$v)throw new DomainException('Version not found.');
  $token=fc_version_token($f,$v);foreach(['header','ingredients','production','batches','selling_sizes','totals']as$k)$v[$k]=json_decode((string)$v[$k.'_json'],true)?:[];
  $q=$pdo->prepare('SELECT version_no,version_status,effective_date,created_by_name,created_at FROM cw_formulation_versions WHERE formulation_id=? ORDER BY version_no DESC');$q->execute([$id]);
  $history=$q->fetchAll();foreach($history as &$item)$item['created_at']=fc_local_timestamp($pdo,(string)$item['created_at']);unset($item);fc_reply(['ok'=>true,'formulation'=>$f,'version'=>$v,'history'=>$history,'version_token'=>$token]);
 }
 if($action==='preview'){$b=fc_body();fc_reply(['ok'=>true]+fc_calculate($b,fc_sources($pdo,$b)));}
 if($action==='save')fc_reply(fc_save($pdo,fc_body()));
 if($action==='archive'){
  $b=fc_body();$id=(int)($b['id']??0);$u=lpc_user();$pdo->beginTransaction();
  $q=$pdo->prepare('SELECT * FROM cw_formulations WHERE id=? FOR UPDATE');$q->execute([$id]);$f=$q->fetch();if(!$f)throw new DomainException('Formulation not found.');
  $q=$pdo->prepare('SELECT * FROM cw_formulation_versions WHERE formulation_id=? AND version_no=?');$q->execute([$id,$f['current_version']]);if(!hash_equals(fc_version_token($f,$q->fetch()),(string)($b['version_token']??'')))throw new DomainException('Record changed. Reopen before archiving.');
  $pdo->prepare("UPDATE cw_formulations SET active=0,status_key='archived',updated_by=?,updated_by_name=? WHERE id=?")->execute([$u['id'],$u['name'],$id]);
  $pdo->prepare("INSERT INTO cw_formulation_audit(formulation_id,action_key,before_json,actor_id,actor_name) VALUES(?,'archived',?,?,?)")->execute([$id,json_encode($f),$u['id'],$u['name']]);$pdo->commit();fc_reply(['ok'=>true]);
 }
 throw new DomainException('Unsupported action.');
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('Formulation costing: '.$e->getMessage());fc_reply(['ok'=>false,'message'=>$e instanceof DomainException?$e->getMessage():'Unable to complete the request. Please retry or contact the Owner.'],400);}
