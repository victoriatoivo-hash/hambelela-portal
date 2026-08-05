<?php

declare(strict_types=1);

function kpi_front_order_normalize(string $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?? '';
}

function kpi_front_order_shift_for_day(string $day, array $schedule): ?array
{
    $weekday = (int) (new DateTimeImmutable($day, new DateTimeZone('Africa/Windhoek')))->format('N');
    $row = $schedule[$weekday] ?? null;
    if (!$row || (int) ($row['is_working'] ?? 1) !== 1 || empty($row['shift_end'])) return null;
    return $row;
}

function kpi_front_order_next_deadline(string $day, array $schedule, array $holidayMap): ?DateTimeImmutable
{
    $zone = new DateTimeZone('Africa/Windhoek');
    $cursor = (new DateTimeImmutable($day, $zone))->modify('+1 day');
    for ($guard = 0; $guard < 14; $guard++, $cursor = $cursor->modify('+1 day')) {
        $date = $cursor->format('Y-m-d');
        if (isset($holidayMap[$date])) continue;
        $shift = kpi_front_order_shift_for_day($date, $schedule);
        if (!$shift) continue;
        return new DateTimeImmutable($date . ' ' . substr((string) $shift['shift_end'], 0, 8), $zone);
    }
    return null;
}

function kpi_front_order_walk_in_aliases(array $settings): array
{
    $raw = (string) ($settings['orders_walk_in_identifiers'] ?? 'walk-in,walk in,walk_in,walk-in customer,walk in customer,walkin customer');
    $aliases = [];
    foreach (preg_split('/[,\r\n;]+/', $raw) ?: [] as $alias) {
        $normal = kpi_front_order_normalize((string) $alias);
        if ($normal !== '') $aliases[$normal] = true;
    }
    return array_keys($aliases);
}

function kpi_front_order_is_walk_in(array $order, array $aliases): bool
{
    foreach ([$order['customer_contact'] ?? '', $order['customer_name'] ?? '', $order['order_type'] ?? ''] as $field) {
        $normal = kpi_front_order_normalize((string) $field);
        foreach ($aliases as $alias) if ($normal !== '' && ($normal === $alias || strpos($normal, $alias) !== false)) return true;
    }
    return false;
}

function kpi_front_order_due_at(DateTimeImmutable $created, array $schedule, array $holidayMap, int $graceMinutes): ?DateTimeImmutable
{
    $day = $created->format('Y-m-d');
    $shift = !isset($holidayMap[$day]) ? kpi_front_order_shift_for_day($day, $schedule) : null;
    if ($shift) {
        $start = new DateTimeImmutable($day . ' ' . substr((string) ($shift['shift_start'] ?? '00:00:00'), 0, 8), $created->getTimezone());
        $close = new DateTimeImmutable($day . ' ' . substr((string) $shift['shift_end'], 0, 8), $created->getTimezone());
        if ($created >= $start && $created <= $close) return $close->modify('+' . max(0, $graceMinutes) . ' minutes');
    }
    $next = kpi_front_order_next_deadline($day, $schedule, $holidayMap);
    return $next ? $next->modify('+' . max(0, $graceMinutes) . ' minutes') : null;
}

function kpi_front_order_completion_event(array $events, ?DateTimeImmutable $created, DateTimeZone $zone): ?array
{
    usort($events, static function (array $a, array $b): int { return strcmp((string) ($a['occurred_at'] ?? ''), (string) ($b['occurred_at'] ?? '')); });
    $seen = [];$fallback = null;
    foreach ($events as $event) {
        $action = kpi_front_order_normalize((string) ($event['action'] ?? ''));
        $newStatus = kpi_front_order_normalize((string) ($event['new_status'] ?? ''));
        if (!in_array($newStatus, ['completed','complete'], true) && !in_array($action, ['completed','complete','ordercompleted','ordercomplete'], true)) continue;
        $key = (string) ($event['source_log'] ?? '') . ':' . (string) ($event['source_event_id'] ?? '') . ':' . (string) ($event['occurred_at'] ?? '');
        if (isset($seen[$key])) continue;$seen[$key] = true;
        if (empty($event['occurred_at'])) continue;
        $at = new DateTimeImmutable((string) $event['occurred_at'], $zone);
        if ($created && $at < $created) continue;
        if ($fallback === null) $fallback = $event;
        if ((int) ($event['actor_user_id'] ?? 0) > 0) return $event;
    }
    return $fallback;
}

function kpi_front_orders_evidence(array $employee, string $fromSql, string $toSql, array $schedule, array $holidays, array $settings, array $leave = []): array
{
    $zone = new DateTimeZone('Africa/Windhoek');$employeeId = (int) $employee['id'];$createdExpr = ops_order_display_datetime_expr('o');
    if (!$schedule) {
        for ($weekday=1;$weekday<=5;$weekday++) $schedule[$weekday]=['weekday'=>$weekday,'is_working'=>1,'shift_start'=>$settings['default_shift_start']??'08:00','shift_end'=>$settings['default_shift_end']??'17:00'];
        $schedule[6]=['weekday'=>6,'is_working'=>1,'shift_start'=>$settings['front_orders_saturday_open']??'09:00','shift_end'=>$settings['front_orders_saturday_close']??'13:00'];
    }
    $deletedWhere = ops_column_exists('ops_orders', 'deleted_at') ? ' AND o.deleted_at IS NULL' : '';
    $orders = ops_rows("SELECT o.id,'order' timeline_module,o.order_number,o.customer_name,o.customer_contact,o.order_type,o.status,o.payment_status,o.total_amount,COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type) fulfilment_mode,{$createdExpr} authoritative_created_at,o.created_at portal_imported_at,o.packed_at,o.completed_at,o.assigned_packer_id,packer.full_name packed_by_name FROM ops_orders o LEFT JOIN ops_employees packer ON packer.id=o.assigned_packer_id WHERE (({$createdExpr} BETWEEN ? AND ?) OR (o.completed_at BETWEEN ? AND ?)){$deletedWhere} ORDER BY {$createdExpr} DESC,o.id DESC LIMIT 500", [$fromSql,$toSql,$fromSql,$toSql]);
    $eventFrom = (new DateTimeImmutable($fromSql, $zone))->modify('-14 days')->format('Y-m-d H:i:s');$eventsByOrder = [];
    foreach (kpi_unified_events($eventFrom, $toSql, null, null, null, null) as $event) {
        if ((string) ($event['record_type'] ?? '') !== 'order' && (string) ($event['section'] ?? '') !== 'orders') continue;
        $eventsByOrder[(int) $event['record_id']][] = $event;
    }
    $holidayMap = array_fill_keys(array_map('strval', $holidays), true);
    foreach ($leave as $leaveRow) {
        if (empty($leaveRow['start_date']) || empty($leaveRow['end_date'])) continue;
        $day = new DateTimeImmutable((string) $leaveRow['start_date'], $zone);$end = new DateTimeImmutable((string) $leaveRow['end_date'], $zone);
        while ($day <= $end) {$holidayMap[$day->format('Y-m-d')] = true;$day = $day->modify('+1 day');}
    }
    $aliases=kpi_front_order_walk_in_aliases($settings);$graceMinutes=max(0,(int)($settings['front_orders_walkin_grace_minutes']??0));$now=new DateTimeImmutable('now',$zone);
    $rows=[];$durations=[];$outstandingAges=[];$counts=['walk_in'=>0,'front_desk_other'=>0,'completed'=>0,'on_time'=>0,'late'=>0,'outstanding'=>0,'outstanding_overdue'=>0,'exceptions'=>0,'unresolved'=>0,'packer_only_removed'=>0];
    foreach ($orders as $order) {
        $created=!empty($order['authoritative_created_at'])?new DateTimeImmutable((string)$order['authoritative_created_at'],$zone):null;$events=$eventsByOrder[(int)$order['id']]??[];$completionEvent=kpi_front_order_completion_event($events,$created,$zone);
        $completedById=(int)($completionEvent['actor_user_id']??0);$completedByName=trim((string)($completionEvent['actor_name']??''));
        $completed=$completionEvent&&!empty($completionEvent['occurred_at'])?new DateTimeImmutable((string)$completionEvent['occurred_at'],$zone):(!empty($order['completed_at'])?new DateTimeImmutable((string)$order['completed_at'],$zone):null);
        $walkIn=kpi_front_order_is_walk_in($order,$aliases);$packedByEmployee=(int)($order['assigned_packer_id']??0)===$employeeId;$completedByEmployee=$completedById===$employeeId;$currentCompleted=in_array(kpi_front_order_normalize((string)$order['status']),['completed','complete'],true)||$completed!==null;
        $attribution='';$unresolved=false;
        if($completedByEmployee)$attribution='Status-change Activity Log — Complete transition';
        elseif($completedById>0){$counts['packer_only_removed']++;continue;}
        elseif(!$currentCompleted&&$walkIn&&$packedByEmployee)$attribution='Current responsible employee — outstanding Walk-in';
        elseif($currentCompleted&&$walkIn&&$packedByEmployee){$unresolved=true;$attribution='Unresolved Historical Attribution — Packed By fallback only';}
        else{$counts['packer_only_removed']++;continue;}
        $deadline=$created?kpi_front_order_due_at($created,$schedule,$holidayMap,$graceMinutes):null;$invalid=!$created||($completed&&$completed<$created);$duration=!$invalid&&$created&&$completed?kpi_business_minutes($created,$completed,$holidays):null;
        $statusBefore=(string)($completionEvent['previous_status']??$completionEvent['old_status']??'New');$statusAfter=(string)($completionEvent['new_status']??($currentCompleted?'Complete':(string)$order['status']));$result='Outstanding';
        if($invalid){$result='Invalid timestamp sequence — Requires review';$unresolved=true;}
        elseif($unresolved)$result='Unresolved Historical Attribution';
        elseif($completed&&$deadline&&$completed<=$deadline){$result='Completed on time';$counts['on_time']++;}
        elseif($completed&&$deadline){$result='Completed late';$counts['late']++;}
        elseif($completed){$result='Due timestamp unavailable — Requires review';$unresolved=true;}
        elseif($deadline&&$now>$deadline){$result='Outstanding — overdue';$counts['outstanding_overdue']++;}
        else$result='Outstanding — within due time';
        if($walkIn)$counts['walk_in']++;else$counts['front_desk_other']++;if($currentCompleted)$counts['completed']++;else{$counts['outstanding']++;if($created)$outstandingAges[]=kpi_business_minutes($created,$now,$holidays);}if($unresolved)$counts['unresolved']++;if(!$unresolved&&$duration!==null)$durations[]=$duration;
        $paid=kpi_front_order_normalize((string)$order['payment_status'])==='paid';if($currentCompleted&&!$paid)$counts['exceptions']++;
        $rows[]=$order+['walk_in'=>$walkIn?'Yes':'No','walk_in_identifier'=>$walkIn?trim((string)($order['customer_contact']?:$order['customer_name']?:$order['order_type'])):'—','mode'=>ucfirst((string)$order['fulfilment_mode']),'packed_by'=>$order['packed_by_name']?:'Unassigned','status_before'=>$statusBefore?:'New','status_after'=>$statusAfter?:'Complete','new_order_at'=>$order['authoritative_created_at'],'completed_at'=>$completed?$completed->format('Y-m-d H:i:s'):null,'completed_by'=>$completedByName!==''?$completedByName:'Not recorded','duration_minutes'=>$duration,'deadline'=>$deadline?$deadline->format('Y-m-d H:i:s'):null,'result'=>$result,'attribution_source'=>$attribution,'evidence_id'=>$completionEvent?((string)($completionEvent['source_log']??'activity_log').' #'.(string)($completionEvent['source_event_id']??'')):'Current order record','evidence_count'=>count($events),'unresolved_attribution'=>$unresolved,'front_desk_category'=>$walkIn?'Walk-in':'Other Front Desk'];
    }
    sort($durations,SORT_NUMERIC);$median=null;if($durations){$mid=intdiv(count($durations),2);$median=count($durations)%2?$durations[$mid]:($durations[$mid-1]+$durations[$mid])/2;}$completedEligible=$counts['on_time']+$counts['late'];$compliance=$completedEligible?round(100*$counts['on_time']/$completedEligible,1):null;
    $walkCompleted=count(array_filter($rows,static function(array$row):bool{return$row['walk_in']==='Yes'&&!$row['unresolved_attribution']&&!empty($row['completed_at']);}));$walkOnTime=count(array_filter($rows,static function(array$row):bool{return$row['walk_in']==='Yes'&&$row['result']==='Completed on time';}));$nonWalkCompleted=count(array_filter($rows,static function(array$row):bool{return$row['walk_in']==='No'&&!$row['unresolved_attribution']&&!empty($row['completed_at']);}));$nonWalkOnTime=count(array_filter($rows,static function(array$row):bool{return$row['walk_in']==='No'&&$row['result']==='Completed on time';}));
    $walkRate=$walkCompleted?round(100*$walkOnTime/$walkCompleted,1):null;$nonWalkRate=$nonWalkCompleted?round(100*$nonWalkOnTime/$nonWalkCompleted,1):null;$walkWeight=max(0,(float)($settings['front_orders_walkin_weight']??50));$nonWalkWeight=max(0,(float)($settings['front_orders_nonwalk_weight']??50));$weighted=0.0;$available=0.0;if($walkRate!==null){$weighted+=$walkRate*$walkWeight;$available+=$walkWeight;}if($nonWalkRate!==null){$weighted+=$nonWalkRate*$nonWalkWeight;$available+=$nonWalkWeight;}$score=$available?round($weighted/$available,1):null;
    return ['rows'=>$rows,'metrics'=>[
        ['label'=>'Total Applicable Orders','value'=>count($rows)],['label'=>'Walk-in Orders','value'=>$counts['walk_in']],['label'=>'Other Front Desk Orders','value'=>$counts['front_desk_other']],['label'=>'Completed Orders','value'=>$counts['completed']],['label'=>'Completed On Time','value'=>$counts['on_time']],['label'=>'Completed Late','value'=>$counts['late']],['label'=>'Outstanding Orders','value'=>$counts['outstanding']],['label'=>'Completion Compliance','value'=>$compliance,'format'=>'percent','status'=>$compliance===null?'unmeasured':'provisional'],['label'=>'Average New-to-Complete','value'=>$durations?round(array_sum($durations)/count($durations),1):null,'format'=>'minutes'],['label'=>'Median New-to-Complete','value'=>$median,'format'=>'minutes'],['label'=>'Fastest Completion','value'=>$durations?$durations[0]:null,'format'=>'minutes'],['label'=>'Slowest Completion','value'=>$durations?$durations[count($durations)-1]:null,'format'=>'minutes'],['label'=>'Oldest Outstanding','value'=>$outstandingAges?max($outstandingAges):null,'format'=>'minutes'],['label'=>'Unresolved Historical Attribution','value'=>$counts['unresolved']],['label'=>'Payment/Status Exceptions','value'=>$counts['exceptions']]
    ],'walk_in_rate'=>$walkRate,'non_walk_rate'=>$nonWalkRate,'orders_score'=>$score,'walk_in_weight'=>$walkWeight,'non_walk_weight'=>$nonWalkWeight,'median_minutes'=>$median,'invalid_count'=>0,'review_count'=>$counts['unresolved'],'assisted_count'=>0,'walk_eligible'=>$walkCompleted,'non_walk_eligible'=>$nonWalkCompleted,'counts'=>$counts,'methodology'=>'Walk-in and Front Desk Order Completion: New Order to Complete, attributed first to the authenticated Complete transition. Packed By is fallback evidence only.'];
}
