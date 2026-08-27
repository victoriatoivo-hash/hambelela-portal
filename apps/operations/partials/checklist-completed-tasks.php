<?php
declare(strict_types=1);

$completedControlQuery = static function (array $changes) use ($filters, $selectedCompletedEmployeeId): string {
    $query = [
        'task_view' => 'completed',
        'completed_year' => $filters['completed_year'],
        'completed_month' => $filters['completed_month'],
        'completed_employee_id' => $selectedCompletedEmployeeId,
    ];
    foreach (['date_from', 'date_to', 'status', 'priority', 'checklist_type', 'task_kind', 'search'] as $filterKey) {
        if ($filters[$filterKey] !== '') $query[$filterKey] = $filters[$filterKey];
    }
    foreach ($changes as $key => $value) $query[$key] = $value;
    return 'checklists.php?' . http_build_query($query);
};
$completedPanelTasks = $selectedCompletedEmployeeId === 'all' ? $tasks : (array) ($selectedCompletedEmployeeGroup['tasks'] ?? []);
$completedPanelName = $selectedCompletedEmployeeId === 'all' ? 'All Employees' : (string) ($selectedCompletedEmployeeGroup['name'] ?? 'Employee');
$completedPeriodLabel = $filters['completed_month'] !== '' ? ($completedMonthOptions[$filters['completed_month']] ?? $filters['completed_month']) : ($filters['completed_year'] !== '' ? $filters['completed_year'] : 'All time');
$completedYear = (int) ($filters['completed_year'] ?: date('Y'));
?>
<section id="completed-tasks-section" data-completed-task-navigation data-completed-employee-id="<?= htmlspecialchars($selectedCompletedEmployeeId, ENT_QUOTES, 'UTF-8') ?>" data-completed-year="<?= htmlspecialchars($filters['completed_year'], ENT_QUOTES, 'UTF-8') ?>" data-completed-month="<?= htmlspecialchars($filters['completed_month'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Completed tasks by employee">
    <div class="completed-task-controls" data-completed-controls>
        <div class="completed-task-control-row"><span>Employee</span><nav class="completed-employee-nav" aria-label="Completed task employees"><a class="completed-employee-nav__item<?= $selectedCompletedEmployeeId === 'all' ? ' is-active' : '' ?>" <?= $selectedCompletedEmployeeId === 'all' ? 'aria-current="page"' : '' ?> href="<?= htmlspecialchars($completedControlQuery(['completed_employee_id' => 'all']), ENT_QUOTES, 'UTF-8') ?>"><span>All Employees</span></a><?php foreach ($completedEmployeeGroups as $completedGroup): ?><?php if (empty($completedGroup['id'])) continue; $completedEmployeeKey = (string) (int) $completedGroup['id']; ?><a class="completed-employee-nav__item<?= $selectedCompletedEmployeeId === $completedEmployeeKey ? ' is-active' : '' ?>" <?= $selectedCompletedEmployeeId === $completedEmployeeKey ? 'aria-current="page"' : '' ?> href="<?= htmlspecialchars($completedControlQuery(['completed_employee_id' => $completedEmployeeKey]), ENT_QUOTES, 'UTF-8') ?>"><span><?= htmlspecialchars((string) $completedGroup['name'], ENT_QUOTES, 'UTF-8') ?></span></a><?php endforeach; ?></nav></div>
        <div class="completed-task-control-row"><span>Year</span><nav class="completed-year-nav" aria-label="Completed task year"><a href="<?= htmlspecialchars($completedControlQuery(['completed_year' => (string) ($completedYear - 1), 'completed_month' => '']), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous year">‹</a><strong><?= $completedYear ?></strong><a href="<?= htmlspecialchars($completedControlQuery(['completed_year' => (string) ($completedYear + 1), 'completed_month' => '']), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next year">›</a></nav></div>
        <div class="completed-task-control-row"><span>Month</span><nav class="completed-month-nav" aria-label="Completed task month"><a class="<?= $filters['completed_month'] === '' ? 'is-active' : '' ?>" <?= $filters['completed_month'] === '' ? 'aria-current="page"' : '' ?> href="<?= htmlspecialchars($completedControlQuery(['completed_month' => '']), ENT_QUOTES, 'UTF-8') ?>">All</a><?php for ($month = 1; $month <= 12; $month++): ?><?php $monthKey = sprintf('%04d-%02d', $completedYear, $month); ?><a class="<?= $filters['completed_month'] === $monthKey ? ' is-active' : '' ?>" <?= $filters['completed_month'] === $monthKey ? 'aria-current="page"' : '' ?> href="<?= htmlspecialchars($completedControlQuery(['completed_month' => $monthKey]), ENT_QUOTES, 'UTF-8') ?>"><?= date('M', mktime(0, 0, 0, $month, 1)) ?></a><?php endfor; ?></nav></div>
    </div>
    <p class="completed-tasks-update-status" data-completed-update-status role="status" aria-live="polite" hidden></p>
    <section class="completed-employee-panel" data-completed-results>
        <header class="completed-employee-panel__header"><div><p>Completed Tasks</p><h2><?= htmlspecialchars($completedPanelName, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($completedPeriodLabel, ENT_QUOTES, 'UTF-8') ?></h2></div><b><?= number_format(count($completedPanelTasks)) ?> completed task<?= count($completedPanelTasks) === 1 ? '' : 's' ?></b></header>
        <div class="completed-employee-table-wrap"><?php $displayTasks = $completedPanelTasks; $displayTaskKind = $selectedCompletedEmployeeId === 'all' ? 'completed-all' : 'completed'; $hideAssignedColumn = $selectedCompletedEmployeeId !== 'all'; $emptyTaskMessage = 'No completed tasks for this employee and period.'; include __DIR__ . '/checklist-task-table.php'; unset($hideAssignedColumn); ?></div>
    </section>
</section>
