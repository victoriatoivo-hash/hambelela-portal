import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const notifications = read('shared/notifications.php');
const sidebar = read('shared/sidebar.php');
const workbook = read('apps/cost-manager/workbook.php');

assert.doesNotMatch(workbook, /apps\/operations\/operations\.php/);
assert.match(
  notifications,
  /!function_exists\('ops_table_exists'\)[\s\S]{0,100}!ops_table_exists\('ops_packing_tasks'\)/,
  'Packing notification lookup must be safe when a non-Operations app renders the shared sidebar.',
);
assert.match(
  sidebar,
  /\$packingAssignmentUnread = 0;[\s\S]{0,320}catch \(Throwable \$packingBadgeError\)/,
  'A notification badge failure must never stop the shared sidebar and page body from rendering.',
);

console.log('Non-Operations sidebar notification safeguards passed.');
