import assert from 'node:assert/strict';
import fs from 'node:fs';

const service=fs.readFileSync(new URL('../shared/epi/PerformanceScore.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../operations-epi-automatic-scoring-migration.sql',import.meta.url),'utf8');
const baseMigration=fs.readFileSync(new URL('../operations-epi-scoring-migration.sql',import.meta.url),'utf8');
const api=fs.readFileSync(new URL('../apps/operations/epi-scoring-performance-data.php',import.meta.url),'utf8');
const page=fs.readFileSync(new URL('../apps/operations/epi-scoring-performance.php',import.meta.url),'utf8');

for(const field of ['automatic_application','minimum_confidence','owner_review_required','exclusion_conditions_json','grace_period_minutes','root_incident_grouping','automatic_status','confidence_level','exception_status','expected_result','actual_result','exception_id','supersedes_event_id']){
  assert.match(migration,new RegExp(field));
  assert.match(baseMigration,new RegExp(field));
}
assert.match(service,/automaticDecision/);
assert.match(service,/automatically_applied/);
assert.match(service,/automatically_excluded/);
assert.match(service,/needs_review/);
assert.match(service,/insufficient_data/);
assert.match(service,/recording_mode.*test/);
assert.match(service,/approved_leave/);
assert.match(service,/system_error/);
assert.match(service,/business_error/);
assert.match(service,/external_dependency/);
assert.match(service,/isMonthLocked/);
assert.match(service,/INSERT IGNORE INTO epi_performance_score_events/, 'evidence/rule uniqueness remains duplicate protection');
assert.match(service,/supersedes_event_id/);
assert.match(service,/automatic_status='reversed'/);
assert.match(service,/overrideEvent/);
for(const action of ['excuse','reassign','mark_system_error','mark_business_error','mark_external_dependency','correct_responsibility','correct_reference','restore_automatic_event']) assert.match(service,new RegExp(action));
assert.match(api,/overrideEvent/);
assert.match(api,/reclassifyPeriod/);
assert.match(api,/eventStatusSummary/);
assert.match(page,/Automatically Applied/);
assert.match(page,/Needs Review/);
assert.doesNotMatch(page,/Pending owner review:/);

console.log('EPI automatic-by-default scoring contracts passed.');
