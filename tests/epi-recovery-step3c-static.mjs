import assert from 'node:assert/strict';
import fs from 'node:fs';

const recovery=fs.readFileSync(new URL('../shared/epi/HistoricalEvidenceRecovery.php',import.meta.url),'utf8');
const source=fs.readFileSync(new URL('../shared/epi/SourceCompletenessEngine.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../operations-epi-recovery-step3c-migration.sql',import.meta.url),'utf8');
const flags=fs.readFileSync(new URL('../shared/epi/FeatureFlags.php',import.meta.url),'utf8');

for(const table of ['epi_historical_recovery_runs','epi_historical_recovery_issues','epi_historical_source_audits'])assert.match(migration,new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
for(const employee of ["2 => 'Secilia Shiweda'","6 => 'Ndinelao Kalola'","7 => 'Klaudia Averinus'"])assert.match(recovery,new RegExp(employee.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')));
assert.doesNotMatch(recovery,/10\s*=>|Victoria Toivo/);
for(const legacy of ['ops_activity_logs','kpi_status_events','ops_order_stage_events','kpi_sessions','hambelela_cashbook_log','hambelela_waybill_sla_log','ops_packing_tasks','ops_error_logs'])assert.match(recovery,new RegExp(legacy));
for(const metadata of ['historical_backfill','legacy_source','legacy_id','original_timestamp','employee_attribution_source','backfilled_at','evidence_confidence'])assert.match(recovery,new RegExp(metadata));
assert.match(recovery,/INSERT IGNORE INTO epi_employee_evidence/);
assert.doesNotMatch(recovery,/UPDATE ops_orders|UPDATE ops_packing_tasks|UPDATE ops_checklist_tasks|DELETE FROM ops_/i);
assert.match(recovery,/beginTransaction/);
assert.match(recovery,/rollBack/);
assert.match(recovery,/Support::uuidFromHash/);
assert.match(recovery,/historical_backfill','legacy:/);
assert.match(recovery,/Session history begins partway through July/);
assert.match(source,/EPI Step 3C Version 1\.1/);
assert.match(source,/historicalAudit/);
assert.match(source,/must not conceal a known gap/);
assert.match(source,/quality_attribution_review_required/);
assert.match(source,/Error Log activity is not proof of employee responsibility/);
assert.match(source,/sourceStatus=.*partial/);
assert.match(flags,/Production EPI cannot be enabled/);
assert.match(flags,/MODE_DISABLED/);
console.log('EPI Recovery Step 3C static contracts passed (34 assertions).');
