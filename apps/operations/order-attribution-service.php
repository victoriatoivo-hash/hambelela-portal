<?php
declare(strict_types=1);

function ops_order_attribution_policy_date(): string
{
    $row=ops_rows("SELECT setting_value FROM kpi_settings WHERE setting_key='packed_by_compliance_effective_date' LIMIT 1")[0]??[];
    return (string)($row['setting_value']??'2026-08-04');
}

function ops_order_attribution_evidence(int $orderId): array
{
    $order=ops_rows('SELECT id,order_number,customer_name,status,created_at,completed_at,fulfilment_mode,assigned_packer_id FROM ops_orders WHERE id=? LIMIT 1',[$orderId])[0]??null;
    if(!$order)return ['classification'=>'unable_to_confirm','sources'=>[],'actors'=>[]];
    if(in_array(strtolower((string)$order['status']),['cancelled','canceled','refunded','failed'],true))return ['classification'=>'not_applicable','sources'=>['order status'],'actors'=>[],'order'=>$order];
    $events=[];
    if(ops_table_exists('ops_order_stage_events'))foreach(ops_rows("SELECT 'ops_order_stage_events' source,stage_key action,employee_id actor_id,created_at occurred_at,metadata FROM ops_order_stage_events WHERE order_id=? AND stage_key IN ('in_progress','completed','packed','verified') ORDER BY created_at,id",[$orderId])as$row)$events[]=$row;
    if(ops_table_exists('kpi_status_events'))foreach(ops_rows("SELECT 'kpi_status_events' source,new_status action,changed_by actor_id,changed_at occurred_at,NULL metadata FROM kpi_status_events WHERE module='order' AND record_id=? AND new_status IN ('in_progress','completed','packed','verified') ORDER BY changed_at,id",[$orderId])as$row)$events[]=$row;
    if(ops_table_exists('ops_activity_logs'))foreach(ops_rows("SELECT 'ops_activity_logs' source,action,employee_id actor_id,created_at occurred_at,metadata FROM ops_activity_logs WHERE entity_type='order' AND entity_id=? AND action IN ('status_changed','order_completed','packed_by_changed','order_packed') ORDER BY created_at,id",[$orderId])as$row)$events[]=$row;
    $itemActors=ops_table_exists('ops_order_items')&&ops_column_exists('ops_order_items','packed_by')?ops_rows('SELECT DISTINCT packed_by actor_id,MIN(packed_at) occurred_at FROM ops_order_items WHERE order_id=? AND packed_by IS NOT NULL GROUP BY packed_by',[$orderId]):[];
    foreach($itemActors as$row)$events[]=['source'=>'ops_order_items.packed_by','action'=>'item_packed','actor_id'=>$row['actor_id'],'occurred_at'=>$row['occurred_at'],'metadata'=>null];
    $actorIds=[];$firstPacking=null;$completion=null;$sources=[];
    foreach($events as$event){$actor=(int)($event['actor_id']??0);if($actor>0)$actorIds[$actor]=true;$sources[(string)$event['source']]=true;if($firstPacking===null&&in_array((string)$event['action'],['in_progress','item_packed','order_packed'],true)&&$actor>0)$firstPacking=$event;if($completion===null&&in_array((string)$event['action'],['completed','packed','verified','order_completed'],true)&&$actor>0)$completion=$event;}
    $ids=array_keys($actorIds);$confirmed=null;$method=null;
    if(count($itemActors)===1){$confirmed=(int)$itemActors[0]['actor_id'];$method='Order item packing actor';}
    elseif($firstPacking&&$completion&&(int)$firstPacking['actor_id']===(int)$completion['actor_id']){$confirmed=(int)$firstPacking['actor_id'];$method='Matching packing-start and completion actor';}
    elseif($firstPacking&&count($ids)===1){$confirmed=(int)$firstPacking['actor_id'];$method='Single packing-related actor';}
    $possible=$confirmed?:($firstPacking?(int)$firstPacking['actor_id']:($completion?(int)$completion['actor_id']:null));
    $classification=$confirmed?'system_confirmed':($events?'staff_confirmation_required':'unable_to_confirm');
    return ['classification'=>$classification,'confirmed_packer_id'=>$confirmed,'possible_packer_id'=>$possible,'method'=>$method,'first_packing_event'=>$firstPacking,'completion_event'=>$completion,'events'=>$events,'sources'=>array_keys($sources),'actors'=>$ids,'order'=>$order];
}

function ops_order_attribution_actor_is_eligible(int $employeeId): bool
{
    if ($employeeId <= 0) return false;
    $hasPackingAssignable = ops_ensure_packing_assignable_column();
    $where = $hasPackingAssignable
        ? "e.packing_assignable = 1"
        : "r.role_key IN ('packer','supervisor_manager')";
    return (bool) ops_rows(
        "SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND {$where} LIMIT 1",
        [$employeeId]
    );
}

function ops_update_order_status_with_attribution(int $orderId,string $status,string $source): array
{
    $database=db();
    $database->beginTransaction();
    try {
        $stmt=$database->prepare('SELECT id,status,assigned_packer_id,created_at FROM ops_orders WHERE id=? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$order) throw new RuntimeException('Order not found. Refresh and try again.');

        $actorId=ops_current_employee_id();
        $autoPacker=null;
        if(in_array($status,['in_progress','completed'],true)
            && empty($order['assigned_packer_id'])
            && ops_order_attribution_actor_is_eligible($actorId)) {
            $autoPacker=$actorId;
        }

        $set='status=?,updated_at=CURRENT_TIMESTAMP';
        $params=[$status];
        if($status==='in_progress'&&ops_column_exists('ops_orders','packing_started_at'))$set.=',packing_started_at=COALESCE(packing_started_at,NOW())';
        if($status==='completed'){
            if(ops_column_exists('ops_orders','packing_started_at'))$set.=',packing_started_at=COALESCE(packing_started_at,NOW())';
            $set.=',packed_at=COALESCE(packed_at,NOW()),completed_at=COALESCE(completed_at,NOW())';
        }
        if($autoPacker){
            $set.=',assigned_packer_id=?';$params[]=$autoPacker;
            if(ops_column_exists('ops_orders','assigned_at'))$set.=',assigned_at=COALESCE(assigned_at,NOW())';
        }
        $params[]=$orderId;
        $database->prepare("UPDATE ops_orders SET {$set} WHERE id=?")->execute($params);

        if($autoPacker&&ops_table_exists('ops_order_attribution_reviews')){
            $policy=((string)$order['created_at']>=ops_order_attribution_policy_date())?1:0;
            $evidence=['source'=>$source,'status'=>$status,'actor_id'=>$autoPacker,'occurred_at'=>date('Y-m-d H:i:s')];
            $database->prepare("INSERT INTO ops_order_attribution_reviews(order_id,classification,possible_packer_id,confirmed_packer_id,confirmation_obtained_from,supporting_note,source_evidence_json,assignment_method,policy_applies,compliance_result,reviewed_by,reviewed_at,restored_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE classification=VALUES(classification),possible_packer_id=VALUES(possible_packer_id),confirmed_packer_id=VALUES(confirmed_packer_id),confirmation_obtained_from=VALUES(confirmation_obtained_from),supporting_note=VALUES(supporting_note),source_evidence_json=VALUES(source_evidence_json),assignment_method=VALUES(assignment_method),policy_applies=VALUES(policy_applies),compliance_result=VALUES(compliance_result),reviewed_by=VALUES(reviewed_by),reviewed_at=NOW(),restored_at=NOW(),updated_at=NOW()")
                ->execute([$orderId,'system_confirmed',$autoPacker,$autoPacker,$autoPacker,'Packed By was automatically assigned to the eligible employee who changed the packing status.',json_encode($evidence,JSON_UNESCAPED_SLASHES),'Automatic status attribution',$policy,$policy?'missed_attribution':'excluded',$actorId]);
            ops_activity_log('packed_by_auto_assigned_after_status_change','order',$orderId,[
                'original_value'=>'Unassigned','new_packer_id'=>$autoPacker,'person_making_correction'=>$actorId,
                'person_confirming'=>$actorId,'supporting_note'=>'Eligible packer changed status while Packed By was unassigned.',
                'source_evidence'=>$evidence,'historical_correction'=>false,'compliance_result'=>$policy?'missed_attribution':'excluded'
            ]);
        }
        $database->commit();
        return ['old_status'=>(string)$order['status'],'auto_assigned_packer_id'=>$autoPacker];
    } catch(Throwable $error) {
        if($database->inTransaction())$database->rollBack();
        throw $error;
    }
}

function ops_seed_and_recover_order_attribution(int $orderId): array
{
    $evidence=ops_order_attribution_evidence($orderId);$order=$evidence['order']??null;if(!$order)return $evidence;
    $confirmed=(int)($evidence['confirmed_packer_id']??0);$class=(string)$evidence['classification'];$restored=$class==='system_confirmed'&&$confirmed>0;
    $policyApplies=((string)($order['created_at']??'')>=ops_order_attribution_policy_date())?1:0;
    $compliance=$restored&&$policyApplies?'missed_attribution':($restored?'excluded':'pending');
    db()->beginTransaction();
    try{
        db()->prepare("INSERT INTO ops_order_attribution_reviews(order_id,classification,possible_packer_id,confirmed_packer_id,source_evidence_json,assignment_method,policy_applies,compliance_result,restored_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE classification=IF(reviewed_at IS NULL,VALUES(classification),classification),possible_packer_id=IF(reviewed_at IS NULL,VALUES(possible_packer_id),possible_packer_id),source_evidence_json=IF(reviewed_at IS NULL,VALUES(source_evidence_json),source_evidence_json),updated_at=NOW()")
          ->execute([$orderId,$class,$evidence['possible_packer_id']??null,$confirmed?:null,json_encode($evidence,JSON_UNESCAPED_SLASHES),$restored?'Historical system recovery':null,$policyApplies,$compliance,$restored?date('Y-m-d H:i:s'):null]);
        if($restored){$stmt=db()->prepare('UPDATE ops_orders SET assigned_packer_id=?,assigned_at=COALESCE(assigned_at,NOW()),updated_at=NOW() WHERE id=? AND assigned_packer_id IS NULL');$stmt->execute([$confirmed,$orderId]);if($stmt->rowCount()){ops_activity_log('historical_packed_by_recovered','order',$orderId,['original_value'=>'Unassigned','new_packer_id'=>$confirmed,'assignment_method'=>'Historical system recovery','source_evidence'=>$evidence['sources'],'historical_correction'=>true]);}}
        db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    $evidence['restored']=$restored;return $evidence;
}

function ops_record_timely_packer_assignment(int $orderId,int $packerId,int $actorId): void
{
    if(!ops_table_exists('ops_order_attribution_reviews'))return;
    $policy=((string)(ops_rows('SELECT created_at FROM ops_orders WHERE id=?',[$orderId])[0]['created_at']??'')>=ops_order_attribution_policy_date())?1:0;
    db()->prepare("INSERT INTO ops_order_attribution_reviews(order_id,classification,confirmed_packer_id,confirmation_obtained_from,supporting_note,source_evidence_json,assignment_method,policy_applies,compliance_result,reviewed_by,reviewed_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE confirmed_packer_id=VALUES(confirmed_packer_id),assignment_method=VALUES(assignment_method),policy_applies=VALUES(policy_applies),compliance_result=VALUES(compliance_result),updated_at=NOW()")
      ->execute([$orderId,'system_confirmed',$packerId,$actorId,'Packed By selected before packing status change.',json_encode(['source'=>'manual Packed By selector'],JSON_UNESCAPED_SLASHES),'Manual and timely',$policy,'compliant',$actorId]);
}
