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
    $rows = ops_rows(
        "SELECT id, assigned_employee_id, task_name, scheduled_at FROM ops_checklist_tasks
         WHERE scheduled_at IS NOT NULL AND scheduled_at <= ? AND released_at IS NULL
           AND archived_at IS NULL AND deleted_at IS NULL ORDER BY scheduled_at, id LIMIT 100",
        [$now]
    );
    $released = 0;
    foreach ($rows as $row) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "UPDATE ops_checklist_tasks SET released_at = ?, date_assigned = ?, employee_visible = 1
                 WHERE id = ? AND released_at IS NULL AND scheduled_at IS NOT NULL AND scheduled_at <= ?"
            );
            $stmt->execute([$now, $now, (int) $row['id'], $now]);
            if ($stmt->rowCount() !== 1) { $pdo->rollBack(); continue; }
            if ((int) ($row['assigned_employee_id'] ?? 0) > 0
                && !notifications_notify_task_assigned((int) $row['id'], (int) $row['assigned_employee_id'], (string) $row['task_name'])) {
                throw new RuntimeException('The scheduled task notification could not be saved.');
            }
            if (function_exists('ops_activity_log')) ops_activity_log('task_released', 'checklist_task', (int) $row['id'], [
                'scheduled_at' => (string) $row['scheduled_at'], 'released_at' => $now, 'assigned_employee_id' => (int) $row['assigned_employee_id'],
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
