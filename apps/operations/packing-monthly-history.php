<?php
declare(strict_types=1);

function packing_monthly_history(PDO $db, DateTimeImmutable $start):array {
    $done = "p.packing_status IN ('done','website','packed_label_needed','done_needs_label')";
    $open = "p.packing_status IN ('not_started','packing','label_created','correction_needed')";
    $live = ops_column_exists('ops_packing_tasks','deleted_at') ? ' AND p.deleted_at IS NULL' : '';
    foreach (['duplicate_removed_at','cancelled_at','reversed_at'] as $flag) {
        if (ops_column_exists('ops_packing_tasks',$flag)) $live.=" AND p.$flag IS NULL";
    }
    $active = ops_column_exists('ops_packing_tasks','archived_at') ? ' AND p.archived_at IS NULL' : '';
    $person = 'p.assigned_employee_id';
    if (ops_table_exists('ops_packing_assignment_log')) {
        // First reassignment after completion identifies the profile that held the work when finished.
        $person = "CASE WHEN $done THEN COALESCE((SELECT COALESCE(a.old_employee_id,0) FROM ops_packing_assignment_log a WHERE a.packing_task_id=p.id AND a.changed_at>p.date_completed ORDER BY a.changed_at,a.id LIMIT 1),p.assigned_employee_id) ELSE p.assigned_employee_id END";
    }
    // One source row per task, never one per edit or status event. Finished archives count; archived backlog does not.

    $stmt=$db->prepare("SELECT $person AS profile_id, CASE WHEN $done THEN 'packed' ELSE 'pending' END AS bucket,
        CASE WHEN $done THEN p.quantity_packed ELSE p.quantity_planned END AS quantity, COUNT(*) AS batches
        FROM ops_packing_tasks p WHERE (($done AND p.date_completed>=? AND p.date_completed<?)
        OR ($open $active)) $live GROUP BY profile_id,bucket,quantity");
    $stmt->execute([$start->format('Y-m-d H:i:s'),$start->modify('+1 month')->format('Y-m-d H:i:s')]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
