<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

final class PartnerProvisioning
{
 private \PDO $db;public function __construct(\PDO $db){$this->db=$db;}
 public function save(int $owner,array $body,string $secret):array
 {
  $name=trim((string)($body['company']??''));$code=strtoupper(trim((string)($body['code']??'')));$person=trim((string)($body['name']??''));$email=strtolower(trim((string)($body['email']??'')));$existing=(int)($body['user_id']??0);
  if($name===''||strlen($name)>190||!preg_match('/^[A-Z0-9-]{2,30}$/D',$code)||$person===''||strlen($person)>190||strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \DomainException('Verified company, code, name and email are required.');
  if(strlen($secret)<16||strlen($secret)>72||trim($secret)==='')throw new \DomainException('Use a private secret of 16–72 bytes.');
  if($this->db->inTransaction())throw new \LogicException('Provisioning owns its transaction.');$this->db->beginTransaction();
  try{
   $s=$this->db->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin' FOR UPDATE");$s->execute([$owner]);if(!$s->fetchColumn())throw new \DomainException('Active Owner required.');
   $s=$this->db->prepare('SELECT * FROM delivery_partners WHERE code=? FOR UPDATE');$s->execute([$code]);$partner=$s->fetch(\PDO::FETCH_ASSOC);
   if($partner){if($partner['name']!==$name||!(int)$partner['active'])throw new \DomainException('Company code must match the verified active partner.');$partnerId=(int)$partner['id'];}
   else{$this->db->prepare('INSERT INTO delivery_partners(name,code,created_by_employee_id) VALUES(?,?,?)')->execute([$name,$code,$owner]);$partnerId=(int)$this->db->lastInsertId();}
   $s=$this->db->prepare('SELECT id,partner_id,display_name FROM delivery_partner_users WHERE email=? FOR UPDATE');$s->execute([$email]);$old=$s->fetch(\PDO::FETCH_ASSOC);
   if($existing){if(!$old||(int)$old['id']!==$existing||(int)$old['partner_id']!==$partnerId||$old['display_name']!==$person)throw new \DomainException('Existing Partner user ID, company, name and email must match.');$id=$existing;$this->db->prepare('UPDATE delivery_partner_users SET password_hash=?,active=1,session_version=session_version+1,failed_attempts=0,locked_until=NULL WHERE id=?')->execute([password_hash($secret,PASSWORD_DEFAULT),$id]);}
   else{if($old)throw new \DomainException('Email already has a Partner account. Verify its existing user ID before resetting.');$this->db->prepare('INSERT INTO delivery_partner_users(partner_id,display_name,email,password_hash,active) VALUES(?,?,?,?,1)')->execute([$partnerId,$person,$email,password_hash($secret,PASSWORD_DEFAULT)]);$id=(int)$this->db->lastInsertId();}
   $result=['partner_id'=>$partnerId,'user_id'=>$id,'reset'=>$existing>0];$this->db->prepare('INSERT INTO ops_security_events(event_type,employee_id,metadata_json) VALUES(?,?,?)')->execute(['delivery_partner_access',$owner,json_encode($result,JSON_THROW_ON_ERROR)]);$this->db->commit();return $result;
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
