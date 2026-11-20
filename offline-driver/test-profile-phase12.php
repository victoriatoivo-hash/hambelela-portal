<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);ob_start();define('BASE_PATH',dirname(__DIR__));define('BASE_URL','');
require BASE_PATH.'/shared/delivery/DriverProfileData.php';
use Hambelela\Delivery\DriverProfileData;
$dsn='mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4';$db=new PDO($dsn,'driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$tables=['ops_employees','ops_roles','delivery_driver_profiles','delivery_jobs','delivery_events','ops_orders','delivery_errands','delivery_errand_details','delivery_errand_events','notifications','notification_recipients','delivery_receipts','delivery_ledger','order_payment_allocations'];$before=[];
foreach($tables as $t){$before[$t]=$db->query('SELECT * FROM '.$t)->fetchAll();$ddl=$db->query('SHOW CREATE TABLE '.$t)->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*FOREIGN KEY[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\s*\) ENGINE/',"\n) ENGINE",$ddl);$db->exec($ddl);if(!in_array($t,['delivery_events','delivery_errand_events']))foreach($before[$t] as $r)$db->prepare('INSERT INTO '.$t.' (`'.implode('`,`',array_keys($r)).'`) VALUES('.implode(',',array_fill(0,count($r),'?')).')')->execute(array_values($r));}
$db->exec('CREATE TEMPORARY TABLE notification_preferences(employee_id INT PRIMARY KEY,desktop_enabled INT,sound_enabled INT)');
$count=0;function yes12($v,$label){global $count;if(!$v)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";$count++;}
function no12($fn,$label){try{$fn();}catch(DomainException $e){yes12(true,$label);return;}throw new RuntimeException('FAIL accepted '.$label);}
function db(){global $db;return $db;}$who=3;function current_user(){global $who;return ['id'=>$who];}
$_SESSION=['csrf'=>'logout-test','driver_profile_csrf'=>'profile-test'];
function http12($p=['action'=>'read','actor'=>3],$method='POST',$get=['profile'=>1],$csrf='profile-test'){$_SERVER['REQUEST_METHOD']=$method;$_POST=['csrf'=>$csrf,'payload'=>json_encode($p)];$GLOBALS['_GET']=$get;http_response_code(200);ob_start();require BASE_PATH.'/shared/delivery/DriverProfile.php';return [http_response_code(),ob_get_clean()];}
$now=new DateTimeImmutable('2026-11-21 12:00:00',new DateTimeZone('Africa/Windhoek'));$svc=new DriverProfileData($db);$p=$svc->read(3,$now);
yes12($p['metrics']['deliveries']===0&&$p['metrics']['errands']===0,'no verified completions is genuine zero');
yes12($p['metrics']['completion_rate']===null,'zero denominator is not fabricated 100 percent');
yes12($p['driver']['duty_state']==='unknown'&&$p['vehicle']['state']==='unavailable'&&$p['schedule']['state']==='unavailable','no invented duty vehicle or roster');
yes12($p['editable_fields']===[],'identity edit denied until owner-approved');
yes12($p['metrics']['errands_needs_review']>0,'legacy collected timestamps remain flagged without invented completion actor');
yes12($svc->read(3,new DateTimeImmutable('2026-12-25 09:00:00',new DateTimeZone('Africa/Windhoek')))['driver']['duty_state']==='unknown','holiday does not infer an assigned shift or on duty');
yes12($p['preferences']['state']==='not_saved','unsaved preferences are not default saved success');
yes12(!preg_match('/salary|bank|loan|email|mobile|password|access_code/',json_encode($p)),'private HR fields excluded');
foreach([1,2,5,0,99999] as $id)no12(fn()=>$svc->read($id,$now),'non-driver denied '.$id);
$job=$before['delivery_jobs'][0];$jid=(int)$job['id'];$proof=['receipt'=>str_repeat('a',32),'delivery_id'=>$jid,'order_id'=>$job['order_id']===null?null:(int)$job['order_id'],'driver_id'=>3,'recipient'=>['recipient_name'=>'Test recipient','recipient_type'=>'customer','relationship'=>'','acknowledged'=>true],'policy'=>['evidence'=>'signature','representative'=>'relationship'],'evidence'=>[['id'=>str_repeat('a',48),'stored_sha256'=>str_repeat('b',64),'kind'=>'signature','mime'=>'image/png']]];
function event12($proof,$at,$type='delivered',$actor=3){global $db,$jid;$db->prepare('INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json,created_at) VALUES(?,?,?,?,?)')->execute([$jid,$type,$actor,json_encode(['proof'=>$proof]),$at]);}
event12($proof,'2026-11-15 22:00:00');event12($proof,'2026-11-15 22:00:00');event12([],'2026-11-17 10:00:00','failed');
$p=$svc->read(3,$now);yes12($p['metrics']['deliveries']===1&&$p['metrics']['needs_review']===1,'duplicates collapsed and failed attempt not completed');yes12($svc->read(4,$now)['metrics']['deliveries']===0,'no other driver metric leakage');
$db->exec('DELETE FROM delivery_events');event12($proof,'2026-11-15 21:59:59');yes12($svc->read(3,$now)['metrics']['deliveries']===0,'before Windhoek Monday excluded');
$db->exec('DELETE FROM delivery_events');event12($proof,'2026-11-22 21:59:59');yes12($svc->read(3,$now)['metrics']['deliveries']===1,'Sunday final second included');
$db->exec('DELETE FROM delivery_events');event12($proof,'2026-11-22 22:00:00');yes12($svc->read(3,$now)['metrics']['deliveries']===0,'next Monday excluded');
$db->exec('DELETE FROM delivery_events');event12($proof,'2026-11-20 12:00:00');$db->exec("UPDATE delivery_jobs SET status='cancelled' WHERE id=$jid");yes12($svc->read(3,$now)['metrics']['deliveries']===1,'later cancellation retains labelled historical completion, not rate denominator');
$db->exec("UPDATE delivery_jobs SET driver_employee_id=4 WHERE id=$jid");yes12($svc->read(3,$now)['metrics']['deliveries']===1&&$svc->read(4,$now)['metrics']['deliveries']===0,'completion credited to event actor after reassignment');
$er=$before['delivery_errands'][0]??null;if(!$er)throw new RuntimeException('Synthetic errand fixture required');$eid=(int)$er['id'];
$db->exec("INSERT INTO delivery_errand_details(errand_id,state) VALUES($eid,'open') ON DUPLICATE KEY UPDATE state='open'");$db->exec("UPDATE delivery_errands SET collected_at='2026-11-20 12:00:00' WHERE id=$eid");
$db->prepare("INSERT INTO delivery_errand_events(errand_id,actor_id,action,after_json,created_at) VALUES(?,3,'completed','{}','2026-11-20 12:00:00')")->execute([$eid]);$db->prepare("INSERT INTO delivery_errand_events(errand_id,actor_id,action,after_json,created_at) VALUES(?,3,'completed','{}','2026-11-20 12:00:00')")->execute([$eid]);
yes12($svc->read(3,$now)['metrics']['errands']===1&&$svc->read(4,$now)['metrics']['errands']===0,'errand completion actor and deduplication');
$eid2=(int)$before['delivery_errands'][1]['id'];$db->exec("INSERT INTO delivery_errand_details(errand_id,state) VALUES($eid2,'open') ON DUPLICATE KEY UPDATE state='open'");$db->exec("UPDATE delivery_errands SET collected_at='2026-11-20 12:00:01' WHERE id=$eid2");$db->prepare("INSERT INTO delivery_errand_events(errand_id,actor_id,action,after_json,created_at) VALUES(?,3,'completed','{}','2026-11-20 12:00:01')")->execute([$eid2]);yes12($svc->read(3,$now)['metrics']['errands']===2,'multiple completed errands counted individually');$db->exec("UPDATE delivery_errands SET collected_at=NULL WHERE id=$eid2");
$db->exec("UPDATE delivery_errand_details SET state='cancelled' WHERE errand_id=$eid");yes12($svc->read(3,$now)['metrics']['errands']===0,'cancelled errand excluded');
$db->exec("UPDATE delivery_errand_details SET state='open' WHERE errand_id=$eid");$db->exec("UPDATE delivery_errands SET collected_at=NULL WHERE id=$eid");yes12($svc->read(3,$now)['metrics']['errands']===0,'reopened errand not completed');
$db->exec("UPDATE delivery_errands SET collected_at='2026-11-20 12:00:00' WHERE id=$eid");$db->prepare("INSERT INTO delivery_errand_events(errand_id,actor_id,action,after_json,created_at) VALUES(?,2,'reopen','{}','2026-11-21 12:00:00')")->execute([$eid]);yes12($svc->read(3,$now)['metrics']['errands']===0,'reopen audit prevents stale collected timestamp credit');
$db->exec('INSERT INTO notification_preferences VALUES(3,0,1),(4,1,0)');yes12($svc->read(3,$now)['preferences']['sound_enabled']===true&&$svc->read(3,$now)['preferences']['desktop_enabled']===false,'canonical saved preferences self-only');
[$status,$out]=http12();yes12($status===200&&json_decode($out,true)['actor']===3,'actual controller returns signed-in actor');
foreach([['action'=>'edit','actor'=>3],['action'=>'save_preferences','actor'=>3],['action'=>'read','actor'=>3,'role'=>'owner_admin'],['action'=>'read','actor'=>4]] as $payload){[$status]=http12($payload);yes12(in_array($status,[403,405]),'unapproved mutations and spoofed payload refused');}
[$status]=http12(['action'=>'read','actor'=>3],'POST',['profile'=>1],'wrong');yes12($status===403,'CSRF rejected');
foreach(['driver','employee','employee_id','user_id'] as $key){[$status]=http12(['action'=>'read','actor'=>3],'GET',['profile'=>1,$key=>4]);yes12($status===403,'caller identity forbidden '.$key);}
[$status]=http12([],'DELETE');yes12($status===405,'delete forbidden');
$who=4;[$status,$out]=http12(['action'=>'read','actor'=>4]);yes12($status===200&&json_decode($out,true)['data']['metrics']['deliveries']===0,'other profile controller only returns its own activity');$who=3;
[$status,$html]=http12([],'GET');yes12($status===200&&str_contains($html,'/apps/delivery/driver/logout.php')&&str_contains($html,'method="post"'),'real existing POST logout form, no fake logout');
yes12(substr_count($html,'class="hp-menu-row"')===6&&substr_count($html,'class="ho-driver-dashboard__tab"')===5,'six rows and five shared navigation tabs');
$bad='<img src=x onerror=alert(1)>'; $db->prepare('UPDATE ops_employees SET full_name=? WHERE id=3')->execute([$bad]);[$status,$html]=http12([],'GET');yes12(!str_contains($html,$bad),'name not interpolated into HTML shell');yes12($svc->read(3,$now)['driver']['display_name']===$bad,'API preserves name for safe textContent rendering');
yes12(DriverProfileData::initials('  Anna   Test Person ')==='AP'&&DriverProfileData::initials('')==='DR'&&DriverProfileData::initials('Éva')==='É','Unicode initials and missing photo fallback');
$db->exec('UPDATE delivery_driver_profiles SET active=0 WHERE employee_id=3');[$status]=http12();yes12($status===403,'driver profile revocation immediately denied');$db->exec('UPDATE delivery_driver_profiles SET active=1 WHERE employee_id=3');
$db->exec("UPDATE ops_employees SET status='inactive' WHERE id=3");[$status]=http12();yes12($status===403,'inactive employee immediately denied');
$clean=new PDO($dsn,'driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);foreach($before as $t=>$rows)yes12($rows===$clean->query('SELECT * FROM '.$t)->fetchAll(),'original '.$t.' unchanged');
echo "TOTAL $count Profile checks passed\n";
