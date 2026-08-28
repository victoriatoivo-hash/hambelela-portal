<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/kpi-reporting.php';
require_once __DIR__ . '/kpi-event-reporting.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');

function kpi_json(array $payload, int $status = 200): void
{
    kpi_send_json($payload, $status);
}

function kpi_scalar_row(string $sql, array $params = []): array
{
    $rows = ops_rows($sql, $params);
    return $rows[0] ?? [];
}

function kpi_business_health_metric($value, int $sample, ?float $previous, bool $lowerIsBetter = false, string $format = 'number'): array
{
    $measured = $value !== null;
    $low = $measured && $sample < 5;
    $delta = null;
    if ($measured && $previous !== null) {
        $delta = round((float) $value - $previous, 1);
    }
    return ['value' => $measured ? (float) $value : null, 'sample' => $sample, 'low_data' => $low, 'delta' => $delta, 'lower_is_better' => $lowerIsBetter, 'format' => $format];
}

function kpi_business_duration(?float $minutes): ?string
{
    if ($minutes === null) return null;
    $minutes = max(0, (int) round($minutes));
    $month = intdiv($minutes, 43200);
    $day = intdiv($minutes % 43200, 1440);
    $hour = intdiv($minutes % 1440, 60);
    $minute = $minutes % 60;
    if ($month > 0) return $month . ' mo' . ($day ? ' ' . $day . ' d' : '');
    if ($day > 0) return $day . ' d' . ($hour ? ' ' . $hour . ' h' : '');
    if ($hour > 0) return $hour . ' h' . ($minute ? ' ' . $minute . ' min' : '');
    return $minute . ' min';
}

/** Backward-compatible classifier used by the Business Health presentation. */
function kpi_business_is_test_employee(array $employee): bool
{
    $identity = strtolower(implode(' ', [(string) ($employee['full_name'] ?? ''), (string) ($employee['email'] ?? ''), (string) ($employee['role_key'] ?? '')]));
    return (bool) preg_match('/karina|kaarina|test|preview/', $identity);
}

try {
    if (!ops_database_ready()) throw new RuntimeException('The operations database is unavailable.');
    $settings = [];
    foreach (ops_rows('SELECT setting_key, setting_value FROM kpi_settings') as $row) $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    $resolvedPeriod = kpi_resolve_reporting_period($_GET);
    $periodKey = $resolvedPeriod['key'];
    $requestedFrom = $resolvedPeriod['from'];
    $requestedTo = $resolvedPeriod['to'];
    $includeHistorical=(string)($_GET['include_historical']??'')==='1';
    $dataStart = $includeHistorical ? $requestedFrom : new DateTimeImmutable($settings['business_health_tracking_start_date'] ?? '2026-07-01', new DateTimeZone('Africa/Windhoek'));
    $adoption = new DateTimeImmutable($settings['adoption_date'] ?? '2026-07-14', new DateTimeZone('Africa/Windhoek'));
    $from = $requestedFrom < $dataStart ? $dataStart : $requestedFrom;
    $to = $requestedTo;
    $rateFrom = $from < $adoption ? $adoption : $from;
    $fromSql = $from->format('Y-m-d 00:00:00'); $toSql = $to->format('Y-m-d 23:59:59');
    $rateFromSql = $rateFrom->format('Y-m-d 00:00:00');
    $days = max(1, (int) $from->diff($to)->days + 1);
    $previousTo = $from->modify('-1 second'); $previousFrom = $previousTo->modify('-' . ($days - 1) . ' days')->setTime(0, 0);
    if ($previousFrom < $dataStart) $previousFrom = $dataStart;
    $previousFromSql = $previousFrom->format('Y-m-d 00:00:00'); $previousToSql = $previousTo->format('Y-m-d H:i:s');

    $cacheKey = hash('sha256', implode('|', [$periodKey, $fromSql, $toSql]));
    $cacheFile = BASE_PATH . '/logs/kpi_business_health_' . $cacheKey . '.json';
    if (empty($_GET['refresh']) && is_file($cacheFile) && filemtime($cacheFile) >= time() - 60) {
        header('X-KPI-Cache: HIT');
        readfile($cacheFile);
        exit;
    }

    $paidRevenue = kpi_paid_revenue_condition('ops_orders');
    $orders = kpi_scalar_row("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN {$paidRevenue} THEN total_amount ELSE 0 END),0) revenue, SUM(status IN ('completed','packed','verified')) completed_n, AVG(CASE WHEN completed_at IS NOT NULL AND completed_at >= created_at AND created_at >= ? THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) avg_minutes, SUM(CASE WHEN completed_at IS NOT NULL AND completed_at >= created_at AND created_at >= ? AND TIMESTAMPDIFF(MINUTE, created_at, completed_at) <= ? * 60 THEN 1 ELSE 0 END) on_time_n FROM ops_orders WHERE created_at BETWEEN ? AND ?", [$rateFromSql, $rateFromSql, (float) ($settings['on_time_dispatch_hours'] ?? 6), $fromSql, $toSql]);
    $previousOrders = kpi_scalar_row("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN {$paidRevenue} THEN total_amount ELSE 0 END),0) revenue FROM ops_orders WHERE created_at BETWEEN ? AND ?", [$previousFromSql, $previousToSql]);
    $orderRows = ops_rows("SELECT id,order_number,customer_name,status,fulfilment_mode,total_amount,created_at,completed_at,assigned_packer_id FROM ops_orders WHERE created_at BETWEEN ? AND ? AND LOWER(status) NOT IN ('cancelled','canceled','refunded','failed') ORDER BY created_at", [$fromSql,$toSql]);
    $orderEvents = kpi_unified_events($fromSql,$toSql,null,'orders');
    $eventsByOrder=[];
    foreach($orderEvents as $event){if((int)($event['record_id']??0)>0)$eventsByOrder[(int)$event['record_id']][]=$event;}
    $orderFlow=['received'=>count($orderRows),'new'=>0,'in_progress'=>0,'completed'=>0,'outstanding'=>0,'reopened'=>0,'collection'=>0,'delivery'=>0,'courier'=>0,'walk_in'=>0,'courier_ready_by_1400'=>0,'eligible_value'=>0.0,'completed_value'=>0.0,'outstanding_value'=>0.0,'collection_value'=>0.0,'delivery_value'=>0.0,'courier_value'=>0.0,'walk_in_value'=>0.0];
    $packerDurations=[];$frontDurations=[];$inProgressToComplete=[];$packerDurationsByActor=[];$packerDurationsByMode=[];$oldestNewMinutes=null;$oldestOutstandingMinutes=null;
    foreach($orderRows as $order){
        $status=strtolower((string)$order['status']);$mode=strtolower((string)$order['fulfilment_mode']);$value=(float)$order['total_amount'];$createdAt=(string)$order['created_at'];
        $isCompleted=in_array($status,['completed','packed','verified'],true);$isNew=in_array($status,['new','pending'],true);$isProgress=in_array($status,['in_progress','processing','packing'],true);
        $isWalkIn=strpos(strtolower((string)$order['customer_name']),'walk-in')!==false||strpos(strtolower((string)$order['customer_name']),'walk in')!==false||strpos($mode,'walk')!==false;
        $orderFlow['eligible_value']+=$value;if($isCompleted){$orderFlow['completed']++;$orderFlow['completed_value']+=$value;}else{$orderFlow['outstanding']++;$orderFlow['outstanding_value']+=$value;}
        if($isNew){$orderFlow['new']++;$age=(time()-strtotime($createdAt))/60;$oldestNewMinutes=$oldestNewMinutes===null?$age:max($oldestNewMinutes,$age);}if($isProgress)$orderFlow['in_progress']++;
        if(strpos($mode,'collection')!==false){$orderFlow['collection']++;$orderFlow['collection_value']+=$value;}elseif(strpos($mode,'delivery')!==false){$orderFlow['delivery']++;$orderFlow['delivery_value']+=$value;}elseif(strpos($mode,'courier')!==false){$orderFlow['courier']++;$orderFlow['courier_value']+=$value;}
        if($isWalkIn){$orderFlow['walk_in']++;$orderFlow['walk_in_value']+=$value;}
        if(!$isCompleted){$age=(time()-strtotime($createdAt))/60;$oldestOutstandingMinutes=$oldestOutstandingMinutes===null?$age:max($oldestOutstandingMinutes,$age);}
        $progressEvent=null;$completeEvent=null;
        foreach($eventsByOrder[(int)$order['id']]??[] as $event){$next=strtolower((string)($event['new_status']?:$event['action']));if($next==='in_progress'&&$progressEvent===null)$progressEvent=$event;if(in_array($next,['completed','packed','verified','order_completed'],true)&&$completeEvent===null)$completeEvent=$event;if($next==='new'&&!empty($event['previous_status']))$orderFlow['reopened']++;}
        if($progressEvent&&strtotime((string)$progressEvent['occurred_at'])>=strtotime($createdAt)){$duration=(strtotime((string)$progressEvent['occurred_at'])-strtotime($createdAt))/60;$packerDurations[]=$duration;$actor=(int)($progressEvent['actor_user_id']??0);if($actor)$packerDurationsByActor[$actor][]=$duration;$packerDurationsByMode[$mode][]=$duration;if(strpos($mode,'courier')!==false&&date('H:i',strtotime((string)$progressEvent['occurred_at']))<='14:00')$orderFlow['courier_ready_by_1400']++;}
        if($completeEvent&&strtotime((string)$completeEvent['occurred_at'])>=strtotime($createdAt)){$duration=(strtotime((string)$completeEvent['occurred_at'])-strtotime($createdAt))/60;$frontDurations[]=$duration;if($progressEvent&&strtotime((string)$completeEvent['occurred_at'])>=strtotime((string)$progressEvent['occurred_at']))$inProgressToComplete[]=(strtotime((string)$completeEvent['occurred_at'])-strtotime((string)$progressEvent['occurred_at']))/60;}
    }
    sort($packerDurations,SORT_NUMERIC);sort($frontDurations,SORT_NUMERIC);
    $median=function(array $values){$count=count($values);if(!$count)return null;$middle=intdiv($count,2);return $count%2?$values[$middle]:($values[$middle-1]+$values[$middle])/2;};
    $orderOverview=['counts'=>$orderFlow,'packing_completion_percent'=>$orderFlow['received']?round(100*($orderFlow['in_progress']+$orderFlow['completed'])/$orderFlow['received'],1):null,'final_completion_percent'=>$orderFlow['received']?round(100*$orderFlow['completed']/$orderFlow['received'],1):null,'timing'=>['packer'=>['average'=>kpi_business_duration($packerDurations?array_sum($packerDurations)/count($packerDurations):null),'median'=>kpi_business_duration($median($packerDurations)),'fastest'=>kpi_business_duration($packerDurations?$packerDurations[0]:null),'slowest'=>kpi_business_duration($packerDurations?$packerDurations[count($packerDurations)-1]:null),'oldest_new'=>kpi_business_duration($oldestNewMinutes)],'front_desk'=>['average'=>kpi_business_duration($frontDurations?array_sum($frontDurations)/count($frontDurations):null),'median'=>kpi_business_duration($median($frontDurations)),'fastest'=>kpi_business_duration($frontDurations?$frontDurations[0]:null),'slowest'=>kpi_business_duration($frontDurations?$frontDurations[count($frontDurations)-1]:null),'oldest_outstanding'=>kpi_business_duration($oldestOutstandingMinutes)],'in_progress_to_complete'=>kpi_business_duration($inProgressToComplete?array_sum($inProgressToComplete)/count($inProgressToComplete):null)],'evidence_count'=>count($orderEvents)];
    $packing = kpi_scalar_row("SELECT COUNT(*) total, SUM(date_loaded IS NOT NULL AND date_started IS NOT NULL AND date_completed IS NOT NULL AND date_loaded <= date_started AND date_started <= date_completed) completed_n, AVG(CASE WHEN date_loaded IS NOT NULL AND date_started IS NOT NULL AND date_completed IS NOT NULL AND date_loaded <= date_started AND date_started <= date_completed THEN TIMESTAMPDIFF(MINUTE, date_started, date_completed) END) avg_minutes, SUM(CASE WHEN date_loaded IS NOT NULL AND date_started IS NOT NULL AND date_completed IS NOT NULL AND date_loaded <= date_started AND date_started <= date_completed AND TIMESTAMPDIFF(MINUTE,date_started,date_completed) <= ? * 60 THEN 1 ELSE 0 END) within_n FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND deleted_at IS NULL", [(float) ($settings['target_fulfilment_hours'] ?? 6), $fromSql, $toSql]);
    $previousPacking = kpi_scalar_row("SELECT AVG(CASE WHEN date_started IS NOT NULL AND date_completed IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,date_started,date_completed) END) avg_minutes FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ?", [$previousFromSql, $previousToSql]);
    $approvedLeavePortalIds=[];
    if(ops_table_exists('employee_user_links')){$employeeLinks=ops_rows('SELECT portal_user_id,hr_employee_id FROM employee_user_links WHERE active=1');$hrToPortal=[];foreach($employeeLinks as$link)$hrToPortal[(int)$link['hr_employee_id']]=(int)$link['portal_user_id'];if($hrToPortal){$hrPlaceholders=implode(',',array_fill(0,count($hrToPortal),'?'));$leaveHrRows=ops_hr_rows("SELECT DISTINCT employee_id FROM leave_requests WHERE status='approved' AND start_date<=CURDATE() AND end_date>=CURDATE() AND employee_id IN ({$hrPlaceholders})",array_keys($hrToPortal));foreach($leaveHrRows as$leaveRow)if(isset($hrToPortal[(int)$leaveRow['employee_id']]))$approvedLeavePortalIds[]=$hrToPortal[(int)$leaveRow['employee_id']];}}
    $staffSql="SELECT COUNT(*) scheduled FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE ".kpi_performance_employee_predicate('e','r')." AND (e.hire_date IS NULL OR e.hire_date <= CURDATE())";$staffParams=[];if($approvedLeavePortalIds){$staffSql.=' AND e.id NOT IN ('.implode(',',array_fill(0,count($approvedLeavePortalIds),'?')).')';$staffParams=$approvedLeavePortalIds;}$staff=kpi_scalar_row($staffSql,$staffParams);
    $attendance = kpi_scalar_row("SELECT COUNT(DISTINCT user_id) present FROM kpi_sessions WHERE DATE(DATE_ADD(login_at, INTERVAL 2 HOUR)) = CURDATE()");
    $completedOrders = (int) ($orders['completed_n'] ?? 0);
    $healthCards = [
        'orders' => kpi_business_health_metric((float) ($orders['total'] ?? 0), (int) ($orders['total'] ?? 0), (float) ($previousOrders['total'] ?? 0)),
        'fulfilment' => kpi_business_health_metric($orders['avg_minutes'] !== null ? (float) $orders['avg_minutes'] : null, $completedOrders, null, true, 'minutes'),
        'dispatch' => kpi_business_health_metric($completedOrders > 0 ? 100 * (int) ($orders['on_time_n'] ?? 0) / $completedOrders : null, $completedOrders, null, false, 'percent'),
        'pack_speed' => kpi_business_health_metric($packing['avg_minutes'] !== null ? (float) $packing['avg_minutes'] : null, (int) ($packing['completed_n'] ?? 0), $previousPacking['avg_minutes'] !== null ? (float) $previousPacking['avg_minutes'] : null, true, 'minutes'),
        'revenue' => kpi_business_health_metric((float) ($orders['revenue'] ?? 0), (int) ($orders['total'] ?? 0), (float) ($previousOrders['revenue'] ?? 0), false, 'currency'),
        'attendance' => kpi_business_health_metric((int) ($staff['scheduled'] ?? 0) > 0 ? 100 * (int) ($attendance['present'] ?? 0) / (int) $staff['scheduled'] : null, (int) ($staff['scheduled'] ?? 0), null, false, 'percent'),
    ];

    $errorRow = kpi_scalar_row("SELECT COUNT(*) employee_errors FROM ops_error_logs WHERE affects_kpi_accuracy=1 AND accuracy_verified_by IS NOT NULL AND COALESCE(occurred_on,DATE(occurred_at),DATE(created_at),DATE(logged_at)) BETWEEN DATE(?) AND DATE(?) AND deleted_at IS NULL", [$rateFromSql, $toSql]);
    $packingCompleted = (int) ($packing['completed_n'] ?? 0);
    $packingAccuracy = $packingCompleted > 0 ? max(0, 100 * (1 - (int) ($errorRow['employee_errors'] ?? 0) / $packingCompleted)) : null;
    $waybills = kpi_scalar_row("SELECT COUNT(*) total, SUM(status='sent') sent_n, SUM(status='sent' AND sent_at <= due_by) on_time_n, SUM(status IN ('pending','overdue') AND due_by < NOW()) overdue_n FROM hambelela_waybills WHERE uploaded_at BETWEEN ? AND ? AND deleted_at IS NULL", [$rateFromSql, $toSql]);
    $tasks = kpi_scalar_row("SELECT COUNT(*) total, SUM(status IN ('completed','complete','approved')) done_n, SUM(status IN ('completed','complete','approved') AND completed_at <= deadline) on_time_n, SUM(status NOT IN ('completed','complete','approved') AND deadline < NOW()) overdue_n FROM ops_checklist_tasks WHERE employee_visible=1 AND (scheduled_at IS NULL OR released_at IS NOT NULL) AND COALESCE(released_at,date_assigned) BETWEEN ? AND ? AND deleted_at IS NULL", [$rateFromSql, $toSql]);
    $website = kpi_scalar_row("SELECT COUNT(*) total, SUM(TIMESTAMPDIFF(MINUTE, date_loaded, frontdesk_website_updated_at) <= ?) on_time_n, AVG(TIMESTAMPDIFF(MINUTE, date_loaded, frontdesk_website_updated_at)) avg_lag_minutes FROM ops_packing_tasks WHERE frontdesk_website_updated_at BETWEEN ? AND ? AND date_loaded IS NOT NULL AND frontdesk_website_updated_at >= date_loaded AND deleted_at IS NULL", [(int) ($settings['website_update_lag_target_minutes'] ?? 60), $rateFromSql, $toSql]);

    $workingDates = 0;
    $cursor = $rateFrom;
    $holidayDates = array_column(ops_rows('SELECT holiday_date FROM kpi_holidays WHERE active=1 AND holiday_date BETWEEN ? AND ?', [$rateFrom->format('Y-m-d'), $to->format('Y-m-d')]), 'holiday_date');
    while ($cursor <= $to) { if ((int) $cursor->format('N') <= 5 && !in_array($cursor->format('Y-m-d'), $holidayDates, true)) $workingDates++; $cursor = $cursor->modify('+1 day'); }
    $recon = kpi_scalar_row('SELECT COUNT(DISTINCT recon_date) days_done FROM hambelela_cashbook_recon WHERE recon_date BETWEEN ? AND ?', [$rateFrom->format('Y-m-d'), $to->format('Y-m-d')]);
    $scores = [];
    $orderN = (int) ($orders['total'] ?? 0); $dispatch = $completedOrders ? 100 * (int) ($orders['on_time_n'] ?? 0) / $completedOrders : null;
    $scores[] = ['key'=>'orders','label'=>'Orders','score'=>$completedOrders >= 5 ? round(0.5*$dispatch + 50*(1-max(0,$orderN-$completedOrders)/max(1,$orderN))) : null,'sample'=>$completedOrders,'reason'=>$completedOrders . ' completed orders measured'];
    $scores[] = ['key'=>'packing','label'=>'Packing','score'=>$packingCompleted >= 5 ? round(40*$packingCompleted/max(1,(int)$packing['total']) + 30*(int)$packing['within_n']/max(1,$packingCompleted) + .3*$packingAccuracy) : null,'sample'=>$packingCompleted,'reason'=>$packingCompleted . ' completed items measured'];
    $waybillN=(int)($waybills['sent_n']??0); $scores[]=['key'=>'waybills','label'=>'Waybills','score'=>$waybillN>=5?max(0,round(100*(int)$waybills['on_time_n']/max(1,$waybillN)-5*(int)$waybills['overdue_n'])):null,'sample'=>$waybillN,'reason'=>(int)$waybills['overdue_n'].' overdue pending'];
    $taskN=(int)($tasks['done_n']??0); $scores[]=['key'=>'tasks','label'=>'Tasks','score'=>$taskN>=5?max(0,round(100*(int)$tasks['on_time_n']/max(1,$taskN)-5*(int)$tasks['overdue_n'])):null,'sample'=>$taskN,'reason'=>(int)$tasks['overdue_n'].' overdue tasks'];
    $scores[]=['key'=>'bookkeeping','label'=>'Bookkeeping','score'=>$workingDates>=5?round(100*(int)($recon['days_done']??0)/max(1,$workingDates)):null,'sample'=>$workingDates,'reason'=>(int)($recon['days_done']??0).' of '.$workingDates.' cash-up days'];
    $websiteN=(int)($website['total']??0); $scores[]=['key'=>'website','label'=>'Website updates','score'=>$websiteN>=5?round(100*(int)$website['on_time_n']/max(1,$websiteN)):null,'sample'=>$websiteN,'reason'=>$websiteN.' updates measured'];
    $scores[]=['key'=>'attendance','label'=>'Attendance','score'=>(int)($staff['scheduled']??0)>=5?round(100*(int)($attendance['present']??0)/max(1,(int)$staff['scheduled'])):null,'sample'=>(int)($staff['scheduled']??0),'reason'=>(int)($attendance['present']??0).' of '.(int)($staff['scheduled']??0).' scheduled staff online'];

    $attention = [];
    $trackingStartSql=$dataStart->format('Y-m-d 00:00:00');
    foreach (ops_rows("SELECT id, COALESCE(customer_name, waybill_reference, CONCAT('Waybill #',id)) label, TIMESTAMPDIFF(HOUR,due_by,NOW()) age, uploaded_by employee_id,due_by FROM hambelela_waybills WHERE status IN ('pending','overdue') AND due_by < NOW() AND uploaded_at>=? AND deleted_at IS NULL ORDER BY due_by LIMIT 8",[$trackingStartSql]) as $row) $attention[]=['type'=>'waybill','severity'=>'urgent','description'=>'Waybill pending customer send: '.$row['label'],'age_hours'=>(int)$row['age'],'overdue'=>kpi_business_duration((int)$row['age']*60),'due_at'=>$row['due_by'],'employee_id'=>(int)$row['employee_id'],'href'=>'courier.php'];
    foreach (ops_rows("SELECT id, item_name label, TIMESTAMPDIFF(HOUR,date_loaded,NOW()) age, assigned_employee_id employee_id,date_loaded due_at,priority FROM ops_packing_tasks WHERE packing_status NOT IN ('done','website','packed_label_needed','done_needs_label','label_created') AND deleted_at IS NULL AND date_loaded>=? AND date_loaded < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY date_loaded LIMIT 8", [$trackingStartSql,(int)($settings['stale_work_days']??2)]) as $row) $attention[]=['type'=>'packing','severity'=>strtolower((string)$row['priority'])==='urgent'?'critical':'urgent','description'=>'Packing item outstanding: '.$row['label'],'age_hours'=>(int)$row['age'],'overdue'=>kpi_business_duration((int)$row['age']*60),'due_at'=>$row['due_at'],'employee_id'=>(int)$row['employee_id'],'href'=>'consignments.php'];
    foreach (ops_rows("SELECT id, task_name label, TIMESTAMPDIFF(HOUR,deadline,NOW()) age, assigned_employee_id employee_id,deadline due_at,priority FROM ops_checklist_tasks WHERE employee_visible=1 AND (scheduled_at IS NULL OR released_at IS NOT NULL) AND status NOT IN ('completed','complete','approved') AND deadline < NOW() AND COALESCE(released_at,date_assigned)>=? AND deleted_at IS NULL ORDER BY deadline LIMIT 8",[$trackingStartSql]) as $row) $attention[]=['type'=>'task','severity'=>strtolower((string)$row['priority'])==='urgent'?'critical':'urgent','description'=>'Overdue task: '.$row['label'],'age_hours'=>(int)$row['age'],'overdue'=>kpi_business_duration((int)$row['age']*60),'due_at'=>$row['due_at'],'employee_id'=>(int)$row['employee_id'],'href'=>'checklists.php'];
    foreach (ops_rows("SELECT id, COALESCE(error_title,category) label, TIMESTAMPDIFF(HOUR,logged_at,NOW()) age, employee_id,logged_at due_at,severity FROM ops_error_logs WHERE status <> 'resolved' AND logged_at>=? AND deleted_at IS NULL ORDER BY logged_at LIMIT 8",[$trackingStartSql]) as $row) $attention[]=['type'=>'error','severity'=>strtolower((string)$row['severity'])==='critical'?'critical':'normal','description'=>'Unresolved confirmed quality error: '.$row['label'],'age_hours'=>(int)$row['age'],'overdue'=>kpi_business_duration((int)$row['age']*60),'due_at'=>$row['due_at'],'employee_id'=>(int)$row['employee_id'],'href'=>'errors.php'];
    $severityRank=['critical'=>3,'urgent'=>2,'normal'=>1];usort($attention,static function(array $a,array $b)use($severityRank):int{$rank=($severityRank[$b['severity']]??0)<=>($severityRank[$a['severity']]??0);return $rank?:($b['age_hours']<=>$a['age_hours']);}); $attention=array_slice($attention,0,12);
    $names=[]; foreach(ops_rows("SELECT e.id AS id, e.full_name,e.email, r.name role_name, r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE ".kpi_performance_employee_predicate('e','r')." ORDER BY e.full_name") as $row){$names[(int)$row['id']]=$row;}
    $team=[]; foreach($names as $id=>$employee){$team[]=['id'=>$id,'name'=>$employee['full_name'],'role'=>$employee['role_name'],'role_key'=>$employee['role_key'],'online'=>false,'hours_today'=>null,'metrics'=>[]];}
    $presenceIds=array_column(ops_rows("SELECT employee_id FROM ops_board_presence WHERE last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE)"),'employee_id');
    $hoursRows=ops_rows("SELECT user_id, SUM(TIMESTAMPDIFF(MINUTE,login_at,COALESCE(logout_at,last_seen_at)))/60 hours FROM kpi_sessions WHERE DATE(DATE_ADD(login_at,INTERVAL 2 HOUR))=CURDATE() GROUP BY user_id"); $hours=[];foreach($hoursRows as $r)$hours[(int)$r['user_id']]=(float)$r['hours'];
    $packerRows=ops_rows("SELECT assigned_employee_id id,COUNT(*) assigned_items,SUM(packing_status IN ('done','website','packed_label_needed','done_needs_label','label_created')) completed_items,SUM(packing_status NOT IN ('done','website','packed_label_needed','done_needs_label','label_created')) outstanding_items,COALESCE(SUM(COALESCE(workload_points_override,workload_points)),0) workload_units FROM ops_packing_tasks WHERE date_loaded BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY assigned_employee_id",[$fromSql,$toSql]);$packerMap=[];foreach($packerRows as$r)$packerMap[(int)$r['id']]=$r;
    $ordersPackedBy=[];$ordersCompletedBy=[];$walkInsCompletedBy=[];
    foreach($orderRows as$order){$progressActor=0;$completeActor=0;foreach($eventsByOrder[(int)$order['id']]??[]as$event){$next=strtolower((string)($event['new_status']?:$event['action']));if(!$progressActor&&$next==='in_progress')$progressActor=(int)($event['actor_user_id']??0);if(!$completeActor&&in_array($next,['completed','packed','verified','order_completed'],true))$completeActor=(int)($event['actor_user_id']??0);}if(!$progressActor)$progressActor=(int)($order['assigned_packer_id']??0);if($progressActor)$ordersPackedBy[$progressActor]=($ordersPackedBy[$progressActor]??0)+1;if($completeActor){$ordersCompletedBy[$completeActor]=($ordersCompletedBy[$completeActor]??0)+1;$walkIn=strpos(strtolower((string)$order['customer_name']),'walk-in')!==false||strpos(strtolower((string)$order['customer_name']),'walk in')!==false||strpos(strtolower((string)$order['fulfilment_mode']),'walk')!==false;if($walkIn)$walkInsCompletedBy[$completeActor]=($walkInsCompletedBy[$completeActor]??0)+1;}}
    $websitePending=(int)(kpi_scalar_row("SELECT COUNT(*) pending FROM ops_packing_tasks WHERE date_loaded BETWEEN ? AND ? AND frontdesk_website_updated_at IS NULL AND deleted_at IS NULL",[$fromSql,$toSql])['pending']??0);
    foreach($team as&$person){
        $id=(int)$person['id'];$person['online']=in_array($id,array_map('intval',$presenceIds),true);$person['hours_today']=$hours[$id]??null;$isPacker=strpos((string)$person['role_key'],'packer')!==false;
        if($isPacker){$m=$packerMap[$id]??[];$assigned=(int)($m['assigned_items']??0);$completed=(int)($m['completed_items']??0);$durations=$packerDurationsByActor[$id]??[];$average=$durations?array_sum($durations)/count($durations):null;$person['card_type']='packer';$person['metrics']=[['label'=>'Packing Items Completed','value'=>$completed,'numeric_value'=>$completed,'denominator'=>$assigned,'evidence'=>'ops_packing_tasks'],['label'=>'Packing Items Outstanding','value'=>(int)($m['outstanding_items']??0),'numeric_value'=>(int)($m['outstanding_items']??0),'denominator'=>$assigned,'evidence'=>'ops_packing_tasks'],['label'=>'Workload Units','value'=>round((float)($m['workload_units']??0),2),'numeric_value'=>(float)($m['workload_units']??0),'denominator'=>$assigned,'evidence'=>'ops_packing_tasks.workload_points','tooltip'=>'Calculated from parsed quantity, package effort, weight or volume, size complexity and priority.'],['label'=>'Orders Packed','value'=>(int)($ordersPackedBy[$id]??0),'numeric_value'=>(int)($ordersPackedBy[$id]??0),'denominator'=>count($durations),'evidence'=>'order status activity'],['label'=>'Avg New → In Progress','value'=>kpi_business_duration($average),'numeric_value'=>$average,'denominator'=>count($durations),'evidence'=>'status history'],['label'=>'Completed versus Assigned','value'=>$assigned?$completed.' of '.$assigned.' completed':'Not calculated','numeric_value'=>$assigned?100*$completed/$assigned:null,'numerator'=>$completed,'denominator'=>$assigned]];
        }else{$completed=(int)($ordersCompletedBy[$id]??0);$applicable=$completed+$orderFlow['outstanding'];$employeeDurations=[];foreach($orderRows as$order){foreach($eventsByOrder[(int)$order['id']]??[]as$event){$next=strtolower((string)($event['new_status']?:$event['action']));if(in_array($next,['completed','packed','verified','order_completed'],true)&&(int)($event['actor_user_id']??0)===$id&&strtotime((string)$event['occurred_at'])>=strtotime((string)$order['created_at'])){$employeeDurations[]=(strtotime((string)$event['occurred_at'])-strtotime((string)$order['created_at']))/60;break;}}}$frontAverage=$employeeDurations?array_sum($employeeDurations)/count($employeeDurations):null;$person['card_type']='front_desk';$person['metrics']=[['label'=>'Orders Completed','value'=>$completed,'numeric_value'=>$completed,'denominator'=>$applicable,'evidence'=>'order status activity'],['label'=>'Walk-in Orders Completed','value'=>(int)($walkInsCompletedBy[$id]??0),'numeric_value'=>(int)($walkInsCompletedBy[$id]??0),'denominator'=>$completed,'evidence'=>'order status activity plus Walk-in identifier'],['label'=>'Orders Outstanding','value'=>$orderFlow['outstanding'],'numeric_value'=>$orderFlow['outstanding'],'denominator'=>$applicable,'evidence'=>'authoritative order status'],['label'=>'Avg New → Complete','value'=>kpi_business_duration($frontAverage),'numeric_value'=>$frontAverage,'denominator'=>count($employeeDurations),'evidence'=>'status history'],['label'=>'Completed versus Applicable','value'=>$applicable?$completed.' of '.$applicable.' completed':'Not calculated','numeric_value'=>$applicable?100*$completed/$applicable:null,'numerator'=>$completed,'denominator'=>$applicable],['label'=>'Website Updates Pending','value'=>$websitePending,'numeric_value'=>$websitePending,'denominator'=>$applicable,'evidence'=>'ops_packing_tasks']];}
    }unset($person);
    $metricFor=static function(array $person,string $label):?array{foreach($person['metrics']??[]as$metric)if((string)($metric['label']??'')===$label)return $metric;return null;};
    $recognitionPick=static function(array $candidates,string $direction):array{if(!$candidates)return ['status'=>'not_determined','message'=>'Not determined — insufficient reliable evidence for this period.'];usort($candidates,static function(array $a,array $b)use($direction):int{return $direction==='min'?($a['result']<=>$b['result']):($b['result']<=>$a['result']);});$winner=$candidates[0]['result'];$winners=array_values(array_filter($candidates,static function(array $candidate)use($winner):bool{return abs((float)$candidate['result']-(float)$winner)<0.0001;}));return ['status'=>count($winners)>1?'tie':'awarded','winners'=>$winners,'confidence'=>'Calculated','tie'=>count($winners)>1];};
    $fastest=[];$workload=[];$ordersPacked=[];$frontCompletion=[];
    foreach($team as$person){if((string)$person['card_type']==='packer'){$speed=$metricFor($person,'Avg New → In Progress');if($speed&&$speed['numeric_value']!==null&&(int)$speed['denominator']>=5)$fastest[]=['employee_id'=>$person['id'],'employee'=>$person['name'],'role'=>$person['role'],'metric'=>'Average New → In Progress','result'=>(float)$speed['numeric_value'],'display'=>kpi_business_duration((float)$speed['numeric_value']),'numerator'=>(int)$speed['denominator'],'denominator'=>(int)$speed['denominator'],'evidence'=>'order status activity'];$units=$metricFor($person,'Workload Units');if($units&&(int)$units['denominator']>0)$workload[]=['employee_id'=>$person['id'],'employee'=>$person['name'],'role'=>$person['role'],'metric'=>'Workload Units','result'=>(float)$units['numeric_value'],'display'=>number_format((float)$units['numeric_value'],2),'numerator'=>(float)$units['numeric_value'],'denominator'=>(int)$units['denominator'],'evidence'=>'ops_packing_tasks workload breakdown'];$packed=$metricFor($person,'Orders Packed');if($packed&&(int)$packed['denominator']>=5)$ordersPacked[]=['employee_id'=>$person['id'],'employee'=>$person['name'],'role'=>$person['role'],'metric'=>'Orders Packed','result'=>(float)$packed['numeric_value'],'display'=>(string)(int)$packed['numeric_value'],'numerator'=>(int)$packed['numeric_value'],'denominator'=>(int)$packed['denominator'],'evidence'=>'order status activity'];}else{$ratio=$metricFor($person,'Completed versus Applicable');if($ratio&&(int)$ratio['denominator']>=5)$frontCompletion[]=['employee_id'=>$person['id'],'employee'=>$person['name'],'role'=>$person['role'],'metric'=>'Front Desk Order Completion Compliance','result'=>(float)$ratio['numeric_value'],'display'=>number_format((float)$ratio['numeric_value'],1).'%','numerator'=>(int)$ratio['numerator'],'denominator'=>(int)$ratio['denominator'],'evidence'=>'order completion status activity'];}}
    $workload=array_values(array_filter($workload,static function(array $candidate):bool{return (int)$candidate['denominator']>=5;}));
    foreach($ordersPacked as&$candidate){$candidate['result']=(int)$candidate['denominator'];$candidate['display']=(string)(int)$candidate['denominator'];$candidate['numerator']=(int)$candidate['denominator'];}unset($candidate);
    $riskRows=ops_rows("SELECT assigned_employee_id employee_id,COUNT(*) assigned_total,SUM(status NOT IN ('completed','complete','approved') AND deadline<NOW()) result FROM ops_checklist_tasks WHERE employee_visible=1 AND (scheduled_at IS NULL OR released_at IS NOT NULL) AND COALESCE(released_at,date_assigned) BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY assigned_employee_id",[$fromSql,$toSql]);$overdueCandidates=[];foreach($riskRows as$r){$id=(int)$r['employee_id'];if(!isset($names[$id])||(int)$r['result']<=0)continue;$overdueCandidates[]=['employee_id'=>$id,'employee'=>$names[$id]['full_name'],'role'=>$names[$id]['role_name'],'metric'=>'Overdue assigned tasks','result'=>(int)$r['result'],'display'=>(string)(int)$r['result'],'numerator'=>(int)$r['result'],'denominator'=>(int)$r['assigned_total'],'evidence'=>'ops_checklist_tasks'];}
    $recognition=['overall'=>[['key'=>'best_overall','title'=>'Best Overall Employee','status'=>'not_determined','message'=>'Best Overall Employee not determined — insufficient comparable evidence.','confidence'=>'Insufficient comparable evidence'],['key'=>'most_improved','title'=>'Most Improved Employee','status'=>'not_determined','message'=>'Not determined — two comparable valid overall-score periods are required.','confidence'=>'Insufficient comparable evidence']],'strengths'=>[['key'=>'fastest_packer','title'=>'Fastest Packer']+$recognitionPick($fastest,'min'),['key'=>'most_orders_packed','title'=>'Most Orders Packed']+$recognitionPick($ordersPacked,'max'),['key'=>'highest_workload','title'=>'Highest Workload Units']+$recognitionPick($workload,'max'),['key'=>'front_completion','title'=>'Best Front Desk Order Completion']+$recognitionPick($frontCompletion,'max')],'risks'=>[['key'=>'overdue_tasks','title'=>'Most Overdue Tasks','classification'=>'Operational Risk']+$recognitionPick($overdueCandidates,'max')],'period'=>['from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d')]];
    $orderTrend=ops_rows("SELECT DATE(created_at) day, COUNT(*) orders, COALESCE(SUM(CASE WHEN {$paidRevenue} THEN total_amount ELSE 0 END),0) revenue FROM ops_orders WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY day",[$fromSql,$toSql]);
    $packingTrend=ops_rows("SELECT DATE(date_completed) day, assigned_employee_id, COUNT(*) items, COALESCE(SUM(workload_points),0) points FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY DATE(date_completed),assigned_employee_id ORDER BY day,assigned_employee_id",[$fromSql,$toSql]);
    $measuredScores=array_values(array_filter(array_column($scores,'score'),static function($score){return $score!==null;}));
    $operationalScore=$measuredScores?round(array_sum($measuredScores)/count($measuredScores),1):null;
    $payload=['ok'=>true,'period'=>array_merge(kpi_period_response($resolvedPeriod,$adoption,$from),['tracking_start_date'=>$dataStart->format('Y-m-d'),'includes_historical'=>$includeHistorical]),'cards'=>$healthCards,'orders_overview'=>$orderOverview,'scores'=>[],'scores_disabled'=>true,'scores_message'=>'Category rankings remain disabled; the objective Operational Score is calculated separately from reliable evidence.','operational_score'=>$operationalScore,'operational_score_message'=>$operationalScore===null?'Not calculated — insufficient operational data since 1 July 2026':null,'attention'=>$attention,'recognition'=>$recognition,'team'=>$team,'excluded_accounts'=>[['classification'=>'Test / Preview Account','match'=>'Karina/Kaarina; account retained and excluded from performance calculations']],'trends'=>['orders'=>$orderTrend,'packing'=>$packingTrend],'last_refreshed_at'=>(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format(DATE_ATOM),'definitions'=>['completed_order_value'=>'Total value of eligible orders whose authoritative status is Completed. It is not labelled revenue unless payment evidence confirms payment.','workload_units'=>'Calculated from parsed quantity, package effort, weight or volume, size complexity and priority.','packer_timing'=>'Order created/New timestamp to the first exact In Progress status event, attributed to that event actor.','front_desk_timing'=>'Order created/New timestamp to the first exact Complete status event, attributed to that event actor.','tracking_start_date'=>'Current operational performance excludes records before 1 July 2026 by default. Older records remain Pre-EPI Historical Records.']];
    @file_put_contents($cacheFile,json_encode($payload,JSON_UNESCAPED_SLASHES),LOCK_EX);
    header('X-KPI-Cache: MISS'); kpi_json($payload);
} catch (Throwable $error) {
    error_log(date(DATE_ATOM).' business health: '.$error->getMessage().PHP_EOL,3,BASE_PATH.'/logs/kpi_errors.log');
    kpi_json(['ok'=>false,'message'=>'Business Health is temporarily unavailable.'],500);
}
