<?php
/** @var array $displayTasks */
/** @var bool $canManage */
/** @var array $priorities */
/** @var array $groups */
/** @var array $statuses */
$emptyTaskMessage = $emptyTaskMessage ?? ($canManage ? 'No tasks match this view and its filters.' : 'No tasks are currently assigned to you.');
?>
<section class="task-board" data-task-board data-task-kind="<?= htmlspecialchars((string) ($displayTaskKind ?? 'all'), ENT_QUOTES, 'UTF-8') ?>">
    <div class="dtb-table-wrap">
    <table class="dtb-board-table task-board-table">
        <colgroup><col class="dtb-col-select"><col class="dtb-col-name"><col class="dtb-col-actions"><col class="dtb-col-assigned"><col class="dtb-col-priority"><col class="dtb-col-due"><col class="dtb-col-days"><col class="dtb-col-status"><col class="dtb-col-progress"><col class="dtb-col-completed"><col class="dtb-col-notes"></colgroup>
        <thead><tr><th class="dtb-select-cell"><input class="dtb-task-check dtb-task-check-all" type="checkbox" aria-label="Select all visible tasks"></th><th>Task</th><th>Details</th><th>Assigned</th><th>Priority</th><th>Due</th><th>When Due</th><th>Status</th><th>Progress</th><th>Completed</th><th>Notes</th></tr></thead>
        <tbody>
            <?php foreach ($displayTasks as $task): ?>
                <?php
                $effective = checklist_effective_status($task);
                $priorityKey = (string) ($task['priority'] ?? 'medium');
                $statusKey = str_replace('_', '-', $effective);
                $savedStatus = checklist_normalize_status((string) ($task['status'] ?? 'new'));
                $rowItems = checklist_json_items((string) ($task['checklist_items'] ?? ''));
                $rowChecked = checklist_json_items((string) ($task['checked_items'] ?? ''));
                $progress = $savedStatus === 'complete' ? 100 : ($savedStatus === 'new' ? 0 : ($rowItems ? (int) round(count($rowChecked) / max(1, count($rowItems)) * 100) : 0));
                $dueState = checklist_due_state((string) ($task['deadline'] ?? ''), $savedStatus);
                $taskId = (int) $task['id'];
                ?>
                <tr class="dtb-task-row task-grid-row" data-task-row data-task-id="<?= $taskId ?>" data-saved-status="<?= htmlspecialchars($savedStatus, ENT_QUOTES, 'UTF-8') ?>" data-display-status="<?= htmlspecialchars($effective, ENT_QUOTES, 'UTF-8') ?>" data-task-name="<?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?>" data-task-assigned="<?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?>" data-task-priority="<?= htmlspecialchars($priorities[$priorityKey] ?? 'Medium', ENT_QUOTES, 'UTF-8') ?>" data-task-status="<?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?>">
                    <td class="dtb-select-cell"><input class="dtb-task-check" type="checkbox" value="<?= $taskId ?>" aria-label="Select <?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?>"></td>
                    <td><button type="button" class="task-name-trigger" data-task-open="<?= $taskId ?>"><?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?></button></td>
                    <td><div class="task-row-actions"><button class="task-detail-icon" type="button" data-task-open="<?= $taskId ?>" aria-label="Open task details"><i data-lucide="panel-right-open"></i></button></div></td>
                    <td><?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="task-priority-cell"><div class="task-priority-fill" data-priority="<?= htmlspecialchars(str_replace('_', '-', $priorityKey), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($priorities[$priorityKey] ?? 'Medium', ENT_QUOTES, 'UTF-8') ?></div></td>
                    <td><?= checklist_date_label((string) ($task['deadline'] ?? '')) ?></td>
                    <td class="task-table__due-cell"><?php if ($dueState): ?><span class="task-due-state task-due-state--<?= htmlspecialchars(str_replace('_', '-', $dueState['value']), ENT_QUOTES, 'UTF-8') ?>" data-task-due-state data-task-due-at="<?= htmlspecialchars($dueState['iso'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($dueState['title'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dueState['label'], ENT_QUOTES, 'UTF-8') ?></span><?php elseif ($savedStatus !== 'complete'): ?><span class="task-due-state task-due-state--missing" data-task-due-state>Set due date</span><?php else: ?>—<?php endif; ?></td>
                    <td class="task-status-cell"><button type="button" class="task-status-trigger" data-task-status-trigger data-status="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" aria-haspopup="menu" aria-expanded="false"><?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?></button></td>
                    <td><span class="task-progress-value"><?= $progress ?>%</span></td>
                    <td data-task-completed><?= htmlspecialchars(checklist_date_label((string) ($task['date_completed'] ?: $task['completed_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="task-notes-preview"><?= htmlspecialchars((string) ($task['completion_note'] ?: $task['notes'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$displayTasks): ?><tr class="dtb-empty-row"><td colspan="11"><?= htmlspecialchars($emptyTaskMessage, ENT_QUOTES, 'UTF-8') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
