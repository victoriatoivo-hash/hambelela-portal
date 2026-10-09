<?php
require __DIR__.'/delivery-recorded-history.php';
class ProfileTestDatabase extends PDO {
    public function __construct(){parent::__construct('sqlite::memory:');$this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);}
    private function sql($sql){return str_replace([' FOR UPDATE','UTC_TIMESTAMP()'],['',"datetime('now')"],$sql);}
    #[\ReturnTypeWillChange]
    public function prepare($sql,$options=[]){return parent::prepare($this->sql($sql),$options);}
    #[\ReturnTypeWillChange]
    public function query($sql,$fetchMode=null,...$args){return $fetchMode===null?parent::query($this->sql($sql)):parent::query($this->sql($sql),$fetchMode,...$args);}
}
$db=new ProfileTestDatabase();
$db->exec("CREATE TABLE ops_roles(id INTEGER PRIMARY KEY,role_key TEXT);CREATE TABLE ops_role_permissions(role_id INTEGER);CREATE TABLE ops_employees(id INTEGER PRIMARY KEY,role_id INTEGER,full_name TEXT,phone TEXT,email TEXT,password_hash TEXT,status TEXT,packing_assignable INTEGER,packing_auto_assignable INTEGER);CREATE TABLE delivery_driver_profiles(employee_id INTEGER PRIMARY KEY,active INTEGER,verified_by_employee_id INTEGER,verified_at TEXT);CREATE TABLE ops_security_events(event_type TEXT,employee_id INTEGER,metadata_json TEXT);INSERT INTO ops_roles VALUES(1,'owner_admin'),(2,'delivery_driver');INSERT INTO ops_employees(id,role_id,full_name,status) VALUES(1,1,'Owner','active')");
$svc=new \Hambelela\Delivery\BackCapture($db,static function(){return [];});
$owner=['kind'=>'employee','id'=>1,'role'=>'owner_admin','active'=>true];
$first=$svc->createHistoricalDriver($owner,'Israel');$again=$svc->createHistoricalDriver($owner,'Israel');
check($first===$again&&(int)$db->query('SELECT COUNT(*) FROM delivery_driver_profiles')->fetchColumn()===1,'Repeated profile creation does not duplicate a Driver');
$r=$db->query('SELECT * FROM ops_employees WHERE id='.$first['id'])->fetch(PDO::FETCH_ASSOC);
check($r['password_hash']===null&&$r['phone']===null&&(int)$r['packing_assignable']===0,'Profile has no login credential or packing access');
check((int)$db->query('SELECT COUNT(*) FROM ops_security_events')->fetchColumn()===1,'Profile creation audit recorded once');
try{$svc->createHistoricalDriver(array_merge($owner,['role'=>'delivery_driver']),'Other');throw new RuntimeException('Unauthorized creation succeeded');}catch(DomainException $e){check(true,'Only Owner can create historical profiles');}
try{$svc->createHistoricalDriver($owner,'Owner');throw new RuntimeException('Duplicate staff identity created');}catch(DomainException $e){check(!$db->inTransaction(),'Existing employee collision rejected and transaction rolled back');}
$db->exec('INSERT INTO ops_role_permissions VALUES(2)');
try{$svc->createHistoricalDriver($owner,'Other');throw new RuntimeException('Broad role allowed');}catch(DomainException $e){check(true,'Role with staff permissions cannot be used');}
$r=\Hambelela\Delivery\BackCapture::recordedHistory(['history_completed_gmt'=>'2026-10-07 11:31:45'],[],true);
check($r['recorded_completed_local']==='2026-10-07T13:31'&&$r['completion_source']==='Original Orders completion event','Recorded Orders UTC event fills missing POS completion');
