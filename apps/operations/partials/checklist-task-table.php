<?php
/** @var array $displayTasks */
/** @var bool $canManage */
/** @var array $priorities */
/** @var array $groups */
/** @var array $statuses */
$emptyTaskMessage = $emptyTaskMessage ?? ($canManage ? 'No tasks match this view and its filters.' : 'No tasks are currently assigned to you.');
$visibleBatchGroups = [];
if ($canManage) {
    foreach ($displayTasks as $batchTask) {
        $batchId = trim((string) ($batchTask['batch_id'] ?? ''));
        if ($batchId === '') continue;
        if (!isset($visibleBatchGroups[$batchId])) $visibleBatchGroups[$batchId] = [
            'name' => (string) $batchTask['task_name'], 'size' => (int) ($batchTask['batch_size'] ?? 0),
            'complete' => 0, 'employees' => [], 'scheduled_at' => (string) ($batchTask['scheduled_at'] ?? ''),
        ];
        if (checklist_normalize_status((string) ($batchTask['status'] ?? 'new')) === 'complete') $visibleBatchGroups[$batchId]['complete']++;
        $visibleBatchGroups[$batchId]['employees'][] = [
            'name' => (string) ($batchTask['assigned_name'] ?? 'Unassigned'),
            'status' => $groups[checklist_effective_status($batchTask)] ?? ($statuses[checklist_effective_status($batchTask)] ?? checklist_effective_status($batchTask)),
            'task_id' => (int) $batchTask['id'],
        ];
    }
}
?>
<?php if ($visibleBatchGroups): ?><div class="task-batch-groups" aria-label="All Employees task groups">
<?php foreach ($visibleBatchGroups as $batchGroup): ?><details class="task-batch-group"><summary><span><strong><?= htmlspecialchars($batchGroup['name'], ENT_QUOTES, 'UTF-8') ?></strong><small>Assigned to: All Employees · <?= count($batchGroup['employees']) ?> employee tasks<?= $batchGroup['scheduled_at'] ? ' · scheduled' : '' ?></small></span><b><?= (int) $batchGroup['complete'] ?> Complete · <?= max(0, count($batchGroup['employees']) - (int) $batchGroup['complete']) ?> Outstanding</b></summary><div class="task-batch-group__employees"><?php foreach ($batchGroup['employees'] as $batchEmployee): ?><button type="button" data-task-open="<?= (int) $batchEmployee['task_id'] ?>"><span><?= htmlspecialchars($batchEmployee['name'], ENT_QUOTES, 'UTF-8') ?></span><b><?= htmlspecialchars($batchEmployee['status'], ENT_QUOTES, 'UTF-8') ?></b></button><?php endforeach; ?></div></details>
<?php endforeach; ?></div><?php endif; ?>
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
                $timing = checklist_task_timing($task);
                $progress = (int) $timing['progress'];
                $whenDueOutcome = $savedStatus === 'complete' ? $timing['outcome'] : $timing['active_outcome'];
                $dueState = ['value'=>$timing['overdue']?'overdue':($savedStatus==='complete'?'complete':'upcoming'),'iso'=>'','title'=>$timing['due_label'].' — '.$whenDueOutcome,'label'=>$whenDueOutcome];
                $taskId = (int) $task['id'];
                ?>
                <tr class="dtb-task-row task-grid-row" data-task-row data-task-id="<?= $taskId ?>" data-deadline-state="<?= htmlspecialchars((string) ($dueState['value'] ?? 'normal'), ENT_QUOTES, 'UTF-8') ?>" data-saved-status="<?= htmlspecialchars($savedStatus, ENT_QUOTES, 'UTF-8') ?>" data-display-status="<?= htmlspecialchars($effective, ENT_QUOTES, 'UTF-8') ?>" data-task-name="<?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?>" data-task-assigned="<?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?>" data-task-priority="<?= htmlspecialchars($priorities[$priorityKey] ?? 'Medium', ENT_QUOTES, 'UTF-8') ?>" data-task-status="<?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?>">
                    <td class="dtb-select-cell"><input class="dtb-task-check" type="checkbox" value="<?= $taskId ?>" aria-label="Select <?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?>"></td>
                    <td><button type="button" class="task-name-trigger" data-task-open="<?= $taskId ?>"><?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?></button><?php if (!empty($task['scheduled_at']) && empty($task['released_at'])): ?><small class="task-scheduled-release">Scheduled · releases <?= htmlspecialchars(checklist_schedule_date_label((string) $task['scheduled_at']), ENT_QUOTES, 'UTF-8') ?> · hidden from employee</small><?php endif; ?></td>
                    <td><div class="task-row-actions"><button class="task-detail-icon" type="button" data-task-open="<?= $taskId ?>" aria-label="Open task details"><i data-lucide="panel-right-open"></i></button></div></td>
                    <td><?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="task-priority-cell"><div class="task-priority-fill" data-priority="<?= htmlspecialchars(str_replace('_', '-', $priorityKey), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($priorities[$priorityKey] ?? 'Medium', ENT_QUOTES, 'UTF-8') ?></div></td>
                    <td><?= htmlspecialchars($timing['due_label'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="task-table__due-cell"><?php if ($dueState): ?><span class="task-due-state task-due-state--<?= htmlspecialchars(str_replace('_', '-', $dueState['value']), ENT_QUOTES, 'UTF-8') ?>" data-task-due-state data-task-due-at="<?= htmlspecialchars($dueState['iso'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($dueState['title'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dueState['label'], ENT_QUOTES, 'UTF-8') ?></span><?php elseif ($savedStatus !== 'complete'): ?><span class="task-due-state task-due-state--missing" data-task-due-state>Set due date</span><?php else: ?>—<?php endif; ?></td>
                    <td class="task-status-cell"><button type="button" class="task-status-trigger" data-task-status-trigger data-status="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" aria-haspopup="menu" aria-expanded="false"><?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?></button></td>
                    <td class="task-progress-cell"><div class="task-progress-track<?= $timing['overdue']?' is-overdue':'' ?><?= $savedStatus==='complete'?' is-complete':'' ?>" data-task-progress-track role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progress ?>" title="Percentage of the available working time used since this task was started."><div class="task-progress-fill" data-task-progress-fill style="width:<?= $progress ?>%"></div><span class="task-progress-value" data-task-progress-value><?= $progress ?>%</span></div></td>
                    <td data-task-completed><?= ($task['date_completed'] ?: $task['completed_at']) ? htmlspecialchars(checklist_date_label((string) ($task['date_completed'] ?: $task['completed_at'])), ENT_QUOTES, 'UTF-8') : ($savedStatus === 'complete' ? 'Completion time unavailable' : '-') ?></td>
                    <td><span class="task-notes-preview"><?= htmlspecialchars((string) ($task['completion_note'] ?: $task['notes'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$displayTasks): ?><tr class="dtb-empty-row"><td colspan="11"><?= htmlspecialchars($emptyTaskMessage, ENT_QUOTES, 'UTF-8') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
