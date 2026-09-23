import fs from 'node:fs';

const courier = fs.readFileSync('apps/operations/courier.php', 'utf8');
const tasks = fs.readFileSync('apps/operations/checklists.php', 'utf8');

const requiredCourier = [
  "w.sent_at IS NOT NULL",
  "w.sent_at IS NULL AND w.status IN ('pending','overdue')",
  "empty($row['sent_at'])",
  "WHERE batch_id = ? AND sent_at IS NULL AND archived_at IS NULL AND deleted_at IS NULL",
  'function invalidateCourierRefresh()',
  "markButton.innerHTML = '<i data-lucide=\"loader-circle\"></i> Sending...'",
  "markButton.innerHTML = originalMarkup",
];

const requiredTasks = [
  "SELECT assigned_employee_id, status, employee_visible, released_at FROM ops_checklist_tasks WHERE id = ?",
  "(int) ($assignmentRow['assigned_employee_id'] ?? 0) !== (int) $targetEmployeeId",
  'function initialiseEmployeeTaskDelivery',
  "taskRoot.dataset.canManage === '1'",
  "['tasks', 'scheduled', 'floating'].includes(view)",
  'force: true',
  'initialiseEmployeeTaskDelivery();',
];

for (const needle of requiredCourier) {
  if (!courier.includes(needle)) throw new Error(`Courier invariant missing: ${needle}`);
}
for (const needle of requiredTasks) {
  if (!tasks.includes(needle)) throw new Error(`Task-delivery invariant missing: ${needle}`);
}

if (/dataset\.manager/.test(tasks)) throw new Error('Employee refresh still checks the wrong manager data attribute.');
if (courier.includes("WHERE batch_id = ? AND status IN ('pending','overdue') AND sent_at IS NULL")) {
  throw new Error('Mark Sent still depends on the mutable display status instead of sent_at.');
}

console.log('Courier sent-state and employee task-delivery static checks passed.');
