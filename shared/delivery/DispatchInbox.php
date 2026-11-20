<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DispatchChat.php';
final class DispatchInbox
{
    public static function feed(\PDO $db,int $employee,int $before,string $tab):array {
        $out=['items'=>[],'more'=>false,'counts'=>['all'=>0,'orders'=>0,'updates'=>0,'alerts'=>0],'through'=>0];if(!DispatchChat::ready($db))return $out;EmployeeDriverSession::actor($db,$employee);
        $scope=" FROM notifications n JOIN notification_recipients r ON r.notification_id=n.id JOIN delivery_chat_messages m ON n.related_type='delivery_chat' AND n.related_id=m.id JOIN delivery_chat_threads t ON t.id=m.thread_id LEFT JOIN delivery_chat_receipts cr ON cr.message_id=m.id AND cr.employee_id=r.employee_id WHERE r.employee_id=? AND t.driver_id=? AND m.sender_id<>? AND n.module='delivery'";$args=[$employee,$employee,$employee];
        $rows=ErrandData::query($db,'SELECT n.priority,COUNT(*) n'.$scope.' AND cr.read_at IS NULL GROUP BY n.priority',$args);foreach($rows as $r){$out['counts']['all']+=(int)$r['n'];$out['counts'][$r['priority']==='urgent'?'alerts':'updates']+=(int)$r['n'];}
        $out['through']=(int)ErrandData::query($db,'SELECT MAX(n.id) n'.$scope,$args)[0]['n'];if($tab==='orders')return $out;
        if($before>0){$scope.=' AND n.id<?';$args[]=$before;}if($tab==='alerts')$scope.=" AND n.priority='urgent'";elseif($tab==='updates')$scope.=" AND n.priority<>'urgent'";
        $out['items']=ErrandData::query($db,"SELECT n.id,m.id job,n.title,n.priority,UNIX_TIMESTAMP(n.created_at) epoch,cr.read_at,'dispatch_chat' kind,'Private dispatch conversation' ref".$scope.' ORDER BY n.id DESC LIMIT 41',$args);$out['more']=count($out['items'])>40;if($out['more'])array_pop($out['items']);return $out;
    }
}
