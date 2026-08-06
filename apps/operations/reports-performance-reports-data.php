<?php

declare(strict_types=1);
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/kpi-reporting.php';
require_once BASE_PATH . '/shared/epi/bootstrap.php';
require_role('owner_admin');

function performance_scored_metric(?float $score, int $sample, array $supporting = [], array $details = [], bool $forceEligible = false): array
{
    $eligible = ($forceEligible || $sample >= 5) && $score !== null;
    return ['score'=>$eligible?round(max(0,min(100,$score)),1):null,'sample'=>$sample,'eligible'=>$eligible,'status'=>$eligible?'measured':'not_enough_data','supporting'=>$supporting,'details'=>$details];
}

function performance_is_complete_status(string $status): bool
{
    return in_array(strtolower(trim($status)), ['complete','completed','packed','verified'], true);
}

function performance_walk_in_sql(string $alias = 'o'): string
{
    return "LOWER(CONCAT_WS(' ',COALESCE({$alias}.customer_contact,''),COALESCE({$alias}.customer_name,''),COALESCE({$alias}.fulfilment_mode,''),COALESCE({$alias}.order_type,''))) REGEXP 'walk[ _-]*in'";
}

function performance_error_person_filter(string $alias, int $employeeId): array
{
    return ["({$alias}.responsible_employee_id=? OR {$alias}.attributed_employee_id=? OR {$alias}.employee_id=? OR {$alias}.people_involved LIKE ? OR {$alias}.people_involved LIKE ? OR {$alias}.people_involved LIKE ? OR {$alias}.people_involved LIKE ?)",[$employeeId,$employeeId,$employeeId,'['.$employeeId.']','['.$employeeId.',%','%,'.$employeeId.',%','%,'.$employeeId.']']];
}

function performance_task_metrics(int $employeeId, string $fromSql, string $toSql, DateTimeImmutable $now): array
{
    $rows=ops_rows("SELECT id,task_name,status,date_assigned,deadline,completed_at,date_completed FROM ops_checklist_tasks WHERE assigned_employee_id=? AND deleted_at IS NULL AND deadline IS NOT NULL AND deadline<=? AND (deadline>=? OR (LOWER(status) NOT IN ('complete','completed','cancelled') AND deadline<?)) ORDER BY deadline",[$employeeId,$toSql,$fromSql,$now->format('Y-m-d H:i:s')]);
    $completed=0;$onTime=0;$completedLate=0;$open=0;$openOverdue=0;$details=[];$nowTs=$now->getTimestamp();
    foreach($rows as$row){$status=strtolower((string)$row['status']);$done=performance_is_complete_status($status);$completedAt=(string)($row['completed_at']?:$row['date_completed']);$dueTs=strtotime((string)$row['deadline'])?:0;$completedTs=$completedAt!==''?(strtotime($completedAt)?:0):0;if($done){$completed++;if($completedTs&&$dueTs&&$completedTs<=$dueTs)$onTime++;else{$completedLate++;$details[]=['record_id'=>(int)$row['id'],'task_name'=>$row['task_name'],'due_at'=>$row['deadline'],'completed_at'=>$completedAt,'late_days'=>$dueTs&&$completedTs?round(($completedTs-$dueTs)/86400,1):null,'state'=>'Completed late'];}}else{$open++;if($dueTs&&$dueTs<$nowTs){$openOverdue++;$details[]=['record_id'=>(int)$row['id'],'task_name'=>$row['task_name'],'due_at'=>$row['deadline'],'completed_at'=>null,'late_days'=>round(($nowTs-$dueTs)/86400,1),'state'=>'Open overdue'];}}}
    $denominator=$completed+$openOverdue;$onTimePercent=$denominator>0?100*$onTime/$denominator:null;
    usort($details,static fn(array$a,array$b):int=>(float)($b['late_days']??0)<=>(float)($a['late_days']??0));
    return ['assigned'=>count($rows),'completed'=>$completed,'on_time'=>$onTime,'completed_late'=>$completedLate,'pending'=>$open,'open_overdue'=>$openOverdue,'on_time_denominator'=>$denominator,'on_time_percent'=>$onTimePercent,'details'=>$details];
}

function performance_overall_score(array $sections, array $weights): array
{
    $points=0.0;$used=0.0;$excluded=[];
    foreach($weights as$key=>$weight){$metric=$sections[$key]??null;if(!$metric||empty($metric['eligible'])){$excluded[]=$key;continue;}$w=(float)$weight;$points+=(float)$metric['score']*$w;$used+=$w;}
    return ['score'=>$used>0?round($points/$used,1):null,'weight_used'=>round($used,1),'weights_renormalised'=>$used>0&&$used<array_sum($weights),'excluded_sections'=>$excluded,'status'=>$used>0?'provisional':'not_enough_data'];
}

function performance_weighted_score(array $components): ?float
{
    $points = 0.0;
    $used = 0.0;
    foreach ($components as $component) {
        if ($component[0] === null) continue;
        $points += (float)$component[0] * (float)$component[1];
        $used += (float)$component[1];
    }
    return $used > 0 ? $points / $used : null;
}

/** Run one report section without allowing it to abort sibling sections. */
function performance_section_attempt(array &$errors, string $section, callable $callback, $fallback)
{
    try {
        return $callback();
    } catch (Throwable $error) {
        $reason = $error->getMessage() !== '' ? $error->getMessage() : 'Source query failed.';
        $errors[$section] = $reason;
        error_log(date(DATE_ATOM).' performance section '.$section.': '.$reason.' in '.$error->getFile().':'.$error->getLine().PHP_EOL,3,BASE_PATH.'/logs/kpi_errors.log');
        return $fallback;
    }
}

function performance_order_status_key(string $value): string
{
    $value = strtolower(trim(str_replace(['-', ' '], '_', $value)));
    if (in_array($value, ['complete','completed','packed','verified','order_completed'], true)) return 'complete';
    if (in_array($value, ['in_progress','started','packing_started'], true)) return 'in_progress';
    if (in_array($value, ['new','new_order','pending'], true)) return 'new';
    return $value;
}

function performance_orders_analysis(array $employees, string $fromSql, string $toSql): array
{
    $displayedAt = ops_order_display_datetime_expr('o');
    $archive = ops_column_exists('ops_orders','archived_at') ? ' AND o.archived_at IS NULL' : '';
    $deleted = ops_column_exists('ops_orders','deleted_at') ? ' AND o.deleted_at IS NULL' : '';
    $orders = ops_rows("SELECT o.id,o.order_number,o.customer_name,o.customer_contact,o.status,o.assigned_packer_id,{$displayedAt} loaded_at,LOWER(TRIM(COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type,''))) stored_mode FROM ops_orders o WHERE {$displayedAt} BETWEEN ? AND ?{$archive}{$deleted} ORDER BY {$displayedAt},o.id",[$fromSql,$toSql]);
    $employeeNames=[];$employeeRoles=[];
    foreach($employees as$person){$employeeNames[(int)$person['id']]=(string)$person['full_name'];$employeeRoles[(int)$person['id']]=(string)$person['role_key'];}
    $eventsByOrder=[];
    foreach(kpi_unified_events($fromSql,$toSql,null,'orders')as$event){
        $recordId=(int)($event['record_id']??0);if($recordId<=0)continue;
        $status=performance_order_status_key((string)($event['new_status']?:$event['action']));
        if(!in_array($status,['in_progress','complete'],true))continue;
        $eventsByOrder[$recordId][$status][]=$event;
    }
    $per=[];
    foreach($employees as$person){$id=(int)$person['id'];$per[$id]=['packed'=>0,'completed'=>0,'in_progress'=>0,'new'=>0,'walk_ins'=>0,'modes'=>['deliveries'=>0,'couriers'=>0,'collections'=>0,'walk_ins'=>0],'speed_minutes'=>null,'speed_measured'=>0,'speed_total'=>0,'completed_without_in_progress'=>0,'weekly'=>[],'completion_step_minutes'=>null,'completion_step_measured'=>0,'walk_in_completion_minutes'=>null,'walk_in_completed'=>0,'walk_in_same_day'=>0,'walk_in_late'=>[]];}
    $speedTotals=[];$completionTotals=[];$walkTotals=[];$businessTotal=0;$businessMeasured=0;$walkTotal=0;$walkPackedBy=[];$walkOpen=[];$walkLate=[];$walkSamples=[];$modeObserved=[];$mobileObserved=[];$sequenceSamples=[];
    foreach($orders as$order){
        $id=(int)$order['id'];$packer=(int)($order['assigned_packer_id']??0);$loaded=(string)$order['loaded_at'];$loadedTs=strtotime($loaded)?:0;
        $status=performance_order_status_key((string)$order['status']);
        $mobileRaw=trim((string)$order['customer_contact']);$mobileKey=strtolower(preg_replace('/[\s\-_]+/',' ',$mobileRaw)??$mobileRaw);$isWalk=in_array($mobileKey,['walk in customer','working customer'],true);
        if($isWalk)$mobileObserved[$mobileRaw]=($mobileObserved[$mobileRaw]??0)+1;
        $modeRaw=(string)$order['stored_mode'];if($modeRaw!=='')$modeObserved[$modeRaw]=($modeObserved[$modeRaw]??0)+1;
        $mode=$isWalk?'walk_ins':(strpos($modeRaw,'courier')!==false?'couriers':($modeRaw==='delivery'?'deliveries':($modeRaw==='collection'?'collections':'other')));
        $inEvent=$eventsByOrder[$id]['in_progress'][0]??null;$completeEvent=$eventsByOrder[$id]['complete'][0]??null;
        $inTs=$inEvent?(strtotime((string)$inEvent['occurred_at'])?:0):0;$completeTs=$completeEvent?(strtotime((string)$completeEvent['occurred_at'])?:0):0;
        if($packer>0&&isset($per[$packer])){
            $per[$packer]['packed']++;if(isset($per[$packer][$status]))$per[$packer][$status]++;
            if($mode!=='other'){$per[$packer]['modes'][$mode]++;if($isWalk)$per[$packer]['walk_ins']++;}
            $per[$packer]['speed_total']++;
            if($inEvent&&$loadedTs&&$inTs>=$loadedTs&&(int)($inEvent['actor_user_id']??0)===$packer){$minutes=($inTs-$loadedTs)/60;$speedTotals[$packer]=($speedTotals[$packer]??0)+$minutes;$per[$packer]['speed_measured']++;}
            if($status==='complete'&&!$inEvent)$per[$packer]['completed_without_in_progress']++;
            $week=date('o-\WW',$loadedTs?:time());$per[$packer]['weekly'][$week]=($per[$packer]['weekly'][$week]??0)+1;
        }
        if($isWalk){$walkTotal++;if($packer>0)$walkPackedBy[$packer]=($walkPackedBy[$packer]??0)+1;}
        if($completeEvent&&$loadedTs&&$completeTs>=$loadedTs){$minutes=($completeTs-$loadedTs)/60;$businessTotal+=$minutes;$businessMeasured++;
            $completeActor=(int)($completeEvent['actor_user_id']??0);
            if($inEvent&&$inTs&&$completeTs>=$inTs&&isset($per[$completeActor])){$step=($completeTs-$inTs)/60;$completionTotals[$completeActor]=($completionTotals[$completeActor]??0)+$step;$per[$completeActor]['completion_step_measured']++;}
            if($isWalk){$same=date('Y-m-d',$loadedTs)===date('Y-m-d',$completeTs);$detail=['record_id'=>$id,'order_number'=>$order['order_number'],'customer'=>$order['customer_name'],'mobile_value'=>$mobileRaw,'packed_by'=>$employeeNames[$packer]??'Unassigned','loaded_at'=>$loaded,'completed_at'=>$completeEvent['occurred_at'],'completed_by'=>$completeEvent['actor_name']??'Unknown','lag_minutes'=>round($minutes,1)];$walkSamples[]=$detail;if(!$same){$walkLate[]=$detail;if(isset($per[$completeActor]))$per[$completeActor]['walk_in_late'][]=$detail;}if(isset($per[$completeActor])){$walkTotals[$completeActor]=($walkTotals[$completeActor]??0)+$minutes;$per[$completeActor]['walk_in_completed']++;if($same)$per[$completeActor]['walk_in_same_day']++;}}
            if($inEvent&&$inTs>=$loadedTs&&$completeTs>=$inTs&&count($sequenceSamples)<2)$sequenceSamples[]=['record_id'=>$id,'order_number'=>$order['order_number'],'loaded_at'=>$loaded,'in_progress_at'=>$inEvent['occurred_at'],'in_progress_by'=>$inEvent['actor_name']??'Unknown','packing_speed_minutes'=>round(($inTs-$loadedTs)/60,1),'completed_at'=>$completeEvent['occurred_at'],'completed_by'=>$completeEvent['actor_name']??'Unknown','front_desk_step_minutes'=>round(($completeTs-$inTs)/60,1)];
        }elseif($isWalk&&!performance_is_complete_status((string)$order['status'])){$detail=['record_id'=>$id,'order_number'=>$order['order_number'],'customer'=>$order['customer_name'],'mobile_value'=>$mobileRaw,'packed_by'=>$employeeNames[$packer]??'Unassigned','status'=>$order['status'],'loaded_at'=>$loaded,'age_minutes'=>$loadedTs?round((min(time(),strtotime($toSql))- $loadedTs)/60,1):null];$walkOpen[]=$detail;$walkSamples[]=$detail;}
    }
    foreach($per as$id=>&$row){if($row['speed_measured'])$row['speed_minutes']=round(($speedTotals[$id]??0)/$row['speed_measured'],1);if($row['completion_step_measured'])$row['completion_step_minutes']=round(($completionTotals[$id]??0)/$row['completion_step_measured'],1);if($row['walk_in_completed'])$row['walk_in_completion_minutes']=round(($walkTotals[$id]??0)/$row['walk_in_completed'],1);$row['weekly']=array_map(static fn($week,$count):array=>['week'=>$week,'orders'=>$count],array_keys($row['weekly']),array_values($row['weekly']));}unset($row);
    arsort($modeObserved);arsort($mobileObserved);
    $frontDeskIds=array_keys(array_filter($employeeRoles,static fn(string$r):bool=>strpos($r,'front_desk')!==false));$frontWalk=0;foreach($frontDeskIds as$id)$frontWalk+=(int)($walkPackedBy[$id]??0);
    $head=[];foreach($per as$id=>$row){$first=strtolower(strtok($employeeNames[$id]??'',' '));if(in_array($first,['klaudia','ndinelao'],true))$head[]=['employee_id'=>$id,'name'=>$employeeNames[$id]]+$row;}
    $modeBreakdown=[];foreach($per as$id=>$row){$name=$employeeNames[$id]??('Employee '.$id);$modeBreakdown[]=(['employee_id'=>$id,'name'=>$name]+$row);}
    return ['per_employee'=>$per,'summary'=>['orders'=>count($orders),'business_average_fulfilment_minutes'=>$businessMeasured?round($businessTotal/$businessMeasured,1):null,'business_fulfilment_coverage'=>['measured'=>$businessMeasured,'total'=>count($orders)],'walk_ins_total'=>$walkTotal,'walk_ins_packed_by_front_desk'=>$frontWalk,'walk_ins_packed_by_others'=>$walkTotal-$frontWalk,'walk_ins_by_employee'=>$walkPackedBy,'open_walk_ins'=>$walkOpen,'late_walk_ins'=>$walkLate],'mode_breakdown'=>$modeBreakdown,'head_to_head'=>$head,'stored_values'=>['modes'=>array_map(static fn($v,$n):array=>['stored_value'=>$v,'records'=>$n],array_keys($modeObserved),array_values($modeObserved)),'walk_in_mobile'=>array_map(static fn($v,$n):array=>['stored_value'=>$v,'records'=>$n],array_keys($mobileObserved),array_values($mobileObserved))],'verification'=>['status_sequences'=>$sequenceSamples,'walk_ins'=>array_slice($walkSamples,0,2)]];
}

function performance_website_metrics(int $employeeId, bool $isPacker, string $fromSql, string $toSql, ?float $targetMinutes): array
{
    if (!$isPacker) {
        $summary = ops_rows("SELECT COUNT(*) required, SUM(frontdesk_website_updated=1 AND frontdesk_website_updated_by=?) confirmed, SUM(frontdesk_website_updated=0 OR frontdesk_website_updated_at IS NULL) unconfirmed, AVG(CASE WHEN frontdesk_website_updated=1 AND frontdesk_website_updated_by=? AND frontdesk_website_updated_at>=date_loaded THEN TIMESTAMPDIFF(MINUTE,date_loaded,frontdesk_website_updated_at) END) average_lag_minutes, SUM(CASE WHEN frontdesk_website_updated=1 AND frontdesk_website_updated_by=? AND frontdesk_website_updated_at>=date_loaded THEN 1 ELSE 0 END) timed_confirmations, SUM(CASE WHEN frontdesk_website_updated=1 AND frontdesk_website_updated_by=? AND frontdesk_website_updated_at>=date_loaded AND ? IS NOT NULL AND TIMESTAMPDIFF(MINUTE,date_loaded,frontdesk_website_updated_at)<=? THEN 1 ELSE 0 END) on_target FROM ops_packing_tasks WHERE date_loaded BETWEEN ? AND ? AND deleted_at IS NULL",[$employeeId,$employeeId,$employeeId,$employeeId,$targetMinutes,$targetMinutes,$fromSql,$toSql])[0]??[];
        $unconfirmed = ops_rows("SELECT id record_id,item_name,date_loaded,TIMESTAMPDIFF(DAY,date_loaded,LEAST(NOW(),?)) age_days,CONCAT('consignments.php?packing_item_id=',id) record_url FROM ops_packing_tasks WHERE date_loaded BETWEEN ? AND ? AND deleted_at IS NULL AND (frontdesk_website_updated=0 OR frontdesk_website_updated_at IS NULL) ORDER BY date_loaded LIMIT 250",[$toSql,$fromSql,$toSql]);
        $weekly = ops_rows("SELECT DATE_FORMAT(frontdesk_website_updated_at,'%x-W%v') week_label,COUNT(*) confirmations FROM ops_packing_tasks WHERE frontdesk_website_updated_by=? AND frontdesk_website_updated_at BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY YEARWEEK(frontdesk_website_updated_at,3) ORDER BY YEARWEEK(frontdesk_website_updated_at,3)",[$employeeId,$fromSql,$toSql]);
        $timed=(int)($summary['timed_confirmations']??0);
        return ['source_type'=>'frontdesk_confirmation','required'=>(int)($summary['required']??0),'confirmed'=>(int)($summary['confirmed']??0),'unconfirmed'=>(int)($summary['unconfirmed']??0),'average_lag_minutes'=>$summary['average_lag_minutes']===null?null:round((float)$summary['average_lag_minutes'],1),'on_time_percent'=>$targetMinutes!==null&&$timed>0?round(100*(int)($summary['on_target']??0)/$timed,1):null,'target_minutes'=>$targetMinutes,'timing_coverage'=>['attributable'=>$timed,'confirmed'=>(int)($summary['confirmed']??0)],'unconfirmed_items'=>$unconfirmed,'weekly_confirmations'=>$weekly,'updates'=>(int)($summary['confirmed']??0),'average_minutes'=>$summary['average_lag_minutes']===null?null:round((float)$summary['average_lag_minutes'],1)];
    }
    $summary=ops_rows("SELECT COUNT(*) required,SUM(packing_website_confirmed=1) ticked,SUM(packing_website_confirmed=0) unticked FROM ops_packing_tasks WHERE assigned_employee_id=? AND date_loaded BETWEEN ? AND ? AND deleted_at IS NULL",[$employeeId,$fromSql,$toSql])[0]??[];
    $events=ops_rows("SELECT l.entity_id record_id,MIN(l.created_at) ticked_at,MIN(p.date_loaded) date_loaded,MIN(p.item_name) item_name,MIN(e.full_name) ticked_by,TIMESTAMPDIFF(MINUTE,MIN(p.date_loaded),MIN(l.created_at)) lag_minutes FROM ops_activity_logs l JOIN ops_packing_tasks p ON p.id=l.entity_id LEFT JOIN ops_employees e ON e.id=l.employee_id WHERE l.entity_type='packing_task' AND l.action='packing_packing_website_confirmed_updated' AND l.employee_id=? AND p.assigned_employee_id=? AND p.date_loaded BETWEEN ? AND ? AND p.deleted_at IS NULL AND (l.metadata LIKE '%\"new_value\":\"1\"%' OR l.metadata LIKE '%\"new_value\":1%') GROUP BY l.entity_id HAVING ticked_at>=date_loaded ORDER BY ticked_at",[$employeeId,$employeeId,$fromSql,$toSql]);
    $timed=count($events);$onTarget=0;$lagTotal=0.0;foreach($events as&$event){$event['record_url']='consignments.php?packing_item_id='.(int)$event['record_id'];$event['lag_minutes']=(int)$event['lag_minutes'];$lagTotal+=(int)$event['lag_minutes'];if($targetMinutes!==null&&(int)$event['lag_minutes']<=$targetMinutes)$onTarget++;}unset($event);
    $ticked=(int)($summary['ticked']??0);
    return ['source_type'=>'board_tick','required'=>(int)($summary['required']??0),'confirmed'=>$ticked,'unconfirmed'=>(int)($summary['unticked']??0),'ticked'=>$ticked,'unticked'=>(int)($summary['unticked']??0),'average_lag_minutes'=>$timed?round($lagTotal/$timed,1):null,'on_time_percent'=>$targetMinutes!==null&&$timed?round(100*$onTarget/$timed,1):null,'target_minutes'=>$targetMinutes,'timing_coverage'=>['attributable'=>$timed,'ticked'=>$ticked,'label'=>$timed.' of '.$ticked.' ticks attributable'],'attributable_ticks'=>$events,'updates'=>$ticked,'average_minutes'=>$timed?round($lagTotal/$timed,1):null];
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
        'website_frontdesk' => [
            "SELECT id,item_name,date_loaded,frontdesk_website_updated_at confirmed_at,frontdesk_website_updated_by confirmed_by,TIMESTAMPDIFF(MINUTE,date_loaded,frontdesk_website_updated_at) lag_minutes FROM ops_packing_tasks WHERE frontdesk_website_updated_at BETWEEN ? AND ? AND deleted_at IS NULL" . ($whereEmployee ? ' AND frontdesk_website_updated_by=?' : '') . " ORDER BY frontdesk_website_updated_at DESC LIMIT {$limit} OFFSET {$offset}",
            "SELECT COUNT(*) total FROM ops_packing_tasks WHERE frontdesk_website_updated_at BETWEEN ? AND ? AND deleted_at IS NULL" . ($whereEmployee ? ' AND frontdesk_website_updated_by=?' : ''),
        ],
        'website_board_ticks' => [
            "SELECT l.entity_id id,p.item_name,p.date_loaded,l.created_at ticked_at,l.employee_id ticked_by,e.full_name ticked_by_name,TIMESTAMPDIFF(MINUTE,p.date_loaded,l.created_at) lag_minutes FROM ops_activity_logs l JOIN ops_packing_tasks p ON p.id=l.entity_id LEFT JOIN ops_employees e ON e.id=l.employee_id WHERE p.date_loaded BETWEEN ? AND ? AND p.deleted_at IS NULL AND l.entity_type='packing_task' AND l.action='packing_packing_website_confirmed_updated' AND (l.metadata LIKE '%\"new_value\":\"1\"%' OR l.metadata LIKE '%\"new_value\":1%')" . ($whereEmployee ? ' AND l.employee_id=?' : '') . " ORDER BY l.created_at DESC LIMIT {$limit} OFFSET {$offset}",
            "SELECT COUNT(*) total FROM ops_activity_logs l JOIN ops_packing_tasks p ON p.id=l.entity_id WHERE p.date_loaded BETWEEN ? AND ? AND p.deleted_at IS NULL AND l.entity_type='packing_task' AND l.action='packing_packing_website_confirmed_updated' AND (l.metadata LIKE '%\"new_value\":\"1\"%' OR l.metadata LIKE '%\"new_value\":1%')" . ($whereEmployee ? ' AND l.employee_id=?' : ''),
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
    $sectionErrors = [];
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
    // Module-table counts honour the requested period. Adoption dates apply only
    // to timing/sequence metrics, never to operational counts.
    $from = $resolved['from'];
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
    $websiteLagTarget = isset($settings['website_update_lag_target_minutes']) && is_numeric($settings['website_update_lag_target_minutes']) ? max(0.0,(float)$settings['website_update_lag_target_minutes']) : null;

    $employees = ops_rows("SELECT e.id,e.full_name,r.name role_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key<>'owner_admin' AND LOWER(e.full_name) NOT LIKE '%karina%' AND LOWER(e.full_name) NOT LIKE '%kaarina%' ORDER BY r.role_key,e.full_name");
    $reports = [];
    foreach ($employees as $person) {
        $id = (int)$person['id'];
        if ($employeeId && $employeeId !== $id) continue;
        $personRole=(string)$person['role_key'];
        if ($role==='packer' && strpos($personRole,'packer')===false) continue;
        if ($role==='front_desk' && strpos($personRole,'front_desk')===false) continue;
        if (!in_array($role,['all','packer','front_desk'],true) && $role!==$personRole) continue;
        $packing = performance_section_attempt($sectionErrors,'packing',static fn()=>ops_rows("SELECT COUNT(*) product_rows,COALESCE(SUM(workload_package_count),0) packages,COALESCE(SUM(COALESCE(workload_unit_count,0)),0) units,COALESCE(SUM(CASE WHEN workload_parse_status='parsed' OR workload_points_override IS NOT NULL THEN COALESCE(workload_points_override,workload_points) ELSE 0 END),0) points,SUM(workload_parse_status='pending_review') review_rows,SUM(COALESCE(workload_weight_grams,0)) grams,SUM(COALESCE(workload_volume_ml,0)) millilitres,AVG(CASE WHEN date_loaded<=date_started AND date_started<=date_completed AND date_completed>=? THEN TIMESTAMPDIFF(MINUTE,date_started,date_completed) END) avg_minutes,SUM(date_loaded<=date_started AND date_started<=date_completed AND date_completed>=?) valid_timing,SUM(quantity_planned REGEXP '^[[:space:]]*[0-9]+([.][0-9]+)?' AND quantity_packed REGEXP '^[[:space:]]*[0-9]+([.][0-9]+)?') comparable_quantities,SUM(quantity_planned REGEXP '^[[:space:]]*[0-9]+([.][0-9]+)?' AND quantity_packed REGEXP '^[[:space:]]*[0-9]+([.][0-9]+)?' AND ABS(CAST(quantity_planned AS DECIMAL(18,3))-CAST(quantity_packed AS DECIMAL(18,3)))>0.0001) quantity_variances FROM ops_packing_tasks WHERE assigned_employee_id=? AND date_completed BETWEEN ? AND ? AND deleted_at IS NULL",[$sectionFrom('packing_timing_adoption_date','2026-07-14'),$sectionFrom('packing_timing_adoption_date','2026-07-14'),$id,$packingFromSql,$toSql])[0]??[],[]);
        $orders = performance_section_attempt($sectionErrors,'orders',static fn()=>ops_rows("SELECT COUNT(DISTINCT id) packed_orders FROM ops_orders WHERE assigned_packer_id=? AND status IN ('completed','packed','verified') AND completed_at BETWEEN ? AND ?",[$id,$fromSql,$toSql])[0]??[],[]);
        $tasks = performance_section_attempt($sectionErrors,'tasks',static fn()=>performance_task_metrics($id,$fromSql,$toSql,new DateTimeImmutable('now',$zone)),['assigned'=>0,'completed'=>0,'on_time'=>0,'completed_late'=>0,'pending'=>0,'open_overdue'=>0,'on_time_denominator'=>0,'on_time_percent'=>null,'details'=>[]]);
        $website = performance_section_attempt($sectionErrors,'website-updates',static fn()=>performance_website_metrics($id,strpos($personRole,'packer')!==false,$fromSql,$toSql,$websiteLagTarget),['source_type'=>'unavailable','required'=>0,'confirmed'=>0,'unconfirmed'=>0,'average_lag_minutes'=>null,'on_time_percent'=>null,'target_minutes'=>$websiteLagTarget,'timing_coverage'=>[],'updates'=>0,'average_minutes'=>null]);
        $waybills = performance_section_attempt($sectionErrors,'courier',static fn()=>ops_rows("SELECT SUM(uploaded_by=? AND uploaded_at BETWEEN ? AND ?) uploaded,SUM(sent_by=? AND sent_at BETWEEN ? AND ?) sent,SUM(sent_by=? AND status='sent' AND sent_at BETWEEN ? AND ? AND sent_at<=due_by) sent_on_time,SUM(sent_by=? AND status='sent' AND sent_at BETWEEN ? AND ? AND sent_at>due_by) sent_late FROM hambelela_waybills WHERE (uploaded_at BETWEEN ? AND ? OR sent_at BETWEEN ? AND ?) AND deleted_at IS NULL",[$id,$fromSql,$toSql,$id,$fromSql,$toSql,$id,$fromSql,$toSql,$id,$fromSql,$toSql,$fromSql,$toSql,$fromSql,$toSql])[0]??[],[]);
        [$errorPersonSql,$errorPersonParams]=performance_error_person_filter('l',$id);
        $errors = performance_section_attempt($sectionErrors,'errors',static fn()=>ops_rows("SELECT COUNT(*) attributable_errors FROM ops_error_logs l WHERE {$errorPersonSql} AND l.attribution_type='employee' AND l.affects_kpi_accuracy=1 AND l.accuracy_verified_by IS NOT NULL AND l.logged_at BETWEEN ? AND ? AND l.deleted_at IS NULL",array_merge($errorPersonParams,[$errorsFromSql,$toSql]))[0]??[],[]);
        $eligible = (int)($packing['product_rows']??0) + (int)($orders['packed_orders']??0) + (int)($tasks['completed']??0);
        $errorCount = (int)($errors['attributable_errors']??0);
        $quantityComparable=(int)($packing['comparable_quantities']??0);$quantityVariances=(int)($packing['quantity_variances']??0);$quantityAccuracy=$quantityComparable>0?100*($quantityComparable-$quantityVariances)/$quantityComparable:null;
        $reports[] = ['id'=>$id,'name'=>$person['full_name'],'role'=>$person['role_name'],'role_key'=>$person['role_key'],'packing'=>['product_rows'=>(int)($packing['product_rows']??0),'items'=>(int)($packing['product_rows']??0),'packages'=>(int)($packing['packages']??0),'units'=>(float)($packing['units']??0),'workload_points'=>round((float)($packing['points']??0),1),'weight_grams'=>(float)($packing['grams']??0),'volume_ml'=>(float)($packing['millilitres']??0),'average_minutes'=>($packing['avg_minutes']??null)===null?null:round((float)$packing['avg_minutes'],1),'timing_coverage'=>['numerator'=>(int)($packing['valid_timing']??0),'denominator'=>(int)($packing['product_rows']??0)],'requires_review'=>(int)($packing['review_rows']??0),'comparable_quantities'=>$quantityComparable,'comparable_rows'=>$quantityComparable,'quantity_variances'=>$quantityVariances,'accuracy'=>$quantityAccuracy,'quantity_accuracy'=>$quantityAccuracy],'orders'=>['packed'=>(int)($orders['packed_orders']??0),'completed'=>0,'in_progress'=>0,'new'=>0,'outstanding'=>0,'walk_ins'=>0,'speed_minutes'=>null,'speed_measured'=>0,'speed_total'=>(int)($orders['packed_orders']??0),'completed_without_in_progress'=>0,'completion_step_minutes'=>null,'completion_step_measured'=>0,'walk_in_completion_minutes'=>null,'walk_in_completed'=>0,'walk_in_same_day'=>0,'walk_in_late'=>[]],'tasks'=>$tasks,'website'=>$website,'waybills'=>array_merge(['uploaded'=>0,'sent'=>0,'sent_on_time'=>0,'sent_late'=>0,'overdue_pending'=>0,'before_cutoff'=>0,'after_cutoff'=>0,'contribution_share'=>null,'on_time_percent'=>null],array_map('intval',$waybills)),'errors'=>['total'=>$errorCount,'critical'=>0,'high'=>0,'customer_impacting'=>0,'financial_impact'=>0,'resolved'=>0],'quality'=>['eligible_work'=>$eligible,'verified_errors'=>$errorCount,'packing_variances'=>$quantityVariances,'packing_comparable'=>$quantityComparable,'accuracy'=>$quantityAccuracy,'status'=>$eligible>0?'measured':'not_measured']];
    }
    $ordersAnalysis=performance_section_attempt($sectionErrors,'orders',static fn()=>performance_orders_analysis($employees,$fromSql,$toSql),['per_employee'=>[],'summary'=>[],'mode_breakdown'=>[],'head_to_head'=>[],'stored_values'=>['modes'=>[],'walk_in_mobile'=>[]],'verification'=>['status_sequences'=>[],'walk_ins'=>[]]]);
    foreach($reports as$reportIndex=>$report){$id=(int)$report['id'];if(isset($ordersAnalysis['per_employee'][$id]))$reports[$reportIndex]['orders']=array_merge($reports[$reportIndex]['orders'],$ordersAnalysis['per_employee'][$id]);$reports[$reportIndex]['orders']['outstanding']=(int)$reports[$reportIndex]['orders']['new']+(int)$reports[$reportIndex]['orders']['in_progress'];}
    $defaultWeights=['packer'=>['packing'=>35,'orders'=>20,'tasks'=>15,'waybills'=>10,'quality'=>10,'attendance'=>10],'front_desk'=>['website'=>15,'bookkeeping'=>25,'orders'=>20,'tasks'=>10,'waybills'=>10,'quality'=>10,'attendance'=>10]];
    $configuredWeights=json_decode((string)($settings['report_weights']??''),true);if(!is_array($configuredWeights))$configuredWeights=$defaultWeights;
    if(!isset($configuredWeights['front_desk']['website'])){$existing=$configuredWeights['front_desk']??[];$existingTotal=array_sum($existing);if($existingTotal>0)foreach($existing as$key=>$value)$existing[$key]=85*(float)$value/$existingTotal;$configuredWeights['front_desk']=['website'=>15]+$existing;else$configuredWeights['front_desk']=$defaultWeights['front_desk'];}
    $epiFilters=['period'=>'custom','date_from'=>$from->format('Y-m-d'),'date_to'=>$to->format('Y-m-d')];
    $ordersEngine=new \Hambelela\EPI\OrdersPerformance(db());$packingEngine=new \Hambelela\EPI\PackingPerformance(db());$taskEngine=new \Hambelela\EPI\TaskPerformance(db());$courierEngine=new \Hambelela\EPI\CourierPerformance(db());$bookkeepingEngine=new \Hambelela\EPI\BookkeepingPerformance(db());$attendanceEngine=new \Hambelela\EPI\AttendancePerformance(db());
    $bookkeepingRows=performance_section_attempt($sectionErrors,'bookkeeping',static fn()=>$bookkeepingEngine->getOrderReconciliation($epiFilters),[]);$bookkeepingDaily=performance_section_attempt($sectionErrors,'bookkeeping',static fn()=>$bookkeepingEngine->getDailyReconciliation($epiFilters),['summary'=>[]]);$cashTotal=count($bookkeepingRows);$cashMatched=count(array_filter($bookkeepingRows,static fn(array$x):bool=>in_array((string)$x['match_status'],['exact_match','date_mismatch'],true)));$cashupDays=(int)($bookkeepingDaily['summary']['days_with_entries']??0);$cashupDone=(int)($bookkeepingDaily['summary']['fully_reconciled']??0);
    $missingCash=array_values(array_map(static fn(array$x):array=>['record_id'=>$x['order_id'],'reference'=>$x['order_number'],'date'=>$x['cash_transaction_at'],'amount'=>round(((int)$x['expected_cash_cents'])/100,2),'match_status'=>$x['match_status'],'match_method'=>$x['match_method'],'url'=>'orders-board.php?order_id='.(int)$x['order_id']],array_filter($bookkeepingRows,static fn(array$x):bool=>(string)$x['match_status']==='missing_bookkeeping_entry')));
    $teamWaybillUploads=(int)(ops_rows("SELECT COUNT(*) total FROM hambelela_waybills WHERE uploaded_at BETWEEN ? AND ? AND deleted_at IS NULL",[$fromSql,$toSql])[0]['total']??0);
    foreach($reports as$reportIndex=>$reportRow){
        $id=(int)$reportRow['id'];$local=$epiFilters+['employee_id'=>$id];$isPacker=strpos((string)$reportRow['role_key'],'packer')!==false;$roleGroup=$isPacker?'packer':'front_desk';
        $orderSummary=performance_section_attempt($sectionErrors,'orders',static fn()=>$ordersEngine->getSummary($local),['average_completion_minutes'=>null]);$orderEvidence=performance_section_attempt($sectionErrors,'orders',static fn()=>$ordersEngine->getEvidence($local,10000),[]);$orderCompleted=array_values(array_filter($orderEvidence,static fn(array$x):bool=>(string)($x['action']??'')==='order_completed'));$targetMinutes=max(1,(float)($settings['target_fulfilment_hours']??6)*60);$orderTimed=array_values(array_filter($orderCompleted,static fn(array$x):bool=>($x['working_minutes']??null)!==null));$orderWithin=count(array_filter($orderTimed,static fn(array$x):bool=>(float)$x['working_minutes']<=$targetMinutes));
        $skippedInProgress=(int)$reportRow['orders']['completed_without_in_progress'];
        if($isPacker){$orderN=(int)$reportRow['orders']['packed'];$measured=(int)$reportRow['orders']['speed_measured'];$speed=$reportRow['orders']['speed_minutes'];$orderTarget=$measured>0&&$speed!==null?max(0,min(100,100*(2-(float)$speed/$targetMinutes))):null;$compliance=$orderN>0?100*$measured/$orderN:null;$orderScore=performance_weighted_score([[$orderTarget,.7],[$compliance,.3]]);$orderMetric=performance_scored_metric($orderScore,$orderN,['orders_packed'=>$orderN,'completed'=>(int)$reportRow['orders']['completed'],'in_progress'=>(int)$reportRow['orders']['in_progress'],'new'=>(int)$reportRow['orders']['new'],'active_timing_count'=>$measured,'average_active_minutes'=>$speed,'timing_coverage'=>['measured'=>$measured,'total'=>(int)$reportRow['orders']['speed_total']],'completed_without_in_progress'=>$skippedInProgress,'status_compliance_percent'=>$compliance,'timing_source'=>'Displayed order timestamp to first attributable In Progress activity event']);}
        else{$walkCompleted=(int)$reportRow['orders']['walk_in_completed'];$walkSame=(int)$reportRow['orders']['walk_in_same_day'];$walkScore=$walkCompleted>0?100*$walkSame/$walkCompleted:null;$orderMetric=performance_scored_metric($walkScore,$walkCompleted,['completed_walk_ins'=>$walkCompleted,'walk_ins_same_day'=>$walkSame,'walk_ins_next_day_or_later'=>count($reportRow['orders']['walk_in_late']),'walk_ins_open'=>count($ordersAnalysis['summary']['open_walk_ins']),'walk_in_same_day_percent'=>$walkScore,'walk_in_average_minutes'=>$reportRow['orders']['walk_in_completion_minutes'],'completion_step_average_minutes'=>$reportRow['orders']['completion_step_minutes'],'completion_step_measured'=>$reportRow['orders']['completion_step_measured'],'completion_timing_source'=>'First attributable In Progress and Complete activity events'],array_slice($reportRow['orders']['walk_in_late'],0,10),$walkCompleted>0);}
        $packingDone=(int)$reportRow['packing']['product_rows'];$packingComparable=(int)$reportRow['packing']['comparable_quantities'];$packingVariances=(int)$reportRow['packing']['quantity_variances'];$packingAccuracy=$packingComparable>0?100*($packingComparable-$packingVariances)/$packingComparable:($packingDone>0?100:null);$packingTiming=(int)$reportRow['packing']['timing_coverage']['numerator'];$packingTimingScore=$packingTiming>0?100:null;$packingBase=$packingAccuracy===null?null:($packingTimingScore===null?$packingAccuracy:.7*$packingAccuracy+.3*$packingTimingScore);$packingMetric=performance_scored_metric($packingBase,$packingDone,['items_packed'=>$packingDone,'units_packed'=>$reportRow['packing']['units'],'weighted_points'=>$reportRow['packing']['workload_points'],'active_timing_count'=>$packingTiming,'average_active_minutes'=>$packingTiming>0?$reportRow['packing']['average_minutes']:null,'within_target_percent'=>$packingTimingScore,'quantity_comparable'=>$packingComparable,'quantity_variances'=>$packingVariances,'accuracy_percent'=>$packingAccuracy,'count_source'=>'ops_packing_tasks.date_completed + assigned_employee_id','timing_source'=>'ops_packing_tasks date_started/date_completed']);
        $taskCompleted=(int)$reportRow['tasks']['completed'];$taskOpen=(int)$reportRow['tasks']['pending'];$taskN=(int)$reportRow['tasks']['on_time_denominator'];$taskDue=$reportRow['tasks']['on_time_percent'];$taskScore=$taskDue===null?null:(float)$taskDue;$taskMetric=performance_scored_metric($taskScore,$taskN,['completed'=>$taskCompleted,'pending'=>$taskOpen,'overdue'=>(int)$reportRow['tasks']['open_overdue'],'completed_late'=>(int)$reportRow['tasks']['completed_late'],'on_time_count'=>(int)$reportRow['tasks']['on_time'],'on_time_denominator'=>$taskN,'on_time_percent'=>$taskDue,'definition'=>'Completed on/before due divided by completed late + currently overdue + on-time tasks'],array_slice($reportRow['tasks']['details'],0,100));
        if($isPacker){$ownUploads=(int)$reportRow['waybills']['uploaded'];$uploadRows=ops_rows("SELECT id,waybill_reference,courier_names,status,uploaded_at,due_by,sent_at FROM hambelela_waybills WHERE uploaded_by=? AND uploaded_at BETWEEN ? AND ? AND deleted_at IS NULL ORDER BY uploaded_at",[$id,$fromSql,$toSql]);$timedUploads=array_values(array_filter($uploadRows,static fn(array$x):bool=>!empty($x['due_by'])));$onTimeUploads=count(array_filter($timedUploads,static fn(array$x):bool=>strtotime((string)$x['uploaded_at'])<=strtotime((string)$x['due_by'])));$overduePending=count(array_filter($uploadRows,static fn(array$x):bool=>strtolower((string)$x['status'])!=='sent'&&!empty($x['due_by'])&&strtotime((string)$x['due_by'])<time()));$contribution=$teamWaybillUploads>0?min(100,200*$ownUploads/$teamWaybillUploads):null;$handling=$ownUploads>0?($timedUploads?100*$onTimeUploads/count($timedUploads):100):0;$noneOverdue=$overduePending===0?100:0;$courierScore=$teamWaybillUploads>=10?.5*(float)$contribution+.4*$handling+.1*$noneOverdue:null;$beforeScore=$ownUploads>=5?$handling:null;$courierMetric=performance_scored_metric($courierScore,$teamWaybillUploads,['uploaded'=>$ownUploads,'team_uploads'=>$teamWaybillUploads,'contribution_share_percent'=>$teamWaybillUploads?100*$ownUploads/$teamWaybillUploads:null,'contribution_component'=>$contribution,'on_time_percent'=>$handling,'overdue_pending'=>$overduePending,'before_g6_1a_score'=>$beforeScore,'after_g6_1a_score'=>$courierScore,'count_source'=>'hambelela_waybills.uploaded_by + uploaded_at'],array_slice($uploadRows,0,100),$teamWaybillUploads>=10);}else{$sent=(int)$reportRow['waybills']['sent'];$sentOn=(int)$reportRow['waybills']['sent_on_time'];$sentLate=(int)$reportRow['waybills']['sent_late'];$rate=$sent?100*$sentOn/$sent:null;$courierMetric=performance_scored_metric($rate,$sent,['sent'=>$sent,'sent_on_time'=>$sentOn,'sent_late'=>$sentLate,'on_time_percent'=>$rate,'count_source'=>'hambelela_waybills.sent_by + sent_at']);}
        $attendance=performance_section_attempt($sectionErrors,'attendance',static fn()=>$attendanceEngine->getSummary($local),['scheduled_days'=>0,'approved_leave_days'=>0,'present_days'=>0,'on_time_days'=>0,'within_grace_days'=>0,'late_days'=>0,'portal_active_minutes'=>0]);$expected=max(0,(int)$attendance['scheduled_days']-(int)$attendance['approved_leave_days']);$attendanceRate=$expected?100*(int)$attendance['present_days']/$expected:null;$punctualN=(int)$attendance['on_time_days']+(int)$attendance['within_grace_days']+(int)$attendance['late_days'];$punctual=$punctualN?100*((int)$attendance['on_time_days']+(int)$attendance['within_grace_days'])/$punctualN:null;$attendanceScore=$attendanceRate===null||$punctual===null?null:.8*$attendanceRate+.2*$punctual;$attendanceMetric=performance_scored_metric($attendanceScore,$expected,['expected_days'=>$expected,'present_days'=>(int)$attendance['present_days'],'approved_leave_days'=>(int)$attendance['approved_leave_days'],'late_arrivals'=>(int)$attendance['late_days'],'attendance_rate'=>$attendanceRate,'punctuality_rate'=>$punctual,'average_daily_active_hours'=>(int)$attendance['present_days']>0?round((int)$attendance['portal_active_minutes']/60/(int)$attendance['present_days'],1):null]);$reports[$reportIndex]['attendance']=['sessions'=>(int)($attendance['session_count']??0),'hours'=>round((int)$attendance['portal_active_minutes']/60,1),'expected_days'=>$expected,'present_days'=>(int)$attendance['present_days'],'on_time_percent'=>$punctual];
        $qualityN=(int)$reportRow['quality']['eligible_work'];$varianceAccuracy=$reportRow['quality']['packing_comparable']>0?100*((int)$reportRow['quality']['packing_comparable']-(int)$reportRow['quality']['packing_variances'])/(int)$reportRow['quality']['packing_comparable']:100;$errorScore=max(0,100-10*(int)$reportRow['quality']['verified_errors']);$qualityScore=$qualityN>0?.7*$varianceAccuracy+.3*$errorScore:null;[$qualityErrorSql,$qualityErrorParams]=performance_error_person_filter('l',$id);$qualityDetails=performance_section_attempt($sectionErrors,'errors',static fn()=>ops_rows("SELECT l.id record_id,l.error_title,l.category,l.severity,l.attribution_type,l.financial_impact,l.customer_impact,l.logged_at FROM ops_error_logs l WHERE {$qualityErrorSql} AND l.logged_at BETWEEN ? AND ? AND l.deleted_at IS NULL ORDER BY l.logged_at DESC LIMIT 100",array_merge($qualityErrorParams,[$errorsFromSql,$toSql])),[]);$qualityMetric=performance_scored_metric($qualityScore,$qualityN,['employee_cause_errors'=>(int)$reportRow['quality']['verified_errors'],'packing_variances'=>(int)$reportRow['quality']['packing_variances'],'packing_comparable'=>(int)$reportRow['quality']['packing_comparable'],'packing_source_note'=>(int)$reportRow['quality']['packing_variances'].' variances recorded in packing list','error_source_note'=>(int)$reportRow['quality']['verified_errors'].' verified employee-cause errors in Error Log','eligible_work'=>$qualityN],$qualityDetails);
        $bookkeepingRate=$cashTotal?100*$cashMatched/$cashTotal:null;$cashupRate=$cashupDays?100*$cashupDone/$cashupDays:null;$bookkeepingScore=$bookkeepingRate===null||$cashupRate===null?null:.7*$bookkeepingRate+.3*$cashupRate;$bookkeepingMetric=performance_scored_metric($bookkeepingScore,$cashTotal,['cash_orders_total'=>$cashTotal,'logged'=>$cashMatched,'missing'=>$cashTotal-$cashMatched,'match_rate'=>$bookkeepingRate,'cashup_working_days'=>$cashupDays,'cashup_completed_days'=>$cashupDone,'cashup_compliance'=>$cashupRate],array_slice($missingCash,0,50));
        $websiteRequired=(int)$reportRow['website']['required'];$websiteCompletion=$websiteRequired>0?100*(int)$reportRow['website']['confirmed']/$websiteRequired:null;$websiteOnTarget=$reportRow['website']['on_time_percent'];$websiteScore=performance_weighted_score([[$websiteCompletion,.7],[$websiteOnTarget,.3]]);$websiteMetric=performance_scored_metric($websiteScore,$websiteRequired,['source_type'=>$reportRow['website']['source_type'],'required'=>$websiteRequired,'confirmed'=>(int)$reportRow['website']['confirmed'],'unconfirmed'=>(int)$reportRow['website']['unconfirmed'],'completion_percent'=>$websiteCompletion,'average_lag_minutes'=>$reportRow['website']['average_lag_minutes'],'on_target_percent'=>$websiteOnTarget,'target_minutes'=>$reportRow['website']['target_minutes'],'timing_coverage'=>$reportRow['website']['timing_coverage']],$reportRow['website']['unconfirmed_items']??[],$websiteRequired>0);
        $sections=['orders'=>$orderMetric,'packing'=>$packingMetric,'tasks'=>$taskMetric,'waybills'=>$courierMetric,'quality'=>$qualityMetric,'attendance'=>$attendanceMetric];if(!$isPacker){$sections['bookkeeping']=$bookkeepingMetric;$sections['website']=$websiteMetric;}
        $reports[$reportIndex]['scored_sections']=$sections;$reports[$reportIndex]['overall_score']=performance_overall_score($sections,$configuredWeights[$roleGroup]??$defaultWeights[$roleGroup]);
        $packingSkipped=(int)(ops_rows("SELECT COUNT(*) total FROM ops_packing_tasks WHERE assigned_employee_id=? AND date_completed BETWEEN ? AND ? AND deleted_at IS NULL AND date_started IS NULL",[$id,$fromSql,$toSql])[0]['total']??0);$taskSkipped=(int)(ops_rows("SELECT COUNT(*) total FROM ops_checklist_tasks t WHERE t.assigned_employee_id=? AND LOWER(t.status) IN ('complete','completed') AND COALESCE(t.completed_at,t.date_completed) BETWEEN ? AND ? AND t.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM kpi_status_events e WHERE e.module IN ('task','tasks') AND e.record_id=t.id AND e.new_status IN ('in_progress','started'))",[$id,$fromSql,$toSql])[0]['total']??0);
        $reports[$reportIndex]['status_compliance']=['orders_completed_without_in_progress'=>$skippedInProgress,'packing_completed_without_started'=>$packingSkipped,'tasks_completed_without_in_progress'=>$taskSkipped];
        $reportEvents=kpi_unified_events($fromSql,$toSql,$id);$reportSources=[];foreach($reportEvents as$reportEvent)$reportSources[$reportEvent['source_log']]=($reportSources[$reportEvent['source_log']]??0)+1;$reports[$reportIndex]['historical_evidence']=['events'=>count($reportEvents),'sources'=>$reportSources];
    }
    $packerPoints=array_values(array_map(static fn(array$r):float=>(float)($r['scored_sections']['packing']['supporting']['weighted_points']??0),array_filter($reports,static fn(array$r):bool=>strpos((string)$r['role_key'],'packer')!==false)));$packerAverage=$packerPoints?array_sum($packerPoints)/count($packerPoints):0.0;
    foreach($reports as$reportIndex=>$reportRow){if(strpos((string)$reportRow['role_key'],'packer')===false)continue;$packing=$reportRow['scored_sections']['packing'];$support=$packing['supporting'];$outputScore=$packerAverage>0?min(100,100*(float)$support['weighted_points']/$packerAverage):null;$tickRequired=(int)$reportRow['website']['required'];$tickCompliance=$tickRequired>0?100*(int)$reportRow['website']['confirmed']/$tickRequired:null;$support['output_vs_packer_average_percent']=$outputScore;$support['website_tick_compliance_percent']=$tickCompliance;$support['website_tick_weight_percent']=5;$support['website_tick_coverage']=$reportRow['website']['timing_coverage'];$score=performance_weighted_score([[$support['within_target_percent'],.38],[$support['accuracy_percent'],.285],[$outputScore,.285],[$tickCompliance,.05]]);$reports[$reportIndex]['scored_sections']['packing']=performance_scored_metric($score,(int)$packing['sample'],$support,$packing['details']);$reports[$reportIndex]['overall_score']=performance_overall_score($reports[$reportIndex]['scored_sections'],$configuredWeights['packer']??$defaultWeights['packer']);}
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
    $fieldMappings=['orders_workflow'=>['mapping'=>'Orders loaded timestamp is ops_order_display_datetime_expr(). In Progress and Complete actors/timestamps are deduplicated from kpi_status_events, ops_order_stage_events and ops_activity_logs.','semantics'=>['loaded_to_in_progress'=>'Packer order packing speed: In Progress means picking is finished.','in_progress_to_complete'=>'Front-desk completion step. This is intentionally different from Packing List semantics.']],'walk_in'=>['exact_normalized_mobile_values'=>['walk in customer','working customer'],'observed_mobile_values'=>$ordersAnalysis['stored_values']['walk_in_mobile'],'mapping'=>'Normalize ops_orders.customer_contact by lowercasing and collapsing spaces, hyphens and underscores; match exact values only.'],'order_modes'=>['observed_values'=>$ordersAnalysis['stored_values']['modes'],'mapping'=>'COALESCE(ops_orders.fulfilment_mode, ops_orders.order_type); values containing courier map to Couriers, exact delivery to Deliveries, exact collection to Collections; walk-in Mobile overrides mode.'],'cash_payments'=>['configured_values'=>array_values(array_filter(array_map('trim',explode(',',(string)($settings['cash_payment_values']??'cash'))))),'observed_values'=>$paymentRows,'mapping'=>'order_payment_allocations.payment_method; authoritative cash component is stored as cash.'],'courier_statuses'=>['observed_values'=>$courierStatusRows,'mapping'=>'hambelela_waybills.status with uploaded_at and sent_at; responsibility is separated between uploaded_by and sent_by.'],'cutoffs'=>['same_day'=>$settings['courier_sameday_cutoff']??'17:00','next_working_day'=>$settings['courier_nextday_cutoff']??'09:00']];
    $metricSources=[
        ['metric'=>'Packing items/products','source'=>'ops_packing_tasks.assigned_employee_id, date_completed','filter'=>'date_completed in report period; deleted_at IS NULL'],
        ['metric'=>'Packing units and workload','source'=>'ops_packing_tasks.workload_unit_count, workload_points(_override)','filter'=>'completed packing rows; parsed workload or explicit override'],
        ['metric'=>'Packing timing','source'=>'ops_packing_tasks.date_started, date_completed','filter'=>'chronological timestamps after timing adoption'],
        ['metric'=>'Packing quantity accuracy','source'=>'ops_packing_tasks.quantity_planned, quantity_packed','filter'=>'both values have comparable leading numeric quantities'],
        ['metric'=>'Orders packed and current status','source'=>'ops_orders.assigned_packer_id, status + Orders displayed timestamp expression','filter'=>'orders loaded in report period; archived/deleted excluded exactly like Orders Board'],
        ['metric'=>'Order packing speed','source'=>'Unified order activity: kpi_status_events + ops_order_stage_events + ops_activity_logs','filter'=>'displayed loaded time to first In Progress event where event actor equals Packed By; missing/mismatched events excluded and disclosed'],
        ['metric'=>'Front-desk completion timing','source'=>'Unified order activity','filter'=>'first In Progress to first Complete; Complete event attributed to its actual actor'],
        ['metric'=>'Walk-ins','source'=>'ops_orders.customer_contact','filter'=>'exact normalized Mobile values: walk in customer or working customer'],
        ['metric'=>'Order status compliance','source'=>'ops_orders + kpi_status_events','filter'=>'completed module rows without any in_progress transition'],
        ['metric'=>'Front-desk completion aging','source'=>'ops_orders.status, created_at','filter'=>'all non-complete orders created by report end; carry-over included'],
        ['metric'=>'Walk-in same-day duty','source'=>'ops_orders customer/mode fields, packed_at, completed_at','filter'=>'configured walk-in marker; created in report period'],
        ['metric'=>'Tasks due/on-time/overdue','source'=>'ops_checklist_tasks.deadline, status, completed_at/date_completed','filter'=>'due by report end; open overdue carry-over included'],
        ['metric'=>'Website updates · Front desk confirmation','source'=>'ops_packing_tasks.frontdesk_website_updated, frontdesk_website_updated_at, frontdesk_website_updated_by; user joins ops_employees.id','filter'=>'packing item date_loaded in report period; popup confirmation only (not board checkbox)'],
        ['metric'=>'Website updates · Packer board tick','source'=>'ops_activity_logs.entity_id, employee_id, created_at, metadata joined to ops_packing_tasks.id and ops_employees.id','filter'=>"entity_type=packing_task; action=packing_packing_website_confirmed_updated; metadata new_value=1; board tick only"],
        ['metric'=>'Waybill uploads','source'=>'hambelela_waybills.uploaded_by, uploaded_at, due_by','filter'=>'uploaded_at in report period; deleted_at IS NULL'],
        ['metric'=>'Waybill sends','source'=>'hambelela_waybills.sent_by, sent_at, due_by, status','filter'=>'sent_at in report period; deleted_at IS NULL'],
        ['metric'=>'Error/quality deductions','source'=>'ops_error_logs attribution, people_involved, severity, category','filter'=>'employee cause + verified KPI attribution in report period'],
        ['metric'=>'Bookkeeping reconciliation','source'=>'order_payment_allocations + ops_cash_book_entries','filter'=>'cash allocation and reconciliation in report period'],
        ['metric'=>'Attendance','source'=>'EPI sessions/business calendar','filter'=>'verified scheduled working days and portal sessions'],
    ];
    $spotReconciliations=[
        'packing'=>ops_rows("SELECT id,item_name,assigned_employee_id,date_completed FROM ops_packing_tasks WHERE date_completed BETWEEN ? AND ? AND deleted_at IS NULL ORDER BY date_completed DESC LIMIT 3",[$fromSql,$toSql]),
        'orders'=>ops_rows("SELECT id,order_number,assigned_packer_id,status,completed_at FROM ops_orders WHERE completed_at BETWEEN ? AND ? ORDER BY completed_at DESC LIMIT 3",[$fromSql,$toSql]),
        'tasks'=>ops_rows("SELECT id,task_name,assigned_employee_id,status,deadline,completed_at FROM ops_checklist_tasks WHERE deadline<=? AND deleted_at IS NULL ORDER BY deadline LIMIT 3",[$toSql]),
        'waybills'=>ops_rows("SELECT id,waybill_reference,uploaded_by,uploaded_at,sent_by,sent_at,status FROM hambelela_waybills WHERE (uploaded_at BETWEEN ? AND ? OR sent_at BETWEEN ? AND ?) AND deleted_at IS NULL ORDER BY COALESCE(sent_at,uploaded_at) DESC LIMIT 3",[$fromSql,$toSql,$fromSql,$toSql]),
        'quality'=>ops_rows("SELECT id,error_title,attribution_type,attributed_employee_id,severity,category,logged_at FROM ops_error_logs WHERE logged_at BETWEEN ? AND ? AND deleted_at IS NULL ORDER BY logged_at DESC LIMIT 3",[$errorsFromSql,$toSql]),
        'website_frontdesk_confirmed'=>ops_rows("SELECT p.id,p.item_name,p.date_loaded,p.frontdesk_website_updated_at confirmed_at,e.full_name confirmed_by,TIMESTAMPDIFF(MINUTE,p.date_loaded,p.frontdesk_website_updated_at) lag_minutes FROM ops_packing_tasks p LEFT JOIN ops_employees e ON e.id=p.frontdesk_website_updated_by WHERE p.date_loaded BETWEEN ? AND ? AND p.deleted_at IS NULL AND p.frontdesk_website_updated=1 AND p.frontdesk_website_updated_at IS NOT NULL ORDER BY p.frontdesk_website_updated_at DESC LIMIT 2",[$fromSql,$toSql]),
        'website_frontdesk_unconfirmed'=>ops_rows("SELECT id,item_name,date_loaded,TIMESTAMPDIFF(DAY,date_loaded,LEAST(NOW(),?)) age_days FROM ops_packing_tasks WHERE date_loaded BETWEEN ? AND ? AND deleted_at IS NULL AND (frontdesk_website_updated=0 OR frontdesk_website_updated_at IS NULL) ORDER BY date_loaded LIMIT 1",[$toSql,$fromSql,$toSql]),
        'website_board_ticks'=>ops_rows("SELECT l.entity_id id,p.item_name,p.date_loaded,l.created_at ticked_at,e.full_name ticked_by,TIMESTAMPDIFF(MINUTE,p.date_loaded,l.created_at) lag_minutes FROM ops_activity_logs l JOIN ops_packing_tasks p ON p.id=l.entity_id LEFT JOIN ops_employees e ON e.id=l.employee_id WHERE p.date_loaded BETWEEN ? AND ? AND p.deleted_at IS NULL AND l.entity_type='packing_task' AND l.action='packing_packing_website_confirmed_updated' AND (l.metadata LIKE '%\"new_value\":\"1\"%' OR l.metadata LIKE '%\"new_value\":1%') ORDER BY l.created_at DESC LIMIT 2",[$fromSql,$toSql]),
        'orders_status_sequences'=>$ordersAnalysis['verification']['status_sequences'],
        'orders_walk_ins'=>$ordersAnalysis['verification']['walk_ins'],
    ];
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
    $requestedSection=(string)($_GET['report_section']??'complete');
    kpi_send_json(['ok'=>true,'error'=>null,'requested_section'=>$requestedSection,'section_errors'=>$sectionErrors,'report'=>['name'=>'Employee Performance Analysis Report','status'=>'provisional','baseline'=>$trusted->format('Y-m-d'),'minimum_sample'=>5,'weights'=>$configuredWeights],'period'=>['key'=>$resolved['key'],'from'=>$resolved['from']->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'effective_from'=>$from->format('Y-m-d'),'working_days'=>$workingDays],'employees'=>$employees,'reports'=>$reports,'charts'=>$charts,'team_summary'=>$teamSummary,'orders_analysis'=>$ordersAnalysis,'operational_risks'=>$risks,'cross_checks'=>['bookkeeping'=>['cash_orders_total'=>$cashTotal,'matched'=>$cashMatched,'missing'=>count($missingCash),'missing_orders'=>$missingCash,'sample_examples'=>array_slice($bookkeepingRows,0,3)],'late_couriers'=>performance_section_attempt($sectionErrors,'courier',static fn()=>array_slice(array_values(array_filter($courierEngine->getEvidence($epiFilters,1000),static fn(array$x):bool=>in_array($x['send_result'],['late','overdue','late_after_availability'],true))),0,100),[]),'spot_reconciliations'=>$spotReconciliations],'metric_sources'=>$metricSources,'field_mappings'=>$fieldMappings,'data_quality'=>$quality,'last_refreshed_at'=>(new DateTimeImmutable('now',$zone))->format(DATE_ATOM)]);
} catch(Throwable $error) {
    error_log(date(DATE_ATOM).' performance reports: '.$error->getMessage().' in '.$error->getFile().':'.$error->getLine().PHP_EOL,3,BASE_PATH.'/logs/kpi_errors.log');
    kpi_send_json(['ok'=>false,'success'=>false,'data'=>null,'message'=>'Performance reports are temporarily unavailable.','error'=>'KPI_PERFORMANCE_REPORT_FAILED','error_code'=>'KPI_PERFORMANCE_REPORT_FAILED'],500);
}
