<?php

declare(strict_types=1);

function task_floating_role_keys(string $pool): array
{
    if ($pool === 'front_desk') return ['front_desk_admin', 'front_desk_admin_employee'];
    if ($pool === 'back_packers') return ['packer', 'packer_production_staff'];
    return [];
}

function task_floating_role_label(string $pool): string
{
    return $pool === 'front_desk' ? 'Front Desk' : ($pool === 'back_packers' ? 'Back / Packers' : 'Unknown team');
}

function task_floating_employee_is_eligible(int $employeeId, string $pool): bool
{
    $roles = task_floating_role_keys($pool);
    if ($employeeId <= 0 || !$roles) return false;
    $marks = implode(',', array_fill(0, count($roles), '?'));
    $stmt = db()->prepare("SELECT 1 FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key IN ({$marks}) LIMIT 1");
    $stmt->execute(array_merge([$employeeId], $roles));
    return (bool) $stmt->fetchColumn();
}

/** Active workload only: released, visible New/In Progress tasks; completed/history never count. */
function task_floating_candidates(string $pool): array
{
    $roles = task_floating_role_keys($pool);
    if (!$roles) return [];
    $marks = implode(',', array_fill(0, count($roles), '?'));
    return ops_rows(
        "SELECT e.id,e.full_name,r.role_key,
                COUNT(DISTINCT CASE WHEN t.status IN ('new','in_progress') AND t.employee_visible=1
                     AND (t.scheduled_at IS NULL OR t.released_at IS NOT NULL)
                     AND t.archived_at IS NULL AND t.deleted_at IS NULL THEN t.id END) active_workload,
                MAX(CASE WHEN t.assignment_type='floating' THEN t.allocated_at END) last_floating_at,
                COUNT(DISTINCT CASE WHEN t.assignment_type='floating' AND t.allocated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN t.id END) recent_floating_count
         FROM ops_employees e
         JOIN ops_roles r ON r.id=e.role_id
         LEFT JOIN ops_checklist_tasks t ON t.assigned_employee_id=e.id
         WHERE e.status='active' AND r.role_key IN ({$marks})
         GROUP BY e.id,e.full_name,r.role_key
         ORDER BY active_workload ASC,
                  CASE WHEN last_floating_at IS NULL THEN 0 ELSE 1 END ASC,
                  last_floating_at ASC,recent_floating_count ASC,e.id ASC",
        $roles
    );
}

/**
 * Atomically allocate one persisted Floating Task. The existing task row is updated;
 * no child/duplicate task is created. Returns the authoritative allocation state.
 */
function task_floating_allocate(int $taskId, string $method = 'automatic', bool $makeVisible = true): array
{
    if ($taskId <= 0) throw new RuntimeException('Choose a valid Floating Task.');
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    $lockName = 'floating-task-' . $taskId;
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lockStmt->execute([$lockName]);
    if ((int) $lockStmt->fetchColumn() !== 1) throw new RuntimeException('This Floating Task is already being allocated. Try again.');
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM ops_checklist_tasks WHERE id=? AND archived_at IS NULL AND deleted_at IS NULL FOR UPDATE');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task || (string) ($task['assignment_type'] ?? 'specific') !== 'floating') throw new RuntimeException('This task is not a Floating Task.');
        if ((int) ($task['assigned_employee_id'] ?? 0) > 0 && (string) ($task['floating_allocation_status'] ?? '') === 'allocated') {
            if ($ownsTransaction) $pdo->commit();
            return ['status'=>'allocated','employee_id'=>(int)$task['assigned_employee_id'],'already_allocated'=>true];
        }
        $pool = (string) ($task['floating_eligible_role'] ?? '');
        $candidates = task_floating_candidates($pool);
        $attemptedAt = date('Y-m-d H:i:s');
        if (!$candidates) {
            $pdo->prepare("UPDATE ops_checklist_tasks SET assigned_employee_id=NULL,allocated_employee_id=NULL,floating_allocation_status='waiting_eligible_employee',allocation_attempted_at=?,allocation_decision_json=?,employee_visible=0 WHERE id=? AND assignment_type='floating'")
                ->execute([$attemptedAt, json_encode(['reason'=>'no_active_eligible_employee','eligible_role'=>$pool]), $taskId]);
            if (function_exists('ops_activity_log')) ops_activity_log('floating_task_allocation_waiting', 'checklist_task', $taskId, ['eligible_role'=>$pool,'attempted_at'=>$attemptedAt]);
            if ($ownsTransaction) $pdo->commit();
            return ['status'=>'waiting_eligible_employee','employee_id'=>null,'eligible_role'=>$pool];
        }
        $selected = $candidates[0];
        $employeeId = (int) $selected['id'];
        if (!task_floating_employee_is_eligible($employeeId, $pool)) throw new RuntimeException('The selected employee is no longer eligible for this Floating Task.');
        $metadata = [
            'eligible_role'=>$pool,'employee_id'=>$employeeId,'active_workload'=>(int)$selected['active_workload'],
            'last_floating_at'=>$selected['last_floating_at'] ?: null,'recent_floating_count'=>(int)$selected['recent_floating_count'],
            'tie_break'=>'least_recent_floating_allocation','candidate_count'=>count($candidates),
        ];
        $update = $pdo->prepare("UPDATE ops_checklist_tasks SET assigned_employee_id=?,allocated_employee_id=?,date_assigned=?,allocated_at=?,allocation_method=?,allocation_attempted_at=?,floating_allocation_status='allocated',allocation_decision_json=?,employee_visible=? WHERE id=? AND assignment_type='floating' AND assigned_employee_id IS NULL");
        $update->execute([$employeeId,$employeeId,$attemptedAt,$attemptedAt,$method,$attemptedAt,json_encode($metadata),$makeVisible?1:0,$taskId]);
        if ($update->rowCount() !== 1) throw new RuntimeException('The Floating Task allocation changed concurrently. Refresh and try again.');
        if (function_exists('ops_activity_log')) ops_activity_log('floating_task_allocated', 'checklist_task', $taskId, $metadata + ['allocation_method'=>$method,'assigned_employee_id'=>$employeeId]);
        if ($ownsTransaction) $pdo->commit();
        return ['status'=>'allocated','employee_id'=>$employeeId,'employee_name'=>(string)$selected['full_name'],'eligible_role'=>$pool,'decision'=>$metadata];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $ignored) {}
    }
}

