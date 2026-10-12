<?php
declare(strict_types=1);
/** Owner-only updates to the existing identity; never rewrites historical foreign keys. */
final class EmployeeAccountEditor
{
 private PDO $db; private ?PDO $hr;
 public function __construct(PDO $db,?PDO $hr){$this->db=$db;$this->hr=$hr;}
 private function rows(string $sql,array $args=[]):array{$s=$this->db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
 public function owner(int $id):void{if(!$this->rows("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin'",[$id]))throw new DomainException('Only Owner/Admin can edit employees.');}
 private function fields():array{$columns=array_column($this->rows('SHOW COLUMNS FROM ops_employees'),'Field');return array_values(array_intersect(['full_name','email','phone','status','role_id','job_title','department','packing_assignable','packing_auto_assignable'],$columns));}
 private function person(int $id):array{$fields=$this->fields();$r=$this->rows('SELECT id,'.implode(',',$fields).' FROM ops_employees WHERE id=?',[$id]);if(!$r)throw new DomainException('Employee not found.');$e=$r[0];$link=$this->rows('SELECT hr_employee_id,active FROM employee_user_links WHERE portal_user_id=?',[$id]);$e['hr_employee_id']=!empty($link[0]['active'])?(int)$link[0]['hr_employee_id']:0;return $e;}
 private function version(array $e):string{return hash('sha256',json_encode($e));}
 public function load(int $owner,int $id):array{
  $this->owner($owner);$e=$this->person($id);$profiles=[];
  if($this->hr){$s=$this->hr->query("SELECT id,emp_number,first_name,last_name,job_title,department,status FROM employees WHERE status='active' ORDER BY first_name,last_name");$profiles=$s->fetchAll(PDO::FETCH_ASSOC);}
  $health=function_exists('hr_access_health')?hr_access_health($this->db,$this->hr,$id):['state'=>'unavailable','detail'=>'HR connection unavailable.'];
  return ['employee'=>$e,'version'=>$this->version($e),'fields'=>$this->fields(),'roles'=>$this->rows('SELECT id,role_key,name FROM ops_roles ORDER BY name'),'profiles'=>$profiles,'hr_health'=>['state'=>$health['state'],'detail'=>$health['detail']]];
 }
 private function audit(int $owner,int $id,string $type,array $data):void{$this->db->prepare('INSERT INTO ops_security_events(event_type,employee_id,metadata_json) VALUES(?,?,?)')->execute([$type,$id,json_encode(['performed_by'=>$owner,'changed_at_utc'=>gmdate('c')]+$data,JSON_THROW_ON_ERROR)]);}
 public function save(int $owner,int $id,array $b):array{
  $this->owner($owner);$this->db->beginTransaction();
  try{
   // Serialize account changes and HR-link claims, including the last-owner check.
   $this->rows('SELECT id FROM ops_employees ORDER BY id FOR UPDATE');$this->owner($owner);$old=$this->person($id);
   if(!hash_equals($this->version($old),(string)($b['version']??'')))throw new DomainException('This employee changed. Close and reopen the drawer before saving.');
   $new=$old;
   foreach($this->fields() as $field){
    if(!array_key_exists($field,$b))continue;
    if(in_array($field,['role_id','packing_assignable','packing_auto_assignable'],true)){$new[$field]=(int)$b[$field];continue;}
    $value=trim((string)$b[$field]);$limit=$field==='email'?190:($field==='phone'?40:160);
    if(strlen($value)>$limit)throw new DomainException('The '.$field.' value is too long.');$new[$field]=$value;
   }
   $new['email']=(string)$new['email'];
   if($new['full_name']===''||($new['email']!==''&&!filter_var($new['email'],FILTER_VALIDATE_EMAIL)))throw new DomainException('Enter a full name and valid email address.');
   $new['email']=strtolower($new['email']);
   if(!in_array($new['status'],['active','inactive'],true))throw new DomainException('Choose Active or Inactive.');
   if($this->rows('SELECT id FROM ops_employees WHERE LOWER(email)=? AND id<>?',[$new['email'],$id]))throw new DomainException('This email belongs to another employee.');
   $role=$this->rows('SELECT role_key,name FROM ops_roles WHERE id=?',[$new['role_id']]);if(!$role)throw new DomainException('Choose a configured employee role.');
   if($new['email']===''&&$role[0]['role_key']!=='delivery_driver')throw new DomainException('An email address is required for this role.');
   if($new['email']==='')$new['email']=null;
   $oldRole=$this->rows('SELECT role_key FROM ops_roles WHERE id=?',[$old['role_id']])[0]['role_key'];
   if($oldRole==='owner_admin'&&$old['status']==='active'&&($role[0]['role_key']!=='owner_admin'||$new['status']!=='active')){
    if(!$this->rows("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id<>? AND e.status='active' AND r.role_key='owner_admin'",[$id]))throw new DomainException('The last active Owner/Admin must remain active.');
   }
   foreach(['packing_assignable','packing_auto_assignable'] as $f)if(isset($new[$f])&&!in_array((int)$new[$f],[0,1],true))throw new DomainException('Invalid packing eligibility.');
   if(isset($new['packing_assignable'],$new['packing_auto_assignable'])&&!$new['packing_assignable'])$new['packing_auto_assignable']=0;
   $new['hr_employee_id']=array_key_exists('hr_employee_id',$b)?(int)$b['hr_employee_id']:$old['hr_employee_id'];
   $linkChanged=$new['hr_employee_id']!==$old['hr_employee_id'];
   if($linkChanged&&empty($b['confirm_hr_link']))throw new DomainException('Confirm the HR link correction before saving.');
   if($linkChanged&&$role[0]['role_key']==='owner_admin')throw new DomainException('Owner HR administration uses the existing HR admin account, not an employee profile link.');
   if($linkChanged&&$new['hr_employee_id']>0){
    if(!$this->hr)throw new DomainException('HR connection unavailable. No changes saved.');
    $s=$this->hr->prepare("SELECT id FROM employees WHERE id=? AND status='active'");$s->execute([$new['hr_employee_id']]);if(!$s->fetchColumn())throw new DomainException('Select an existing active HR profile.');
    if($this->rows('SELECT portal_user_id FROM employee_user_links WHERE hr_employee_id=? AND active=1 AND portal_user_id<>?',[$new['hr_employee_id'],$id]))throw new DomainException('This HR profile is linked to another portal employee.');
   }
   $changes=[];$sets=[];$args=[];
   foreach($this->fields() as $f)if((string)$new[$f]!== (string)$old[$f]){$changes[$f]=['previous'=>$old[$f],'new'=>$new[$f]];$sets[]=$f.'=?';$args[]=$new[$f];}
   if($sets){$args[]=$id;$this->db->prepare('UPDATE ops_employees SET '.implode(',',$sets).',updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute($args);}
   if($linkChanged){
    if($new['hr_employee_id']>0)$this->db->prepare('INSERT INTO employee_user_links(portal_user_id,hr_employee_id,role,linked_by,active) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE hr_employee_id=VALUES(hr_employee_id),role=VALUES(role),linked_by=VALUES(linked_by),active=1,linked_at=CURRENT_TIMESTAMP')->execute([$id,$new['hr_employee_id'],$role[0]['name'],$owner]);
    else $this->db->prepare('UPDATE employee_user_links SET active=0,linked_by=? WHERE portal_user_id=?')->execute([$owner,$id]);
    $changes['hr_employee_id']=['previous'=>$old['hr_employee_id'],'new'=>$new['hr_employee_id']];
   }
   if($new['hr_employee_id']>0&&$new['status']==='active'&&($linkChanged||isset($changes['full_name']))){
    $health=hr_access_health($this->db,$this->hr,$id);
    if(!in_array($health['state'],['ready','account_missing'],true))throw new DomainException($health['detail']);
   }
   if($changes)$this->audit($owner,$id,'employee_account_updated',['changes'=>$changes]);
   $revoke=!empty($b['revoke_sessions'])||isset($changes['role_id'])||isset($changes['status'])||$linkChanged;
   if($revoke)$this->audit($owner,$id,'employee_sessions_revoked',['revoked_before'=>time(),'reason'=>'Owner account update']);
   $this->db->commit();return ['success'=>true,'message'=>'Changes Saved','employee_id'=>$id,'sessions_revoked'=>$revoke];
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}

function employee_account_has_edits(PDO $db,int $id):bool{
 $s=$db->prepare("SELECT id FROM ops_security_events WHERE employee_id=? AND event_type='employee_account_updated' LIMIT 1");$s->execute([$id]);return (bool)$s->fetchColumn();
}
function employee_account_session_revoked(PDO $db,int $id,string $authenticatedAt):bool{
 $s=$db->prepare("SELECT metadata_json FROM ops_security_events WHERE employee_id=? AND event_type='employee_sessions_revoked' ORDER BY id DESC LIMIT 1");$s->execute([$id]);$row=$s->fetchColumn();if(!$row)return false;
 $event=json_decode($row,true);$login=strtotime($authenticatedAt);return !$login||$login<=(int)($event['revoked_before']??0);
}
