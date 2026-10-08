<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;

/** Captures saved conversation obligations, including future unattended follow-ups. */
final class CommunicationDeadlineBridge
{
    public static function capture(PDO $db,string $action,array $record,array $meta):void
    {
        $activation=V2Store::activation($db);if(!$activation)return;
        $at=Support::timestamp($meta['occurred_at']??null)->format('Y-m-d H:i:s');
        $created=(string)($record['created_at']??$at);
        $newInbound=in_array($action,['whatsapp_message_saved','whatsapp_message_received'],true)
            && ($meta['direction']??'')==='inbound' && $at>=$activation['enforcement_start_at'];
        $ref='CONVERSATION-'.(int)$record['id'];$employee=(int)($record['assigned_employee_id']??0);
        if($created<$activation['enforcement_start_at']&&!$newInbound && !V2Store::one($db,
            "SELECT id FROM epi_v2_operational_deadlines WHERE module='Orders' AND object_reference=? AND starts_at>=? LIMIT 1",
            [$ref,$activation['enforcement_start_at']]))return;
        $actor=(int)($meta['employee_id']??0);$owners=new OwnershipPeriodEngine($db);
        $owner=$owners->ownerAt('Orders',$ref,$at);
        if($newInbound && $employee===0){
            $db->prepare("INSERT IGNORE INTO epi_v2_object_duties VALUES('Orders',?,'front_desk')")->execute([$ref]);
        }
        if($employee>0 && (!$owner || (int)$owner['employee_id']!==$employee)) {
            $directed=$actor===(int)$activation['approved_by'] && (V2Store::policy($db)['task_assignment_policy']??'')==='owner_directed';
            if($actor===$employee || $directed)$owners->assign(['module'=>'Orders','object_reference'=>$ref,
                'employee_id'=>$employee,'assigned_by'=>$actor,'accepted_by'=>$actor,
                'authority'=>$directed?'owner_directed':'employee_acceptance',
                'effective_from'=>$at,'accepted_at'=>$at,'source'=>'saved_communication_assignment']);
        }
        $engine=new DeadlineEngine($db);
        $due=(string)($record['follow_up_at']??'');
        if($action==='save_whatsapp_conversation' && $due!=='' && $due>$at)$engine->schedule(['module'=>'Orders','object_reference'=>$ref,
            'obligation_key'=>'customer_followup','breach_event_key'=>'customer_followup_breached',
            'starts_at'=>$at,'due_at'=>$due,'cycle_id'=>'followup:'.$due,'source_event'=>$action,
            'responsible_team'=>'front_desk']);
        // A response target must be explicitly configured, never guessed from message volume.
        $minutes=(int)(V2Store::policy($db)['communication_response_minutes']??0);
        $waiting=V2Store::one($db,"SELECT id FROM epi_v2_operational_deadlines WHERE module='Orders' AND object_reference=?
            AND obligation_key='customer_response' AND state IN('open','breached','needs_attribution') LIMIT 1",[$ref]);
        if(!$waiting && $newInbound && $minutes>0 && !empty($record['last_customer_message_at']) && $record['last_customer_message_at']===$at)
            $engine->schedule(['module'=>'Orders','object_reference'=>$ref,'obligation_key'=>'customer_response',
                'breach_event_key'=>'customer_response_sla_breached','starts_at'=>$at,
                'due_at'=>(new BusinessTimeEngine($db))->addWorkingMinutes($at,$minutes),
                'cycle_id'=>'response:'.$at,'source_event'=>$action,'responsible_team'=>'front_desk']);
        if($actor>0 && ($meta['direction']??'')==='outbound') {
            $engine->fulfilObject('Orders',$ref,'customer_response',$actor,$at);
        }
        if($actor>0 && ($record['status']??'')==='resolved') {
            $engine->fulfilObject('Orders',$ref,'customer_followup',$actor,$at);
        }
    }
}
