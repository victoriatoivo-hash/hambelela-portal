<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/shared/delivery/DispatchChat.php';
use Hambelela\Delivery\DispatchChat;
putenv('HO_DRIVER_PROOF_DIR='.dirname(BASE_PATH).'/driver-proof-private-review');
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='driver_review')exit(1);
if(($argv[1]??'')==='worker'){
 $actor=(int)$argv[2];$s=$db->prepare("SELECT full_name FROM ops_employees WHERE id=? AND full_name LIKE 'Phase08 isolated race %'");$s->execute([$actor]);if(!$s->fetchColumn())exit(2);
 echo json_encode((new DispatchChat($db))->send($actor,0,['key'=>$argv[3],'body'=>'Concurrent synthetic send','urgent'=>false]),JSON_THROW_ON_ERROR);exit;
}
$marker='Phase08 isolated race '.bin2hex(random_bytes(8));$role=(int)$db->query("SELECT id FROM ops_roles WHERE role_key='delivery_driver'")->fetchColumn();
$actor=random_int(500000,900000);$db->prepare("INSERT INTO ops_employees(id,role_id,status,full_name) VALUES(?,?,'active',?)")->execute([$actor,$role,$marker]);$db->prepare('INSERT INTO delivery_driver_profiles(employee_id,active,verified_by_employee_id,verified_at) VALUES(?,1,1,UTC_TIMESTAMP())')->execute([$actor]);
$key=bin2hex(random_bytes(4)).'-aaaa-4aaa-8aaa-'.bin2hex(random_bytes(6));$children=[];
try{
 for($i=0;$i<2;$i++){$p=[];$proc=proc_open([PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=pdo_mysql','-d','extension=mbstring',__FILE__,'worker',(string)$actor,$key],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$p,null,null,['bypass_shell'=>true]);fclose($p[0]);$children[]=[$proc,$p];}
 $out=[];foreach($children as [$proc,$p]){$text=stream_get_contents($p[1]);$err=stream_get_contents($p[2]);fclose($p[1]);fclose($p[2]);proc_close($proc);$j=json_decode($text,true);if(!$j||!isset($j['id']))throw new RuntimeException('Worker did not return canonical receipt: '.$err.$text);$out[]=$j;}
 if($out[0]['id']!==$out[1]['id']||$out[0]['replayed']===$out[1]['replayed'])throw new RuntimeException('Concurrent deduplication failed');echo "PASS two concurrent sends return one persisted message ID\n";
 $s=$db->prepare('SELECT COUNT(*) FROM delivery_chat_messages WHERE sender_id=?');$s->execute([$actor]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Duplicate message');
 $s=$db->prepare("SELECT COUNT(*) FROM notifications WHERE related_type='delivery_chat' AND related_id=?");$s->execute([$out[0]['id']]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Duplicate notification');echo "PASS concurrent message has exactly one notification\n";
}finally{
 // Cleanup is restricted to this newly-created synthetic identity and exact marker.
 $s=$db->prepare('SELECT id FROM ops_employees WHERE id=? AND full_name=?');$s->execute([$actor,$marker]);if(!$s->fetchColumn())throw new RuntimeException('Cleanup identity changed; stopped');
 $db->beginTransaction();$s=$db->prepare('SELECT id FROM delivery_chat_threads WHERE driver_id=?');$s->execute([$actor]);$thread=(int)$s->fetchColumn();
 $s=$db->prepare("SELECT n.id FROM notifications n JOIN delivery_chat_messages m ON n.related_type='delivery_chat' AND n.related_id=m.id WHERE m.sender_id=?");$s->execute([$actor]);foreach($s->fetchAll(PDO::FETCH_COLUMN) as $notice){$db->prepare('DELETE FROM notification_recipients WHERE notification_id=?')->execute([$notice]);$db->prepare('DELETE FROM notifications WHERE id=?')->execute([$notice]);}
 $db->prepare('DELETE r FROM delivery_chat_receipts r JOIN delivery_chat_messages m ON m.id=r.message_id WHERE m.thread_id=?')->execute([$thread]);$db->prepare('DELETE FROM delivery_chat_audit WHERE thread_id=?')->execute([$thread]);$db->prepare('DELETE FROM delivery_chat_messages WHERE thread_id=?')->execute([$thread]);$db->prepare('DELETE FROM delivery_chat_threads WHERE id=? AND driver_id=?')->execute([$thread,$actor]);$db->prepare('DELETE FROM delivery_driver_profiles WHERE employee_id=?')->execute([$actor]);$db->prepare('DELETE FROM ops_employees WHERE id=? AND full_name=?')->execute([$actor,$marker]);$db->commit();echo "PASS isolated race fixtures removed; existing conversations retained\n";
}
