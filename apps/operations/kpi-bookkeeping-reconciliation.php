<?php

declare(strict_types=1);

/**
 * Build read-only, evidence-based cash reconciliation for an employee KPI.
 * Financial source rows are never changed by this helper.
 */
function kpi_bookkeeping_reconciliation(int $employeeId, string $fromSql, string $toSql, array $settings): array
{
    $empty = [
        'metrics' => [], 'orders' => [], 'daily' => [], 'deposits' => [],
        'score' => null, 'score_evidence' => 'No eligible evidence.',
        'deposit_schedule_configured' => false, 'excluded_from_scoring' => 0,
    ];
    if ($employeeId <= 0 || !ops_table_exists('ops_orders') || !ops_table_exists('order_payment_allocations') || !ops_table_exists('ops_cash_book_entries')) return $empty;

    $deadline = preg_match('/^\d{2}:\d{2}$/', (string) ($settings['bookkeeping_cash_deadline'] ?? ''))
        ? (string) $settings['bookkeeping_cash_deadline'] : '17:00';
    $depositSchedule = trim((string) ($settings['bookkeeping_deposit_schedule'] ?? ''));
    $depositScheduleConfigured = $depositSchedule !== '' && $depositSchedule !== 'not_configured';
    $allocationTable = ops_table_exists('bookkeeping_order_allocations');

    $orders = ops_rows(
        "SELECT o.id,o.order_number,o.customer_name,o.payment_status,o.status,o.created_at,o.payment_updated_at,o.refund_total,
                p.amount_cents,p.source payment_source,p.updated_at allocation_updated_at
         FROM ops_orders o
         JOIN order_payment_allocations p ON p.order_id=o.id AND p.payment_method='cash' AND p.amount_cents>0
         WHERE o.payment_status IN ('paid','partial')
           AND COALESCE(o.payment_updated_at,p.updated_at,o.updated_at,o.created_at) BETWEEN ? AND ?
         ORDER BY COALESCE(o.payment_updated_at,p.updated_at,o.updated_at,o.created_at),o.id",
        [$fromSql, $toSql]
    );
    $orderIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['id'], $orders)));
    $entriesByOrder = [];
    $possibleByAmountDate = [];
    $allEntries = ops_rows(
        "SELECT b.id,b.transaction_date,b.transaction_type,b.description,b.related_order_id,b.related_order_number,b.cash_in,b.cash_out,
                b.source,b.notes,b.recorded_by,b.created_by_user_id,b.created_by_name,b.edited_by,b.updated_by_user_id,b.status,b.deleted_at,b.created_at,b.updated_at,
                COALESCE(e.full_name,b.created_by_name,'Unknown employee') created_by_name_resolved
         FROM ops_cash_book_entries b
         LEFT JOIN ops_employees e ON e.id=COALESCE(b.created_by_user_id,b.recorded_by)
         WHERE b.transaction_date BETWEEN DATE_SUB(?,INTERVAL 2 DAY) AND DATE_ADD(?,INTERVAL 2 DAY)
         ORDER BY b.created_at,b.id",
        [$fromSql, $toSql]
    );
    foreach ($allEntries as $entry) {
        $related = (int) ($entry['related_order_id'] ?? 0);
        if ($related > 0) $entriesByOrder[$related][] = $entry;
        $key = substr((string) $entry['transaction_date'], 0, 10) . '|' . number_format((float) $entry['cash_in'], 2, '.', '');
        $possibleByAmountDate[$key][] = $entry;
    }

    $allocationsByOrder = [];
    if ($allocationTable && $orderIds) {
        $marks = implode(',', array_fill(0, count($orderIds), '?'));
        foreach (ops_rows("SELECT a.*,b.created_at entry_created_at,b.transaction_date,b.status,b.deleted_at,b.cash_in,
                                  COALESCE(e.full_name,b.created_by_name,'Unknown employee') created_by_name_resolved
                           FROM bookkeeping_order_allocations a
                           JOIN ops_cash_book_entries b ON b.id=a.bookkeeping_entry_id
                           LEFT JOIN ops_employees e ON e.id=COALESCE(b.created_by_user_id,b.recorded_by)
                           WHERE a.order_id IN ({$marks}) AND a.review_status='confirmed'", $orderIds) as $allocation) {
            $allocationsByOrder[(int) $allocation['order_id']][] = $allocation;
        }
    }

    $resultRows = [];
    foreach ($orders as $order) {
        $orderId = (int) $order['id'];
        $expectedCents = (int) $order['amount_cents'];
        $paymentAt = (string) ($order['payment_updated_at'] ?: $order['allocation_updated_at'] ?: '');
        $linked = [];
        foreach ($allocationsByOrder[$orderId] ?? [] as $allocation) {
            $linked[] = [
                'entry_id'=>(int)$allocation['bookkeeping_entry_id'], 'amount_cents'=>(int)$allocation['cash_amount_allocated_cents'],
                'created_at'=>(string)$allocation['entry_created_at'], 'transaction_date'=>(string)$allocation['transaction_date'],
                'created_by'=>(string)$allocation['created_by_name_resolved'], 'active'=>empty($allocation['deleted_at']) && (string)$allocation['status']==='active',
                'source'=>'bookkeeping_order_allocations #'.(int)$allocation['allocation_id'],
            ];
        }
        if (!$linked) {
            foreach ($entriesByOrder[$orderId] ?? [] as $entry) {
                $linked[] = [
                    'entry_id'=>(int)$entry['id'], 'amount_cents'=>(int)round((float)$entry['cash_in']*100),
                    'created_at'=>(string)$entry['created_at'], 'transaction_date'=>(string)$entry['transaction_date'],
                    'created_by'=>(string)$entry['created_by_name_resolved'], 'active'=>empty($entry['deleted_at']) && (string)$entry['status']==='active',
                    'source'=>'ops_cash_book_entries.related_order_id',
                ];
            }
        }
        $active = array_values(array_filter($linked, static fn(array $row): bool => $row['active']));
        $recordedCents = array_sum(array_column($active, 'amount_cents'));
        $classification = 'missing';
        if (count($active) > 1 && $recordedCents >= $expectedCents) $classification = 'duplicate';
        elseif ($recordedCents > 0 && $recordedCents < $expectedCents) $classification = 'partially_recorded';
        elseif ($recordedCents > 0 && $recordedCents !== $expectedCents) $classification = 'amount_mismatch';
        elseif ($recordedCents === $expectedCents && count($active) === 1) $classification = 'matched';
        elseif (!$active) {
            $key = substr($paymentAt, 0, 10) . '|' . number_format($expectedCents / 100, 2, '.', '');
            if (!empty($possibleByAmountDate[$key])) $classification = 'ambiguous_match';
        }
        $first = $active[0] ?? null;
        $delayMinutes = null;
        $deadlineAt = null;
        $backdated = false;
        if ($first && $paymentAt !== '' && strtotime($paymentAt) !== false && strtotime((string)$first['created_at']) !== false) {
            $delayMinutes = max(0, (int) floor((strtotime((string)$first['created_at']) - strtotime($paymentAt)) / 60));
            $deadlineAt = substr($paymentAt, 0, 10) . ' ' . $deadline . ':00';
            $backdated = substr((string)$first['transaction_date'], 0, 10) < substr((string)$first['created_at'], 0, 10);
            if ($classification === 'matched' && strtotime((string)$first['created_at']) > strtotime($deadlineAt)) $classification = 'matched_late';
        }
        $possible = $classification === 'ambiguous_match' ? ($possibleByAmountDate[$key] ?? []) : [];
        $resultRows[] = [
            'order_id'=>$orderId, 'order_number'=>$order['order_number'], 'customer_name'=>$order['customer_name'],
            'payment_date'=>$paymentAt ?: null, 'payment_type'=>$order['payment_source']==='order_list'?'Cash allocation':'Cash',
            'cash_expected'=>$expectedCents/100, 'paid'=>in_array((string)$order['payment_status'],['paid','partial'],true)?'Yes':'No',
            'bookkeeping_entry'=>$first ? 'BK-'.$first['entry_id'] : null, 'bookkeeping_entry_id'=>$first['entry_id'] ?? null,
            'cash_recorded'=>$recordedCents/100, 'recorded_at'=>$first['created_at'] ?? null,
            'effective_transaction_date'=>$first['transaction_date'] ?? null, 'recorded_by'=>$first['created_by'] ?? null,
            'responsible_employee_id'=>$employeeId, 'delay_minutes'=>$delayMinutes, 'deadline_at'=>$deadlineAt,
            'same_day'=>($first && substr($paymentAt,0,10)===substr((string)$first['created_at'],0,10)),
            'backdated'=>$backdated, 'result'=>$classification, 'evidence_source'=>$first['source'] ?? ($possible?'date/amount candidate only':'none'),
            'requires_review'=>in_array($classification,['missing','amount_mismatch','partially_recorded','duplicate','ambiguous_match'],true),
            'excluded_from_scoring'=>in_array($classification,['ambiguous_match'],true) || $paymentAt==='',
        ];
    }

    $deposits = array_values(array_filter($allEntries, static function(array $entry): bool {
        return preg_match('/deposit|banking/i', (string)($entry['transaction_type'].' '.$entry['source'].' '.$entry['description'])) === 1;
    }));
    foreach ($deposits as &$deposit) {
        $deposit['deposit_amount'] = max((float)$deposit['cash_out'], (float)$deposit['cash_in']);
        $deposit['recorded_at'] = $deposit['created_at'];
        $deposit['recorded_by'] = $deposit['created_by_name_resolved'];
        $deposit['timing_label'] = $depositScheduleConfigured ? 'Recorded in Bookkeeping' : 'Evidence only — deposit schedule not configured';
    }
    unset($deposit);

    $daily = [];
    foreach ($resultRows as $row) {
        $day = substr((string)($row['payment_date'] ?: $row['effective_transaction_date']), 0, 10) ?: 'Timestamp unavailable';
        if (!isset($daily[$day])) $daily[$day]=['date'=>$day,'cash_orders'=>0,'expected_cash'=>0.0,'matched_orders'=>0,'recorded_cash'=>0.0,'missing'=>0,'variance'=>0.0,'same_day_entries'=>0,'deposit_expected'=>$depositScheduleConfigured?'Configured':'Not configured','deposit_recorded'=>0.0,'deposit_variance'=>null,'responsible_employee_id'=>$employeeId,'result'=>'Reconciled'];
        $daily[$day]['cash_orders']++;$daily[$day]['expected_cash']+=(float)$row['cash_expected'];$daily[$day]['recorded_cash']+=(float)$row['cash_recorded'];
        if (in_array($row['result'],['matched','matched_late'],true)) $daily[$day]['matched_orders']++;
        if ($row['result']==='missing') $daily[$day]['missing']++;
        if ($row['same_day']) $daily[$day]['same_day_entries']++;
        if ($row['requires_review']) $daily[$day]['result']='Review'; elseif ($row['result']==='matched_late' && $daily[$day]['result']==='Reconciled') $daily[$day]['result']='Exception';
    }
    foreach ($deposits as $deposit) { $day=substr((string)$deposit['transaction_date'],0,10); if(isset($daily[$day]))$daily[$day]['deposit_recorded']+=(float)$deposit['deposit_amount']; }
    foreach ($daily as &$day) { $day['variance']=round($day['expected_cash']-$day['recorded_cash'],2); if($depositScheduleConfigured)$day['deposit_variance']=round($day['expected_cash']-$day['deposit_recorded'],2); }
    unset($day);

    $eligibleScore = array_values(array_filter($resultRows, static fn(array $row): bool => !$row['excluded_from_scoring']));
    $matched = count(array_filter($eligibleScore, static fn(array $row): bool => in_array($row['result'],['matched','matched_late'],true)));
    $reconciliationScore = $eligibleScore ? round(100*$matched/count($eligibleScore),1) : null;
    $timed = array_values(array_filter($resultRows, static fn(array $row): bool => in_array($row['result'],['matched','matched_late'],true) && $row['delay_minutes']!==null));
    $onTime = count(array_filter($timed, static fn(array $row): bool => $row['result']==='matched'));
    $timelinessScore = $timed ? round(100*$onTime/count($timed),1) : null;
    $scoreParts=[]; if($reconciliationScore!==null)$scoreParts[]=['weight'=>60,'score'=>$reconciliationScore]; if($timelinessScore!==null)$scoreParts[]=['weight'=>25,'score'=>$timelinessScore];
    $weight=array_sum(array_column($scoreParts,'weight'));$score=$weight?round(array_sum(array_map(static fn(array$p):float=>$p['weight']*$p['score'],$scoreParts))/$weight,1):null;
    $delays=array_values(array_filter(array_column($resultRows,'delay_minutes'),static fn($v):bool=>$v!==null));sort($delays,SORT_NUMERIC);$median=$delays?($delays[intdiv(count($delays),2)]):null;
    $sumExpected=array_sum(array_column($resultRows,'cash_expected'));$sumRecorded=array_sum(array_column($resultRows,'cash_recorded'));
    return [
        'metrics'=>[
            ['label'=>'Cash Orders Expected','value'=>count($resultRows)],['label'=>'Cash Orders Matched','value'=>$matched],
            ['label'=>'Cash Orders Missing','value'=>count(array_filter($resultRows,static fn($r)=>$r['result']==='missing'))],
            ['label'=>'Cash Amount Expected','value'=>$sumExpected,'format'=>'currency'],['label'=>'Cash Amount Recorded','value'=>$sumRecorded,'format'=>'currency'],
            ['label'=>'Cash Variance','value'=>$sumExpected-$sumRecorded,'format'=>'currency'],
            ['label'=>'Same-Day Logging Compliance','value'=>$timelinessScore,'format'=>'percent','status'=>$timelinessScore===null?'unmeasured':'measured'],
            ['label'=>'Average Logging Delay','value'=>$delays?round(array_sum($delays)/count($delays),1):null,'format'=>'minutes'],
            ['label'=>'Median Logging Delay','value'=>$median,'format'=>'minutes'],
            ['label'=>'Duplicate or Mismatched Entries','value'=>count(array_filter($resultRows,static fn($r)=>in_array($r['result'],['duplicate','amount_mismatch','partially_recorded'],true)))],
            ['label'=>'Deposits Expected','value'=>$depositScheduleConfigured?'Configured':'Not configured','status'=>$depositScheduleConfigured?'provisional':'unmeasured'],
            ['label'=>'Deposits Recorded','value'=>count($deposits)],
            ['label'=>'Deposit Amount Variance','value'=>null,'status'=>'unmeasured','explanation'=>$depositScheduleConfigured?'Requires configured retained-float and expense rules.':'Deposit schedule not configured; excluded from scoring.'],
            ['label'=>'Entries Requiring Review','value'=>count(array_filter($resultRows,static fn($r)=>$r['requires_review']))],
        ],
        'orders'=>$resultRows, 'daily'=>array_values($daily), 'deposits'=>$deposits, 'score'=>$score,
        'score_evidence'=>$matched.' of '.count($eligibleScore).' eligible cash orders reliably reconciled; '.count($timed).' matched entries have reliable timing. Deposit evidence is '.($depositScheduleConfigured?'configured but provisional.':'excluded because no schedule is configured.'),
        'deposit_schedule_configured'=>$depositScheduleConfigured, 'deposit_schedule'=>$depositSchedule ?: null,
        'cash_deadline'=>$deadline, 'excluded_from_scoring'=>count(array_filter($resultRows,static fn($r)=>$r['excluded_from_scoring'])),
        'matching_method'=>'Immutable order ID or confirmed audited allocation only',
    ];
}
