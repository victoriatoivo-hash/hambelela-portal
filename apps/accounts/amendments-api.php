<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/accounts-amendments.php';
amendments_require_access(); amendments_schema_ready();
header('Content-Type: application/json; charset=utf-8');
try {
 $method=$_SERVER['REQUEST_METHOD']??'GET'; $action=(string)($_REQUEST['action']??'list');
 if($method==='GET'&&$action==='list'){
  [$scope,$params]=amendments_scope_sql('a'); $where=[$scope];
  foreach(['status','application_key'] as $field){$value=trim((string)($_GET[$field]??''));if($value!==''){$where[]="a.$field=?";$params[]=$value;}}
  $search=trim((string)($_GET['search']??''));if($search!==''){$where[]='(a.subject LIKE ? OR a.related_reference LIKE ?)';$params[]="%$search%";$params[]="%$search%";}
  $uid=(int)(current_user()['id']??0);
  $sql='SELECT a.*,SUM(CASE WHEN m.sender_user_id<>? AND m.id>COALESCE(r.last_read_message_id,0) THEN 1 ELSE 0 END) unread_count,MAX(m.created_at) last_message_at FROM accounting_amendments a LEFT JOIN accounting_amendment_messages m ON m.amendment_id=a.id LEFT JOIN accounting_amendment_reads r ON r.amendment_id=a.id AND r.user_id=? WHERE '.implode(' AND ',$where).' GROUP BY a.id ORDER BY a.updated_at DESC LIMIT 250';
  $stmt=db()->prepare($sql);$stmt->execute(array_merge([$uid,$uid],$params));echo json_encode(['ok'=>true,'items'=>$stmt->fetchAll(),'csrf'=>amendments_csrf()]);exit;
 }
 if($method==='GET'&&$action==='thread'){$row=amendments_row((int)($_GET['id']??0));if(!$row)throw new RuntimeException('Amendment not found.');echo json_encode(['ok'=>true]+amendments_thread_payload($row));exit;}
 amendments_verify_csrf((string)($_POST['csrf']??'')); $user=current_user();$uid=(int)($user['id']??0);$name=(string)($user['name']??'Portal user');$role=accounts_role_key();
 if($action==='create'){
  if(!accounts_can('amendments.create'))throw new RuntimeException('You cannot create amendments.');
  $subject=trim((string)($_POST['subject']??''));$message=trim((string)($_POST['message']??''));$app=(string)($_POST['application_key']??'general_accounts');
  if($subject===''||$message==='')throw new RuntimeException('Subject and message are required.');if(!isset(amendments_allowed_applications()[$app]))throw new RuntimeException('Choose a valid accounting application.');
  db()->beginTransaction();$stmt=db()->prepare('INSERT INTO accounting_amendments(subject,application_key,period_key,related_reference,related_url,priority,status,created_by,created_by_name,created_by_role)VALUES(?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$subject,$app,trim((string)($_POST['period_key']??''))?:null,trim((string)($_POST['related_reference']??''))?:null,trim((string)($_POST['related_url']??''))?:null,(string)($_POST['priority']??'normal'),'open',$uid,$name,$role]);$id=(int)db()->lastInsertId();db()->prepare('INSERT INTO accounting_amendment_messages(amendment_id,sender_user_id,sender_name,sender_role,message)VALUES(?,?,?,?,?)')->execute([$id,$uid,$name,$role,$message]);$mid=(int)db()->lastInsertId();amendments_upload_files($id,$mid);db()->prepare('INSERT INTO accounting_amendment_status_history(amendment_id,old_status,new_status,changed_by,changed_by_name)VALUES(?,NULL,?,?,?)')->execute([$id,'open',$uid,$name]);db()->commit();$row=amendments_row($id);amendments_notify($row,$mid,$message);echo json_encode(['ok'=>true,'message'=>'Amendment created.','id'=>$id]+amendments_thread_payload($row));exit;
 }
 $id=(int)($_POST['id']??0);$row=amendments_row($id);if(!$row)throw new RuntimeException('Amendment not found.');
 if($action==='reply'){$message=trim((string)($_POST['message']??''));if($message===''&&empty($_FILES['files']['name'][0]))throw new RuntimeException('Enter a reply or attach a file.');$status=accounts_is_owner()?'owner_responded':'accountant_responded';db()->prepare('INSERT INTO accounting_amendment_messages(amendment_id,sender_user_id,sender_name,sender_role,message)VALUES(?,?,?,?,?)')->execute([$id,$uid,$name,$role,$message]);$mid=(int)db()->lastInsertId();amendments_upload_files($id,$mid);db()->prepare('UPDATE accounting_amendments SET status=?,updated_at=NOW() WHERE id=?')->execute([$status,$id]);amendments_notify($row,$mid,$message?:'Attachment added');$row=amendments_row($id);echo json_encode(['ok'=>true,'message'=>'Reply sent.']+amendments_thread_payload($row));exit;}
 if($action==='status'){$new=(string)($_POST['status']??'');if(!in_array($new,['open','needs_more_information','resolved'],true))throw new RuntimeException('Choose a valid status.');$old=(string)$row['status'];db()->prepare('UPDATE accounting_amendments SET status=?,resolved_by=?,resolved_by_name=?,resolved_at=? WHERE id=?')->execute([$new,$new==='resolved'?$uid:null,$new==='resolved'?$name:null,$new==='resolved'?date('Y-m-d H:i:s'):null,$id]);db()->prepare('INSERT INTO accounting_amendment_status_history(amendment_id,old_status,new_status,changed_by,changed_by_name)VALUES(?,?,?,?,?)')->execute([$id,$old,$new,$uid,$name]);db()->prepare('INSERT INTO accounting_amendment_messages(amendment_id,sender_user_id,sender_name,sender_role,message)VALUES(?,?,?,?,?)')->execute([$id,$uid,$name,$role,'Status changed to '.str_replace('_',' ',$new).'.']);$mid=(int)db()->lastInsertId();amendments_notify($row,$mid,'Status changed to '.$new);echo json_encode(['ok'=>true,'message'=>'Status updated.']);exit;}
 throw new RuntimeException('Unknown action.');
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
