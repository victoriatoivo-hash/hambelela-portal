<?php
declare(strict_types=1);

/** Read-only explanation of the existing calculation; no replacement scoring formula. */
function kpi_business_health_coverage(array $components): array
{
    $sources = [
        'orders'=>['orders','Order status and completion records'],
        'packing'=>['packing-performance','Assigned Packing List work and recorded output'],
        'waybills'=>['waybills','Courier upload/send timestamps and pending deadlines'],
        'tasks'=>['task-management','Assigned tasks, completion timestamps and deadlines'],
        'bookkeeping'=>['bookkeeping','Cash-up reconciliation days'],
        'website'=>['website-updates','Stock availability and website-update timestamps'],
        'attendance'=>['attendance','Today’s portal session presence; not physical attendance'],
        'system_issues'=>['work-quality','Verified system-issue outcomes'],
        'communications'=>['work-quality','Recorded customer conversations and follow-ups'],
        'quality'=>['errors-quality','Error Log resolution and repeat-incident records'],
    ];
    $rows = []; $measured = 0;
    foreach ($components as $component) {
        $key = (string)($component['key'] ?? '');
        $sample = (int)($component['sample'] ?? 0);
        $valid = isset($component['score']) && is_numeric($component['score']);
        if ($valid) $measured++;
        $status = $valid ? 'measured' : ($key === 'packing' ? 'output_only' : ($sample === 0 ? 'no_observations' : 'insufficient_observations'));
        $needed = $valid ? 'Existing evidence requirement met.' : ($key === 'packing'
            ? 'Packing output is available, but this business indicator has no approved comparable batch-scoring measure. A management-approved measure is needed; output must not be substituted for a score.'
            : 'The existing calculation requires at least 5 qualifying observations. Record the source activity and its required timestamps/outcomes; missing observations are not employee failures.');
        $rows[] = array_merge($component, [
            'status'=>$status, 'minimum_sample'=>$key === 'packing' ? null : 5,
            'source'=>$sources[$key][1] ?? 'Original operational evidence',
            'report_tab'=>$sources[$key][0] ?? 'work-quality',
            'needed'=>$needed, 'scope'=>$key === 'attendance' ? 'Current day only (legacy indicator)' : 'Selected reporting period',
        ]);
    }
    return ['measured_categories'=>$measured, 'required_categories'=>count($rows),
        'complete'=>count($rows)>0 && $measured===count($rows), 'components'=>$rows,
        'explanation'=>'The existing business indicator averages its required category results only when all are measured. Missing categories are not dropped, assigned zero or replaced by order completion. Employee scores use their separate approved role calculations.'];
}
