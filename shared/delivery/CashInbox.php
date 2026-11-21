<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/CashHandover.php';
final class CashInbox
{
 private const SCOPE=" FROM notification_recipients r JOIN notifications n ON n.id=r.notification_id JOIN delivery_cash_handovers h ON h.id=n.related_id WHERE r.employee_id=? AND h.driver_id=? AND n.module='delivery' AND n.related_type='delivery_cash_handover' AND r.cleared_at IS NULL";
 public static function feed(\PDO $db,int $id,int $before,string $tab):array {
  $out=['items'=>[],'more'=>false,'counts'=>['all'=>0,'orders'=>0,'updates'=>0,'alerts'=>0],'through'=>0];if(!CashHandover::ready($db))return $out;EmployeeDriverSession::actor($db,$id);
  $s=$db->prepare('SELECT MAX(n.id),SUM(r.read_at IS NULL)'.self::SCOPE);$s->execute([$id,$id]);[$out['through'],$n]=$s->fetch(\PDO::FETCH_NUM);$out['counts']['all']=$out['counts']['updates']=(int)$n;
  if(in_array($tab,['orders','alerts','deliveries','errands'],true))return $out;$args=[$id,$id];$where='';if($before>0){$where=' AND n.id<?';$args[]=$before;}
  if($tab==='unread')$where.=' AND r.read_at IS NULL';
  $s=$db->prepare("SELECT n.id,n.related_id job,n.title,n.priority,n.created_at,UNIX_TIMESTAMP(n.created_at) epoch,r.read_at,'cash_handover' kind,CONCAT('CH-',h.id) ref".self::SCOPE.$where.' ORDER BY n.id DESC LIMIT 41');$s->execute($args);$out['items']=$s->fetchAll(\PDO::FETCH_ASSOC);$out['more']=count($out['items'])>40;if($out['more'])array_pop($out['items']);return $out;
 }
 public static function mark(\PDO $db,int $employee,int $id,bool $all):void {
  if(!CashHandover::ready($db))return;EmployeeDriverSession::actor($db,$employee);$s=$db->prepare('SELECT n.id'.self::SCOPE.' AND n.id'.($all?'<=?':'=?').' FOR UPDATE');$s->execute([$employee,$employee,$id]);foreach($s->fetchAll(\PDO::FETCH_COLUMN) as $n)$db->prepare('UPDATE notification_recipients SET read_at=UTC_TIMESTAMP() WHERE notification_id=? AND employee_id=? AND read_at IS NULL')->execute([$n,$employee]);
 }
}
