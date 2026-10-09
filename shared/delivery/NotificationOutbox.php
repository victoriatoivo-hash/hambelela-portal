<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryDeadline.php';

/** Uses the existing notification tables. Never runs schema creation or DDL. */
final class NotificationOutbox
{
 public static function attempt(\PDO $db):void{try{self::drain($db);}catch(\Throwable $e){error_log('Delivery notification dispatch unavailable: '.get_class($e));}}
 public static function queue(\PDO $db,int $job,int $recipient,string $key,array $payload):void
 {
  if(!$db->inTransaction())throw new \LogicException('Outbox must be committed with the Delivery action.');
  $db->prepare('INSERT IGNORE INTO delivery_outbox(event_key,delivery_id,recipient_employee_id,payload_json,next_attempt_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute([$key,$job,$recipient,json_encode($payload,JSON_THROW_ON_ERROR)]);
 }
 public static function notifyFront(\PDO $db,int $job,string $key,string $kind):void
 {
  $s=$db->query("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key<>'delivery_driver' AND (r.role_key IN ('owner_admin','front_desk_admin','front_desk_admin_employee') OR EXISTS(SELECT 1 FROM delivery_employee_grants g JOIN ops_permissions p ON p.id=g.permission_id WHERE g.employee_id=e.id AND p.permission_key='delivery_reconcile'))");
  foreach($s->fetchAll(\PDO::FETCH_COLUMN) as $id)self::queue($db,$job,(int)$id,$key.':'.$id,['kind'=>$kind]);
 }
 public static function drain(\PDO $db,int $limit=10):int
 {
  if($db->inTransaction())throw new \LogicException('Notification dispatch must occur after the business commit.');$sent=0;
  for($n=0;$n<min(50,max(1,$limit));$n++){
   $id=null;$db->beginTransaction();
   try{
    $s=$db->query('SELECT * FROM delivery_outbox WHERE delivered_at IS NULL AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY id LIMIT 1 FOR UPDATE');$o=$s->fetch(\PDO::FETCH_ASSOC);if(!$o){$db->commit();break;}$id=(int)$o['id'];
    $s=$db->prepare("SELECT e.status,r.role_key,j.driver_employee_id,j.public_reference,j.urgent,EXISTS(SELECT 1 FROM delivery_employee_grants g JOIN ops_permissions p ON p.id=g.permission_id WHERE g.employee_id=e.id AND p.permission_key='delivery_reconcile') can_reconcile FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_jobs j ON j.id=? WHERE e.id=?");$s->execute([$o['delivery_id'],$o['recipient_employee_id']]);$r=$s->fetch(\PDO::FETCH_ASSOC);
    // Do not leak former assignments or notify revoked/inactive accounts.
    $driver=$r&&$r['role_key']==='delivery_driver';$allowed=$r&&$r['status']==='active'&&($driver?(int)$r['driver_employee_id']===(int)$o['recipient_employee_id']:(in_array($r['role_key'],['owner_admin','front_desk_admin','front_desk_admin_employee'],true)||(bool)$r['can_reconcile']));
    if($allowed){
     $payload=json_decode($o['payload_json'],true);$kind=$payload['kind']??'assignment';$title=['reconciliation'=>'Delivery collection ready for handover','failed'=>'Delivery needs attention','partner'=>'New partner delivery request','assignment'=>'Delivery assignment updated','deadline'=>'Customer delivery time updated','deadline_late'=>'Customer delivery deadline passed'][$kind]??'Delivery update';
     if($driver&&$kind==='assignment')$title=(int)$r['urgent']?'URGENT DELIVERY':'New Delivery';
     $deadline=DeliveryDeadline::label(DeliveryDeadline::latest($db,(int)$o['delivery_id']));
     $s=$db->prepare("INSERT INTO notifications(title,message,module,related_type,related_id,priority,deduplication_key,action_link) VALUES(?,?,'delivery','delivery_job',?,?,?,?)");$s->execute([$title,$r['public_reference'].' · '.$deadline.' · Open Delivery to view the current details.',$o['delivery_id'],(int)$r['urgent']?'urgent':'normal','delivery:'.$o['event_key'],$driver?'/apps/delivery/driver/index.php':'/apps/delivery/index.php']);$notice=(int)$db->lastInsertId();
     $db->prepare('INSERT INTO notification_recipients(notification_id,employee_id) VALUES(?,?)')->execute([$notice,$o['recipient_employee_id']]);$sent++;
    }
    $db->prepare('UPDATE delivery_outbox SET delivered_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id=?')->execute([$id]);$db->commit();
   }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();if($id!==null)$db->prepare('UPDATE delivery_outbox SET attempts=attempts+1,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE id=?')->execute([$id]);error_log('Delivery notification retained for retry: '.get_class($e));break;}
  }return $sent;
 }
}
