<?php

declare(strict_types=1);

function kpi_packer_median(array $values): ?float
{
    if (!$values) return null;
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);
    return count($values) % 2 ? (float) $values[$middle] : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
}

function kpi_packer_average(array $values): ?float
{
    return $values ? round(array_sum($values) / count($values), 1) : null;
}

function kpi_packer_time_of_day_average(array $timestamps): ?string
{
    if (!$timestamps) return null;
    $seconds = array_map(static function (string $timestamp): int {
        $time = new DateTimeImmutable($timestamp, new DateTimeZone('Africa/Windhoek'));
        return ((int) $time->format('H')) * 3600 + ((int) $time->format('i')) * 60 + (int) $time->format('s');
    }, $timestamps);
    $average = (int) round(array_sum($seconds) / count($seconds));
    return sprintf('%02d:%02d', intdiv($average, 3600), intdiv($average % 3600, 60));
}

function kpi_packer_epi_orders_evidence(int $employeeId,string $fromSql,string $toSql,array $settings,array $holidays):?array
{
    $events=[];
    if(ops_table_exists('epi_employee_evidence'))$events=ops_rows("SELECT ev.id evidence_id,ev.reference_number,ev.occurred_at,ev.working_minutes,ev.metadata_json,ev.status_before,ev.status_after,o.id order_id,o.customer_name,o.status,o.assigned_at,o.created_at portal_imported_at,COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type) fulfilment_mode,o.total_weight_kg,e.full_name packed_by,COALESCE(items.item_quantity,0) item_quantity FROM epi_employee_evidence ev LEFT JOIN ops_orders o ON o.order_number=ev.reference_number LEFT JOIN ops_employees e ON e.id=ev.employee_id LEFT JOIN (SELECT order_id,SUM(quantity) item_quantity FROM ops_order_items GROUP BY order_id) items ON items.order_id=o.id WHERE ev.employee_id=? AND LOWER(ev.module)='packing' AND ev.action='order_packed' AND ev.occurred_at BETWEEN ? AND ? ORDER BY ev.occurred_at DESC,ev.id DESC",[$employeeId,$fromSql,$toSql]);
    if(!$events){
        $normalized=[];$recordIds=[];$references=[];
        foreach(kpi_unified_events($fromSql,$toSql,$employeeId,'orders')as$event){
            $status=strtolower(str_replace(['_',' '],'',(string)($event['new_status']??'')));
            if((string)($event['record_type']??'')!=='order'||(string)($event['action']??'')!=='status_changed'||$status!=='inprogress')continue;
            $normalized[]=$event;$recordId=(int)($event['record_id']??0);if($recordId>0)$recordIds[$recordId]=true;
            $reference=trim((string)($event['related_reference']??''));if($reference!=='')$references[$reference]=true;
        }
        if($normalized){
            $hasWeight=ops_column_exists('ops_orders','total_weight_kg');$weightSelect=$hasWeight?'o.total_weight_kg':'NULL total_weight_kg';$createdExpr=ops_order_display_datetime_expr('o');
            $conditions=[];$params=[];$ids=array_keys($recordIds);$refs=array_keys($references);
            if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$conditions[]='o.id IN ('.$marks.')';array_push($params,...$ids);if(ops_column_exists('ops_orders','woo_order_id')){$conditions[]='o.woo_order_id IN ('.$marks.')';array_push($params,...$ids);}}
            if($refs){$conditions[]='o.order_number IN ('.implode(',',array_fill(0,count($refs),'?')).')';array_push($params,...$refs);}
            $orders=$conditions?ops_rows("SELECT o.id order_id,o.woo_order_id,o.order_number,o.customer_name,o.status,o.assigned_at,{$createdExpr} portal_imported_at,COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type) fulfilment_mode,{$weightSelect},COALESCE(items.item_quantity,0) item_quantity FROM ops_orders o LEFT JOIN (SELECT order_id,SUM(quantity) item_quantity FROM ops_order_items GROUP BY order_id) items ON items.order_id=o.id WHERE ".implode(' OR ',$conditions),$params):[];
            $byId=[];$byWoo=[];$byReference=[];foreach($orders as$order){$byId[(int)$order['order_id']]=$order;if((int)($order['woo_order_id']??0)>0)$byWoo[(int)$order['woo_order_id']]=$order;$byReference[(string)$order['order_number']]=$order;}
            $seenTransition=[];foreach($normalized as$event){$sourceId=(int)($event['record_id']??0);$reference=trim((string)($event['related_reference']??''));$order=$byId[$sourceId]??$byWoo[$sourceId]??($reference!==''?($byReference[$reference]??null):null);if(!$order)continue;$recordId=(int)$order['order_id'];if(isset($seenTransition[$recordId]))continue;$seenTransition[$recordId]=true;$start=!empty($order['portal_imported_at'])?new DateTimeImmutable((string)$order['portal_imported_at'],new DateTimeZone('Africa/Windhoek')):null;$done=new DateTimeImmutable((string)$event['occurred_at'],new DateTimeZone('Africa/Windhoek'));$minutes=$start&&$done>=$start?kpi_business_minutes($start,$done,$holidays):null;$events[]=$order+['evidence_id'=>$event['event_id'],'occurred_at'=>$event['occurred_at'],'status_before'=>$event['previous_status'],'status_after'=>$event['new_status'],'packed_by'=>$event['actor_name'],'reference_number'=>$order['order_number'],'working_minutes'=>$minutes,'metadata_json'=>json_encode(['order_type'=>$order['fulfilment_mode'],'created_at'=>$order['portal_imported_at'],'evidence_source'=>$event['source_log']])];}
        }
    }
    if(!$events)return null;
    $targetMinutes=max(1,(float)($settings['target_fulfilment_hours']??6)*60);$seen=[];$rows=[];$durations=[];$counts=['total'=>0,'items'=>0.0,'eligible'=>0,'on_time'=>0,'late'=>0,'courier'=>0,'courier_cutoff_missed'=>0,'review'=>0];
    foreach($events as$event){$reference=trim((string)($event['reference_number']??''));if($reference===''||isset($seen[$reference]))continue;$seen[$reference]=true;$meta=json_decode((string)($event['metadata_json']??''),true);if(!is_array($meta))$meta=[];$minutes=is_numeric($event['working_minutes']??null)?max(0,(float)$event['working_minutes']):null;$mode=!empty($meta['is_walk_in'])?'Walk-in assistance':ucfirst(strtolower((string)($meta['packing_classification']??$meta['order_type']??$event['fulfilment_mode']??'Other')));$courier=strtolower($mode)==='courier';$missed=!empty($meta['courier_cutoff_missed']);if($minutes!==null){$counts['eligible']++;$durations[]=$minutes;if($minutes<=$targetMinutes)$counts['on_time']++;else$counts['late']++;}else$counts['review']++;if($courier){$counts['courier']++;if($missed)$counts['courier_cutoff_missed']++;}$counts['total']++;$counts['items']+=(float)($event['item_quantity']??0);$rows[]=['id'=>(int)($event['order_id']??0),'timeline_module'=>'order','order_number'=>$reference,'customer_name'=>$event['customer_name']??'—','packed_by'=>$event['packed_by']??'—','assigned_by'=>'Orders activity evidence','assigned_at'=>$event['assigned_at']??null,'packing_started_at'=>$meta['created_at']??$meta['order_created_at']??$event['portal_imported_at']??null,'packing_completed_at'=>$event['occurred_at'],'mode'=>$mode,'item_quantity'=>(float)($event['item_quantity']??0),'total_weight_kg'=>$event['total_weight_kg']??null,'packing_minutes'=>$minutes,'turnaround_minutes'=>$minutes,'status'=>'In Progress','result'=>$minutes===null?'Timing unavailable — review required':($minutes<=$targetMinutes?'Within '.round($targetMinutes/60,1).'-hour target':'Outside '.round($targetMinutes/60,1).'-hour target')];}
    $average=kpi_packer_average($durations);$median=kpi_packer_median($durations);$rate=$counts['eligible']?round(100*$counts['on_time']/$counts['eligible'],1):null;$metric=static fn(string$label,$value,?string$format=null,string$explanation=''):array=>array_filter(['label'=>$label,'value'=>$value,'format'=>$format,'explanation'=>$explanation,'status'=>$value===null?'unmeasured':'measured'],static fn($v)=>$v!==null&&$v!=='');
    return['rows'=>$rows,'metrics'=>[$metric('Orders Moved New → In Progress',$counts['total'],null,'Authoritative employee-attributed order transitions in the selected period.'),$metric('Order Items Prepared',$counts['items']),$metric('Within Target',$counts['on_time']),$metric('Late',$counts['late']),$metric('On-Time Rate',$rate,'percent','Measured against the configured '.$targetMinutes.' business-minute target.'),$metric('Average New → In Progress',$average,'minutes'),$metric('Median New → In Progress',$median,'minutes'),$metric('Courier Orders',$counts['courier']),$metric('Courier Cut-offs Missed',$counts['courier_cutoff_missed']),$metric('Timing Records Needing Review',$counts['review'])],'counts'=>$counts,'average_minutes'=>$average,'median_minutes'=>$median,'target_minutes'=>$targetMinutes,'on_time_rate'=>$rate,'historical'=>true,'timestamp_coverage'=>['complete'=>$counts['eligible'],'incomplete'=>$counts['review']]];
}

function kpi_packer_orders_evidence(array $employee, string $fromSql, string $toSql, array $schedule, array $holidays, array $settings, array $leave = []): array
{
    $employeeId = (int) $employee['id'];
    $epiEvidence=kpi_packer_epi_orders_evidence($employeeId,$fromSql,$toSql,$settings,$holidays);if($epiEvidence!==null)return$epiEvidence;
    $createdExpr = ops_order_display_datetime_expr('o');
    $deletedWhere = ops_column_exists('ops_orders', 'deleted_at') ? ' AND o.deleted_at IS NULL' : '';
    $hasWeight = ops_column_exists('ops_orders', 'total_weight_kg');
    $weightSelect = $hasWeight ? 'o.total_weight_kg' : 'NULL AS total_weight_kg';
    $attributionJoin = '';
    $attributionExpr = 'o.assigned_packer_id';
    $attributionSourceExpr = "'Completion-time Packed By'";
    if (ops_table_exists('ops_order_attribution_reviews')) {
        $attributionJoin = "LEFT JOIN ops_order_attribution_reviews attribution ON attribution.order_id=o.id AND attribution.confirmed_packer_id IS NOT NULL";
        $attributionExpr = 'COALESCE(attribution.confirmed_packer_id,o.assigned_packer_id)';
        $attributionSourceExpr = "COALESCE(attribution.assignment_method,'Completion-time Packed By')";
    }
    $orders = ops_rows(
        "SELECT o.id,'order' timeline_module,o.order_number,o.customer_name,o.status,
                COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type) fulfilment_mode,
                {$createdExpr} order_created_at,o.created_at portal_imported_at,o.assigned_at,o.packing_started_at,o.packed_at,o.completed_at,
                {$weightSelect},{$attributionExpr} historical_packer_id,packer.full_name packed_by,
                {$attributionSourceExpr} attribution_source,
                COALESCE(items.item_quantity,0) item_quantity,COALESCE(items.item_lines,0) item_lines
         FROM ops_orders o
         {$attributionJoin}
         LEFT JOIN ops_employees packer ON packer.id={$attributionExpr}
         LEFT JOIN (SELECT order_id,SUM(quantity) item_quantity,COUNT(*) item_lines FROM ops_order_items GROUP BY order_id) items ON items.order_id=o.id
         WHERE {$attributionExpr}=? AND o.status IN ('completed','packed','verified')
           AND COALESCE(o.packed_at,o.completed_at) BETWEEN ? AND ?{$deletedWhere}
         ORDER BY COALESCE(o.packed_at,o.completed_at) DESC,o.id DESC LIMIT 1000",
        [$employeeId, $fromSql, $toSql]
    );

    // Older live schemas do not have explicit_logout_at yet. Build the
    // aggregate from columns that actually exist instead of failing the whole
    // Orders report for an optional productivity metric.
    $sessionEnd = ops_column_exists('kpi_sessions', 'explicit_logout_at')
        ? 'COALESCE(explicit_logout_at,logout_at,last_seen_at)'
        : 'COALESCE(logout_at,last_seen_at)';
    $session = ops_rows("SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND,login_at,{$sessionEnd})),0) seconds FROM kpi_sessions WHERE user_id=? AND login_at BETWEEN ? AND ?", [$employeeId,$fromSql,$toSql])[0] ?? [];
    $hours = max(0.0, (float) ($session['seconds'] ?? 0) / 3600);
    $qualityRows = ops_rows("SELECT category,customer_impact FROM ops_error_logs WHERE responsible_employee_id=? AND affects_kpi_accuracy=1 AND accuracy_verified_by IS NOT NULL AND COALESCE(occurred_on,DATE(occurred_at),DATE(created_at),DATE(logged_at)) BETWEEN DATE(?) AND DATE(?) AND deleted_at IS NULL", [$employeeId,$fromSql,$toSql]);

    $turnaround=[];$assignment=[];$packing=[];$courierTimes=[];$rows=[];
    $counts=['total'=>0,'items'=>0.0,'collection'=>0,'delivery'=>0,'courier'=>0,'before_12'=>0,'before_14'=>0,'missed_14'=>0,'incomplete_timing'=>0];
    foreach ($orders as $order) {
        $created = !empty($order['order_created_at']) ? strtotime((string)$order['order_created_at']) : false;
        $assigned = !empty($order['assigned_at']) ? strtotime((string)$order['assigned_at']) : false;
        $started = !empty($order['packing_started_at']) ? strtotime((string)$order['packing_started_at']) : false;
        $completedText = (string)($order['packed_at'] ?: $order['completed_at']);
        $completed = $completedText !== '' ? strtotime($completedText) : false;
        $validTurnaround = $created !== false && $completed !== false && $completed >= $created;
        $validAssignment = $assigned !== false && $started !== false && $started >= $assigned;
        $validPacking = $started !== false && $completed !== false && $completed >= $started;
        $turnaroundMinutes = $validTurnaround ? round(($completed-$created)/60,1) : null;
        $assignmentMinutes = $validAssignment ? round(($started-$assigned)/60,1) : null;
        $packingMinutes = $validPacking ? round(($completed-$started)/60,1) : null;
        if ($turnaroundMinutes !== null) $turnaround[]=$turnaroundMinutes;
        if ($assignmentMinutes !== null) $assignment[]=$assignmentMinutes;
        if ($packingMinutes !== null) $packing[]=$packingMinutes;
        if (!$validTurnaround || !$validAssignment || !$validPacking) $counts['incomplete_timing']++;
        $mode = strtolower(trim((string)$order['fulfilment_mode']));
        if (isset($counts[$mode])) $counts[$mode]++;
        if ($mode==='courier' && $completed !== false) {
            $courierTimes[]=$completedText;
            $clock=(new DateTimeImmutable($completedText,new DateTimeZone('Africa/Windhoek')))->format('H:i:s');
            if ($clock<'12:00:00') $counts['before_12']++;
            if ($clock<'14:00:00') $counts['before_14']++; else $counts['missed_14']++;
        }
        $counts['total']++;
        $counts['items']+=(float)$order['item_quantity'];
        $rows[]=$order+[
            'mode'=>ucfirst($mode ?: 'Unknown'),'assigned_by'=>'Activity Log','packing_completed_at'=>$completedText,
            'turnaround_minutes'=>$turnaroundMinutes,'assignment_to_start_minutes'=>$assignmentMinutes,'packing_minutes'=>$packingMinutes,
            'result'=>$validTurnaround&&$validAssignment&&$validPacking?'Verified permanent timestamps':'Insufficient historical data',
        ];
    }
    $packingErrors=count($qualityRows);$missing=0;$wrong=0;$returns=0;$complaints=0;
    foreach($qualityRows as $error){$category=strtolower((string)$error['category']);if(strpos($category,'missing')!==false||strpos($category,'omitted')!==false)$missing++;if(strpos($category,'wrong')!==false||strpos($category,'incorrect')!==false)$wrong++;if(strpos($category,'return')!==false)$returns++;if(trim((string)$error['customer_impact'])!=='')$complaints++;}
    $heavyThreshold=(float)($settings['heavy_order_threshold_kg']??10);$heavy=null;$light=null;
    if($hasWeight){$heavy=count(array_filter($rows,static fn(array $r):bool=>$r['total_weight_kg']!==null&&(float)$r['total_weight_kg']>=$heavyThreshold));$light=count(array_filter($rows,static fn(array $r):bool=>$r['total_weight_kg']!==null&&(float)$r['total_weight_kg']<$heavyThreshold));}
    $metric=static fn(string $label,$value,?string $format=null,string $explanation=''):array=>array_filter(['label'=>$label,'value'=>$value,'format'=>$format,'explanation'=>$explanation,'status'=>$value===null?'unmeasured':'measured'],static fn($v)=>$v!==null&&$v!=='');
    $metrics=[
        $metric('Distinct Orders Packed',$counts['total'],null,'Unique completed orders attributed to this employee at completion.'),
        $metric('Total Items Packed',$counts['items'],null,'Sum of stored order-line quantities.'),
        $metric('Collection Orders',$counts['collection']),$metric('Delivery Orders',$counts['delivery']),$metric('Courier Orders',$counts['courier']),
        $metric('Average New → Packed Turnaround',kpi_packer_average($turnaround),'minutes'),$metric('Median New → Packed Turnaround',kpi_packer_median($turnaround),'minutes'),
        $metric('Average Assignment → Packing Start',kpi_packer_average($assignment),'minutes'),$metric('Average Packing Time',kpi_packer_average($packing),'minutes'),
        $metric('Courier Ready Before 12:00',$counts['before_12']),$metric('Courier Ready Before 14:00',$counts['before_14']),$metric('Missed Courier Cut-off',$counts['missed_14']),
        $metric('Average Courier Ready Time',kpi_packer_time_of_day_average($courierTimes)),
        $metric('Orders Per Hour',$hours>0?round($counts['total']/$hours,2):null,null,$hours>0?'Uses stored authenticated login/logout session duration.':'Insufficient historical session data.'),
        $metric('Average Items Per Order',$counts['total']?round($counts['items']/$counts['total'],2):null),
        $metric('Heavy Orders Packed',$heavy,null,'Threshold: '.number_format($heavyThreshold,1).' kg.'),$metric('Light Orders Packed',$light),
        $metric('Packing Errors',$packingErrors),$metric('Missing Items',$missing),$metric('Wrong Items',$wrong),$metric('Returns Caused by Packing',$returns),$metric('Customer Complaints',$complaints),
    ];
    // Keep the owner Orders report contract in sync with the richer employee
    // evidence response. These aliases are derived from the same single-pass
    // order set; they do not trigger per-order activity-log queries.
    $counts['courier_ready']=$counts['before_14'];
    $counts['review']=$counts['incomplete_timing'];
    return ['rows'=>$rows,'metrics'=>$metrics,'counts'=>$counts,'average_minutes'=>kpi_packer_average($turnaround),'lead_minutes'=>0,'historical'=>true,'timestamp_coverage'=>['complete'=>$counts['total']-$counts['incomplete_timing'],'incomplete'=>$counts['incomplete_timing']],'weight_threshold_kg'=>$heavyThreshold];
}
