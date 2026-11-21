<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DispatchChat.php';
final class DispatchInbox
{
    private const SCOPE=" FROM notifications n JOIN notification_recipients r ON r.notification_id=n.id JOIN delivery_chat_messages m ON n.related_type='delivery_chat' AND n.related_id=m.id JOIN delivery_chat_threads t ON t.id=m.thread_id WHERE r.employee_id=? AND t.driver_id=? AND m.sender_id<>? AND n.module='delivery' AND r.cleared_at IS NULL";
    public static function feed(\PDO $db,int $employee,int $before,string $tab):array {
        $out=['items'=>[],'more'=>false,'counts'=>['all'=>0,'orders'=>0,'updates'=>0,'alerts'=>0],'through'=>0];if(!DispatchChat::ready($db))return $out;EmployeeDriverSession::actor($db,$employee);
        $scope=self::SCOPE;$args=[$employee,$employee,$employee];
        $rows=ErrandData::query($db,'SELECT n.priority,COUNT(*) n'.$scope.' AND r.read_at IS NULL GROUP BY n.priority',$args);foreach($rows as $r){$out['counts']['all']+=(int)$r['n'];$out['counts'][$r['priority']==='urgent'?'alerts':'updates']+=(int)$r['n'];}
        $out['through']=(int)ErrandData::query($db,'SELECT MAX(n.id) n'.$scope,$args)[0]['n'];if(in_array($tab,['orders','deliveries','errands'],true))return $out;
        if($tab==='unread')$scope.=' AND r.read_at IS NULL';
        if($before>0){$scope.=' AND n.id<?';$args[]=$before;}if($tab==='alerts')$scope.=" AND n.priority='urgent'";elseif($tab==='updates')$scope.=" AND n.priority<>'urgent'";
        $out['items']=ErrandData::query($db,"SELECT n.id,m.id job,n.title,n.priority,n.created_at,UNIX_TIMESTAMP(n.created_at) epoch,r.read_at,'dispatch_chat' kind,'Private dispatch conversation' ref".$scope.' ORDER BY n.id DESC LIMIT 41',$args);$out['more']=count($out['items'])>40;if($out['more'])array_pop($out['items']);return $out;
    }
    public static function mark(\PDO $db,int $employee,int $id,bool $all):void {
        if(!DispatchChat::ready($db))return;EmployeeDriverSession::actor($db,$employee);
        $q=$db->prepare('SELECT n.id'.self::SCOPE.' AND n.id'.($all?'<=?':'=?').' FOR UPDATE');$q->execute([$employee,$employee,$employee,$id]);
        foreach($q->fetchAll(\PDO::FETCH_COLUMN) as $notice)$db->prepare('UPDATE notification_recipients SET read_at=UTC_TIMESTAMP() WHERE notification_id=? AND employee_id=? AND read_at IS NULL')->execute([$notice,$employee]);
    }
}
