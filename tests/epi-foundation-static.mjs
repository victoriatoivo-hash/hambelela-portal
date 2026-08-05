import assert from 'node:assert/strict';
import fs from 'node:fs';

const migration = fs.readFileSync('operations-epi-foundation-migration.sql', 'utf8');
const performance = fs.readFileSync('shared/epi/Performance.php', 'utf8');
const evidence = fs.readFileSync('shared/epi/EvidenceEngine.php', 'utf8');
const activity = fs.readFileSync('shared/epi/ActivityEngine.php', 'utf8');
const business = fs.readFileSync('shared/epi/BusinessTimeEngine.php', 'utf8');
const ownership = fs.readFileSync('shared/epi/OwnershipEngine.php', 'utf8');
const engine = fs.readFileSync('shared/epi/PerformanceEngine.php', 'utf8');
const css = fs.readFileSync('assets/css/epi.css', 'utf8');

for (const table of [
  'epi_employee_evidence', 'epi_employee_activity', 'epi_employee_monthly_scores',
  'epi_employee_departments', 'epi_employee_performance_settings', 'epi_employee_grace_periods',
  'epi_employee_business_calendar', 'epi_performance_cache', 'epi_performance_logs',
  'epi_employee_ownership_history',
]) assert.match(migration, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));

assert.match(migration, /\('epi_enabled', '0', 'boolean'/, 'feature flag must default off');
assert.match(evidence, /INSERT IGNORE INTO epi_employee_evidence/, 'evidence must deduplicate');
assert.doesNotMatch(evidence, /DELETE FROM epi_employee_evidence|UPDATE epi_employee_evidence/);
assert.match(activity, /INSERT IGNORE INTO epi_employee_activity/);
assert.doesNotMatch(activity, /DELETE FROM epi_employee_activity|UPDATE epi_employee_activity/);
assert.match(business, /\$day === 7/);
assert.match(business, /saturday_open.*09:00/s);
assert.match(business, /weekday_open.*08:00/s);
assert.match(ownership, /INSERT INTO epi_employee_ownership_history/);
assert.match(engine, /never computes, aggregates or writes employee scores/);
for (const method of ['recordEvidence', 'getEvidence', 'getEmployee', 'getDepartment', 'getTimeline']) {
  assert.match(performance, new RegExp(`function ${method}\\(`));
}
assert.match(css, /--epi-primary: #AB3619/);
assert.match(css, /--epi-transition: \.25s/);

for (const forbidden of ['kpi-employee.php', 'reports.php', 'portal.css']) {
  assert.equal(migration.includes(forbidden), false);
}

console.log('EPI foundation static checks passed.');
