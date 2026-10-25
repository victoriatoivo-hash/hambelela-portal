<?php
// Real multipart uploads against a loopback-only PHP server and disposable test DB.
$uploadId=assignment('prepare',0);$pdo->prepare("UPDATE marketing_work_items SET status='completed',completed_at=NOW(),completed_by=2 WHERE id=?")->execute([$uploadId]);$owner=true;$actor=99;
$route=<<<'PHP'
<?php
define('BASE_PATH',getenv('MC_TEST_FIXTURE'));define('BASE_URL','');
$pdo=new PDO(getenv('MC_TEST_DSN'), 'root',getenv('MARKETING_TEST_PASSWORD')?:'synthetic-ci-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(){global $pdo;return $pdo;}
function marketing_is_owner(){return true;}function marketing_employee_id(){return 99;}function current_user(){return ['name'=>'Synthetic Owner'];}
function notifications_schema_ready(){return true;}
function notifications_create($data,$recipients){if(isset($_POST['fail_notice']))return null;$q=db()->prepare('INSERT INTO notifications(title,message,related_id,related_type,action_link,deduplication_key,created_at)VALUES(?,?,?,?,?,?,NOW())');$q->execute([$data['title'],$data['message'],$data['related_id'],$data['related_type'],$data['action_link'],$data['deduplication_key']]);$id=(int)db()->lastInsertId();foreach($recipients as $recipient)db()->prepare('INSERT INTO notification_recipients(notification_id,employee_id)VALUES(?,?)')->execute([$id,$recipient]);return $id;}
require getenv('MC_TEST_ROOT').'/shared/marketing-execution.php';require getenv('MC_TEST_ROOT').'/shared/marketing-workspace.php';require getenv('MC_TEST_ROOT').'/shared/marketing-content-workflow.php';
try {marketing_request_correction((int)$_POST['id'],$_POST,$_FILES['correction_asset']??[]);echo json_encode(['ok'=>true]);}
catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
PHP;
file_put_contents($fixture.'/upload-route.php',$route);file_put_contents($fixture.'/reference.txt','Synthetic reference: correct this caption.');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errNo,$errText);if(!$socket)throw new RuntimeException($errText);$address=stream_socket_get_name($socket,false);fclose($socket);
$env=array_merge(getenv(),['MC_TEST_FIXTURE'=>$fixture,'MC_TEST_ROOT'=>$root,'MC_TEST_DSN'=>$dsn.';dbname='.$database]);
$server=proc_open([PHP_BINARY,'-d','upload_max_filesize=32M','-d','post_max_size=34M','-S',$address,$fixture.'/upload-route.php'],[0=>['pipe','r'],1=>['file',$fixture.'/upload-server.log','a'],2=>['file',$fixture.'/upload-server.log','a']],$pipes,$fixture,$env);
if(!is_resource($server))throw new RuntimeException('Unable to start loopback upload fixture.');
try {
    for($attempt=0;$attempt<50;$attempt++){$probe=@stream_socket_client('tcp://'.$address,$errNo,$errText,.1);if($probe){fclose($probe);break;}usleep(100000);}
    $postUpload=static function(array $data,string $filename='reference.txt')use($fixture,$address):array {
        $args=['curl','--silent','--show-error','--max-time','15','http://'.$address];
        foreach($data as $key=>$value){$args[]='--form-string';$args[]=$key.'='.$value;}
        $args[]='--form';$args[]='correction_asset=@'.$fixture.'/reference.txt;filename='.$filename;
        $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
        if($status!==0)throw new RuntimeException('Upload fixture transport failed: '.$errors);
        $result=json_decode($body,true);if(!is_array($result))throw new RuntimeException('Upload fixture response: '.$body);return $result;
    };
    $payload=['id'=>$uploadId,'correction_note'=>'Use the attached reference.','correction_due_at'=>(new DateTimeImmutable(marketing_now()))->modify('+2 days')->format('Y-m-d\TH:i'),'request_key'=>str_repeat('1',32),'expected_status'=>'completed','expected_correction_key'=>''];
    $bad=$postUpload($payload,'not-an-image.jpg');ok(!$bad['ok'],'Disguised image rejected by existing MIME validation');
    ok(marketing_execution_item($uploadId)['status']==='completed','Rejected upload does not reopen assignment');
    $filesBefore=(int)$pdo->query('SELECT COUNT(*) FROM marketing_item_versions')->fetchColumn();$noticesBefore=(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    $failed=$postUpload($payload+['fail_notice'=>'1']);ok(!$failed['ok'],'Notification failure rejects correction with upload');
    ok((int)$pdo->query('SELECT COUNT(*) FROM marketing_item_versions')->fetchColumn()===$filesBefore&&marketing_execution_item($uploadId)['status']==='completed','Notification failure rolls back attachment record and assignment');
    ok(count(glob($fixture.'/uploads/marketing/*.bin')?:[])===0,'Rolled-back correction removes its newly uploaded file');
    $saved=$postUpload($payload);ok($saved['ok'],'Real multipart correction upload saved successfully');
    $updated=marketing_execution_item($uploadId);$mediaId=(int)$updated['correction_media_id'];$media=marketing_media_get($mediaId);
    ok($mediaId>0&&(int)$media['item_id']===$uploadId&&$media['version_label']==='Correction reference'&&(int)$media['uploaded_by']===99,'Attachment linked to correct assignment, round and Owner');
    ok(file_get_contents($fixture.'/uploads/marketing/'.$media['stored_name'])==='Synthetic reference: correct this caption.','Stored upload bytes preserved');
    ok($postUpload($payload)['ok']&&(int)$pdo->query('SELECT COUNT(*) FROM marketing_item_versions')->fetchColumn()===$filesBefore+1&&(int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===$noticesBefore+1,'Retry does not duplicate upload or correction notification');
    $owner=false;$actor=2;ok((int)marketing_media_get($mediaId)['id']===$mediaId,'Assigned employee can read correction reference');
    $html=renderAssignment($uploadId,false,'employee-correction-with-attachment');ok(strpos($html,'Correction reference')!==false&&strpos($html,'reference.txt')!==false&&strpos($html,'Preview attachment')!==false,'Employee sees reference beside correction instructions');
    $actor=3;rejected(fn()=>marketing_media_get($mediaId),'Other employees cannot access correction media');
    $owner=true;$actor=99;$pdo->prepare("UPDATE marketing_work_items SET status='completed' WHERE id=?")->execute([$uploadId]);
    $next=array_merge($payload,['request_key'=>str_repeat('2',32),'expected_correction_key'=>$payload['request_key']]);marketing_request_correction($uploadId,$next);
    ok(marketing_execution_item($uploadId)['correction_media_id']===null&&(int)marketing_media_get($mediaId)['id']===$mediaId,'New round without attachment preserves earlier media but does not mislabel it as current');
    ob_start();marketing_correction_form(array_merge($updated,['status'=>'completed']));$form=ob_get_clean();ok(strpos($form,'enctype="multipart/form-data"')!==false&&strpos($form,'name="correction_asset"')!==false&&strpos($form,'mk-correction-attachment')!==false,'Owner form provides themed optional media upload');
} finally {proc_terminate($server);proc_close($server);}
echo "PASS correction multipart upload, atomic rollback, retry deduplication, employee access and reference display\n";
