<?php
declare(strict_types=1);
require_once __DIR__.'/Service.php';
require_once dirname(__DIR__).'/notifications.php';

function acknowledgments_ready():bool{
    try{db()->query('SELECT id FROM portal_ack_instructions LIMIT 0');return true;}catch(Throwable $e){return false;}
}
function acknowledgments_pending(int $employee):int{
    if(!acknowledgments_ready())return 0;$q=db()->prepare("SELECT COUNT(*) FROM portal_ack_recipients WHERE employee_id=? AND status<>'acknowledged'");$q->execute([$employee]);return (int)$q->fetchColumn();
}
/** Retryable delivery uses the existing notification centre and evidence store, not a second ledger. */
function acknowledgments_deliver():void{
    if(!acknowledgments_ready())return;
    $db=db();
    // One immutable overdue event, dated at the deadline, including late acknowledgments.
    $now=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');
    $db->prepare("INSERT IGNORE INTO portal_ack_events(recipient_id,actor_id,event_type,message,request_key,created_at,notification_delivered_at) SELECT r.id,i.sender_id,'overdue','Required acknowledgment passed its deadline',CONCAT('overdue-',r.id),i.deadline,? FROM portal_ack_recipients r JOIN portal_ack_instructions i ON i.id=r.instruction_id WHERE i.required=1 AND i.deadline IS NOT NULL AND i.deadline<? AND (r.acknowledged_at IS NULL OR r.acknowledged_at>i.deadline)")->execute([$now,$now]);
    $rows=$db->query("SELECT v.*,r.employee_id,r.employee_name,r.acknowledged_at,r.status,i.title,i.version,i.deadline,i.notify_immediately FROM portal_ack_events v JOIN portal_ack_recipients r ON r.id=v.recipient_id JOIN portal_ack_instructions i ON i.id=r.instruction_id WHERE v.notification_delivered_at IS NULL OR v.evidence_recorded_at IS NULL ORDER BY (v.notification_delivered_at IS NULL) DESC,v.id LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row){
        $event=$row['event_type'];$id=(int)$row['id'];$to=in_array($event,['clarify','acknowledge'],true)?notifications_role_recipients(['owner_admin']):[(int)$row['employee_id']];
        if($row['notification_delivered_at']===null){
            $skip=$event==='assigned'&&!(int)$row['notify_immediately'];$key='ack-event-'.$id;
            $notification=$skip?null:notifications_create(['created_by'=>(int)$row['actor_id'],'module'=>'system','required_delivery'=>true,'title'=>$event==='clarify'?'Acknowledgment needs clarification':($event==='acknowledge'?'Instruction acknowledged':'Company notice: '.$row['title']),'message'=>$event==='clarify'?$row['employee_name'].' requested clarification.':$row['title'],'related_type'=>'acknowledgment','related_id'=>(int)$row['recipient_id'],'action_link'=>BASE_URL.'/apps/acknowledgments/index.php?record='.$row['recipient_id'],'deduplication_key'=>$key],$to);
            $q=$db->prepare('SELECT id FROM notifications WHERE deduplication_key=?');$q->execute([$key]);
            if($skip||$notification||$q->fetchColumn())$db->prepare('UPDATE portal_ack_events SET notification_delivered_at=NOW() WHERE id=?')->execute([$id]);
        }
        if($row['evidence_recorded_at']===null){
            try{
                require_once dirname(__DIR__).'/epi/bootstrap.php';\Hambelela\EPI\Performance::configure($db);
                $clarified=$db->prepare("SELECT COUNT(*) FROM portal_ack_events WHERE recipient_id=? AND event_type='clarify'");$clarified->execute([$row['recipient_id']]);
                $evidence=\Hambelela\EPI\Performance::recordEvidence(['module'=>'Portal Activity','reference_number'=>'ACK-'.$row['recipient_id'],'employee_id'=>(int)$row['employee_id'],'employee_name'=>$row['employee_name'],'action'=>'acknowledgment_'.$event,'action_description'=>'Acknowledgments: '.$event,'activity_source'=>'portal_ack_events','timestamp'=>$row['created_at'],'deduplication_key'=>'ack-evidence-'.$id,'metadata'=>['instruction_version'=>(int)$row['version'],'actor_id'=>(int)$row['actor_id'],'excluded_from_scoring'=>true,'clarification_is_not_misconduct'=>true,'deadline'=>$row['deadline'],'on_time'=>$event==='acknowledge'&&(!$row['deadline']||$row['created_at']<=$row['deadline']),'completed_after_clarification'=>$event==='acknowledge'&&(int)$clarified->fetchColumn()>0]]);
                if($evidence)$db->prepare('UPDATE portal_ack_events SET evidence_recorded_at=NOW() WHERE id=?')->execute([$id]);
            }catch(Throwable $e){error_log('Acknowledgment evidence pending: '.$e->getMessage());}
        }
    }
}
