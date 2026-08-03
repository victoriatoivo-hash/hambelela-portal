<?php

declare(strict_types=1);
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/kpi-reporting.php';
require_role('owner_admin');

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
    if((string)($_GET['action']??'')==='export_evidence_csv'){$exportFrom=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['date_from']??''))?(string)$_GET['date_from']:'2026-07-01';$exportTo=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['date_to']??''))?(string)$_GET['date_to']:date('Y-m-d');$exportEvents=kpi_unified_events($exportFrom.' 00:00:00',$exportTo.' 23:59:59',max(0,(int)($_GET['employee_id']??0))?:null);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="kpi-evidence-'.$exportFrom.'-'.$exportTo.'.csv"');$out=fopen('php://output','wb');fputcsv($out,['Event ID','Section','Record type','Record ID','Actor ID','Actor','Action','Previous status','New status','Occurred at','Source log','Source event ID','Evidence quality','Related reference','Metadata']);foreach($exportEvents as$event)fputcsv($out,[$event['event_id'],$event['section'],$event['record_type'],$event['record_id'],$event['actor_user_id'],$event['actor_name'],$event['action'],$event['previous_status'],$event['new_status'],$event['occurred_at'],$event['source_log'],$event['source_event_id'],$event['evidence_quality'],$event['related_reference'],json_encode($event['metadata'],JSON_UNESCAPED_SLASHES)]);fclose($out);exit;}
    if (!ops_database_ready()) throw new RuntimeException('The operations database is unavailable.');
    $zone = new DateTimeZone('Africa/Windhoek');
    $settings = [];
    foreach (ops_rows('SELECT setting_key,setting_value FROM kpi_settings') as $row) $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
    $trusted = new DateTimeImmutable($settings['data_start_date'] ?? '2026-07-01', $zone);
    $input = $_GET;
    $input['trusted_start_date'] = $trusted->format('Y-m-d');
    $resolved = kpi_resolve_reporting_period($input);
    $from = $resolved['from'] < $trusted ? $trusted : $resolved['from'];
    $to = $resolved['to'];
    $fromSql = $from->format('Y-m-d 00:00:00');
    $toSql = $to->format('Y-m-d 23:59:59');
    $sectionFrom=static function(string $key,string $fallback)use($from,$settings,$zone):string{$adopted=new DateTimeImmutable($settings[$key]??$fallback,$zone);return($from<$adopted?$adopted:$from)->format('Y-m-d 00:00:00');};
    $packingFromSql=$sectionFrom('packing_list_adoption_date','2026-07-01');
    $ordersFromSql=$sectionFrom('orders_attribution_adoption_date','2026-07-16');
    $tasksFromSql=$sectionFrom('tasks_adoption_date','2026-07-14');
    $websiteFromSql=$sectionFrom('website_timing_adoption_date','2026-07-15');
    $waybillsFromSql=$sectionFrom('waybills_adoption_date','2026-07-14');
    $errorsFromSql=$sectionFrom('error_log_adoption_date','2026-07-14');
    $employeeId = max(0, (int)($_GET['employee_id'] ?? 0));
    $role = trim((string)($_GET['role'] ?? 'all'));

    $employees = ops_rows("SELECT e.id,e.full_name,r.name role_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key<>'owner_admin' ORDER BY r.role_key,e.full_name");
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
        $reports[] = ['id'=>$id,'name'=>$person['full_name'],'role'=>$person['role_name'],'role_key'=>$person['role_key'],'packing'=>['product_rows'=>(int)($packing['product_rows']??0),'packages'=>(int)($packing['packages']??0),'workload_points'=>round((float)($packing['points']??0),1),'weight_grams'=>(float)($packing['grams']??0),'volume_ml'=>(float)($packing['millilitres']??0),'average_minutes'=>$packing['avg_minutes']===null?null:round((float)$packing['avg_minutes'],1),'timing_coverage'=>['numerator'=>(int)($packing['valid_timing']??0),'denominator'=>(int)($packing['product_rows']??0)],'requires_review'=>(int)($packing['review_rows']??0)],'orders'=>['packed'=>(int)($orders['packed_orders']??0)],'tasks'=>array_map('intval',$tasks),'website'=>['updates'=>(int)($website['updates']??0),'average_minutes'=>$website['avg_minutes']===null?null:round((float)$website['avg_minutes'],1)],'waybills'=>array_map('intval',$waybills),'quality'=>['eligible_work'=>$eligible,'verified_errors'=>$errorCount,'accuracy'=>$eligible>0&&$errorCount>0?round(max(0,100-(100*$errorCount/$eligible)),1):null,'status'=>$eligible>0&&$errorCount>0?'partial_data':'not_measured']];
    }
    foreach($reports as$reportIndex=>$reportRow){$reportEvents=kpi_unified_events($fromSql,$toSql,(int)$reportRow['id']);$reportSources=[];foreach($reportEvents as$reportEvent)$reportSources[$reportEvent['source_log']]=($reportSources[$reportEvent['source_log']]??0)+1;$reports[$reportIndex]['historical_evidence']=['events'=>count($reportEvents),'sources'=>$reportSources];}
    $coverage=kpi_activity_coverage_by_section();$quality = ['status'=>'partial_data','trusted_start_date'=>$trusted->format('Y-m-d'),'metric_adoption_dates'=>['packing_list'=>$coverage['packing_list']??($settings['packing_list_adoption_date']??'2026-07-01'),'orders'=>$coverage['orders']??($settings['orders_attribution_adoption_date']??'2026-07-16'),'packing_timing'=>$settings['packing_timing_adoption_date']??'2026-07-14','website_timing'=>$coverage['website_updates']??($settings['website_timing_adoption_date']??'2026-07-15'),'tasks'=>$coverage['tasks']??($settings['tasks_adoption_date']??'2026-07-14'),'waybills'=>$coverage['courier_waybills']??($settings['waybills_adoption_date']??'2026-07-14'),'bookkeeping'=>$coverage['bookkeeping']??($settings['bookkeeping_adoption_date']??'2026-07-14'),'errors'=>$coverage['error_log']??($settings['error_log_adoption_date']??'2026-07-14'),'attendance'=>$coverage['portal_activity']??($settings['attendance_adoption_date']??null)],'warnings'=>['Scores remain provisional when source coverage is incomplete. Existing Activity Logs are used where they reliably identify the actor, record and event; only the missing portion of an incomplete transition is unavailable.']];
    $charts = ['packers'=>[], 'front_desk'=>[]];
    foreach ($reports as $report) {
        if (strpos((string)$report['role_key'], 'packer') !== false) {
            $charts['packers'][] = ['id'=>$report['id'],'name'=>$report['name'],'orders'=>$report['orders']['packed'],'packages'=>$report['packing']['packages'],'points'=>$report['packing']['workload_points'],'timing_valid'=>$report['packing']['timing_coverage']['numerator'],'timing_total'=>$report['packing']['timing_coverage']['denominator']];
        } else {
            $charts['front_desk'][] = ['id'=>$report['id'],'name'=>$report['name'],'website'=>$report['website']['updates'],'waybills'=>$report['waybills']['sent'],'tasks'=>$report['tasks']['completed'],'on_time'=>$report['tasks']['on_time'],'overdue'=>$report['tasks']['open_overdue']];
        }
    }
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
    kpi_send_json(['ok'=>true,'period'=>['key'=>$resolved['key'],'from'=>$resolved['from']->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'effective_from'=>$from->format('Y-m-d')],'employees'=>$employees,'reports'=>$reports,'charts'=>$charts,'data_quality'=>$quality,'last_refreshed_at'=>(new DateTimeImmutable('now',$zone))->format(DATE_ATOM)]);
} catch(Throwable $error) {
    error_log(date(DATE_ATOM).' performance reports: '.$error->getMessage().' in '.$error->getFile().':'.$error->getLine().PHP_EOL,3,BASE_PATH.'/logs/kpi_errors.log');
    kpi_send_json(['ok'=>false,'success'=>false,'data'=>null,'message'=>'Performance reports are temporarily unavailable.','error_code'=>'KPI_PERFORMANCE_REPORT_FAILED'],500);
}
