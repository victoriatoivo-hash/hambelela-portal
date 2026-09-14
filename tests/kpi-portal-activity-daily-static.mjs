import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const employee = read('apps/operations/kpi-employee-data.php');
const reporting = read('apps/operations/kpi-reporting.php');
const presence = read('apps/operations/portal-presence.php');
const ui = read('assets/js/kpi-employee.js');

assert.match(employee, /action.*presence_timeline/, 'daily evidence endpoint must exist');
assert.match(employee, /Schedule not configured/, 'missing schedules must not become rest days');
assert.match(employee, /kpi_unified_events\(\$fromSql,\$toSql,\$employeeId\)/, 'daily workflow counts must use the shared audit source');
assert.match(employee, /Historical evidence unavailable|historical_evidence_available/, 'missing history must be explicit');
assert.match(employee, /INTERVAL 120 SECOND/, 'online status must use the shared 120-second timeout');
assert.match(employee, /portal_activity_window_seconds/, 'productive active time must use a configurable evidence window');
assert.match(employee, /authenticatedHours-\$portalActiveHours/, 'inactive and productive time must remain separate');
assert.match(reporting, /authenticated_session_hours/, 'session presence must remain separately available');
assert.match(reporting, /session_end_reason/, 'logout and expiry evidence must be retained');
assert.match(presence, /INTERVAL 120 SECOND/, 'live presence and attendance must share the timeout');
assert.match(presence, /session_duration_seconds/, 'live popup must use authoritative session duration');
assert.match(ui, /data-presence-timeline-date/, 'every daily row must expose its evidence');
assert.match(ui, /data-presence-filter/, 'daily evidence must have compact filters');
assert.match(ui, /No portal activity is not proof of physical absence/, 'timeline wording must remain factual');

console.log('KPI daily Portal Activity static checks passed.');
