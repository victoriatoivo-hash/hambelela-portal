<?php

declare(strict_types=1);

/** A task is released when it was never scheduled, or its release was recorded. */
function task_is_released_sql(string $alias = 't'): string
{
    return "({$alias}.scheduled_at IS NULL OR {$alias}.released_at IS NOT NULL)";
}

function task_release_due_scheduled_tasks(): int
{
    if (!function_exists('ops_table_exists') || !ops_table_exists('ops_checklist_tasks')
        || !ops_column_exists('ops_checklist_tasks', 'scheduled_at')
        || !ops_column_exists('ops_checklist_tasks', 'released_at')) return 0;

    $now = (new DateTimeImmutable('now', new DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');
    $floatingColumns = ops_column_exists('ops_checklist_tasks', 'assignment_type') ? ', t.assignment_type, t.floating_eligible_role, t.floating_allocation_status' : '';
    $rows = ops_rows(
        "SELECT t.id, t.assigned_employee_id, t.task_name, t.scheduled_at{$floatingColumns} FROM ops_checklist_tasks t
         LEFT JOIN ops_checklist_recurring_templates rt ON rt.id=t.recurring_template_id
         WHERE t.scheduled_at IS NOT NULL AND t.scheduled_at <= ? AND t.released_at IS NULL
           AND t.status NOT IN ('complete','completed','done','archived','deleted','trashed','cancelled')
           AND (t.recurring_template_id IS NULL OR (rt.is_active=1 AND COALESCE(rt.status,'active')='active'))
           AND t.archived_at IS NULL AND t.deleted_at IS NULL ORDER BY t.scheduled_at, t.id LIMIT 100",
        [$now]
    );
    $released = 0;
    foreach ($rows as $row) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $isFloating = (string) ($row['assignment_type'] ?? 'specific') === 'floating';
            if ($isFloating && function_exists('task_floating_allocate')) {
                $allocation = task_floating_allocate((int) $row['id'], 'automatic', false);
                $row['assigned_employee_id'] = (int) ($allocation['employee_id'] ?? 0);
            }
            $hasAssignee = (int) ($row['assigned_employee_id'] ?? 0) > 0;
            $stmt = $pdo->prepare(
                "UPDATE ops_checklist_tasks SET released_at = ?, date_assigned = CASE WHEN ? = 1 THEN COALESCE(date_assigned, ?) ELSE date_assigned END, employee_visible = ?
                 WHERE id = ? AND released_at IS NULL AND scheduled_at IS NOT NULL AND scheduled_at <= ?
                   AND status NOT IN ('complete','completed','done','archived','deleted','trashed','cancelled')
                   AND archived_at IS NULL AND deleted_at IS NULL
                   AND (recurring_template_id IS NULL OR EXISTS (SELECT 1 FROM ops_checklist_recurring_templates rt WHERE rt.id=ops_checklist_tasks.recurring_template_id AND rt.is_active=1 AND COALESCE(rt.status,'active')='active'))"
            );
            $stmt->execute([$now, $hasAssignee ? 1 : 0, $now, $hasAssignee ? 1 : 0, (int) $row['id'], $now]);
            if ($stmt->rowCount() !== 1) { $pdo->rollBack(); continue; }
            if ($hasAssignee
                && !notifications_notify_task_assigned((int) $row['id'], (int) $row['assigned_employee_id'], (string) $row['task_name'])) {
                error_log('Scheduled task released; automatic notification retry pending for task '.(int)$row['id']);
            }
            if (function_exists('ops_activity_log')) ops_activity_log('task_released', 'checklist_task', (int) $row['id'], [
                'scheduled_at' => (string) $row['scheduled_at'], 'released_at' => $now, 'assigned_employee_id' => $hasAssignee ? (int) $row['assigned_employee_id'] : null,
                'assignment_type' => $isFloating ? 'floating' : 'specific',
            ]);
            $pdo->commit();

            $released++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Scheduled task release failed for task ' . (int) $row['id'] . ': ' . $e->getMessage());
        }
    }
    return $released;
}
