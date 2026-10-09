<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
/** Explicit Owner-approved routing, stored as immutable configuration audit events. */
final class PartnerRouting
{
 public static function driver(\PDO $db,int $partner):int
 {
  $s=$db->prepare("SELECT metadata_json FROM ops_security_events WHERE event_type='delivery_partner_routing' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.partner_id'))=? ORDER BY id DESC LIMIT 1");$s->execute([(string)$partner]);$value=$s->fetchColumn();$data=$value?json_decode((string)$value,true):[];return (int)($data['driver_id']??0);
 }
 public static function save(\PDO $db,int $owner,int $partner,int $driver,string $reason):void
 {
  $reason=trim($reason);if(strlen($reason)<5||strlen($reason)>500)throw new \DomainException('Routing verification reason required.');if($db->inTransaction())throw new \LogicException('Routing owns its transaction.');$db->beginTransaction();
  try{
   $s=$db->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin' FOR UPDATE");$s->execute([$owner]);if(!$s->fetchColumn())throw new \DomainException('Owner access required.');
   $s=$db->prepare('SELECT id FROM delivery_partners WHERE id=? AND active=1 FOR UPDATE');$s->execute([$partner]);if(!$s->fetchColumn())throw new \DomainException('Active partner required.');
   $s=$db->prepare("SELECT p.employee_id FROM delivery_driver_profiles p JOIN ops_employees e ON e.id=p.employee_id JOIN ops_roles r ON r.id=e.role_id WHERE p.employee_id=? AND p.active=1 AND e.status='active' AND r.role_key='delivery_driver' FOR UPDATE");$s->execute([$driver]);if(!$s->fetchColumn())throw new \DomainException('Verified active Driver required.');
   $data=['partner_id'=>$partner,'driver_id'=>$driver,'previous_driver_id'=>self::driver($db,$partner),'reason'=>$reason];
   $db->prepare('INSERT INTO ops_security_events(event_type,employee_id,metadata_json) VALUES(?,?,?)')->execute(['delivery_partner_routing',$owner,json_encode($data,JSON_THROW_ON_ERROR)]);$db->commit();
  }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
}
