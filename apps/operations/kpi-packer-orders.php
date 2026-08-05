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

function kpi_packer_orders_evidence(array $employee, string $fromSql, string $toSql, array $schedule, array $holidays, array $settings, array $leave = []): array
{
    $employeeId = (int) $employee['id'];
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
    $qualityRows = ops_rows("SELECT category,customer_impact FROM ops_error_logs WHERE responsible_employee_id=? AND affects_kpi_accuracy=1 AND accuracy_verified_by IS NOT NULL AND logged_at BETWEEN ? AND ? AND deleted_at IS NULL", [$employeeId,$fromSql,$toSql]);

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
