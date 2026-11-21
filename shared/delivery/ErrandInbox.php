<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/EmployeeDriverSession.php';
final class ErrandInbox
{
    private const SCOPE=" FROM notification_recipients r JOIN notifications n ON n.id=r.notification_id LEFT JOIN delivery_errands e ON n.related_type='delivery_errand' AND e.id=n.related_id LEFT JOIN delivery_errand_requests q ON n.related_type='delivery_errand_request' AND q.id=n.related_id WHERE r.employee_id=? AND n.module='delivery' AND r.cleared_at IS NULL AND ((n.related_type='delivery_errand' AND e.driver_id=?) OR (n.related_type='delivery_errand_request' AND q.requested_by=?))";
    private static function ready(\PDO $db):bool {try{$db->query('SELECT id FROM delivery_errand_requests LIMIT 0');return true;}catch(\PDOException $e){if($e->getCode()==='42S02')return false;throw $e;}}
    public static function feed(\PDO $db,int $id,int $before,string $tab):array
    {
        $counts=['all'=>0,'orders'=>0,'updates'=>0,'alerts'=>0];$out=['items'=>[],'more'=>false,'counts'=>$counts,'through'=>0];if(!self::ready($db))return $out;
        EmployeeDriverSession::actor($db,$id);$s=$db->prepare('SELECT MAX(n.id)'.self::SCOPE);$s->execute([$id,$id,$id]);$out['through']=(int)$s->fetchColumn();
        $s=$db->prepare('SELECT n.priority,COUNT(*) n'.self::SCOPE.' AND r.read_at IS NULL GROUP BY n.priority');$s->execute([$id,$id,$id]);foreach($s as $r){$out['counts']['all']+=(int)$r['n'];$out['counts'][$r['priority']==='urgent'?'alerts':'updates']+=(int)$r['n'];}
        if(in_array($tab,['orders','deliveries'],true))return $out;$params=[$id,$id,$id];$where='';if($before>0){$where.=' AND n.id<?';$params[]=$before;}if($tab==='alerts')$where.=" AND n.priority='urgent'";elseif($tab==='updates')$where.=" AND n.priority<>'urgent'";
        if($tab==='unread')$where.=' AND r.read_at IS NULL';
        $s=$db->prepare("SELECT n.id,n.related_id job,n.title,n.priority,n.created_at,UNIX_TIMESTAMP(n.created_at) epoch,r.read_at,IF(n.related_type='delivery_errand','errand','errand_request') kind,CONCAT(IF(n.related_type='delivery_errand','Errand ER-','Request #'),n.related_id) ref".self::SCOPE.$where.' ORDER BY n.id DESC LIMIT 41');$s->execute($params);$out['items']=$s->fetchAll(\PDO::FETCH_ASSOC);$out['more']=count($out['items'])>40;if($out['more'])array_pop($out['items']);return $out;
    }
    public static function mark(\PDO $db,int $employee,int $id,bool $all):void
    {
        if(!self::ready($db))return;EmployeeDriverSession::actor($db,$employee);
        $s=$db->prepare('SELECT r.notification_id'.self::SCOPE.' AND n.id'.($all?'<=?':'=?').' FOR UPDATE');$s->execute([$employee,$employee,$employee,$id]);
        foreach($s->fetchAll(\PDO::FETCH_COLUMN) as $notice)$db->prepare('UPDATE notification_recipients SET read_at=UTC_TIMESTAMP() WHERE notification_id=? AND employee_id=? AND read_at IS NULL')->execute([$notice,$employee]);
    }
}
