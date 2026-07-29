import assert from 'node:assert/strict';
import fs from 'node:fs';

const employee = fs.readFileSync(new URL('../apps/hr-portal/my-leave.php', import.meta.url), 'utf8');
const admin = fs.readFileSync(new URL('../apps/hr-portal/leave.php', import.meta.url), 'utf8');
const controller = fs.readFileSync(new URL('../apps/hr-portal/includes/leave-reason-popover.js', import.meta.url), 'utf8');
const styles = fs.readFileSync(new URL('../apps/hr-portal/includes/styles.css', import.meta.url), 'utf8');

for (const page of [employee, admin]) {
  assert.match(page, /data-leave-history/);
  assert.match(page, /data-leave-reason-trigger/);
  assert.match(page, />View reason</);
  assert.match(page, /htmlspecialchars\(\$r\['reject_reason'\], ENT_QUOTES, 'UTF-8'\)/);
  assert.match(page, /u\.name AS reviewer_name/);
  assert.doesNotMatch(page, /font-size:10px;color:var\(--red\);margin-top:2px/);
}

assert.match(employee, /WHERE lr\.employee_id=\?/);
assert.match(admin, /requireAdmin\(\)/);
assert.match(controller, /document\.body\.appendChild\(popover\)/);
assert.match(controller, /\.textContent = trigger\.dataset\.reason/);
assert.match(controller, /event\.key === 'Escape'/);
assert.match(controller, /window\.addEventListener\('scroll', positionPopover, true\)/);
assert.match(controller, /documentElement\.dataset\.leaveReasonPopoversInitialised/);
assert.match(styles, /\.leave-reason-popover\{position:fixed/);
assert.match(styles, /white-space:pre-wrap/);
assert.match(styles, /@media \(max-width:479px\)/);

console.log('HR leave rejection reason popover checks passed.');
