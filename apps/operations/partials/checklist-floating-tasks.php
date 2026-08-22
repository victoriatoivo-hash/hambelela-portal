<?php
$floatingSummary = ['active'=>0,'waiting'=>0,'in_progress'=>0,'completed_month'=>0,'overdue'=>0];
foreach ($tasks as $floatingTask) {
    $floatingStatus = checklist_normalize_status((string) ($floatingTask['status'] ?? 'new'));
    $allocationStatus = (string) ($floatingTask['floating_allocation_status'] ?? 'pending');
    if ($allocationStatus === 'waiting_eligible_employee') $floatingSummary['waiting']++;
    if ($allocationStatus === 'allocated' && $floatingStatus !== 'complete') $floatingSummary['active']++;
    if ($floatingStatus === 'in_progress') $floatingSummary['in_progress']++;
    $completedAt = (string) (($floatingTask['date_completed'] ?? '') ?: ($floatingTask['completed_at'] ?? ''));
    if ($floatingStatus === 'complete' && substr($completedAt, 0, 7) === date('Y-m')) $floatingSummary['completed_month']++;
    if ($floatingStatus !== 'complete' && !empty($floatingTask['deadline']) && strtotime((string) $floatingTask['deadline']) < time()) $floatingSummary['overdue']++;
}
?>
<section class="task-floating-view" data-floating-task-view data-csrf-token="<?= htmlspecialchars($taskAttachmentCsrf, ENT_QUOTES, 'UTF-8') ?>">
  <header class="task-floating-view__header"><div><span>Owner allocation monitor</span><h2>Floating Tasks</h2><p>Role-based tasks allocated fairly by current open workload and least-recent rotation.</p></div></header>
  <div class="task-floating-summary">
    <?php foreach (['active'=>'Active allocated','waiting'=>'Waiting','in_progress'=>'In Progress','completed_month'=>'Completed this month','overdue'=>'Overdue'] as $key=>$label): ?>
      <article><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><strong data-floating-summary="<?= $key ?>"><?= number_format($floatingSummary[$key]) ?></strong></article>
    <?php endforeach; ?>
  </div>
  <div class="task-floating-table-wrap">
    <table class="task-floating-table">
      <thead><tr><th>Task</th><th>Eligible team</th><th>Allocated employee</th><th>Allocation</th><th>Release / allocated</th><th>Due</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($tasks as $floatingTask): $allocationStatus=(string)($floatingTask['floating_allocation_status']??'pending'); ?>
        <tr data-floating-task-row="<?= (int)$floatingTask['id'] ?>">
          <td><button type="button" class="task-floating-open" data-task-open="<?= (int)$floatingTask['id'] ?>"><?= htmlspecialchars((string)$floatingTask['task_name'], ENT_QUOTES, 'UTF-8') ?></button><small>#<?= (int)$floatingTask['id'] ?></small></td>
          <td><?= htmlspecialchars(task_floating_role_label((string)($floatingTask['floating_eligible_role']??'')), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)($floatingTask['assigned_name']??'Waiting for eligible employee'), ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="task-floating-state task-floating-state--<?= htmlspecialchars(str_replace('_','-',$allocationStatus), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$allocationStatus)), ENT_QUOTES, 'UTF-8') ?></span><small><?= htmlspecialchars((string)($floatingTask['allocation_method']??'Automatic'), ENT_QUOTES, 'UTF-8') ?></small></td>
          <td><?= htmlspecialchars(checklist_date_label((string)(($floatingTask['allocated_at']??'') ?: ($floatingTask['scheduled_at']??'') ?: ($floatingTask['created_at']??''))), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars(checklist_date_label((string)($floatingTask['deadline']??'')), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($statuses[checklist_normalize_status((string)($floatingTask['status']??'new'))]??'New', ENT_QUOTES, 'UTF-8') ?></td>
          <td><?php if ($allocationStatus === 'waiting_eligible_employee'): ?><button type="button" class="task-floating-retry" data-floating-retry="<?= (int)$floatingTask['id'] ?>">Retry allocation</button><?php else: ?><span class="task-floating-no-action">—</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tasks): ?><tr><td colspan="8" class="task-floating-empty">No Floating Tasks match these filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="task-floating-feedback" data-floating-feedback hidden role="status"></p>
</section>
