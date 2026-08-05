<?php

declare(strict_types=1);
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/kpi-reporting.php';
require_once BASE_PATH . '/shared/epi/bootstrap.php';
require_role('owner_admin');

function performance_scored_metric(?float $score, int $sample, array $supporting = [], array $details = []): array
{
    $eligible = $sample >= 5 && $score !== null;
    return ['score'=>$eligible?round(max(0,min(100,$score)),1):null,'sample'=>$sample,'eligible'=>$eligible,'status'=>$eligible?'measured':'not_enough_data','supporting'=>$supporting,'details'=>$details];
}

function performance_overall_score(array $sections, array $weights): array
{
    $points=0.0;$used=0.0;$excluded=[];
    foreach($weights as$key=>$weight){$metric=$sections[$key]??null;if(!$metric||empty($metric['eligible'])){$excluded[]=$key;continue;}$w=(float)$weight;$points+=(float)$metric['score']*$w;$used+=$w;}
    return ['score'=>$used>0?round($points/$used,1):null,'weight_used'=>round($used,1),'weights_renormalised'=>$used>0&&$used<array_sum($weights),'excluded_sections'=>$excluded,'status'=>$used>0?'provisional':'not_enough_data'];
}

function performance_csv_string(array $headers, array $rows): string
{
    $stream = fopen('php://temp', 'w+b');
    fputcsv($stream, $headers);
    foreach ($rows as $row) fputcsv($stream, $row);
    rewind($stream);
    $csv = (string)stream_get_contents($stream);
    fclose($stream);
    return "\xEF\xBB\xBF" . $csv;
}

function performance_evidence(string $type, int $employeeId, string $fromSql, string $toSql, int $limit, int $offset): array
{
    $limit = max(1, min(50000, $limit));
    $offset = max(0, $offset);
    $whereEmployee = $employeeId > 0;
    $queries = [
        'orders' => [
            "SELECT id,order_number,customer_name,status,completed_at,assigned_packer_id FROM ops_orders WHERE status IN ('completed','packed','verified') AND completed_at BETWEEN ? AND ?" . ($whereEmployee ? ' AND assigned_packer_id=?' : '') . " ORDER BY completed_at DESC LIMIT {$limit} OFFSET {$offset}",
            "SELECT COUNT(*) total FROM ops_orders WHERE status IN ('completed','packed','verified') AND completed_at BETWEEN ? AND ?" . ($whereEmployee ? ' AND assigned_packer_id=?' : ''),
        ],
        'packing' => [
            "SELECT id,item_name,date_loaded,date_started,date_completed,workload_package_count,workload_points,workload_points_override,workload_parse_status,assigned_employee_id FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND deleted_at IS NULL" . ($whereEmployee ? ' AND assigned_employee_id=?' : '') . " ORDER BY date_completed DESC LIMIT {$limit} OFFSET {$offset}",
            "SELECT COUNT(*) total FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND deleted_at IS NULL" . ($whereEmployee ? ' AND assigned_employee_id=?' : ''),
        ],
        'tasks' => [
            "SELECT id,task_name,status,date_assigned,deadline,completed_at,assigned_employee_id FROM ops_checklist_tasks WHERE date_assigned BETWEEN ? AND ? AND deleted_at IS NULL" . ($whereEmployee ? ' AND assigned_employee_id=?' : '') . " ORDER BY date_assigned DESC LIMIT {$limit} OFFSET {$offset}",
            "SELECT COUNT(*) total FROM ops_checklist_tasks WHERE date_assigned BETWEEN ? AND ? AND deleted_at IS NULL" . ($whereEmployee ? ' AND assigned_employee_id=?' : ''),
        ],
        'website' => [
            "SELECT id,item_name,date_loaded,frontdesk_website_updated_at,frontdesk_website_updated_by FROM ops_packing_tasks WHERE frontdesk_website_updated_at BETWEEN ? AND ? AND deleted_at IS NULL" . ($whereEmployee ? ' AND frontdesk_website_updated_by=?' : '') . " ORDER BY frontdesk_website_updated_at DESC LIMIT {$limit} OFFSET {$offset}",
            "SELECT COUNT(*) total FROM ops_packing_tasks WHERE frontdesk_website_updated_at BETWEEN ? AND ? AND deleted_at IS NULL" . ($whereEmployee ? ' AND frontdesk_website_updated_by=?' : ''),
        ],
        'waybills' => [
            "SELECT id,courier_names,status,uploaded_at,due_by,sent_at,uploaded_by,sent_by FROM hambelela_waybills WHERE (uploaded_at BETWEEN ? AND ? OR sent_at BETWEEN ? AND ?) AND deleted_at IS NULL" . ($whereEmployee ? ' AND (uploaded_by=? OR sent_by=?)' : '') . " ORDER BY COALESCE(sent_at,uploaded_at) DESC LIMIT {$limit} OFFSET {$offset}",
            "SELECT COUNT(*) total FROM hambelela_waybills WHERE (uploaded_at BETWEEN ? AND ? OR sent_at BETWEEN ? AND ?) AND deleted_at IS NULL" . ($whereEmployee ? ' AND (uploaded_by=? OR sent_by=?)' : ''),
        ],
        'errors' => [
            "SELECT id,error_title,category,severity,logged_at,responsible_employee_id,accuracy_verified_by FROM ops_error_logs WHERE affects_kpi_accuracy=1 AND accuracy_verified_by IS NOT NULL AND logged_at BETWEEN ? AND ? AND deleted_at IS NULL" . ($whereEmployee ? ' AND responsible_employee_id=?' : '') . " ORDER BY logged_at DESC LIMIT {$limit} OFFSET {$offset}",
            "SELECT COUNT(*) total FROM ops_error_logs WHERE affects_kpi_accuracy=1 AND accuracy_verified_by IS NOT NULL AND logged_at BETWEEN ? AND ? AND deleted_at IS NULL" . ($whereEmployee ? ' AND responsible_employee_id=?' : ''),
        ],
    ];
    if (!isset($queries[$type])) return ['rows'=>[], 'total'=>0];
    if ($type === 'waybills') $params = $whereEmployee ? [$fromSql,$toSql,$fromSql,$toSql,$employeeId,$employeeId] : [$fromSql,$toSql,$fromSql,$toSql];
    else $params = $whereEmployee ? [$fromSql,$toSql,$employeeId] : [$fromSql,$toSql];
    $rows = ops_rows($queries[$type][0], $params);
    $count = ops_rows($queries[$type][1], $params);
    return ['rows'=>$rows, 'total'=>(int)($count[0]['total'] ?? 0)];
}

try {
    if((string)($_GET['action']??'')==='activity_log_audit'){kpi_send_json(['ok'=>true,'sources'=>kpi_activity_log_audit(),'coverage_by_section'=>kpi_activity_coverage_by_section()]);}
    if((string)($_GET['action']??'')==='export_evidence_csv'){$exportFrom=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['date_from']??''))?(string)$_GET['date_from']:'2026-07-01';$exportTo=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['date_to']??''))?(string)$_GET['date_to']:date('Y-m-d');$exportEvents=kpi_unified_events($exportFrom.' 00:00:00',$exportTo.' 23:59:59',max(0,(int)($_GET['employee_id']??0))?:null);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="employee-performance-evidence-'.$exportFrom.'-'.$exportTo.'.csv"');$out=fopen('php://output','wb');fputcsv($out,['Event ID','Section','Record type','Record ID','Actor ID','Actor','Action','Previous status','New status','Occurred at','Source log','Source event ID','Evidence quality','Related reference','Metadata']);foreach($exportEvents as$event)fputcsv($out,[$event['event_id'],$event['section'],$event['record_type'],$event['record_id'],$event['actor_user_id'],$event['actor_name'],$event['action'],$event['previous_status'],$event['new_status'],$event['occurred_at'],$event['source_log'],$event['source_event_id'],$event['evidence_quality'],$event['related_reference'],json_encode($event['metadata'],JSON_UNESCAPED_SLASHES)]);fclose($out);exit;}
    if (!ops_database_ready()) throw new RuntimeException('The operations database is unavailable.');
    $zone = new DateTimeZone('Africa/Windhoek');
    $settings = [];
    foreach (ops_rows('SELECT setting_key,setting_value FROM kpi_settings') as $row) $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
    $trusted = new DateTimeImmutable($settings['adoption_date'] ?? '2026-07-14', $zone);
    $input = $_GET;
    if((string)($input['period']??'since_adoption')==='since_adoption'){$input['period']='custom';$input['date_from']=$trusted->format('Y-m-d');$input['date_to']=(new DateTimeImmutable('today',$zone))->format('Y-m-d');}
    $input['trusted_start_date'] = $trusted->format('Y-m-d');
    $resolved = kpi_resolve_reporting_period($input);
    $from = $resolved['from'] < $trusted ? $trusted : $resolved['from'];
    $to = $resolved['to'];
    $fromSql = $from->format('Y-m-d 00:00:00');
    $toSql = $to->format('Y-m-d 23:59:59');
    $workingDays=ops_table_exists('epi_employee_business_calendar')?(int)(ops_rows("SELECT COUNT(*) total FROM epi_employee_business_calendar WHERE business_date BETWEEN ? AND ? AND is_working_day=1",[$from->format('Y-m-d'),$to->format('Y-m-d')])[0]['total']??0):0;if($workingDays===0){for($cursor=$from;$cursor<=$to;$cursor=$cursor->modify('+1 day'))if((int)$cursor->format('N')<=6)$workingDays++;}
    $sectionFrom=static function(string $key,string $fallback)use($from,$settings,$zone):string{$adopted=new DateTimeImmutable($settings[$key]??$fallback,$zone);return($from<$adopted?$adopted:$from)->format('Y-m-d 00:00:00');};
    $packingFromSql=$sectionFrom('packing_list_adoption_date','2026-07-01');
    $ordersFromSql=$sectionFrom('orders_attribution_adoption_date','2026-07-16');
    $tasksFromSql=$sectionFrom('tasks_adoption_date','2026-07-14');
    $websiteFromSql=$sectionFrom('website_timing_adoption_date','2026-07-15');
    $waybillsFromSql=$sectionFrom('waybills_adoption_date','2026-07-14');
    $errorsFromSql=$sectionFrom('error_log_adoption_date','2026-07-14');
    $employeeId = max(0, (int)($_GET['employee_id'] ?? 0));
    $role = trim((string)($_GET['role'] ?? 'all'));

    $employees = ops_rows("SELECT e.id,e.full_name,r.name role_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key<>'owner_admin' AND LOWER(e.full_name) NOT LIKE '%karina%' AND LOWER(e.full_name) NOT LIKE '%kaarina%' ORDER BY r.role_key,e.full_name");
    $reports = [];
    foreach ($employees as $person) {
        $id = (int)$person['id'];
        if ($employeeId && $employeeId !== $id) continue;
        $personRole=(string)$person['role_key'];
        if ($role==='packer' && strpos($personRole,'packer')===false) continue;
        if ($role==='front_desk' && strpos($personRole,'front_desk')===false) continue;
        if (!in_array($role,['all','packer','front_desk'],true) && $role!==$personRole) continue;
        $packing = ops_rows("SELECT COUNT(*) product_rows,COALESCE(SUM(workload_package_count),0) packages,COALESCE(SUM(CASE WHEN workload_parse_status='parsed' OR workload_points_override IS NOT NULL THEN COALESCE(workload_points_override,workload_points) ELSE 0 END),0) points,SUM(workload_parse_status='pending_review') review_rows,SUM(COALESCE(workload_weight_grams,0)) grams,SUM(COALESCE(workload_volume_ml,0)) millilitres,AVG(CASE WHEN date_loaded<=date_started AND date_started<=date_completed AND date_completed>=? THEN TIMESTAMPDIFF(MINUTE,date_started,date_completed) END) avg_minutes,SUM(date_loaded<=date_started AND date_started<=date_completed AND date_completed>=?) valid_timing FROM ops_packing_tasks WHERE assigned_employee_id=? AND date_completed BETWEEN ? AND ? AND deleted_at IS NULL",[$sectionFrom('packing_timing_adoption_date','2026-07-14'),$sectionFrom('packing_timing_adoption_date','2026-07-14'),$id,$packingFromSql,$toSql])[0]??[];
        $orders = ops_rows("SELECT COUNT(DISTINCT id) packed_orders FROM ops_orders WHERE assigned_packer_id=? AND status IN ('completed','packed','verified') AND completed_at BETWEEN ? AND ?",[$id,$ordersFromSql,$toSql])[0]??[];
        $tasks = ops_rows("SELECT COUNT(*) assigned,SUM(status='complete') completed,SUM(status='complete' AND completed_at<=deadline) on_time,SUM(status='complete' AND completed_at>deadline) completed_late,SUM(status<>'complete' AND deadline<NOW()) open_overdue FROM ops_checklist_tasks WHERE assigned_employee_id=? AND date_assigned BETWEEN ? AND ? AND deleted_at IS NULL",[$id,$tasksFromSql,$toSql])[0]??[];
        $website = ops_rows("SELECT COUNT(*) updates,AVG(CASE WHEN frontdesk_website_updated_at>=date_loaded THEN TIMESTAMPDIFF(MINUTE,date_loaded,frontdesk_website_updated_at) END) avg_minutes FROM ops_packing_tasks WHERE frontdesk_website_updated_by=? AND frontdesk_website_updated_at BETWEEN ? AND ? AND deleted_at IS NULL",[$id,$websiteFromSql,$toSql])[0]??[];
        $waybills = ops_rows("SELECT SUM(uploaded_by=?) uploaded,SUM(sent_by=?) sent,SUM(sent_by=? AND status='sent' AND sent_at<=due_by) sent_on_time,SUM(sent_by=? AND status='sent' AND sent_at>due_by) sent_late FROM hambelela_waybills WHERE (uploaded_at BETWEEN ? AND ? OR sent_at BETWEEN ? AND ?) AND deleted_at IS NULL",[$id,$id,$id,$id,$waybillsFromSql,$toSql,$waybillsFromSql,$toSql])[0]??[];
        $errors = ops_rows("SELECT COUNT(*) attributable_errors FROM ops_error_logs WHERE responsible_employee_id=? AND affects_kpi_accuracy=1 AND accuracy_verified_by IS NOT NULL AND logged_at BETWEEN ? AND ? AND deleted_at IS NULL",[$id,$errorsFromSql,$toSql])[0]??[];
        $eligible = (int)($packing['product_rows']??0) + (int)($orders['packed_orders']??0) + (int)($tasks['completed']??0);
        $errorCount = (int)($errors['attributable_errors']??0);
        $reports[] = ['id'=>$id,'name'=>$person['full_name'],'role'=>$person['role_name'],'role_key'=>$person['role_key'],'packing'=>['product_rows'=>(int)($packing['product_rows']??0),'packages'=>(int)($packing['packages']??0),'workload_points'=>round((float)($packing['points']??0),1),'weight_grams'=>(float)($packing['grams']??0),'volume_ml'=>(float)($packing['millilitres']??0),'average_minutes'=>$packing['avg_minutes']===null?null:round((float)$packing['avg_minutes'],1),'timing_coverage'=>['numerator'=>(int)($packing['valid_timing']??0),'denominator'=>(int)($packing['product_rows']??0)],'requires_review'=>(int)($packing['review_rows']??0)],'orders'=>['packed'=>(int)($orders['packed_orders']??0)],'tasks'=>array_map('intval',$tasks),'website'=>['updates'=>(int)($website['updates']??0),'average_minutes'=>$website['avg_minutes']===null?null:round((float)$website['avg_minutes'],1)],'waybills'=>array_map('intval',$waybills),'quality'=>['eligible_work'=>$eligible,'verified_errors'=>$errorCount,'accuracy'=>$eligible>0?round(max(0,100-(100*$errorCount/$eligible)),1):null,'status'=>$eligible>0?'measured':'not_measured']];
    }
    $defaultWeights=['packer'=>['packing'=>35,'orders'=>20,'tasks'=>15,'waybills'=>10,'quality'=>10,'attendance'=>10],'front_desk'=>['bookkeeping'=>30,'orders'=>25,'tasks'=>15,'waybills'=>10,'quality'=>10,'attendance'=>10]];
    $configuredWeights=json_decode((string)($settings['report_weights']??''),true);if(!is_array($configuredWeights))$configuredWeights=$defaultWeights;
    $epiFilters=['period'=>'custom','date_from'=>$from->format('Y-m-d'),'date_to'=>$to->format('Y-m-d')];
    $ordersEngine=new \Hambelela\EPI\OrdersPerformance(db());$packingEngine=new \Hambelela\EPI\PackingPerformance(db());$taskEngine=new \Hambelela\EPI\TaskPerformance(db());$courierEngine=new \Hambelela\EPI\CourierPerformance(db());$bookkeepingEngine=new \Hambelela\EPI\BookkeepingPerformance(db());$attendanceEngine=new \Hambelela\EPI\AttendancePerformance(db());
    $bookkeepingRows=$bookkeepingEngine->getOrderReconciliation($epiFilters);$bookkeepingDaily=$bookkeepingEngine->getDailyReconciliation($epiFilters);$cashTotal=count($bookkeepingRows);$cashMatched=count(array_filter($bookkeepingRows,static fn(array$x):bool=>in_array((string)$x['match_status'],['exact_match','date_mismatch'],true)));$cashupDays=(int)($bookkeepingDaily['summary']['days_with_entries']??0);$cashupDone=(int)($bookkeepingDaily['summary']['fully_reconciled']??0);
    $missingCash=array_values(array_map(static fn(array$x):array=>['record_id'=>$x['order_id'],'reference'=>$x['order_number'],'date'=>$x['cash_transaction_at'],'amount'=>round(((int)$x['expected_cash_cents'])/100,2),'match_status'=>$x['match_status'],'match_method'=>$x['match_method'],'url'=>'orders-board.php?order_id='.(int)$x['order_id']],array_filter($bookkeepingRows,static fn(array$x):bool=>(string)$x['match_status']==='missing_bookkeeping_entry')));
    foreach($reports as$reportIndex=>$reportRow){
        $id=(int)$reportRow['id'];$local=$epiFilters+['employee_id'=>$id];$isPacker=strpos((string)$reportRow['role_key'],'packer')!==false;$roleGroup=$isPacker?'packer':'front_desk';
        $orderSummary=$ordersEngine->getSummary($local);$orderEvidence=$ordersEngine->getEvidence($local,10000);$orderCompleted=array_values(array_filter($orderEvidence,static fn(array$x):bool=>(string)$x['action']==='order_completed'));$targetMinutes=max(1,(float)($settings['target_fulfilment_hours']??6)*60);$orderTimed=array_values(array_filter($orderCompleted,static fn(array$x):bool=>$x['working_minutes']!==null));$orderWithin=count(array_filter($orderTimed,static fn(array$x):bool=>(float)$x['working_minutes']<=$targetMinutes));
        if($isPacker){$orderN=count($orderCompleted);$orderOnTime=$orderN>0?max(0,100-(100*(int)$orderSummary['late_orders']/$orderN)):null;$orderTarget=count($orderTimed)>0?100*$orderWithin/count($orderTimed):null;$orderScore=$orderOnTime===null||$orderTarget===null?null:.6*$orderOnTime+.4*$orderTarget;$orderMetric=performance_scored_metric($orderScore,$orderN,['orders_packed'=>$reportRow['orders']['packed'],'active_timing_count'=>count($orderTimed),'average_active_minutes'=>$orderSummary['average_completion_minutes'],'on_time_percent'=>$orderOnTime,'within_target_percent'=>$orderTarget]);}
        else{$walkins=array_values(array_filter($orderCompleted,static function(array$x):bool{$m=json_decode((string)($x['metadata_json']??''),true);return!empty($m['is_walk_in']);}));$walkinTarget=max(1,(int)($settings['walkin_completion_target_minutes']??60));$walkTimed=array_values(array_filter($walkins,static fn(array$x):bool=>$x['working_minutes']!==null));$walkWithin=count(array_filter($walkTimed,static fn(array$x):bool=>(float)$x['working_minutes']<=$walkinTarget));$walkScore=$walkTimed?100*$walkWithin/count($walkTimed):null;$orderMetric=performance_scored_metric($walkScore,count($walkins),['completed'=>count($walkins),'within_target'=>$walkWithin,'target_minutes'=>$walkinTarget,'currently_open'=>(int)($orderSummary['orders_outstanding']??0),'average_lag_minutes'=>$orderSummary['average_completion_minutes']]);}
        $packingSummary=$packingEngine->getEmployeeSummary($local);$packingEvidence=$packingEngine->getEvidence($local,10000);$packingDone=(int)$packingSummary['items_completed'];$packingOnTime=max(0,$packingDone-(int)($packingSummary['priority']['compliance']['completed_late']??0));$packingWithin=$packingDone?100*$packingOnTime/$packingDone:null;$packingAccuracy=$packingSummary['quantity']['accuracy_percent'];$packingBase=$packingWithin===null||$packingAccuracy===null?null:.4*$packingWithin+.3*(float)$packingAccuracy+.3*100;$packingMetric=performance_scored_metric($packingBase,$packingDone,['items_packed'=>$packingDone,'weighted_points'=>round((float)$packingSummary['workload']['workload_points'],1),'average_active_minutes'=>$packingSummary['turnaround']['started_to_done']['average'],'within_target_percent'=>$packingWithin,'accuracy_percent'=>$packingAccuracy,'high_weight_share'=>$packingDone?round(100*((int)$packingSummary['workload']['heavy']+(int)$packingSummary['workload']['very_heavy'])/$packingDone,1):null]);
        $taskSummary=$taskEngine->getSummary($local);$taskCompleted=(int)$taskSummary['status']['completed'];$taskOpen=(int)$taskSummary['status']['still_open'];$taskN=$taskCompleted+$taskOpen;$taskDue=(float)($taskSummary['timeliness']['on_time_percent']??0);$openCompliance=$taskOpen>0?100*(1-(int)$taskSummary['current_risk']['overdue']/$taskOpen):100;$taskScore=$taskCompleted>0?.7*$taskDue+.3*max(0,$openCompliance):null;$taskMetric=performance_scored_metric($taskScore,$taskN,['completed'=>$taskCompleted,'pending'=>$taskOpen,'overdue'=>(int)$taskSummary['current_risk']['overdue'],'on_time_percent'=>$taskSummary['timeliness']['on_time_percent'],'average_completion_minutes'=>$taskSummary['timeliness']['assigned_to_completed']['average'],'total_overdue_minutes'=>$taskSummary['current_risk']['business_minutes_overdue']]);
        $courierRows=$courierEngine->getEvidence($local,1000);if($isPacker){$courierRelevant=array_values(array_filter($courierRows,static fn(array$x):bool=>(int)($x['uploaded_by']??0)===$id&&!in_array($x['upload_result'],['Insufficient historical data'],true)));$courierOn=count(array_filter($courierRelevant,static fn(array$x):bool=>$x['upload_result']==='on_time'));$courierOverdue=0;$courierDetails=array_values(array_filter($courierRelevant,static fn(array$x):bool=>$x['upload_result']==='late'));}else{$courierRelevant=array_values(array_filter($courierRows,static fn(array$x):bool=>(int)($x['sent_by']??0)===$id&&!in_array($x['send_result'],['Insufficient historical data','blocked_by_late_upload','sent_after_late_availability'],true)));$courierOn=count(array_filter($courierRelevant,static fn(array$x):bool=>$x['send_result']==='on_time'));$courierOverdue=count(array_filter($courierRows,static fn(array$x):bool=>$x['send_result']==='overdue'&&(int)($x['sent_by']??0)===$id));$courierDetails=array_values(array_filter($courierRelevant,static fn(array$x):bool=>in_array($x['send_result'],['late','overdue','late_after_availability'],true)));}$courierN=count($courierRelevant);$courierRate=$courierN?100*$courierOn/$courierN:null;$courierMetric=performance_scored_metric($courierRate===null?null:max(0,$courierRate-5*$courierOverdue),$courierN,['uploaded'=>count(array_filter($courierRows,static fn(array$x):bool=>(int)($x['uploaded_by']??0)===$id)),'sent'=>count(array_filter($courierRows,static fn(array$x):bool=>(int)($x['sent_by']??0)===$id)),'on_time_percent'=>$courierRate,'late'=>$courierN-$courierOn,'overdue_pending'=>$courierOverdue],array_slice($courierDetails,0,50));
        $attendance=$attendanceEngine->getSummary($local);$expected=max(0,(int)$attendance['scheduled_days']-(int)$attendance['approved_leave_days']);$attendanceRate=$expected?100*(int)$attendance['present_days']/$expected:null;$punctualN=(int)$attendance['on_time_days']+(int)$attendance['within_grace_days']+(int)$attendance['late_days'];$punctual=$punctualN?100*((int)$attendance['on_time_days']+(int)$attendance['within_grace_days'])/$punctualN:null;$attendanceScore=$attendanceRate===null||$punctual===null?null:.8*$attendanceRate+.2*$punctual;$attendanceMetric=performance_scored_metric($attendanceScore,$expected,['expected_days'=>$expected,'present_days'=>(int)$attendance['present_days'],'approved_leave_days'=>(int)$attendance['approved_leave_days'],'late_arrivals'=>(int)$attendance['late_days'],'attendance_rate'=>$attendanceRate,'punctuality_rate'=>$punctual,'average_daily_active_hours'=>(int)$attendance['present_days']>0?round((int)$attendance['portal_active_minutes']/60/(int)$attendance['present_days'],1):null]);
        $qualityN=(int)$reportRow['quality']['eligible_work'];$qualityMetric=performance_scored_metric(max(0,100-10*(int)$reportRow['quality']['verified_errors']),$qualityN,['employee_cause_errors'=>(int)$reportRow['quality']['verified_errors'],'eligible_work'=>$qualityN,'resolved_percent'=>null,'average_resolution_minutes'=>null]);
        $bookkeepingRate=$cashTotal?100*$cashMatched/$cashTotal:null;$cashupRate=$cashupDays?100*$cashupDone/$cashupDays:null;$bookkeepingScore=$bookkeepingRate===null||$cashupRate===null?null:.7*$bookkeepingRate+.3*$cashupRate;$bookkeepingMetric=performance_scored_metric($bookkeepingScore,$cashTotal,['cash_orders_total'=>$cashTotal,'logged'=>$cashMatched,'missing'=>$cashTotal-$cashMatched,'match_rate'=>$bookkeepingRate,'cashup_working_days'=>$cashupDays,'cashup_completed_days'=>$cashupDone,'cashup_compliance'=>$cashupRate],array_slice($missingCash,0,50));
        $sections=['orders'=>$orderMetric,'packing'=>$packingMetric,'tasks'=>$taskMetric,'waybills'=>$courierMetric,'quality'=>$qualityMetric,'attendance'=>$attendanceMetric];if(!$isPacker)$sections['bookkeeping']=$bookkeepingMetric;
        $reports[$reportIndex]['scored_sections']=$sections;$reports[$reportIndex]['overall_score']=performance_overall_score($sections,$configuredWeights[$roleGroup]??$defaultWeights[$roleGroup]);
        $reportEvents=kpi_unified_events($fromSql,$toSql,$id);$reportSources=[];foreach($reportEvents as$reportEvent)$reportSources[$reportEvent['source_log']]=($reportSources[$reportEvent['source_log']]??0)+1;$reports[$reportIndex]['historical_evidence']=['events'=>count($reportEvents),'sources'=>$reportSources];
    }
    $packerPoints=array_values(array_map(static fn(array$r):float=>(float)($r['scored_sections']['packing']['supporting']['weighted_points']??0),array_filter($reports,static fn(array$r):bool=>strpos((string)$r['role_key'],'packer')!==false)));$packerAverage=$packerPoints?array_sum($packerPoints)/count($packerPoints):0.0;
    foreach($reports as$reportIndex=>$reportRow){if(strpos((string)$reportRow['role_key'],'packer')===false)continue;$packing=$reportRow['scored_sections']['packing'];$support=$packing['supporting'];$outputScore=$packerAverage>0?min(100,100*(float)$support['weighted_points']/$packerAverage):null;$support['output_vs_packer_average_percent']=$outputScore;$score=$support['within_target_percent']===null||$support['accuracy_percent']===null||$outputScore===null?null:.4*(float)$support['within_target_percent']+.3*(float)$support['accuracy_percent']+.3*$outputScore;$reports[$reportIndex]['scored_sections']['packing']=performance_scored_metric($score,(int)$packing['sample'],$support,$packing['details']);$reports[$reportIndex]['overall_score']=performance_overall_score($reports[$reportIndex]['scored_sections'],$configuredWeights['packer']??$defaultWeights['packer']);}
    $coverage=kpi_activity_coverage_by_section();$quality = ['status'=>'partial_data','trusted_start_date'=>$trusted->format('Y-m-d'),'metric_adoption_dates'=>['packing_list'=>$coverage['packing_list']??($settings['packing_list_adoption_date']??'2026-07-01'),'orders'=>$coverage['orders']??($settings['orders_attribution_adoption_date']??'2026-07-16'),'packing_timing'=>$settings['packing_timing_adoption_date']??'2026-07-14','website_timing'=>$coverage['website_updates']??($settings['website_timing_adoption_date']??'2026-07-15'),'tasks'=>$coverage['tasks']??($settings['tasks_adoption_date']??'2026-07-14'),'waybills'=>$coverage['courier_waybills']??($settings['waybills_adoption_date']??'2026-07-14'),'bookkeeping'=>$coverage['bookkeeping']??($settings['bookkeeping_adoption_date']??'2026-07-14'),'errors'=>$coverage['error_log']??($settings['error_log_adoption_date']??'2026-07-14'),'attendance'=>$coverage['portal_activity']??($settings['attendance_adoption_date']??null)],'warnings'=>['Scores remain provisional when source coverage is incomplete. Existing Activity Logs are used where they reliably identify the actor, record and event; only the missing portion of an incomplete transition is unavailable.']];
    $charts = ['packers'=>[], 'front_desk'=>[]];
    foreach ($reports as $report) {
        if (strpos((string)$report['role_key'], 'packer') !== false) {
            $charts['packers'][] = ['id'=>$report['id'],'name'=>$report['name'],'orders'=>$report['orders']['packed'],'packages'=>$report['packing']['packages'],'points'=>$report['packing']['workload_points'],'timing_valid'=>$report['packing']['timing_coverage']['numerator'],'timing_total'=>$report['packing']['timing_coverage']['denominator']];
        } else {
            $charts['front_desk'][] = ['id'=>$report['id'],'name'=>$report['name'],'website'=>$report['website']['updates'],'waybills'=>$report['waybills']['sent'],'tasks'=>$report['tasks']['completed'],'on_time'=>$report['tasks']['on_time'],'overdue'=>$report['tasks']['open_overdue']];
        }
    }
    $teamSummary=['employees'=>count($reports),'orders_packed'=>0,'packing_rows'=>0,'packages'=>0,'workload_points'=>0.0,'tasks_completed'=>0,'tasks_overdue'=>0,'website_updates'=>0,'waybills_sent'=>0,'waybills_late'=>0,'verified_errors'=>0,'eligible_work'=>0];
    $risks=[];
    foreach($reports as $report){
        $teamSummary['orders_packed']+=(int)$report['orders']['packed'];$teamSummary['packing_rows']+=(int)$report['packing']['product_rows'];$teamSummary['packages']+=(int)$report['packing']['packages'];$teamSummary['workload_points']+=(float)$report['packing']['workload_points'];$teamSummary['tasks_completed']+=(int)$report['tasks']['completed'];$teamSummary['tasks_overdue']+=(int)$report['tasks']['open_overdue'];$teamSummary['website_updates']+=(int)$report['website']['updates'];$teamSummary['waybills_sent']+=(int)$report['waybills']['sent'];$teamSummary['waybills_late']+=(int)$report['waybills']['sent_late'];$teamSummary['verified_errors']+=(int)$report['quality']['verified_errors'];$teamSummary['eligible_work']+=(int)$report['quality']['eligible_work'];
        if((int)$report['tasks']['open_overdue']>0)$risks[]=['severity'=>'high','employee_id'=>$report['id'],'employee'=>$report['name'],'section'=>'Tasks','message'=>$report['tasks']['open_overdue'].' active task(s) overdue'];
        if((int)$report['waybills']['sent_late']>0)$risks[]=['severity'=>'medium','employee_id'=>$report['id'],'employee'=>$report['name'],'section'=>'Courier','message'=>$report['waybills']['sent_late'].' waybill(s) sent after the recorded due time'];
        if((int)$report['packing']['requires_review']>0)$risks[]=['severity'=>'review','employee_id'=>$report['id'],'employee'=>$report['name'],'section'=>'Packing','message'=>$report['packing']['requires_review'].' workload row(s) require review'];
    }
    $teamSummary['workload_points']=round($teamSummary['workload_points'],1);$teamSummary['accuracy']=$teamSummary['eligible_work']>0?round(max(0,100-(100*$teamSummary['verified_errors']/$teamSummary['eligible_work'])),1):null;
    $modeRows=ops_rows("SELECT LOWER(TRIM(COALESCE(NULLIF(fulfilment_mode,''),order_type))) stored_value,COUNT(*) records FROM ops_orders WHERE created_at BETWEEN ? AND ? GROUP BY stored_value ORDER BY records DESC",[$fromSql,$toSql]);
    $paymentRows=ops_rows("SELECT LOWER(TRIM(payment_method)) stored_value,COUNT(*) records FROM order_payment_allocations WHERE updated_at BETWEEN ? AND ? GROUP BY stored_value ORDER BY records DESC",[$fromSql,$toSql]);
    $courierStatusRows=ops_rows("SELECT LOWER(TRIM(status)) stored_value,COUNT(*) records FROM hambelela_waybills WHERE COALESCE(sent_at,uploaded_at) BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY stored_value ORDER BY records DESC",[$fromSql,$toSql]);
    $fieldMappings=['walk_in'=>['configured_value'=>$settings['walkin_mode_value']??'walk_in','observed_values'=>$modeRows,'mapping'=>'COALESCE(ops_orders.fulfilment_mode, ops_orders.order_type); EPI metadata is_walk_in is authoritative for historical scoring.'],'cash_payments'=>['configured_values'=>array_values(array_filter(array_map('trim',explode(',',(string)($settings['cash_payment_values']??'cash'))))),'observed_values'=>$paymentRows,'mapping'=>'order_payment_allocations.payment_method; authoritative cash component is stored as cash.'],'courier_statuses'=>['observed_values'=>$courierStatusRows,'mapping'=>'hambelela_waybills.status with uploaded_at and sent_at; responsibility is separated between uploaded_by and sent_by.'],'cutoffs'=>['same_day'=>$settings['courier_sameday_cutoff']??'17:00','next_working_day'=>$settings['courier_nextday_cutoff']??'09:00']];
    if ((string)($_GET['action']??'') === 'evidence') {
        $type = trim((string)($_GET['evidence_type'] ?? 'orders'));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
        $evidence = performance_evidence($type, $employeeId, $fromSql, $toSql, $perPage, ($page-1)*$perPage);
        kpi_send_json(['ok'=>true,'type'=>$type,'page'=>$page,'per_page'=>$perPage,'total'=>$evidence['total'],'rows'=>$evidence['rows']]);
    }
    if ((string)($_GET['action']??'') === 'export_bundle') {
        if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP export is not available on this server.');
        $temp = tempnam(sys_get_temp_dir(), 'performance-');
        $zip = new ZipArchive();
        if ($zip->open($temp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('The report archive could not be created.');
        $summaryRows=[];
        foreach($reports as $r)$summaryRows[]=[$r['name'],$r['role'],$r['orders']['packed'],$r['packing']['product_rows'],$r['packing']['packages'],$r['packing']['workload_points'],$r['packing']['timing_coverage']['numerator'].'/'.$r['packing']['timing_coverage']['denominator'],$r['tasks']['completed'],$r['tasks']['on_time'],$r['tasks']['open_overdue'],$r['website']['updates'],$r['waybills']['sent'],$r['quality']['verified_errors'],$r['quality']['accuracy']??''];
        $zip->addFromString('Summary.csv',performance_csv_string(['Employee','Role','Orders packed','Packing rows','Packages','Workload points','Timing coverage','Tasks completed','Tasks on time','Open overdue','Website updates','Waybills sent','Verified errors','Accuracy'],$summaryRows));
        $allowedIds=array_map(static function(array $report):int{return (int)$report['id'];},$reports);
        $employeeKeys=['orders'=>'assigned_packer_id','packing'=>'assigned_employee_id','tasks'=>'assigned_employee_id','website'=>'frontdesk_website_updated_by','errors'=>'responsible_employee_id'];
        foreach(['orders','packing','tasks','website','waybills','errors'] as $type){
            $e=performance_evidence($type,$employeeId,$fromSql,$toSql,50000,0);$rows=$e['rows'];
            if($employeeId===0&&$role!=='all'){
                if($type==='waybills')$rows=array_values(array_filter($rows,static function(array $row)use($allowedIds):bool{return in_array((int)($row['uploaded_by']??0),$allowedIds,true)||in_array((int)($row['sent_by']??0),$allowedIds,true);}));
                else{$employeeKey=$employeeKeys[$type]??'';if($employeeKey)$rows=array_values(array_filter($rows,static function(array $row)use($allowedIds,$employeeKey):bool{return in_array((int)($row[$employeeKey]??0),$allowedIds,true);}));}
            }
            $headers=$rows?array_keys($rows[0]):['No records'];$csvRows=[];foreach($rows as $row)$csvRows[]=array_values($row);$name=$type==='packing'?'Packing List':ucwords(str_replace('_',' ',$type));$zip->addFromString($name.'.csv',performance_csv_string($headers,$csvRows));
        }
        $zip->addFromString('Bookkeeping.csv',performance_csv_string(['Data quality notice'],[['Bookkeeping evidence is not attributed to individual performance until its attribution rules are verified.']]));
        $zip->addFromString('Attendance.csv',performance_csv_string(['Data quality notice'],[['Attendance is not scored until schedules and session records are verified.']]));
        $zip->addFromString('Data Quality.csv',performance_csv_string(['Setting','Value'],[['Trusted start date',$quality['trusted_start_date']],['Status',$quality['status']],['Orders attribution adoption',$quality['metric_adoption_dates']['orders']],['Packing timing adoption',$quality['metric_adoption_dates']['packing_timing']],['Website timing adoption',$quality['metric_adoption_dates']['website_timing']],['Attendance adoption',$quality['metric_adoption_dates']['attendance']??'Not reliably measured'],['Disclosure',implode(' ',$quality['warnings'])]]));
        $zip->close();
        header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="performance-evidence-'.$from->format('Ymd').'-'.$to->format('Ymd').'.zip"');header('Content-Length: '.filesize($temp));readfile($temp);unlink($temp);exit;
    }
    if ((string)($_GET['action']??'') === 'export_csv') {
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="performance-report-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv"');
        $out=fopen('php://output','wb');fputcsv($out,['Employee','Role','Orders packed','Packing rows','Packages','Workload points','Timing coverage','Tasks completed','Tasks on time','Open overdue','Website updates','Waybills sent','Verified errors','Accuracy']);
        foreach($reports as $r)fputcsv($out,[$r['name'],$r['role'],$r['orders']['packed'],$r['packing']['product_rows'],$r['packing']['packages'],$r['packing']['workload_points'],$r['packing']['timing_coverage']['numerator'].'/'.$r['packing']['timing_coverage']['denominator'],$r['tasks']['completed'],$r['tasks']['on_time'],$r['tasks']['open_overdue'],$r['website']['updates'],$r['waybills']['sent'],$r['quality']['verified_errors'],$r['quality']['accuracy']??'—']);fclose($out);exit;
    }
    kpi_send_json(['ok'=>true,'report'=>['name'=>'Employee Performance Analysis Report','status'=>'provisional','baseline'=>$trusted->format('Y-m-d'),'minimum_sample'=>5,'weights'=>$configuredWeights],'period'=>['key'=>$resolved['key'],'from'=>$resolved['from']->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'effective_from'=>$from->format('Y-m-d'),'working_days'=>$workingDays],'employees'=>$employees,'reports'=>$reports,'charts'=>$charts,'team_summary'=>$teamSummary,'operational_risks'=>$risks,'cross_checks'=>['bookkeeping'=>['cash_orders_total'=>$cashTotal,'matched'=>$cashMatched,'missing'=>count($missingCash),'missing_orders'=>$missingCash,'sample_examples'=>array_slice($bookkeepingRows,0,3)],'late_couriers'=>array_slice(array_values(array_filter($courierEngine->getEvidence($epiFilters,1000),static fn(array$x):bool=>in_array($x['send_result'],['late','overdue','late_after_availability'],true))),0,100)],'field_mappings'=>$fieldMappings,'data_quality'=>$quality,'last_refreshed_at'=>(new DateTimeImmutable('now',$zone))->format(DATE_ATOM)]);
} catch(Throwable $error) {
    error_log(date(DATE_ATOM).' performance reports: '.$error->getMessage().' in '.$error->getFile().':'.$error->getLine().PHP_EOL,3,BASE_PATH.'/logs/kpi_errors.log');
    kpi_send_json(['ok'=>false,'success'=>false,'data'=>null,'message'=>'Performance reports are temporarily unavailable.','error_code'=>'KPI_PERFORMANCE_REPORT_FAILED'],500);
}
