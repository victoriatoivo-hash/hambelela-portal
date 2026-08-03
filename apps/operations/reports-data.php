<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/kpi-reporting.php';
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

function kpi_metric($value, int $sample, ?float $previous, bool $lowerIsBetter = false, string $format = 'number'): array
{
    $measured = $value !== null;
    $low = $measured && $sample < 5;
    $delta = null;
    if ($measured && $previous !== null) {
        $delta = round((float) $value - $previous, 1);
    }
    return ['value' => $measured ? (float) $value : null, 'sample' => $sample, 'low_data' => $low, 'delta' => $delta, 'lower_is_better' => $lowerIsBetter, 'format' => $format];
}

try {
    if (!ops_database_ready()) throw new RuntimeException('The operations database is unavailable.');
    $settings = [];
    foreach (ops_rows('SELECT setting_key, setting_value FROM kpi_settings') as $row) $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    $resolvedPeriod = kpi_resolve_reporting_period($_GET);
    $periodKey = $resolvedPeriod['key'];
    $requestedFrom = $resolvedPeriod['from'];
    $requestedTo = $resolvedPeriod['to'];
    $dataStart = new DateTimeImmutable($settings['trusted_performance_start_date'] ?? '2026-07-10', new DateTimeZone('Africa/Windhoek'));
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
    $packing = kpi_scalar_row("SELECT COUNT(*) total, SUM(date_loaded IS NOT NULL AND date_started IS NOT NULL AND date_completed IS NOT NULL AND date_loaded <= date_started AND date_started <= date_completed) completed_n, AVG(CASE WHEN date_loaded IS NOT NULL AND date_started IS NOT NULL AND date_completed IS NOT NULL AND date_loaded <= date_started AND date_started <= date_completed THEN TIMESTAMPDIFF(MINUTE, date_started, date_completed) END) avg_minutes, SUM(CASE WHEN date_loaded IS NOT NULL AND date_started IS NOT NULL AND date_completed IS NOT NULL AND date_loaded <= date_started AND date_started <= date_completed AND TIMESTAMPDIFF(MINUTE,date_started,date_completed) <= ? * 60 THEN 1 ELSE 0 END) within_n FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND deleted_at IS NULL", [(float) ($settings['target_fulfilment_hours'] ?? 6), $fromSql, $toSql]);
    $previousPacking = kpi_scalar_row("SELECT AVG(CASE WHEN date_started IS NOT NULL AND date_completed IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,date_started,date_completed) END) avg_minutes FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ?", [$previousFromSql, $previousToSql]);
    $approvedLeavePortalIds=[];
    if(ops_table_exists('employee_user_links')){$employeeLinks=ops_rows('SELECT portal_user_id,hr_employee_id FROM employee_user_links WHERE active=1');$hrToPortal=[];foreach($employeeLinks as$link)$hrToPortal[(int)$link['hr_employee_id']]=(int)$link['portal_user_id'];if($hrToPortal){$hrPlaceholders=implode(',',array_fill(0,count($hrToPortal),'?'));$leaveHrRows=ops_hr_rows("SELECT DISTINCT employee_id FROM leave_requests WHERE status='approved' AND start_date<=CURDATE() AND end_date>=CURDATE() AND employee_id IN ({$hrPlaceholders})",array_keys($hrToPortal));foreach($leaveHrRows as$leaveRow)if(isset($hrToPortal[(int)$leaveRow['employee_id']]))$approvedLeavePortalIds[]=$hrToPortal[(int)$leaveRow['employee_id']];}}
    $staffSql="SELECT COUNT(*) scheduled FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key <> 'owner_admin' AND (e.hire_date IS NULL OR e.hire_date <= CURDATE())";$staffParams=[];if($approvedLeavePortalIds){$staffSql.=' AND e.id NOT IN ('.implode(',',array_fill(0,count($approvedLeavePortalIds),'?')).')';$staffParams=$approvedLeavePortalIds;}$staff=kpi_scalar_row($staffSql,$staffParams);
    $attendance = kpi_scalar_row("SELECT COUNT(DISTINCT user_id) present FROM kpi_sessions WHERE DATE(DATE_ADD(login_at, INTERVAL 2 HOUR)) = CURDATE()");
    $completedOrders = (int) ($orders['completed_n'] ?? 0);
    $healthCards = [
        'orders' => kpi_metric((float) ($orders['total'] ?? 0), (int) ($orders['total'] ?? 0), (float) ($previousOrders['total'] ?? 0)),
        'fulfilment' => kpi_metric($orders['avg_minutes'] !== null ? (float) $orders['avg_minutes'] : null, $completedOrders, null, true, 'minutes'),
        'dispatch' => kpi_metric($completedOrders > 0 ? 100 * (int) ($orders['on_time_n'] ?? 0) / $completedOrders : null, $completedOrders, null, false, 'percent'),
        'pack_speed' => kpi_metric($packing['avg_minutes'] !== null ? (float) $packing['avg_minutes'] : null, (int) ($packing['completed_n'] ?? 0), $previousPacking['avg_minutes'] !== null ? (float) $previousPacking['avg_minutes'] : null, true, 'minutes'),
        'revenue' => kpi_metric((float) ($orders['revenue'] ?? 0), (int) ($orders['total'] ?? 0), (float) ($previousOrders['revenue'] ?? 0), false, 'currency'),
        'attendance' => kpi_metric((int) ($staff['scheduled'] ?? 0) > 0 ? 100 * (int) ($attendance['present'] ?? 0) / (int) $staff['scheduled'] : null, (int) ($staff['scheduled'] ?? 0), null, false, 'percent'),
    ];

    $errorRow = kpi_scalar_row("SELECT COUNT(*) employee_errors FROM ops_error_logs WHERE affects_kpi_accuracy=1 AND accuracy_verified_by IS NOT NULL AND logged_at BETWEEN ? AND ? AND deleted_at IS NULL", [$rateFromSql, $toSql]);
    $packingCompleted = (int) ($packing['completed_n'] ?? 0);
    $packingAccuracy = $packingCompleted > 0 ? max(0, 100 * (1 - (int) ($errorRow['employee_errors'] ?? 0) / $packingCompleted)) : null;
    $waybills = kpi_scalar_row("SELECT COUNT(*) total, SUM(status='sent') sent_n, SUM(status='sent' AND sent_at <= due_by) on_time_n, SUM(status IN ('pending','overdue') AND due_by < NOW()) overdue_n FROM hambelela_waybills WHERE uploaded_at BETWEEN ? AND ? AND deleted_at IS NULL", [$rateFromSql, $toSql]);
    $tasks = kpi_scalar_row("SELECT COUNT(*) total, SUM(status IN ('completed','complete','approved')) done_n, SUM(status IN ('completed','complete','approved') AND completed_at <= deadline) on_time_n, SUM(status NOT IN ('completed','complete','approved') AND deadline < NOW()) overdue_n FROM ops_checklist_tasks WHERE created_at BETWEEN ? AND ? AND deleted_at IS NULL", [$rateFromSql, $toSql]);
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
    foreach (ops_rows("SELECT id, COALESCE(customer_name, waybill_reference, CONCAT('Waybill #',id)) label, TIMESTAMPDIFF(HOUR,due_by,NOW()) age, uploaded_by employee_id FROM hambelela_waybills WHERE status IN ('pending','overdue') AND due_by < NOW() AND deleted_at IS NULL ORDER BY due_by LIMIT 8") as $row) $attention[]=['type'=>'waybill','description'=>'Overdue waybill: '.$row['label'],'age_hours'=>(int)$row['age'],'employee_id'=>(int)$row['employee_id'],'href'=>'courier.php'];
    foreach (ops_rows("SELECT id, item_name label, TIMESTAMPDIFF(HOUR,date_loaded,NOW()) age, assigned_employee_id employee_id FROM ops_packing_tasks WHERE packing_status NOT IN ('done','website','packed_label_needed','done_needs_label','label_created') AND deleted_at IS NULL AND date_loaded < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY date_loaded LIMIT 8", [(int)($settings['stale_work_days']??2)]) as $row) $attention[]=['type'=>'packing','description'=>'Stale packing item: '.$row['label'],'age_hours'=>(int)$row['age'],'employee_id'=>(int)$row['employee_id'],'href'=>'consignments.php'];
    foreach (ops_rows("SELECT id, task_name label, TIMESTAMPDIFF(HOUR,deadline,NOW()) age, assigned_employee_id employee_id FROM ops_checklist_tasks WHERE status NOT IN ('completed','complete','approved') AND deadline < NOW() AND deleted_at IS NULL ORDER BY deadline LIMIT 8") as $row) $attention[]=['type'=>'task','description'=>'Overdue task: '.$row['label'],'age_hours'=>(int)$row['age'],'employee_id'=>(int)$row['employee_id'],'href'=>'checklists.php'];
    foreach (ops_rows("SELECT id, COALESCE(error_title,category) label, TIMESTAMPDIFF(HOUR,logged_at,NOW()) age, employee_id FROM ops_error_logs WHERE status <> 'resolved' AND deleted_at IS NULL ORDER BY logged_at LIMIT 8") as $row) $attention[]=['type'=>'error','description'=>'Unresolved error: '.$row['label'],'age_hours'=>(int)$row['age'],'employee_id'=>(int)$row['employee_id'],'href'=>'errors.php'];
    usort($attention, static fn(array $a,array $b): int=>$b['age_hours']<=>$a['age_hours']); $attention=array_slice($attention,0,12);
    $names=[]; foreach(ops_rows("SELECT e.id AS id, e.full_name, r.name role_name, r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key<>'owner_admin' ORDER BY e.full_name") as $row)$names[(int)$row['id']]=$row;
    $team=[]; foreach($names as $id=>$employee){$team[]=['id'=>$id,'name'=>$employee['full_name'],'role'=>$employee['role_name'],'role_key'=>$employee['role_key'],'online'=>false,'hours_today'=>null,'metrics'=>[]];}
    $presenceIds=array_column(ops_rows("SELECT employee_id FROM ops_board_presence WHERE last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE)"),'employee_id');
    $hoursRows=ops_rows("SELECT user_id, SUM(TIMESTAMPDIFF(MINUTE,login_at,COALESCE(logout_at,last_seen_at)))/60 hours FROM kpi_sessions WHERE DATE(DATE_ADD(login_at,INTERVAL 2 HOUR))=CURDATE() GROUP BY user_id"); $hours=[];foreach($hoursRows as $r)$hours[(int)$r['user_id']]=(float)$r['hours'];
    $packerRows=ops_rows("SELECT assigned_employee_id id, COUNT(*) items, COALESCE(SUM(workload_points),0) points, SUM(packing_status NOT IN ('done','website','packed_label_needed','label_created')) open_items FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY assigned_employee_id",[$fromSql,$toSql]);$packerMap=[];foreach($packerRows as $r)$packerMap[(int)$r['id']]=$r;
    foreach($team as &$person){$id=(int)$person['id'];$person['online']=in_array($id,array_map('intval',$presenceIds),true);$person['hours_today']=$hours[$id]??null;if(strpos((string)$person['role_key'],'packer')!==false){$m=$packerMap[$id]??[];$person['metrics']=[['label'=>'Items','value'=>(int)($m['items']??0)],['label'=>'Weighted points','value'=>(float)($m['points']??0)],['label'=>'Open items','value'=>(int)($m['open_items']??0)]];}else{$person['metrics']=[['label'=>'Orders processed','value'=>null],['label'=>'Website updates','value'=>null],['label'=>'Avg update lag','value'=>null]];}}unset($person);
    $orderTrend=ops_rows("SELECT DATE(created_at) day, COUNT(*) orders, COALESCE(SUM(CASE WHEN {$paidRevenue} THEN total_amount ELSE 0 END),0) revenue FROM ops_orders WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY day",[$fromSql,$toSql]);
    $packingTrend=ops_rows("SELECT DATE(date_completed) day, assigned_employee_id, COUNT(*) items, COALESCE(SUM(workload_points),0) points FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY DATE(date_completed),assigned_employee_id ORDER BY day,assigned_employee_id",[$fromSql,$toSql]);
    $payload=['ok'=>true,'period'=>kpi_period_response($resolvedPeriod,$adoption,$from),'cards'=>$healthCards,'scores'=>[],'scores_disabled'=>true,'scores_message'=>'Composite scores and rankings are disabled while KPI source integrity is under review.','attention'=>$attention,'team'=>$team,'trends'=>['orders'=>$orderTrend,'packing'=>$packingTrend],'last_refreshed_at'=>(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format(DATE_ATOM),'definitions'=>['revenue'=>'Gross total of paid orders, excluding cancelled, refunded, failed and error-log records.']];
    @file_put_contents($cacheFile,json_encode($payload,JSON_UNESCAPED_SLASHES),LOCK_EX);
    header('X-KPI-Cache: MISS'); kpi_json($payload);
} catch (Throwable $error) {
    error_log(date(DATE_ATOM).' business health: '.$error->getMessage().PHP_EOL,3,BASE_PATH.'/logs/kpi_errors.log');
    kpi_json(['ok'=>false,'message'=>'Business Health is temporarily unavailable.'],500);
}
