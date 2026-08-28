<?php

declare(strict_types=1);

function kpi_front_order_normalize(string $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?? '';
}

function kpi_front_orders_dashboard_base(array $employee, string $fromSql, string $toSql, array $schedule, array $holidays, array $settings, array $leave = []): array
{
    $base = kpi_front_orders_evidence($employee, $fromSql, $toSql, $schedule, $holidays, $settings, $leave);
    $zone = new DateTimeZone('Africa/Windhoek'); $now = new DateTimeImmutable('now', $zone); $eventsByOrder=$base['_events_by_order']??[];unset($base['_events_by_order']);
    $risks=['pending'=>0,'overdue'=>0,'paid_status_exceptions'=>0,'reopened'=>0,'conflicting_completion_actors'=>0,'unclear_responsibility'=>0];$pendingBreakdown=['new'=>0,'in_progress_ready'=>0,'other'=>0];$modeCounts=[];$modePerformance=[];$paymentExceptions=[];
    foreach($base['rows'] as $index=>$row){$events=$eventsByOrder[(int)$row['id']]??[];$walkIn=(string)$row['walk_in']==='Yes';$created=!empty($row['new_order_at'])?new DateTimeImmutable((string)$row['new_order_at'],$zone):null;$readyEvent=kpi_front_order_ready_event($events,$created,$zone);$readyAt=!empty($row['ready_at'])?new DateTimeImmutable((string)$row['ready_at'],$zone):($readyEvent&&!empty($readyEvent['occurred_at'])?new DateTimeImmutable((string)$readyEvent['occurred_at'],$zone):null);$completed=!empty($row['completed_at'])?new DateTimeImmutable((string)$row['completed_at'],$zone):null;$clockStart=!empty($row['clock_start'])?new DateTimeImmutable((string)$row['clock_start'],$zone):($walkIn?$created:$readyAt);$duration=array_key_exists('duration_minutes',$row)?$row['duration_minutes']:($clockStart&&$completed&&$completed>=$clockStart?kpi_business_minutes($clockStart,$completed,$holidays):null);
        $base['rows'][$index]['ready_at']=$readyAt?$readyAt->format('Y-m-d H:i:s'):null;$base['rows'][$index]['clock_start']=$clockStart?$clockStart->format('Y-m-d H:i:s'):null;$base['rows'][$index]['clock_basis']=$row['clock_basis']??($walkIn?'New → Complete':'Ready/In Progress → Complete');$base['rows'][$index]['duration_minutes']=$duration;$base['rows'][$index]['pending_age_minutes']=$clockStart&&!$completed?kpi_business_minutes($clockStart,$now,$holidays):null;
        if(!$walkIn&&!$readyAt){$base['rows'][$index]['unresolved_attribution']=true;$base['rows'][$index]['result']='Orders With Unclear Historical Responsibility';$base['rows'][$index]['attribution_source']='Ready/In Progress transition not recorded';}elseif(!empty($base['rows'][$index]['unresolved_attribution']))$base['rows'][$index]['result']='Orders With Unclear Historical Responsibility';
        $complete=in_array(kpi_front_order_normalize((string)$row['status']),['complete','completed'],true)||$completed!==null;$paid=kpi_front_order_normalize((string)($row['payment_status']??''))==='paid';$exception=$row['exception_type']??null;$base['rows'][$index]['paid_status']=$paid?'Paid':'Not paid';$base['rows'][$index]['exception_type']=$exception;if($exception){$risks['paid_status_exceptions']++;$paymentExceptions[]=$base['rows'][$index];}
        if(!$completed){$risks['pending']++;$status=kpi_front_order_normalize((string)$row['status']);if($status==='new')$pendingBreakdown['new']++;elseif(in_array($status,['inprogress','ready','packed'],true))$pendingBreakdown['in_progress_ready']++;else$pendingBreakdown['other']++;if(strpos((string)$row['result'],'overdue')!==false)$risks['overdue']++;}if(!empty($base['rows'][$index]['unresolved_attribution']))$risks['unclear_responsibility']++;
        $actors=[];$seenComplete=false;foreach($events as$event){$new=kpi_front_order_normalize((string)($event['new_status']??''));if(in_array($new,['complete','completed'],true)){$seenComplete=true;if((int)($event['actor_user_id']??0)>0)$actors[(int)$event['actor_user_id']]=true;}elseif($seenComplete&&$new!==''&&!in_array($new,['complete','completed'],true))$risks['reopened']++;}if(count($actors)>1)$risks['conflicting_completion_actors']++;
        $mode=trim((string)($row['mode']??''))?:'Unspecified';$modeCounts[$mode]=($modeCounts[$mode]??0)+1;if(!isset($modePerformance[$mode]))$modePerformance[$mode]=['mode'=>$mode,'on_time'=>0,'late'=>0,'pending'=>0];if($base['rows'][$index]['result']==='Completed on time')$modePerformance[$mode]['on_time']++;elseif($base['rows'][$index]['result']==='Completed late')$modePerformance[$mode]['late']++;elseif(!$completed)$modePerformance[$mode]['pending']++;}
    $walk=kpi_front_order_stats($base['rows'],'Walk-in');$other=kpi_front_order_stats($base['rows'],'Other Front Desk');$onTime=$walk['on_time']+$other['on_time'];$late=$walk['late']+$other['late'];$denominator=$onTime+$late;$compliance=$denominator?round(100*$onTime/$denominator,1):null;$walkWeight=max(0,(float)($settings['front_orders_walkin_weight']??50));$otherWeight=max(0,(float)($settings['front_orders_nonwalk_weight']??50));$weighted=0.0;$available=0.0;if($walk['compliance_rate']!==null){$weighted+=$walk['compliance_rate']*$walkWeight;$available+=$walkWeight;}if($other['compliance_rate']!==null){$weighted+=$other['compliance_rate']*$otherWeight;$available+=$otherWeight;}$score=$available?round($weighted/$available,1):null;arsort($modeCounts);$modeMix=[];$totalModes=array_sum($modeCounts);foreach($modeCounts as$mode=>$count)$modeMix[]=['mode'=>$mode,'count'=>$count,'share'=>$totalModes?round(100*$count/$totalModes,1):0];
    $base['walk_in_rate']=$walk['compliance_rate'];$base['non_walk_rate']=$other['compliance_rate'];$base['walk_eligible']=$walk['compliance_denominator'];$base['non_walk_eligible']=$other['compliance_denominator'];$base['orders_score']=$score;$base['duty_analysis']=['walk_in'=>$walk,'other_front_desk'=>$other,'overall'=>['total'=>$walk['total']+$other['total'],'completed'=>$walk['completed']+$other['completed'],'on_time'=>$onTime,'late'=>$late,'pending'=>$walk['pending']+$other['pending'],'compliance_rate'=>$compliance,'compliance_numerator'=>$onTime,'compliance_denominator'=>$denominator]];$base['pending_breakdown']=$pendingBreakdown;$base['mode_mix']=$modeMix;$base['mode_performance']=array_values($modePerformance);$base['payment_exceptions']=$paymentExceptions;$base['risk_flags']=$risks;$base['review_count']=$risks['unclear_responsibility'];$base['counts']=array_merge($base['counts'],$risks);$base['methodology']='Delivery waiting time is paused until Paid is recorded, then the business-hours close window begins. Collection waiting time is external and excluded from employee lateness; Complete confirms collection and payment. Closed hours and Sundays do not count.';return $base;
}

function kpi_front_orders_dashboard(array $employee, string $fromSql, string $toSql, array $schedule, array $holidays, array $settings, array $leave = []): array
{
    $payload = kpi_front_orders_dashboard_base($employee, $fromSql, $toSql, $schedule, $holidays, $settings, $leave);
    $payload['duty_analysis']['overall'] = kpi_front_order_stats($payload['rows'] ?? [], '*');
    return $payload;
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

function kpi_front_order_ready_event(array $events, ?DateTimeImmutable $created, DateTimeZone $zone): ?array
{
    usort($events, static fn(array $a, array $b): int => strcmp((string) ($a['occurred_at'] ?? ''), (string) ($b['occurred_at'] ?? '')));
    foreach ($events as $event) {
        $status = kpi_front_order_normalize((string) ($event['new_status'] ?? ''));
        $action = kpi_front_order_normalize((string) ($event['action'] ?? ''));
        if (!in_array($status, ['inprogress','ready','readyforcompletion','packed'], true)
            && !in_array($action, ['inprogress','ready','readyforcompletion','orderinprogress','orderready','packed'], true)) continue;
        if (empty($event['occurred_at'])) continue;
        $at = new DateTimeImmutable((string) $event['occurred_at'], $zone);
        if (!$created || $at >= $created) return $event;
    }
    return null;
}

function kpi_front_order_stats(array $rows, string $category): array
{
    $eligible = array_values(array_filter($rows, static fn(array $row): bool => ($category === '*' || $row['front_desk_category'] === $category) && !$row['unresolved_attribution']));
    $completed = array_values(array_filter($eligible, static fn(array $row): bool => !empty($row['completed_at'])));
    $durations = array_values(array_filter(array_map(static fn(array $row) => $row['duration_minutes'], $completed), static fn($value): bool => $value !== null));
    sort($durations, SORT_NUMERIC); $mid = intdiv(count($durations), 2);
    $median = !$durations ? null : (count($durations) % 2 ? $durations[$mid] : ($durations[$mid - 1] + $durations[$mid]) / 2);
    $onTime = count(array_filter($completed, static fn(array $row): bool => $row['result'] === 'Completed on time'));
    $late = count(array_filter($completed, static fn(array $row): bool => $row['result'] === 'Completed late'));
    $pending = array_values(array_filter($eligible, static fn(array $row): bool => empty($row['completed_at'])));
    usort($pending, static fn(array $a, array $b): int => (float) ($b['pending_age_minutes'] ?? 0) <=> (float) ($a['pending_age_minutes'] ?? 0));
    return ['total'=>count($eligible),'completed'=>count($completed),'on_time'=>$onTime,'late'=>$late,'pending'=>count($pending),'average_minutes'=>$durations?round(array_sum($durations)/count($durations),1):null,'median_minutes'=>$median,'fastest_minutes'=>$durations?$durations[0]:null,'slowest_minutes'=>$durations?$durations[count($durations)-1]:null,'oldest_pending_minutes'=>$pending?($pending[0]['pending_age_minutes']??null):null,'compliance_rate'=>($onTime+$late)>0?round(100*$onTime/($onTime+$late),1):null,'compliance_numerator'=>$onTime,'compliance_denominator'=>$onTime+$late];
}

function kpi_front_orders_evidence(array $employee, string $fromSql, string $toSql, array $schedule, array $holidays, array $settings, array $leave = []): array
{
    $zone = new DateTimeZone('Africa/Windhoek');$employeeId = (int) $employee['id'];$createdExpr = ops_order_display_datetime_expr('o');
    if (!$schedule) {
        for ($weekday=1;$weekday<=5;$weekday++) $schedule[$weekday]=['weekday'=>$weekday,'is_working'=>1,'shift_start'=>$settings['default_shift_start']??'08:00','shift_end'=>$settings['default_shift_end']??'17:00'];
        $schedule[6]=['weekday'=>6,'is_working'=>1,'shift_start'=>$settings['front_orders_saturday_open']??'09:00','shift_end'=>$settings['front_orders_saturday_close']??'13:00'];
    }
    $deletedWhere = ops_column_exists('ops_orders', 'deleted_at') ? ' AND o.deleted_at IS NULL' : '';
    $paidTimeColumns=[];foreach(['portal_paid_decided_at','paid_updated_at','payment_updated_at']as$column)if(ops_column_exists('ops_orders',$column))$paidTimeColumns[]='o.'.$column;$paidTimeExpr=$paidTimeColumns?'COALESCE('.implode(',',$paidTimeColumns).')':'NULL';
    $paidByColumns=[];foreach(['portal_paid_decided_by_employee_id','paid_updated_by_employee_id','payment_updated_by_employee_id']as$column)if(ops_column_exists('ops_orders',$column))$paidByColumns[]='o.'.$column;$paidByExpr=$paidByColumns?'COALESCE('.implode(',',$paidByColumns).')':'NULL';
    $orders = ops_rows("SELECT o.id,'order' timeline_module,o.order_number,o.customer_name,o.customer_contact,o.order_type,o.status,o.payment_status,o.total_amount,COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type) fulfilment_mode,{$createdExpr} authoritative_created_at,o.created_at portal_imported_at,o.packed_at,o.completed_at,o.assigned_packer_id,{$paidTimeExpr} payment_recorded_at,{$paidByExpr} payment_recorded_by_employee_id,packer.full_name packed_by_name FROM ops_orders o LEFT JOIN ops_employees packer ON packer.id=o.assigned_packer_id WHERE (({$createdExpr} BETWEEN ? AND ?) OR (o.completed_at BETWEEN ? AND ?)){$deletedWhere} ORDER BY {$createdExpr} DESC,o.id DESC LIMIT 2000", [$fromSql,$toSql,$fromSql,$toSql]);
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
    $aliases=kpi_front_order_walk_in_aliases($settings);$graceMinutes=max(0,(int)($settings['front_orders_walkin_grace_minutes']??0));$walkInTarget=max(1,(int)($settings['front_orders_walkin_completion_minutes']??120));$now=new DateTimeImmutable('now',$zone);
    $rows=[];$durations=[];$outstandingAges=[];$counts=['walk_in'=>0,'front_desk_other'=>0,'completed'=>0,'on_time'=>0,'late'=>0,'outstanding'=>0,'outstanding_overdue'=>0,'exceptions'=>0,'unresolved'=>0,'packer_only_removed'=>0];
    foreach ($orders as $order) {
        $created=!empty($order['authoritative_created_at'])?new DateTimeImmutable((string)$order['authoritative_created_at'],$zone):null;$events=$eventsByOrder[(int)$order['id']]??[];$completionEvent=kpi_front_order_completion_event($events,$created,$zone);
        $completedById=(int)($completionEvent['actor_user_id']??0);$completedByName=trim((string)($completionEvent['actor_name']??''));
        $completed=$completionEvent&&!empty($completionEvent['occurred_at'])?new DateTimeImmutable((string)$completionEvent['occurred_at'],$zone):(!empty($order['completed_at'])?new DateTimeImmutable((string)$order['completed_at'],$zone):null);
        $walkIn=kpi_front_order_is_walk_in($order,$aliases);$packedByEmployee=(int)($order['assigned_packer_id']??0)===$employeeId;$completedByEmployee=$completedById===$employeeId;$currentCompleted=in_array(kpi_front_order_normalize((string)$order['status']),['completed','complete'],true)||$completed!==null;
        $attribution='';$unresolved=false;
        if($walkIn&&$packedByEmployee)$attribution='Walk-in responsibility — assigned employee on Orders';
        elseif($completedByEmployee)$attribution='Status-change Activity Log — Complete transition';
        else{$counts['packer_only_removed']++;continue;}
        $readyEvent=kpi_front_order_ready_event($events,$created,$zone);$readyAt=$readyEvent&&!empty($readyEvent['occurred_at'])?new DateTimeImmutable((string)$readyEvent['occurred_at'],$zone):null;$paidEvent=null;foreach($events as$event){if(kpi_front_order_normalize((string)($event['action']??''))==='paymentstatusupdated'&&kpi_front_order_normalize((string)($event['new_status']??''))==='paid'){$paidEvent=$event;break;}}$paid=kpi_front_order_normalize((string)$order['payment_status'])==='paid';$paidAtRaw=$order['payment_recorded_at']??($paidEvent['occurred_at']??null);$paidAt=$paidAtRaw?new DateTimeImmutable((string)$paidAtRaw,$zone):null;$paidBy=(string)($paidEvent['actor_name']??(($order['payment_recorded_by_employee_id']??null)?'Employee #'.(int)$order['payment_recorded_by_employee_id']:'Not recorded'));
        $modeKey=kpi_front_order_normalize((string)$order['fulfilment_mode']);$delivery=strpos($modeKey,'delivery')!==false;$collection=strpos($modeKey,'collection')!==false;$closeTarget=max(1,(int)($settings['front_orders_close_after_payment_minutes']??120));$deadline=$created?kpi_front_order_due_at($created,$schedule,$holidayMap,$graceMinutes):null;$clockStart=$created;$clockBasis='New → Complete';$invalid=!$created||($completed&&$completed<$created);$duration=!$invalid&&$created&&$completed?kpi_business_minutes($created,$completed,$holidays):null;$exception=null;
        $statusBefore=(string)($completionEvent['previous_status']??$completionEvent['old_status']??'New');$statusAfter=(string)($completionEvent['new_status']??($currentCompleted?'Complete':(string)$order['status']));$result='Outstanding';
        if($invalid){$result='Invalid timestamp sequence — Requires review';$unresolved=true;}
        elseif($walkIn){$clockBasis='New → Complete (business hours)';$clockStart=$created;$duration=$created&&$completed&&$completed>=$created?kpi_business_minutes($created,$completed,$holidays):null;$deadline=null;if($currentCompleted&&$duration!==null&&$duration<=$walkInTarget){$result='Completed on time';$counts['on_time']++;}elseif($currentCompleted&&$duration!==null){$result='Completed late';$counts['late']++;}else{$elapsed=$created?kpi_business_minutes($created,$now,$holidays):0;$result=$elapsed>$walkInTarget?'Walk-in — completion overdue':'Walk-in — awaiting completion';if($elapsed>$walkInTarget)$counts['outstanding_overdue']++;}}
        elseif($collection){$clockBasis='External wait — customer collection';$duration=null;$clockStart=$created;if($currentCompleted&&!$paid){$result='Complete but not paid — Requires review';$exception='Complete but not paid';}elseif($currentCompleted){$result='Completed — collection and payment confirmed';}else{$customerWaitHours=$created?($now->getTimestamp()-$created->getTimestamp())/3600:0;$result=$customerWaitHours>=72?'Awaiting customer collection — cancellation review':'Awaiting customer collection';}}
        elseif($delivery){$clockBasis='Paid confirmed → Complete';$clockStart=$paidAt;$duration=$paidAt&&$completed&&$completed>=$paidAt?kpi_business_minutes($paidAt,$completed,$holidays):null;if(!$paid){$result=$currentCompleted?'Complete but not paid — Requires review':'Awaiting driver payment';if($currentCompleted)$exception='Complete but not paid';}elseif(!$paidAt){$result='Paid timestamp unavailable — Requires review';$unresolved=true;}elseif($currentCompleted&&$completed&&$completed<$paidAt){$result='Complete recorded before payment — Requires review';$exception='Complete recorded before payment';}elseif($currentCompleted&&$duration!==null&&$duration<=$closeTarget){$result='Completed on time';$counts['on_time']++;}elseif($currentCompleted&&$duration!==null){$result='Completed late';$counts['late']++;}else{$elapsed=kpi_business_minutes($paidAt,$now,$holidays);$result=$elapsed>$closeTarget?'Paid — completion overdue':'Paid — awaiting completion';if($elapsed>$closeTarget)$counts['outstanding_overdue']++;}}
        elseif($unresolved)$result='Unresolved Historical Attribution';
        elseif($completed&&$deadline&&$completed<=$deadline){$result='Completed on time';$counts['on_time']++;}
        elseif($completed&&$deadline){$result='Completed late';$counts['late']++;}
        elseif($completed){$result='Due timestamp unavailable — Requires review';$unresolved=true;}
        elseif($deadline&&$now>$deadline){$result='Outstanding — overdue';$counts['outstanding_overdue']++;}
        else$result='Outstanding — within due time';
        if($walkIn)$counts['walk_in']++;else$counts['front_desk_other']++;if($currentCompleted)$counts['completed']++;else{$counts['outstanding']++;if($created)$outstandingAges[]=kpi_business_minutes($created,$now,$holidays);}if($unresolved)$counts['unresolved']++;if(!$unresolved&&$duration!==null)$durations[]=$duration;
        if($exception)$counts['exceptions']++;
        $rows[]=$order+['walk_in'=>$walkIn?'Yes':'No','walk_in_identifier'=>$walkIn?trim((string)($order['customer_contact']?:$order['customer_name']?:$order['order_type'])):'—','mode'=>ucfirst((string)$order['fulfilment_mode']),'packed_by'=>$order['packed_by_name']?:'Unassigned','status_before'=>$statusBefore?:'New','status_after'=>$statusAfter?:'Complete','new_order_at'=>$order['authoritative_created_at'],'ready_at'=>$readyAt?$readyAt->format('Y-m-d H:i:s'):null,'payment_recorded_at'=>$paidAt?$paidAt->format('Y-m-d H:i:s'):null,'payment_recorded_by'=>$paidBy,'completed_at'=>$completed?$completed->format('Y-m-d H:i:s'):null,'completed_by'=>$completedByName!==''?$completedByName:'Not recorded','clock_start'=>$clockStart?$clockStart->format('Y-m-d H:i:s'):null,'clock_basis'=>$clockBasis,'duration_minutes'=>$duration,'deadline'=>$deadline?$deadline->format('Y-m-d H:i:s'):null,'result'=>$result,'exception_type'=>$exception,'attribution_source'=>$attribution,'evidence_id'=>$completionEvent?((string)($completionEvent['source_log']??'activity_log').' #'.(string)($completionEvent['source_event_id']??'')):'Current order record','evidence_count'=>count($events),'unresolved_attribution'=>$unresolved,'front_desk_category'=>$walkIn?'Walk-in':'Other Front Desk'];
    }
    sort($durations,SORT_NUMERIC);$median=null;if($durations){$mid=intdiv(count($durations),2);$median=count($durations)%2?$durations[$mid]:($durations[$mid-1]+$durations[$mid])/2;}$completedEligible=$counts['on_time']+$counts['late'];$compliance=$completedEligible?round(100*$counts['on_time']/$completedEligible,1):null;
    $walkCompleted=count(array_filter($rows,static function(array$row):bool{return$row['walk_in']==='Yes'&&!$row['unresolved_attribution']&&!empty($row['completed_at']);}));$walkOnTime=count(array_filter($rows,static function(array$row):bool{return$row['walk_in']==='Yes'&&$row['result']==='Completed on time';}));$nonWalkCompleted=count(array_filter($rows,static function(array$row):bool{return$row['walk_in']==='No'&&!$row['unresolved_attribution']&&!empty($row['completed_at']);}));$nonWalkOnTime=count(array_filter($rows,static function(array$row):bool{return$row['walk_in']==='No'&&$row['result']==='Completed on time';}));
    $walkRate=$walkCompleted?round(100*$walkOnTime/$walkCompleted,1):null;$nonWalkRate=$nonWalkCompleted?round(100*$nonWalkOnTime/$nonWalkCompleted,1):null;$walkWeight=max(0,(float)($settings['front_orders_walkin_weight']??50));$nonWalkWeight=max(0,(float)($settings['front_orders_nonwalk_weight']??50));$weighted=0.0;$available=0.0;if($walkRate!==null){$weighted+=$walkRate*$walkWeight;$available+=$walkWeight;}if($nonWalkRate!==null){$weighted+=$nonWalkRate*$nonWalkWeight;$available+=$nonWalkWeight;}$score=$available?round($weighted/$available,1):null;
    return ['rows'=>$rows,'metrics'=>[
        ['label'=>'Front Desk Orders in Scope','value'=>count($rows),'explanation'=>$counts['walk_in'].' walk-ins assigned + '.$counts['front_desk_other'].' other orders completed by this employee.'],['label'=>'Walk-ins Assigned','value'=>$counts['walk_in'],'explanation'=>'Orders identified by the configured walk-in marker and assigned to this employee on the Orders page.'],['label'=>'Other Orders Completed','value'=>$counts['front_desk_other'],'explanation'=>'Non-walk-in orders whose recorded Complete transition was performed by this employee.'],['label'=>'Completed Orders in Scope','value'=>$counts['completed'],'explanation'=>'In-scope orders whose authoritative current status or completion evidence is Complete.'],['label'=>'Completed On Time','value'=>$counts['on_time']],['label'=>'Completed Late','value'=>$counts['late']],['label'=>'Orders Still Pending Completion','value'=>$counts['outstanding']],['label'=>'Completion Compliance','value'=>$compliance,'format'=>'percent','status'=>$compliance===null?'unmeasured':'provisional','numerator'=>$counts['on_time'],'denominator'=>$completedEligible,'explanation'=>$counts['on_time'].' on time of '.$completedEligible.' completed orders with usable deadline evidence.'],['label'=>'Unclear Historical Responsibility','value'=>$counts['unresolved']],['label'=>'Paid and Status Exceptions','value'=>$counts['exceptions'],'explanation'=>'Complete without Paid, Complete before the recorded payment, or missing payment audit evidence.']
    ],'walk_in_rate'=>$walkRate,'non_walk_rate'=>$nonWalkRate,'orders_score'=>$score,'walk_in_weight'=>$walkWeight,'non_walk_weight'=>$nonWalkWeight,'median_minutes'=>$median,'invalid_count'=>0,'review_count'=>$counts['unresolved'],'assisted_count'=>0,'walk_eligible'=>$walkCompleted,'non_walk_eligible'=>$nonWalkCompleted,'counts'=>$counts,'methodology'=>'Walk-ins are reconciled from the configured walk-in marker and the employee assigned on Orders, with a two-business-hour New-to-Complete target. Closed hours, Sundays and configured holidays are excluded. Other Front Desk work requires the employee-authenticated Complete transition. Every widget uses this same in-scope order set.','_events_by_order'=>$eventsByOrder];
}

/**
 * Evidence-first Front Desk report. Walk-ins use Loaded -> Complete; ordinary
 * orders enter Front Desk responsibility only at their first Ready/In Progress
 * transition and use Ready -> Complete. Unclear historical attribution is kept
 * visible but excluded from timing and score.
 */
function kpi_front_orders_report(array $employee, string $fromSql, string $toSql, array $schedule, array $settings): array
{
    $zone=new DateTimeZone('Africa/Windhoek');$employeeId=(int)$employee['id'];$createdExpr=ops_order_display_datetime_expr('o');
    if(!$schedule){for($day=1;$day<=5;$day++)$schedule[$day]=['is_working'=>1,'shift_start'=>'08:00','shift_end'=>'17:00'];$schedule[6]=['is_working'=>1,'shift_start'=>'09:00','shift_end'=>'13:00'];}
    $holidayMap=[];if(ops_table_exists('epi_employee_business_calendar'))foreach(ops_rows("SELECT business_date FROM epi_employee_business_calendar WHERE business_date BETWEEN DATE_SUB(?,INTERVAL 2 DAY) AND DATE_ADD(?,INTERVAL 14 DAY) AND is_working_day=0",[substr($fromSql,0,10),substr($toSql,0,10)])as$closed)$holidayMap[(string)$closed['business_date']]=true;
    $paidAt=ops_column_exists('ops_orders','paid_updated_at')?'o.paid_updated_at':'NULL';$paidBy=ops_column_exists('ops_orders','paid_updated_by_employee_id')?'o.paid_updated_by_employee_id':'NULL';$deleted=ops_column_exists('ops_orders','deleted_at')?' AND o.deleted_at IS NULL':'';
    $orders=ops_rows("SELECT o.id,o.order_number,o.customer_name,o.customer_contact,o.status,o.payment_status,o.total_amount,o.assigned_packer_id,{$createdExpr} loaded_at,LOWER(TRIM(COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type,''))) stored_mode,{$paidAt} paid_at,{$paidBy} paid_by FROM ops_orders o WHERE {$createdExpr} BETWEEN ? AND ?{$deleted} ORDER BY {$createdExpr},o.id",[$fromSql,$toSql]);
    $employeeNames=[];foreach(ops_rows("SELECT id,full_name FROM ops_employees")as$person)$employeeNames[(int)$person['id']]=(string)$person['full_name'];
    $events=[];foreach(kpi_unified_events((new DateTimeImmutable($fromSql,$zone))->modify('-14 days')->format('Y-m-d H:i:s'),$toSql,null,'orders')as$event)$events[(int)($event['record_id']??0)][]=$event;
    $aliases=kpi_front_order_walk_in_aliases($settings);$actualNow=new DateTimeImmutable('now',$zone);$periodEnd=new DateTimeImmutable($toSql,$zone);$now=$actualNow<$periodEnd?$actualNow:$periodEnd;
    $groups=['walk_in'=>[],'other'=>[]];$rows=[];$unclear=[];$exceptions=[];$risks=[];$modes=[];$statusBreak=['still_new'=>0,'ready'=>0,'other'=>0];
    foreach($orders as$order){$id=(int)$order['id'];$loaded=new DateTimeImmutable((string)$order['loaded_at'],$zone);$isWalk=kpi_front_order_is_walk_in($order,$aliases);$orderEvents=$events[$id]??[];usort($orderEvents,static fn(array$a,array$b):int=>strcmp((string)$a['occurred_at'],(string)$b['occurred_at']));$readyEvent=null;$completeEvent=null;$paidEvent=null;
        foreach($orderEvents as$event){$status=kpi_front_order_normalize((string)($event['new_status']?:$event['action']));$action=kpi_front_order_normalize((string)($event['action']??''));if(!$readyEvent&&in_array($status,['inprogress','ready'],true))$readyEvent=$event;if(!$completeEvent&&in_array($status,['complete','completed'],true))$completeEvent=$event;if(!$paidEvent&&$action==='paymentstatusupdated'&&kpi_front_order_normalize((string)($event['new_status']??''))==='paid')$paidEvent=$event;}
        $current=kpi_front_order_normalize((string)$order['status']);$complete=in_array($current,['complete','completed','packed','verified'],true);$stageStart=$isWalk?$loaded:($readyEvent&&!empty($readyEvent['occurred_at'])?new DateTimeImmutable((string)$readyEvent['occurred_at'],$zone):null);
        $applicable=$isWalk||$stageStart!==null||in_array($current,['inprogress','ready'],true);if(!$applicable)continue;
        $completedAt=$completeEvent&&!empty($completeEvent['occurred_at'])?new DateTimeImmutable((string)$completeEvent['occurred_at'],$zone):null;$completedBy=(int)($completeEvent['actor_user_id']??0);$attributionClear=$completedAt&&$completedBy===$employeeId;$attributionIssue='';
        if($complete&&!$completeEvent)$attributionIssue='Complete status exists but no attributable Complete transition was found.';elseif($completeEvent&&$completedBy<=0)$attributionIssue='Complete transition has no employee attribution.';elseif($completeEvent&&$completedBy!==$employeeId)$attributionIssue='Complete transition belongs to '.($completeEvent['actor_name']??'another employee').'.';
        $deadline=$stageStart?kpi_front_order_due_at($stageStart,$schedule,$holidayMap,max(0,(int)($settings['front_orders_walkin_grace_minutes']??0))):null;$duration=$stageStart&&$completedAt&&$completedAt>=$stageStart?kpi_business_minutes($stageStart,$completedAt,array_keys($holidayMap)):null;$onTime=$attributionClear&&$deadline&&$completedAt<=$deadline;$late=$attributionClear&&$deadline&&$completedAt>$deadline;$pending=!$complete;$pendingOverdue=$pending&&$deadline&&$now>$deadline;$age=$stageStart?kpi_business_minutes($stageStart,$now,array_keys($holidayMap)):null;
        $mode=strpos((string)$order['stored_mode'],'courier')!==false?'courier':((string)$order['stored_mode']==='delivery'?'delivery':((string)$order['stored_mode']==='collection'?'collection':'other'));$paid=kpi_front_order_normalize((string)$order['payment_status'])==='paid';$paidAt=$order['paid_at']?:($paidEvent['occurred_at']??null);$paidById=(int)($order['paid_by']??($paidEvent['actor_user_id']??0));$exception='';if($complete&&!$paid)$exception='Complete but Paid is not ticked';elseif($paid&&!$complete)$exception='Paid is ticked but status is unfinished';elseif($paid&&(!$paidAt||$paidById<=0))$exception='Paid attribution or timestamp is missing';
        $record=['record_id'=>$id,'order_number'=>$order['order_number'],'customer'=>$order['customer_name'],'mode'=>$mode,'current_status'=>$order['status'],'packed_by'=>$employeeNames[(int)$order['assigned_packer_id']]??'Unassigned','completed_by'=>$completeEvent['actor_name']??'Not recorded','loaded_at'=>$loaded->format('Y-m-d H:i:s'),'ready_at'=>$readyEvent['occurred_at']??null,'completed_at'=>$completedAt?$completedAt->format('Y-m-d H:i:s'):null,'due_at'=>$deadline?$deadline->format('Y-m-d H:i:s'):null,'duration_minutes'=>$duration,'pending_age_minutes'=>$pending?$age:null,'on_time'=>$onTime,'late'=>$late,'pending'=>$pending,'pending_overdue'=>$pendingOverdue,'attribution_clear'=>$attributionClear,'attribution_issue'=>$attributionIssue,'paid'=>$paid,'paid_at'=>$paidAt,'paid_by'=>$employeeNames[$paidById]??'Not recorded','exception_reason'=>$exception,'responsible_stage'=>$isWalk?'Walk-in: New to Complete':'Front Desk: Ready/In Progress to Complete','evidence_url'=>'orders-board.php?order_id='.$id];$rows[]=$record;$groups[$isWalk?'walk_in':'other'][]=$record;if($mode!=='other')$modes[$mode][]=$record;
        if($attributionIssue!=='')$unclear[]=$record+['score_treatment'=>'Excluded from personal timing and score'];if($exception!=='')$exceptions[]=$record;
        if($pending){if(in_array($current,['new','neworder','pending'],true))$statusBreak['still_new']++;elseif(in_array($current,['inprogress','ready'],true))$statusBreak['ready']++;else$statusBreak['other']++;$riskLevel=$pendingOverdue?'critical':'warning';$risks[]=$record+['risk_level'=>$riskLevel,'reason'=>$pendingOverdue?'Order remains unfinished after its Front Desk deadline':'Order is still pending completion'];}
        if($exception!=='')$risks[]=$record+['risk_level'=>$complete&&!$paid?'urgent':'warning','reason'=>$exception];if($attributionIssue!=='')$risks[]=$record+['risk_level'=>'information','reason'=>$attributionIssue];
    }
    $summarise=static function(array$source):array{$completed=array_values(array_filter($source,static fn(array$x):bool=>$x['attribution_clear']));$on=array_values(array_filter($completed,static fn(array$x):bool=>$x['on_time']));$late=array_values(array_filter($completed,static fn(array$x):bool=>$x['late']));$pending=array_values(array_filter($source,static fn(array$x):bool=>$x['pending']));$duePending=array_values(array_filter($pending,static fn(array$x):bool=>$x['pending_overdue']));$dur=array_values(array_filter(array_column($completed,'duration_minutes'),static fn($x):bool=>$x!==null));sort($dur,SORT_NUMERIC);$n=count($dur);$median=$n?($n%2?$dur[intdiv($n,2)]:($dur[$n/2-1]+$dur[$n/2])/2):null;$eligible=count($on)+count($late)+count($duePending);return ['total'=>count($source),'completed'=>count($completed),'on_time'=>count($on),'late'=>count($late),'pending'=>count($pending),'due_pending'=>count($duePending),'eligible'=>$eligible,'excluded'=>count($source)-$eligible,'compliance_percent'=>$eligible?round(100*count($on)/$eligible,1):null,'average_minutes'=>$n?round(array_sum($dur)/$n,1):null,'median_minutes'=>$median,'fastest_minutes'=>$n?$dur[0]:null,'slowest_minutes'=>$n?$dur[$n-1]:null,'oldest_pending_minutes'=>$pending?max(array_map(static fn(array$x):int=>(int)($x['pending_age_minutes']??0),$pending)):null];};
    $walk=$summarise($groups['walk_in']);$other=$summarise($groups['other']);$overall=$summarise($rows);$overall['status_breakdown']=$statusBreak;$overall['payment_status_exceptions']=count($exceptions);$overall['payment_status_compliance']=count($rows)?round(100*(count($rows)-count($exceptions))/count($rows),1):null;$overall['pending_rate']=count($rows)?round(100*$overall['pending']/count($rows),1):null;
    $modeDistribution=[];$modeCompliance=[];foreach(['collection','courier','delivery']as$mode){$modeRows=$modes[$mode]??[];$modeDistribution[$mode]=['count'=>count($modeRows),'percent'=>count($rows)?round(100*count($modeRows)/count($rows),1):0];$modeCompliance[$mode]=$summarise($modeRows);}
    $walkWeight=max(0,(float)($settings['front_orders_walkin_weight']??50));$otherWeight=max(0,(float)($settings['front_orders_nonwalk_weight']??50));$pendingWeight=0.0;$components=[];$points=0.0;$weightUsed=0.0;foreach([['Walk-in completion',$walkWeight,$walk['compliance_percent'],(int)$walk['eligible']],['Other Front Desk completion',$otherWeight,$other['compliance_percent'],(int)$other['eligible']]]as[$label,$weight,$value,$evidence]){if($value!==null){$points+=(float)$value*$weight/100;$weightUsed+=$weight;$components[]=['metric'=>$label,'weight'=>$weight,'score'=>$value,'contribution'=>round((float)$value*$weight/100,1),'evidence_count'=>$evidence];}}$score=$weightUsed>0?round(100*$points/$weightUsed,1):null;
    usort($risks,static function(array$a,array$b):int{$rank=['critical'=>0,'urgent'=>1,'warning'=>2,'information'=>3];return($rank[$a['risk_level']]??9)<=>($rank[$b['risk_level']]??9)?:((int)($b['pending_age_minutes']??0)<=>(int)($a['pending_age_minutes']??0));});
    return ['rows'=>$rows,'summary'=>['applicable'=>count($rows),'unclear_attribution'=>count($unclear),'exceptions'=>count($exceptions),'complete_but_unpaid'=>count(array_filter($exceptions,static fn(array$x):bool=>$x['exception_reason']==='Complete but Paid is not ticked')),'paid_but_unfinished'=>count(array_filter($exceptions,static fn(array$x):bool=>$x['exception_reason']==='Paid is ticked but status is unfinished')),'other_payment_conflicts'=>count(array_filter($exceptions,static fn(array$x):bool=>!in_array($x['exception_reason'],['Complete but Paid is not ticked','Paid is ticked but status is unfinished'],true)))],'walk_in'=>$walk,'other'=>$other,'overall'=>$overall,'mode_distribution'=>$modeDistribution,'mode_compliance'=>$modeCompliance,'payment_exceptions'=>$exceptions,'unclear_attribution'=>$unclear,'risks'=>$risks,'score'=>['value'=>$score,'status'=>$score===null?'insufficient_data':'provisional','band'=>$score===null?'Not Calculated':($score>=85?'Excellent':($score>=70?'Good':($score>=50?'Needs Improvement':'Critical'))),'confidence'=>count($unclear)===0?'High':(count($unclear)<=max(5,count($rows)*.1)?'Moderate':'Low'),'coverage'=>['measured'=>$walk['eligible']+$other['eligible'],'applicable'=>count($rows),'unclear'=>count($unclear)],'formula'=>$walkWeight.'% × Walk-in compliance + '.$otherWeight.'% × Other Front Desk compliance','working'=>'Walk-in '.($walk['compliance_percent']??'not measured').'% × '.$walkWeight.'% + Other '.($other['compliance_percent']??'not measured').'% × '.$otherWeight.'% = '.($score??'Not Calculated').'%','components'=>$components],'mapping'=>['paid_state'=>'ops_orders.payment_status','paid_time'=>'ops_orders.paid_updated_at with payment_status_updated Activity Log fallback','paid_employee'=>'ops_orders.paid_updated_by_employee_id with Activity Log fallback','completion'=>'first attributable Complete status event','ready'=>'first Ready/In Progress status event','walk_in'=>'configured identifiers across Mobile/customer/type fields']];
}
