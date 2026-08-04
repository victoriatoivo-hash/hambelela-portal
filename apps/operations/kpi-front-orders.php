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

function kpi_front_orders_evidence(array $employee, string $fromSql, string $toSql, array $schedule, array $holidays, array $settings, array $leave = []): array
{
    $zone = new DateTimeZone('Africa/Windhoek');
    $employeeId = (int) $employee['id'];
    $createdExpr = ops_order_display_datetime_expr('o');
    $deletedWhere = ops_column_exists('ops_orders', 'deleted_at') ? ' AND o.deleted_at IS NULL' : '';
    $orders = ops_rows("SELECT o.id,'order' timeline_module,o.order_number,o.customer_name,o.customer_contact,o.status,o.payment_status,o.total_amount,COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type) fulfilment_mode,{$createdExpr} authoritative_created_at,o.created_at portal_imported_at,o.packed_at,o.completed_at,o.assigned_packer_id,packer.full_name packed_by_name FROM ops_orders o LEFT JOIN ops_employees packer ON packer.id=o.assigned_packer_id WHERE (({$createdExpr} BETWEEN ? AND ?) OR (o.completed_at BETWEEN ? AND ?)){$deletedWhere} ORDER BY {$createdExpr} DESC,o.id DESC LIMIT 500", [$fromSql,$toSql,$fromSql,$toSql]);
    $eventFrom = (new DateTimeImmutable($fromSql, $zone))->modify('-14 days')->format('Y-m-d H:i:s');
    $eventsByOrder = [];
    foreach (kpi_unified_events($eventFrom, $toSql, null, null, null, null) as $event) {
        if ((string) ($event['record_type'] ?? '') !== 'order' && (string) ($event['section'] ?? '') !== 'orders') continue;
        $eventsByOrder[(int) $event['record_id']][] = $event;
    }
    $holidayMap = array_fill_keys(array_map('strval', $holidays), true);
    $absenceMap = $holidayMap;
    foreach ($leave as $leaveRow) {
        if (empty($leaveRow['start_date']) || empty($leaveRow['end_date'])) continue;
        $leaveDay = new DateTimeImmutable((string) $leaveRow['start_date'], $zone);
        $leaveEnd = new DateTimeImmutable((string) $leaveRow['end_date'], $zone);
        while ($leaveDay <= $leaveEnd) {
            $absenceMap[$leaveDay->format('Y-m-d')] = true;
            $leaveDay = $leaveDay->modify('+1 day');
        }
    }
    $rows = [];$walkEligible=0;$walkCompliant=0;$nonWalkEligible=0;$nonWalkCompliant=0;$durations=[];
    $counts=['walkins_handled'=>0,'walkins_closed'=>0,'orders_finalised'=>0,'within_one_day'=>0,'overdue'=>0,'exceptions'=>0,'invalid'=>0,'review'=>0,'assisted'=>0];
    foreach ($orders as $order) {
        $events = $eventsByOrder[(int) $order['id']] ?? [];
        $inProgressEvent = null;$completionEvent = null;
        foreach ($events as $event) {
            $action = kpi_front_order_normalize((string) ($event['action'] ?? ''));
            $newStatus = kpi_front_order_normalize((string) ($event['new_status'] ?? ''));
            if ($newStatus === 'inprogress' || in_array($action, ['inprogress','orderinprogress'], true)) $inProgressEvent = $event;
            if ($newStatus === 'completed' || in_array($action, ['completed','ordercompleted'], true)) $completionEvent = $event;
        }
        $created = !empty($order['authoritative_created_at']) ? new DateTimeImmutable((string) $order['authoritative_created_at'], $zone) : null;
        $inProgress = $inProgressEvent ? new DateTimeImmutable((string) $inProgressEvent['occurred_at'], $zone) : null;
        $completed = !empty($order['completed_at']) ? new DateTimeImmutable((string) $order['completed_at'], $zone) : ($completionEvent ? new DateTimeImmutable((string) $completionEvent['occurred_at'], $zone) : null);
        $packed = !empty($order['packed_at']) ? new DateTimeImmutable((string) $order['packed_at'], $zone) : null;
        $invalidSequence = !$created || ($completed && $completed < $created) || ($packed && $packed < $created) || ($inProgress && $inProgress < $created) || ($inProgress && $completed && $completed < $inProgress);
        $indicatorValue = trim((string) ($order['customer_contact'] ?: $order['customer_name']));
        $indicator = kpi_front_order_normalize((string) $order['customer_contact'] . ' ' . (string) $order['customer_name']);
        $walkIndicator = strpos($indicator, 'walkin') !== false;
        $mode = kpi_front_order_normalize((string) $order['fulfilment_mode']);
        $collection = $mode === 'collection';
        $walkState = $walkIndicator && $collection ? 'Yes' : ((!$walkIndicator && !$collection) ? 'No' : 'Requires review');
        if ($walkState === 'Requires review') $counts['review']++;
        $paid = kpi_front_order_normalize((string) $order['payment_status']) === 'paid';
        $complete = kpi_front_order_normalize((string) $order['status']) === 'completed' && $completed;
        $day = $created ? $created->format('Y-m-d') : '';
        $onApprovedAbsence = $day !== '' && isset($absenceMap[$day]) && !isset($holidayMap[$day]);
        $shift = $day !== '' && !isset($absenceMap[$day]) ? kpi_front_order_shift_for_day($day, $schedule) : null;
        $sameDayDeadline = $shift ? new DateTimeImmutable($day . ' ' . substr((string) $shift['shift_end'], 0, 8), $zone) : null;
        $deadline = $walkState === 'Yes' ? $sameDayDeadline : ($inProgress ? kpi_front_order_next_deadline($inProgress->format('Y-m-d'), $schedule, $absenceMap) : null);
        $result = 'Timing confidence: Incomplete';$eligible = false;$businessMinutes = null;
        if ($invalidSequence) {$result='Invalid timestamp sequence — Excluded pending review';$counts['invalid']++;}
        elseif ($onApprovedAbsence) {$result='Approved leave — queue ownership requires a recorded substitute';$counts['review']++;}
        elseif ($walkState === 'Requires review') {$result='Walk-in data mismatch — Requires review';}
        elseif ($walkState === 'Yes') {
            $eligible = $created && $sameDayDeadline;
            if (!$order['assigned_packer_id']) {$result='Missing packer';$counts['exceptions']++;}
            elseif ($complete && !$paid) {$result='Complete but unpaid';$counts['exceptions']++;}
            elseif (!$complete && $paid) {$result='Paid but incomplete';$counts['exceptions']++;}
            elseif (!$complete && !$paid) {$result='Incomplete and unpaid';$counts['exceptions']++;}
            elseif ($completed > $sameDayDeadline) {$result='Completed late';$counts['overdue']++;}
            else {$result='Compliant';$walkCompliant++;$counts['walkins_closed']++;}
            if ($eligible) $walkEligible++;
            if ((int) $order['assigned_packer_id'] === $employeeId) $counts['walkins_handled']++;
            if ($created && $completed) $businessMinutes=kpi_business_minutes($created,$completed,$holidays);
        } else {
            $eligible = $inProgress && $deadline;
            if (!$inProgress) $result='In Progress timestamp unavailable — Timing confidence: Incomplete';
            elseif (!$complete && new DateTimeImmutable('now',$zone)>$deadline) {$result='Incomplete — overdue';$counts['overdue']++;}
            elseif (!$complete) $result='Incomplete — within grace period';
            elseif ($completed->format('Y-m-d')===$inProgress->format('Y-m-d')) {$result='Same day';$nonWalkCompliant++;$counts['within_one_day']++;}
            elseif ($completed<=$deadline) {$result='Completed next working day — Within grace period';$nonWalkCompliant++;$counts['within_one_day']++;}
            else {$result='Completed late';$counts['overdue']++;}
            if ($eligible) $nonWalkEligible++;
            if ($inProgress && $completed) $businessMinutes=kpi_business_minutes($inProgress,$completed,$holidays);
        }
        $completedById = (int) ($completionEvent['actor_user_id'] ?? 0);
        $completedByName = (string) ($completionEvent['actor_name'] ?? 'Completion actor unavailable');
        if ($completedById === $employeeId) $counts['orders_finalised']++;
        elseif ($completedById > 0) $counts['assisted']++;
        if ($businessMinutes !== null && !$invalidSequence) $durations[]=$businessMinutes;
        $rows[] = $order + [
            'walk_in'=>$walkState,'mobile_indicator'=>$indicatorValue,'mode'=>ucfirst((string)$order['fulfilment_mode']),
            'created_at'=>$order['authoritative_created_at'],'in_progress_at'=>$inProgressEvent['occurred_at']??null,
            'packed_by'=>$order['packed_by_name']?:'Unassigned','completed_by'=>$completedByName,'completed_by_id'=>$completedById?:null,
            'front_queue_owner'=>$onApprovedAbsence?'Unassigned - approved leave':(string)$employee['full_name'],'deadline'=>$deadline?$deadline->format('Y-m-d H:i:s'):null,
            'result'=>$result,'duration_minutes'=>$businessMinutes,'timestamp_valid'=>!$invalidSequence,
            'handled_by'=>$walkState==='Yes'?($order['packed_by_name']?:'Unassigned'):null,
            'paid_by_deadline'=>$walkState==='Yes'&&$paid&&$completed&&$sameDayDeadline&&$completed<=$sameDayDeadline?'Yes':'No',
            'completed_by_deadline'=>$walkState==='Yes'&&$completed&&$sameDayDeadline&&$completed<=$sameDayDeadline?'Yes':'No',
            'assistance_indicator'=>$completedById>0&&$completedById!==$employeeId?'Completed by another employee - substitution not recorded':'',
            'evidence_count'=>count($events),
        ];
    }
    sort($durations,SORT_NUMERIC);$median=null;if($durations){$mid=intdiv(count($durations),2);$median=count($durations)%2?$durations[$mid]:($durations[$mid-1]+$durations[$mid])/2;}
    $walkRate=$walkEligible?round(100*$walkCompliant/$walkEligible,1):null;$nonWalkRate=$nonWalkEligible?round(100*$nonWalkCompliant/$nonWalkEligible,1):null;
    $walkWeight=max(0,(float)($settings['front_orders_walkin_weight']??50));$nonWalkWeight=max(0,(float)($settings['front_orders_nonwalk_weight']??50));
    $weightedTotal=0.0;$availableWeight=0.0;if($walkRate!==null){$weightedTotal+=$walkRate*$walkWeight;$availableWeight+=$walkWeight;}if($nonWalkRate!==null){$weightedTotal+=$nonWalkRate*$nonWalkWeight;$availableWeight+=$nonWalkWeight;}$ordersScore=$availableWeight>0?round($weightedTotal/$availableWeight,1):null;
    return ['rows'=>$rows,'metrics'=>[
        ['label'=>'Walk-ins Handled','value'=>$counts['walkins_handled']],['label'=>'Walk-ins Closed Correctly','value'=>$counts['walkins_closed']],['label'=>'Walk-in Compliance','value'=>$walkRate,'format'=>'percent','status'=>$walkRate===null?'unmeasured':'provisional'],
        ['label'=>'Orders Finalised','value'=>$counts['orders_finalised']],['label'=>'Finalised Within One Working Day','value'=>$counts['within_one_day']],['label'=>'Average Finalisation Time','value'=>$durations?round(array_sum($durations)/count($durations),1):null,'format'=>'minutes'],
        ['label'=>'Overdue Orders','value'=>$counts['overdue']],['label'=>'Payment/Status Exceptions','value'=>$counts['exceptions']],
    ],'walk_in_rate'=>$walkRate,'non_walk_rate'=>$nonWalkRate,'orders_score'=>$ordersScore,'walk_in_weight'=>$walkWeight,'non_walk_weight'=>$nonWalkWeight,'median_minutes'=>$median,'invalid_count'=>$counts['invalid'],'review_count'=>$counts['review'],'assisted_count'=>$counts['assisted'],'walk_eligible'=>$walkEligible,'non_walk_eligible'=>$nonWalkEligible];
}
