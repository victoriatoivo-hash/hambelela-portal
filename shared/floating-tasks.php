<?php

declare(strict_types=1);

function floating_task_role_keys(string $group): array
{
    if ($group === 'front_desk') return ['front_desk_admin', 'front_desk_admin_employee'];
    if ($group === 'packers') return ['packer', 'packer_production_staff'];
    return ['front_desk_admin', 'front_desk_admin_employee', 'packer', 'packer_production_staff', 'supervisor_manager'];
}
function floating_task_candidates(string $group): array
{
    $keys = floating_task_role_keys($group);
    $marks = implode(',', array_fill(0, count($keys), '?'));
    return ops_rows(
        "SELECT e.id,e.full_name,r.role_key,
          SUM(CASE WHEN t.assignment_mode='floating' AND t.status IN ('new','in_progress') AND t.deleted_at IS NULL AND t.archived_at IS NULL THEN 1 ELSE 0 END) active_floating,
          SUM(CASE WHEN t.status IN ('new','in_progress') AND t.deleted_at IS NULL AND t.archived_at IS NULL THEN 1 ELSE 0 END) active_total,
          SUM(CASE WHEN t.status IN ('new','in_progress') AND t.deadline<NOW() AND t.deleted_at IS NULL AND t.archived_at IS NULL THEN 1 ELSE 0 END) overdue_total,
          (SELECT COUNT(*) FROM ops_floating_task_assignments fa WHERE fa.assigned_employee_id=e.id AND fa.assigned_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) recent_floating,
          (SELECT MAX(fa.assigned_at) FROM ops_floating_task_assignments fa WHERE fa.assigned_employee_id=e.id) last_floating_at
         FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id
         LEFT JOIN ops_checklist_tasks t ON t.assigned_employee_id=e.id
         WHERE e.status='active' AND r.role_key IN ({$marks})
         GROUP BY e.id,e.full_name,r.role_key
         ORDER BY active_floating,active_total,overdue_total,recent_floating,
          CASE WHEN last_floating_at IS NULL THEN 0 ELSE 1 END,last_floating_at,e.id",
        $keys
    );
}

function floating_task_assign(int $taskId, string $group, string $source = 'automatic_release', ?int $actorId = null, ?string $reason = null): array
{
    $group = in_array($group, ['front_desk','packers','all_employees'], true) ? $group : 'all_employees';
    $candidates = floating_task_candidates($group);
    if (!$candidates) throw new RuntimeException('No active employees are eligible for this Floating Task.');
    $chosen = $candidates[0];
    $employeeId = (int) $chosen['id'];
    $task = ops_rows('SELECT assigned_employee_id,task_name FROM ops_checklist_tasks WHERE id=? LIMIT 1', [$taskId])[0] ?? null;
    if (!$task) throw new RuntimeException('Floating Task not found.');
    $previousId = (int) ($task['assigned_employee_id'] ?? 0);
    db()->prepare("UPDATE ops_checklist_tasks SET assigned_employee_id=?,floating_assigned_at=NOW(),date_assigned=NOW(),employee_visible=1 WHERE id=?")->execute([$employeeId,$taskId]);
    db()->prepare("INSERT INTO ops_floating_task_assignments(task_id,previous_employee_id,assigned_employee_id,assignment_source,assignment_reason,assigned_by) VALUES(?,?,?,?,?,?)")
        ->execute([$taskId,$previousId?:null,$employeeId,$source,$reason,$actorId?:null]);
    if (function_exists('ops_activity_log')) ops_activity_log($previousId > 0 ? 'floating_task_reassigned' : 'floating_task_assigned', 'checklist_task', $taskId, [
        'eligible_group'=>$group,'previous_assigned_employee_id'=>$previousId?:null,'assigned_employee_id'=>$employeeId,
        'assignment_source'=>$source,'assignment_reason'=>$reason,'fairness_inputs'=>[
            'active_floating'=>(int)$chosen['active_floating'],'active_total'=>(int)$chosen['active_total'],
            'overdue_total'=>(int)$chosen['overdue_total'],'recent_floating'=>(int)$chosen['recent_floating'],
            'last_floating_at'=>$chosen['last_floating_at'] ?: null,
        ],
    ]);
    return $chosen;
}
