<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
require_once __DIR__.'/PerformanceRefreshRuntime.php';
final class HandoverPerformanceBridge
{
    public static function capture(PDO $db,string $action,array $plan,array $meta):void
    {
        if(!PerformanceRefreshRuntime::enabled($db))return;
        $ref='HANDOVER-'.$plan['id'];$at=Support::timestamp($meta['occurred_at']??null)->format('Y-m-d H:i:s');
        $engine=new DeadlineEngine($db);$actor=(int)($meta['employee_id']??0);
        if($action==='handover_accepted' && !empty($plan['accepted_at']) && $plan['planned_start']>$at){
            $db->prepare("INSERT IGNORE INTO epi_v2_object_duties VALUES('Tasks',?,'front_desk')")->execute([$ref]);
            $engine->schedule(['module'=>'Tasks','object_reference'=>$ref,'obligation_key'=>'complete_handover',
                'breach_event_key'=>'internal_handover_missed','starts_at'=>$at,'due_at'=>$plan['planned_start'],
                'cycle_id'=>'accepted:'.$plan['accepted_at'],'source_event'=>$action,'responsible_team'=>'front_desk',
                'source_evidence'=>['plan'=>$plan,'expected'=>'Review and hand over Front Desk work at the accepted start time']]);
        }elseif($action==='handover_replanned' && $actor>0){
            $engine->cancelObject('Tasks',$ref,$actor,$at);
        }elseif($action==='handover_started' && $actor>0){
            // Called before duty transfer, preserving the outgoing responsible interval.
            $engine->fulfilObject('Tasks',$ref,'complete_handover',$actor,$at);
        }
    }
}
