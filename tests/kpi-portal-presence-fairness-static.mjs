import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (file) => fs.readFileSync(new URL(`../${file}`, import.meta.url), 'utf8');
const data = read('apps/operations/kpi-employee-data.php');
const reporting = read('apps/operations/kpi-reporting.php');
const presence = read('apps/operations/portal-presence.php');
const login = read('login.php');
const review = read('apps/operations/kpi-presence-review.php');
const ui = read('assets/js/kpi-employee.js');

assert.match(presence, /session_expired_at/);
assert.match(presence, /inactive_expiry/);
assert.match(login, /explicit_logout_at/);
assert.match(login, /explicit_logout/);
assert.match(reporting, /active_periods/);
assert.match(reporting, /inactive_gaps/);
assert.match(review, /owner_note/);
assert.match(review, /kpi_portal_presence_reviewed/);
assert.match(data, /requires_owner_review/);
assert.match(data, /Missing evidence is not scored as zero/);
assert.match(reporting, /'attendance'=>10/);
assert.match(data, /'weight'=>\$weights\['attendance'\]/);
assert.doesNotMatch(data, /portal_active_hours[^\n]{0,100}'result'/);
assert.match(ui, /not physical attendance or hours worked/);
assert.match(ui, /data-save-presence-review/);

console.log('KPI portal-presence fairness static checks passed.');
