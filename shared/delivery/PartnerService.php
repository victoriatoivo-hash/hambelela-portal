<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';
require_once __DIR__.'/PartnerAccess.php';
require_once __DIR__.'/NotificationOutbox.php';
require_once __DIR__.'/PartnerRouting.php';

/** Partner requests are isolated from Orders and never create employee identities. */
final class PartnerService
{
 private \PDO $db;
 public function __construct(\PDO $db){$this->db=$db;(new PartnerAccess($db))->install();}
 private function rows(string $sql,array $args=[]):array{$s=$this->db->prepare($sql);$s->execute($args);return $s->fetchAll(\PDO::FETCH_ASSOC);}
 public function actor(array $actor,bool $lock=false):array
 {
  if(($actor['kind']??'')!=='partner'||empty($actor['active']))throw new \DomainException('Partner access required.');
  $fresh=(new PartnerAccess($this->db))->identity((int)$actor['id'],$lock);
  if($fresh['partner_id']!==(int)$actor['partner_id'])throw new \DomainException('Partner account unavailable.');
  if(isset($actor['session_version'])&&(int)$actor['session_version']!==(int)$fresh['session_version'])throw new \DomainException('Please sign in again.');
  return $fresh;
 }
 public function board(array $actor):array
 {
  $actor=$this->actor($actor);
  $partner=$this->rows('SELECT name FROM delivery_partners WHERE id=?',[$actor['partner_id']]);
  [$scope,$scopeArgs]=PartnerAccess::scope($actor,'');
  // Never expose internal employee, reconciliation, credential or accounting data.
  return ['partner_name'=>$partner[0]['name'],'jobs'=>$this->rows("SELECT id,public_reference,partner_reference,customer_name,customer_mobile,address,area,fee_cents,fee_payer,partner_cod_due_cents,scheduled_date,urgent,status,notes,created_at,version FROM delivery_jobs WHERE $scope ORDER BY id DESC LIMIT 200",$scopeArgs),'zones'=>$this->rows('SELECT id,area,fee_cents FROM delivery_zones WHERE active=1 ORDER BY area')];
 }
 public function arrange(array $actor,string $uuid,array $body):array
 {
  return $this->arrangeRequest($actor,$uuid,$body,null);
 }
 public function arrangeForOwner(array $owner,int $contactId,string $uuid,array $body):array
 {
  if(($owner['kind']??'')!=='employee'||($owner['role']??'')!=='owner_admin'||empty($owner['active']))throw new \DomainException('Owner access required.');
  $contacts=$this->rows("SELECT u.id,u.partner_id FROM delivery_partner_users u JOIN delivery_partners p ON p.id=u.partner_id WHERE u.id=? AND u.active=1 AND p.active=1 AND (LOWER(TRIM(p.name)) IN ('tedlaser','tedlaser and engraving') OR LOWER(p.code)='tedlaser')",[$contactId]);
  if(!$contacts)throw new \DomainException('Select a configured active Tedlaser contact.');
  return $this->arrangeRequest(['kind'=>'partner','id'=>(int)$contacts[0]['id'],'partner_id'=>(int)$contacts[0]['partner_id'],'active'=>true],$uuid,$body,$owner);
 }
 private function arrangeRequest(array $actor,string $uuid,array $body,?array $owner):array
 {
  if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uuid))throw new \DomainException('Invalid request ID.');
  $b=[];foreach(['customer_name','mobile','address','reference','date','notes','fee_payer'] as $key)$b[$key]=trim((string)($body[$key]??''));
  $b['zone_id']=(int)($body['zone_id']??0);$b['cod']=DeliveryPolicy::cents((string)($body['cod']??'0'));$b['urgent']=!empty($body['urgent']);
  $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$b['date'],new \DateTimeZone('Africa/Windhoek'));
  if(!$day||$day->format('Y-m-d')!==$b['date']||$day<new \DateTimeImmutable('today',new \DateTimeZone('Africa/Windhoek')))throw new \DomainException('Choose today or a future date.');
  if($b['customer_name']===''||strlen($b['customer_name'])>190||!preg_match('/^\+?[0-9 ()-]{7,30}$/D',$b['mobile'])||$b['address']===''||strlen($b['address'])>2000||strlen($b['reference'])>190||strlen($b['notes'])>4000||!in_array($b['fee_payer'],['customer','partner'],true))throw new \DomainException('Check customer, address, reference and fee payer.');
  if($this->db->inTransaction())throw new \LogicException('Partner service owns its transaction.');$this->db->beginTransaction();
  try{
   // Serialize each partner's requests so duplicate references cannot race.
   $p=$this->rows('SELECT id,name,code FROM delivery_partners WHERE id=? AND active=1 FOR UPDATE',[$actor['partner_id']]);if(!$p)throw new \DomainException('Partner unavailable.');$fresh=$this->actor($actor,true);if(!$owner&&!PartnerAccess::can($fresh,'create'))throw new \DomainException('Creating deliveries is not permitted.');
   if($owner){$verified=$this->rows("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin' FOR UPDATE",[$owner['id']]);if(!$verified)throw new \DomainException('Active Owner required.');}
   $key=$owner?'employee:'.$owner['id']:'partner:'.$actor['id'];$hash=hash('sha256',json_encode($owner?[$b,$actor['partner_id'],$actor['id']]:$b,JSON_THROW_ON_ERROR));$old=$this->rows('SELECT * FROM delivery_requests WHERE actor_key=? AND request_uuid=? FOR UPDATE',[$key,$uuid]);
   if($old){if($old[0]['action']!=='partner_arranged'||!hash_equals($old[0]['request_hash'],$hash))throw new \DomainException('Request ID already used.');$result=json_decode($old[0]['response_json'],true,32,JSON_THROW_ON_ERROR);$this->db->commit();return $result;}
   if($b['reference']!==''&&$this->rows("SELECT id FROM delivery_jobs WHERE partner_id=? AND partner_reference=? AND status<>'cancelled' LIMIT 1",[$actor['partner_id'],$b['reference']]))throw new \DomainException('This partner reference already has a delivery. Contact Front to reschedule it.');
   $zones=$this->rows('SELECT * FROM delivery_zones WHERE id=? AND active=1 FOR UPDATE',[$b['zone_id']]);$zone=$zones[0]??null;if(!$zone)throw new \DomainException('Choose a configured delivery area.');
   $driver=PartnerRouting::driver($this->db,(int)$actor['partner_id']);
   if(!$this->rows("SELECT p.employee_id FROM delivery_driver_profiles p JOIN ops_employees e ON e.id=p.employee_id JOIN ops_roles r ON r.id=e.role_id WHERE p.employee_id=? AND p.active=1 AND e.status='active' AND r.role_key='delivery_driver' FOR UPDATE",[$driver]))throw new \DomainException('Front must configure a verified Driver for this partner before requests can be submitted.');
   $ref='PART-'.strtoupper(bin2hex(random_bytes(8)));
   $this->db->prepare("INSERT INTO delivery_jobs(public_reference,source,partner_id,partner_user_id,partner_reference,customer_name,customer_mobile,address,area,zone_id,fee_cents,fee_payer,partner_cod_due_cents,scheduled_date,urgent,notes,driver_employee_id) VALUES(?,'partner',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$ref,$actor['partner_id'],$actor['id'],$b['reference'],$b['customer_name'],$b['mobile'],$b['address'],$zone['area'],$zone['id'],$zone['fee_cents'],$b['fee_payer'],$b['cod'],$b['date'],(int)$b['urgent'],$b['notes'],$driver]);
   $id=(int)$this->db->lastInsertId();
   $prefix=(strtolower(trim($p[0]['name']))==='tedlaser'||strtolower($p[0]['code'])==='tedlaser')?'TD-':'PART-';
   $ref=$prefix.str_pad((string)$id,4,'0',STR_PAD_LEFT);
   $this->db->prepare('UPDATE delivery_jobs SET public_reference=?,partner_reference=? WHERE id=?')->execute([$ref,$b['reference']!==''?$b['reference']:null,$id]);
   $result=['id'=>$id,'reference'=>$ref,'status'=>'ready'];$json=json_encode($result,JSON_THROW_ON_ERROR);
   if($owner){$this->db->prepare('UPDATE delivery_jobs SET arranged_by_employee_id=? WHERE id=?')->execute([$owner['id'],$id]);$this->db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(?,'partner_arranged',?,?)")->execute([$id,$owner['id'],json_encode($result+['on_behalf_of_partner_user_id'=>$actor['id']],JSON_THROW_ON_ERROR)]);}
   else $this->db->prepare("INSERT INTO delivery_events(delivery_id,event_type,partner_user_id,metadata_json) VALUES(?,'partner_arranged',?,?)")->execute([$id,$actor['id'],$json]);
   NotificationOutbox::notifyFront($this->db,$id,'partner:'.$id,'partner');
   NotificationOutbox::queue($this->db,$id,$driver,'partner:'.$id.':driver',['kind'=>'assignment']);
   $this->db->prepare('INSERT INTO delivery_requests(actor_key,request_uuid,action,request_hash,response_json) VALUES(?,?,?,?,?)')->execute([$key,$uuid,'partner_arranged',$hash,$json]);
   $this->db->commit();return $result;
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
