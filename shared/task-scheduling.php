<?php

declare(strict_types=1);

/** A task is released when it was never scheduled, or its release was recorded. */
function task_is_released_sql(string $alias = 't'): string
{
    return "({$alias}.scheduled_at IS NULL OR {$alias}.released_at IS NOT NULL)";
}

function task_scheduled_popup_recipient_ids(array $values, int $assignedId): array
{
    $ids = [];
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === 'assigned' && $assignedId > 0) $ids[] = $assignedId;
        elseif ($value === 'role:front_desk') $ids = array_merge($ids, notifications_role_recipients(['front_desk_admin', 'front_desk_admin_employee']));
        elseif ($value === 'role:packers') $ids = array_merge($ids, notifications_role_recipients(['packer', 'packer_production_staff']));
        elseif ($value === 'role:all_relevant') $ids = array_merge($ids, notifications_role_recipients(['front_desk_admin', 'front_desk_admin_employee', 'packer', 'packer_production_staff', 'supervisor_manager']));
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !ops_table_exists('ops_employees')) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    return array_map(static fn(array $row): int => (int) $row['id'], ops_rows("SELECT id FROM ops_employees WHERE status = 'active' AND id IN ({$marks})", $ids));
}

function task_deliver_configured_popup(array $task): bool
{
    $taskId = (int) ($task['id'] ?? 0);
    if ($taskId <= 0 || empty($task['urgent_alert_enabled']) || !empty($task['urgent_alert_sent_at'])) return true;
    $claim = db()->prepare("UPDATE ops_checklist_tasks SET urgent_alert_claimed_at = NOW(), urgent_alert_last_error = NULL WHERE id = ? AND urgent_alert_enabled = 1 AND urgent_alert_sent_at IS NULL AND urgent_alert_claimed_at IS NULL");
    $claim->execute([$taskId]);
    if ($claim->rowCount() !== 1) return true;
    try {
        $configured = json_decode((string) ($task['urgent_alert_recipients_json'] ?? '[]'), true);
        $configured = is_array($configured) ? $configured : [];
        $recipientIds = task_scheduled_popup_recipient_ids($configured, (int) ($task['assigned_employee_id'] ?? 0));
        if (!$recipientIds) throw new RuntimeException('No eligible popup recipients were found at release.');
        $notificationId = notifications_create([
            'title' => (string) ($task['task_name'] ?? 'Task assigned'),
            'message' => 'Urgent task assigned.', 'module' => 'tasks', 'priority' => 'urgent', 'sound_key' => 'urgent',
            'related_type' => 'checklist_task', 'related_id' => $taskId,
            'action_link' => BASE_URL . '/apps/operations/checklists.php?task_view=active&task_id=' . $taskId,
        ], $recipientIds);
        if (!$notificationId) throw new RuntimeException('Popup notification creation returned no notification ID.');
        db()->prepare('UPDATE ops_checklist_tasks SET urgent_alert_sent_at = NOW(), urgent_alert_claimed_at = NULL, urgent_alert_last_error = NULL WHERE id = ?')->execute([$taskId]);
        if (function_exists('ops_activity_log')) ops_activity_log('task_urgent_alert_sent', 'checklist_task', $taskId, [
            'notification_id' => $notificationId, 'recipient_ids' => $recipientIds, 'delivery' => 'scheduled_release',
        ]);
        return true;
    } catch (Throwable $error) {
        db()->prepare('UPDATE ops_checklist_tasks SET urgent_alert_claimed_at = NULL, urgent_alert_last_error = ? WHERE id = ? AND urgent_alert_sent_at IS NULL')->execute([substr($error->getMessage(), 0, 500), $taskId]);
        if (function_exists('ops_activity_log')) ops_activity_log('task_urgent_alert_failed', 'checklist_task', $taskId, ['error' => $error->getMessage()]);
        error_log('Scheduled task popup failed for task ' . $taskId . ': ' . $error->getMessage());
        return false;
    }
}

function task_release_due_scheduled_tasks(): int
{
    if (!function_exists('ops_table_exists') || !ops_table_exists('ops_checklist_tasks')
        || !ops_column_exists('ops_checklist_tasks', 'scheduled_at')
        || !ops_column_exists('ops_checklist_tasks', 'released_at')) return 0;

    $now = (new DateTimeImmutable('now', new DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');
    $hasPopupConfig = ops_column_exists('ops_checklist_tasks', 'urgent_alert_recipients_json')
        && ops_column_exists('ops_checklist_tasks', 'urgent_alert_claimed_at')
        && ops_column_exists('ops_checklist_tasks', 'urgent_alert_last_error');
    $popupColumns = $hasPopupConfig ? ', urgent_alert_enabled, urgent_alert_recipients_json, urgent_alert_sent_at' : '';
    $floatingColumns = ops_column_exists('ops_checklist_tasks', 'assignment_type') ? ', assignment_type, floating_eligible_role, floating_allocation_status' : '';
    $rows = ops_rows(
        "SELECT id, assigned_employee_id, task_name, scheduled_at{$floatingColumns}{$popupColumns} FROM ops_checklist_tasks
         WHERE scheduled_at IS NOT NULL AND scheduled_at <= ? AND released_at IS NULL
           AND status NOT IN ('complete','completed','done','archived','deleted','trashed')
           AND archived_at IS NULL AND deleted_at IS NULL ORDER BY scheduled_at, id LIMIT 100",
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
                   AND status NOT IN ('complete','completed','done','archived','deleted','trashed')"
            );
            $stmt->execute([$now, $hasAssignee ? 1 : 0, $now, $hasAssignee ? 1 : 0, (int) $row['id'], $now]);
            if ($stmt->rowCount() !== 1) { $pdo->rollBack(); continue; }
            if ($hasAssignee
                && !notifications_notify_task_assigned((int) $row['id'], (int) $row['assigned_employee_id'], (string) $row['task_name'])) {
                throw new RuntimeException('The scheduled task notification could not be saved.');
            }
            if (function_exists('ops_activity_log')) ops_activity_log('task_released', 'checklist_task', (int) $row['id'], [
                'scheduled_at' => (string) $row['scheduled_at'], 'released_at' => $now, 'assigned_employee_id' => $hasAssignee ? (int) $row['assigned_employee_id'] : null,
                'assignment_type' => $isFloating ? 'floating' : 'specific',
            ]);
            $pdo->commit();
            if ($hasPopupConfig) task_deliver_configured_popup($row);
            $released++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Scheduled task release failed for task ' . (int) $row['id'] . ': ' . $e->getMessage());
        }
    }
    if ($hasPopupConfig) db()->exec("UPDATE ops_checklist_tasks SET urgent_alert_claimed_at = NULL WHERE urgent_alert_sent_at IS NULL AND urgent_alert_claimed_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $pendingPopups = $hasPopupConfig ? ops_rows(
        "SELECT id, assigned_employee_id, task_name, urgent_alert_enabled, urgent_alert_recipients_json, urgent_alert_sent_at
         FROM ops_checklist_tasks
         WHERE released_at IS NOT NULL AND urgent_alert_enabled = 1 AND urgent_alert_sent_at IS NULL
           AND status NOT IN ('complete','completed','done','archived','deleted','trashed')
           AND urgent_alert_claimed_at IS NULL AND archived_at IS NULL AND deleted_at IS NULL
         ORDER BY released_at, id LIMIT 100"
    ) : [];
    foreach ($pendingPopups as $pendingPopup) task_deliver_configured_popup($pendingPopup);
    return $released;
}
