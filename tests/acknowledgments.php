<?php
declare(strict_types=1);
require __DIR__.'/../shared/acknowledgments/Service.php';
use Hambelela\Acknowledgments\Service as S;
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);echo "PASS $message\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException $e){check(true,$label);return;}throw new RuntimeException($label);}
$dsn=getenv('ACK_TEST_DSN');
if($dsn!=='mysql:host=127.0.0.1;port=13317;dbname=acknowledgments_test')throw new RuntimeException('Explicit isolated test database required');
$server=new PDO('mysql:host=127.0.0.1;port=13317','root',getenv('ACK_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS acknowledgments_test');
$db=new PDO($dsn,'root',getenv('ACK_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('DROP TABLE IF EXISTS portal_ack_events,portal_ack_recipients,portal_ack_instructions,ops_employees,ops_roles');
$db->exec("CREATE TABLE ops_roles(id INT PRIMARY KEY,role_key VARCHAR(40));INSERT INTO ops_roles VALUES(1,'owner_admin'),(2,'front_desk_admin'),(3,'delivery_driver');CREATE TABLE ops_employees(id INT PRIMARY KEY,role_id INT,full_name VARCHAR(190),status VARCHAR(20));INSERT INTO ops_employees VALUES(1,1,'Synthetic Owner','active'),(2,2,'Synthetic Front','active'),(3,3,'Synthetic Driver','active'),(4,2,'Inactive','inactive')");
$db->exec(file_get_contents(__DIR__.'/../database/acknowledgments.sql'));
$s=new S($db);$body=['request_key'=>S::uuid(),'title'=>'Synthetic instruction','message_html'=>'<p onclick="bad()">Read <b>carefully</b></p><script>alert(1)</script><ol><li>Step</li></ol>','recipients'=>[2,3,2],'required'=>true,'notify_immediately'=>true,'deadline'=>'2026-10-01T09:00'];
denied(fn()=>$s->send(2,$body),'Employee cannot create instructions');
$sent=$s->send(1,$body);check(!$sent['duplicate'],'Owner sends instruction');check($s->send(1,$body)['duplicate'],'Send replay idempotent');
check(count($s->listing(1)['records'])===2,'One record per unique recipient');check(count($s->listing(2)['records'])===1,'Employee sees own record only');
$record=$s->listing(2)['records'][0];$id=(int)$record['id'];
check($record['overdue']===true,'Overdue required record tracked');
$detail=$s->detail(2,$id);check(strpos($detail['message_html'],'onclick')===false&&strpos($detail['message_html'],'script')===false&&strpos($detail['message_html'],'<ol>')!==false,'Rich text sanitised while preserving supported formatting');
denied(fn()=>$s->detail(3,$id),'Driver cannot read another employee conversation');
$respond=fn($actor,$action,$message='',$understood=false)=>$s->respond($actor,['request_key'=>S::uuid(),'recipient_id'=>$id,'action'=>$action,'message'=>$message,'understood'=>$understood],'synthetic-session');
denied(fn()=>$respond(1,'acknowledge','',true),'Owner cannot sign for employee');
denied(fn()=>$respond(2,'acknowledge'),'Understanding checkbox required');
denied(fn()=>$respond(2,'clarify'),'Clarification message required');
$respond(2,'clarify','Please explain step one');check($s->detail(2,$id)['status']==='clarification','Clarification preserved');
$respond(1,'explained_personally','Explained step one in person');check($s->detail(2,$id)['status']==='pending','Personal explanation does not acknowledge for employee');
$respond(1,'reply','Follow this instruction');
$key=S::uuid();$ack=['request_key'=>$key,'recipient_id'=>$id,'action'=>'acknowledge','understood'=>true];$s->respond(2,$ack,'synthetic-session');$s->respond(2,$ack,'synthetic-session');
$d=$s->detail(2,$id);check($d['status']==='acknowledged'&&$d['session_reference']===hash('sha256','synthetic-session'),'Acknowledgment stores correct identity/session reference');check(count($d['events'])===5,'Reply and clarification history immutable; no duplicate acknowledgment');
denied(fn()=>$respond(2,'clarify','Change finished'),'Completed acknowledgment cannot be edited');
$revised=$body;$revised['request_key']=S::uuid();$revised['revise_instruction']=$sent['instruction_id'];$revised['title']='Revised';$s->send(1,$revised);check(count($s->listing(2)['records'])===2,'Revision creates separate obligation');check($s->detail(2,$id)['title']==='Synthetic instruction','Original instruction remains unchanged');
check($s->popup(2)['title']==='Revised','New instruction available to login popup');
$bad=$body;$bad['request_key']=S::uuid();$bad['recipients']=[4];denied(fn()=>$s->send(1,$bad),'Inactive recipient rejected');
check((int)$db->query('SELECT COUNT(*) FROM portal_ack_instructions')->fetchColumn()===2,'Failed send rolls back');
echo "Acknowledgments service checks complete. Synthetic database only.\n";
