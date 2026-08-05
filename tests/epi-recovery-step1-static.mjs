import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const notifications = read('notifications.php');
const flags = read('shared/epi/FeatureFlags.php');
const evidence = read('shared/epi/EvidenceEngine.php');
const activity = read('shared/epi/ActivityEngine.php');
const ownership = read('shared/epi/OwnershipEngine.php');
const verifier = read('shared/epi/RecoveryVerifier.php');
const settings = read('apps/operations/my-account.php');
const scoring = read('shared/epi/PerformanceScore.php');
const header = read('shared/header.php');
const sidebar = read('shared/sidebar.php');

assert.match(notifications, /class="workspace module notifications-page"/);
assert.match(notifications, /apps\/operations\/operations\.php/);
assert.match(flags, /MODE_DISABLED = 'disabled'/);
assert.match(flags, /MODE_TEST = 'test'/);
assert.match(flags, /MODE_ENABLED = 'enabled'/);
assert.match(flags, /Production EPI cannot be enabled during Recovery Step 1/);
assert.match(flags, /previous_mode.*new_mode.*changed_by.*reason/s);
for (const engine of [evidence, activity, ownership]) {
  assert.match(engine, /allowsRecording/);
}
assert.match(verifier, /'Orders', 'Packing List', 'Tasks', 'Courier', 'Bookkeeping', 'Attendance'/);
assert.match(verifier, /'test_data' => true/);
assert.match(verifier, /'excluded_from_scoring' => true/);
assert.match(verifier, /deduplicated/);
assert.match(settings, /Recording Test Mode/);
assert.match(settings, /Enabled — reserved for later approval/);
assert.match(settings, /Only Owner\/Admin can manage EPI recovery settings/);
assert.match(scoring, /recording_mode<>'test'/);
assert.match(header, /Notification header summary failed/);
assert.match(sidebar, /Notification sidebar summary failed/);

console.log('EPI Recovery Step 1 static checks passed.');
