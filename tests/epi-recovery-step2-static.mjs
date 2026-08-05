import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(new URL(`../${p}`, import.meta.url), 'utf8');
const migration = read('operations-epi-quality-migration.sql');
const bridge = read('shared/epi/QualityActivityBridge.php');
const notifications = read('shared/epi/NotificationActivityBridge.php');
const general = read('shared/epi/GeneralActivityBridge.php');
const packing = read('shared/epi/PackingActivityBridge.php');
const notificationHooks = read('shared/notifications.php');
const api = read('apps/operations/epi-quality-performance-data.php');
const page = read('apps/operations/epi-quality-performance.php');
const operations = read('apps/operations/operations.php');

for (const table of ['epi_quality_categories','epi_quality_severities','epi_quality_error_profiles','epi_quality_status_history','epi_quality_owner_reviews','epi_quality_responsibility_allocations','epi_quality_financial_impacts','epi_quality_corrective_actions','epi_quality_record_links','epi_quality_root_causes','epi_quality_repeat_reviews','epi_quality_exceptions']) {
  assert.match(migration, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
}
assert.match(bridge, /logged_by_employee_id/);
assert.match(bridge, /responsible_employee_id/);
assert.match(bridge, /quality-evidence/);
assert.match(bridge, /Performance::mode\(\)===FeatureFlags::MODE_TEST/);
assert.match(operations, /QualityActivityBridge::record/);
assert.match(notificationHooks, /NotificationActivityBridge::record\(db\(\), 'notification_created'/);
assert.match(notificationHooks, /notification_marked_read/);
assert.match(notificationHooks, /notification_dismissed/);
assert.match(notifications, /if \(\$actionable\) Performance::recordEvidence/);
assert.match(general, /moduleEntities/);
assert.match(general, /background|polling|heartbeat|page_view/);
assert.match(operations, /GeneralActivityBridge::record/);
assert.match(packing, /Front Desk Live Website Inventory Update/);
assert.match(packing, /Packer Website Update Confirmation/);
assert.match(api, /require_role\('owner_admin'\)/);
assert.match(api, /Employees cannot approve an error recorded against themselves/);
assert.match(api, /total exactly 100%/);
assert.match(api, /epi_quality_decimal/);
assert.match(api, /corrective_action/);
assert.match(api, /repeat_review/);
assert.doesNotMatch(api, /employee_monthly_scores|score_impact\s*=|deduction_points/);
assert.match(page, /Quality verification/);
assert.match(page, /View Evidence/);
assert.match(page, /Activity timeline/);
console.log('EPI Recovery Step 2 static verification passed.');
