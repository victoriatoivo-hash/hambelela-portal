<?php
declare(strict_types=1);
$dsn=getenv('MARKETING_TEST_DSN')?:'mysql:host=127.0.0.1;port=33316';if(!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+$/D',$dsn))throw new RuntimeException('Loopback only');
$pdo=new PDO($dsn,'root',getenv('MARKETING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$database='marketing_workspace_'.bin2hex(random_bytes(5));$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);$actor=2;$owner=false;
$fixture=sys_get_temp_dir().'/marketing-workspace-'.bin2hex(random_bytes(5));mkdir($fixture.'/shared',0700,true);file_put_contents($fixture.'/shared/notifications.php','<?php // test notification adapter');define('BASE_PATH',$fixture);define('BASE_URL','');
function db(){global $pdo;return $pdo;}function marketing_employee_id(){global $actor;return $actor;}function marketing_is_owner(){global $owner;return $owner;}function current_user(){return ['id'=>marketing_employee_id(),'name'=>marketing_is_owner()?'Owner':'Hope'];}function marketing_e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function marketing_column_exists($table,$column){$q=db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();}
function notifications_role_recipients($roles){return [99];}function notifications_create($data,$recipients){db()->prepare('INSERT INTO test_notices(dedup,recipient)VALUES(?,?)')->execute([$data['deduplication_key'],implode(',',$recipients)]);return(int)db()->lastInsertId();}
function ok($v,$m){if(!$v)throw new RuntimeException($m);echo 'PASS '.$m."\n";}function rejects($f,$m){try{$f();}catch(RuntimeException $e){ok(true,$m);return;}throw new RuntimeException('Unexpected acceptance '.$m);}
require __DIR__.'/../shared/marketing-execution.php';require __DIR__.'/../shared/marketing-workspace.php';
try{
 db()->exec("CREATE TABLE marketing_work_items(id INT PRIMARY KEY,title VARCHAR(190),assigned_employee_id INT,cancelled_at DATETIME NULL,status VARCHAR(30),approval_required INT,caption TEXT,published_at DATETIME NULL,content_type VARCHAR(30),platform VARCHAR(80),published_url VARCHAR(500),due_at DATETIME,priority VARCHAR(30));
 INSERT INTO marketing_work_items VALUES(1,'First',2,NULL,'in_progress',1,'Text',NULL,'social_post','',NULL,'2026-10-14 15:00:00','normal'),(2,'Other',3,NULL,'brief',1,'',NULL,'social_post','',NULL,NULL,'normal');
 CREATE TABLE marketing_item_history(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT,old_status VARCHAR(30),new_status VARCHAR(30),note TEXT,actor_id INT,actor_name VARCHAR(100));
 CREATE TABLE marketing_item_versions(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT,file_name VARCHAR(255),mime_type VARCHAR(120),file_size INT,uploaded_by INT);
 INSERT INTO marketing_item_versions VALUES(1,1,'one.png','image/png',100,99),(2,2,'private.png','image/png',100,3),(3,0,'mine.mp3','audio/mpeg',100,2);
 CREATE TABLE test_notices(id INT AUTO_INCREMENT PRIMARY KEY,dedup VARCHAR(190) UNIQUE,recipient VARCHAR(100));");
 marketing_execution_schema_ready();marketing_workspace_schema();marketing_workspace_schema();
 $before=marketing_execution_item(1);
 for($n=0;$n<5;$n++)marketing_note_send(1,'Question '.$n,str_pad(dechex($n),32,'a'));
 ok(marketing_execution_item(1)===$before,'five employee notes do not change status, deadline, approval or completion');
 ok((int)db()->query('SELECT COUNT(*) FROM marketing_notes')->fetchColumn()===5,'notes persist with identity and time');
 ok(db()->query('SELECT recipient FROM test_notices LIMIT 1')->fetchColumn()==='99','employee notes notify Owner');
 marketing_note_send(1,'Retry',str_pad('0',32,'a'));ok((int)db()->query('SELECT COUNT(*) FROM marketing_notes')->fetchColumn()===5,'retry does not duplicate note or notification');
 rejects(fn()=>marketing_note_send(2,'Forbidden',str_repeat('b',32)),'other employee notes denied');
 $owner=true;$actor=99;marketing_note_send(1,'Owner reply',str_repeat('c',32));ok(db()->query('SELECT recipient FROM test_notices ORDER BY id DESC LIMIT 1')->fetchColumn()==='2','Owner reply notifies assigned employee');
 $owner=false;$actor=2;ok(marketing_media_get(1)['file_name']==='one.png','existing assigned versions remain accessible');rejects(fn()=>marketing_media_get(2),'other employee media denied');
 marketing_media_attach(3,1);marketing_media_attach(3,1);ok((int)db()->query('SELECT COUNT(*) FROM marketing_media_links')->fetchColumn()===1,'attach reuses existing file with no duplicate link');
 rejects(fn()=>marketing_media_attach(3,2),'cannot attach to another employee assignment');
 ok(marketing_execution_item(1)===$before,'media attachment does not complete assignment');
 ok(count(marketing_assignment_media(1))===2,'attached and original files appear together for assignment review');
 db()->exec("INSERT INTO marketing_media_links(version_id,item_id,channel_key,linked_by,linked_at)VALUES(3,1,'instagram',2,NOW()),(3,1,'tiktok',2,NOW())");
 $media=marketing_assignment_media(1);ok(in_array('instagram',$media[0]['linked_channels'],true)&&in_array('tiktok',$media[0]['linked_channels'],true),'same file appears on every linked channel without duplicating media');
 $owner=true;marketing_media_delete(1);ok((int)db()->query('SELECT COUNT(*) FROM marketing_item_versions')->fetchColumn()===3,'authorised removal preserves original versions');
 rejects(fn()=>marketing_media_get(1),'removed media is no longer served');
 $path=$fixture.'/fake.jpg';file_put_contents($path,'<?php echo "bad";');rejects(fn()=>marketing_media_validate($path,'fake.jpg',filesize($path)),'executable disguised as an image rejected');
 $path2=$fixture.'/tiny.png';file_put_contents($path2,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aM1sAAAAASUVORK5CYII='));ok(marketing_media_validate($path2,'tiny.png',filesize($path2))==='image/png','real safe image accepted');
 rejects(fn()=>marketing_media_validate($path2,'evil.php',filesize($path2)),'executable extension rejected despite image content');
 rejects(fn()=>marketing_media_validate($path2,'large.png',31*1024*1024),'oversized media rejected');
 ok(marketing_media_type('audio/mp4')==='audio'&&marketing_media_type('video/webm')==='videos','audio and video have appropriate preview types');
 $safe=marketing_safe_brief('<p>Hello <strong>bold</strong></p><script>alert(1)</script><img src=x onerror=alert(1)>');ok(strpos($safe,'<strong>bold</strong>')!==false&&strpos($safe,'script')===false&&strpos($safe,'onerror')===false,'brief formatting preserved without executable markup');
}finally{db()->exec('DROP DATABASE '.$database);foreach(glob($fixture.'/*')as$f)if(is_file($f))unlink($f);unlink($fixture.'/shared/notifications.php');rmdir($fixture.'/shared');rmdir($fixture);}
