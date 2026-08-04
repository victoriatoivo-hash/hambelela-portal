import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const report = read('apps/operations/historical-order-attribution-report.php');
const reportsPage = read('apps/operations/reports.php');
const taskAttachment = read('apps/operations/task-attachment.php');

assert.match(report, /if\(!user_has_role\('owner_admin'\)\)\{http_response_code\(403\)/, 'report and in-file exports must reject every non-owner role before loading data');
assert.doesNotMatch(report, /user_has_role\('owner_admin','front_desk_admin'/, 'front desk roles must not access the owner report');
assert.match(reportsPage, /if \(user_has_role\('owner_admin'\)\).*historical-order-attribution-report\.php/s, 'report link must be owner-only');
assert.match(report, /full_csv/);
assert.match(report, /staff_csv/);
assert.match(report, /Possible packer \(unconfirmed\)/);
assert.match(report, /Returning this copy does not change attribution until the owner approves it/);
for (const forbidden of ['ops_checklist_tasks', 'task_attachment_upload', 'notifications_notify', 'mail(']) {
  assert.ok(!report.includes(forbidden), `report must not automatically distribute through ${forbidden}`);
}
assert.match(taskAttachment, /ops_task_scope_for_current_user\(\)/, 'task-file downloads must use the authenticated task scope');
assert.match(taskAttachment, /AND t\.assigned_employee_id = \?/, 'regular employees may access only attachments for their assigned tasks');
assert.match(taskAttachment, /Attachment not found\./, 'unauthorized attachment requests must not disclose file details');

console.log('Owner-only attribution report and secure task attachment checks passed.');
