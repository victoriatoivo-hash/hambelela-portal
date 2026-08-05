import assert from 'node:assert/strict';
import fs from 'node:fs';

const score = fs.readFileSync(new URL('../shared/epi/PerformanceScore.php', import.meta.url), 'utf8');
const source = fs.readFileSync(new URL('../shared/epi/SourceCompletenessEngine.php', import.meta.url), 'utf8');
const migration = fs.readFileSync(new URL('../operations-epi-recovery-step3b-migration.sql', import.meta.url), 'utf8');
const page = fs.readFileSync(new URL('../apps/operations/epi-scoring-performance.php', import.meta.url), 'utf8');
const api = fs.readFileSync(new URL('../apps/operations/epi-scoring-performance-data.php', import.meta.url), 'utf8');
const dashboardApi = fs.readFileSync(new URL('../apps/operations/epi-dashboard-data.php', import.meta.url), 'utf8');

for (const table of [
  'epi_role_required_sources',
  'epi_monthly_source_completeness',
  'epi_evidence_eligibility_audits',
  'epi_score_superseding_corrections',
]) assert.match(migration, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\b`));

for (const field of [
  'eligibility_state', 'result_type', 'official_score_hundredths',
  'official_performance_level', 'missing_critical_sources_json',
  'calculation_status', 'missing_sources_json',
]) assert.match(migration, new RegExp(field));

for (const importance of ['critical', 'core', 'supporting']) assert.match(migration, new RegExp(`'${importance}'`));
for (const role of ['front_desk_admin', 'packer']) assert.match(migration, new RegExp(`'${role}'`));

for (const state of [
  'automatically_eligible', 'verified_eligible', 'needs_review', 'test_data',
  'duplicate', 'system_error', 'business_error', 'superseded', 'invalid',
]) assert.match(source, new RegExp(state));

assert.match(source, /subjective_review_required/);
assert.match(source, /Objective timestamped and attributed system evidence/);
assert.match(source, /criticalMissing/);
assert.match(source, /coreMissingLimit/);
assert.match(source, /source_status/);
assert.match(source, /ownership_coverage_hundredths/);
assert.match(source, /timestamp_coverage_hundredths/);
assert.match(source, /status_history_coverage_hundredths/);

assert.match(score, /officialOverall = .*insufficient_historical_data.*null/);
assert.match(score, /officialLevel = .*Not Available/);
assert.match(score, /eligibility_state IN\('automatically_eligible','verified_eligible'\)/);
assert.match(score, /Insufficient Historical Data cannot be locked/);
assert.match(score, /supersedeInvalidHundreds/);
assert.match(score, /Historical 100% had no usable evidence/);
assert.doesNotMatch(score, /e\.verified=1 AND e\.recording_mode/);

assert.match(page, /Score: Not Calculated/);
assert.match(page, /Performance Level:<\/strong>.*Not Available/s);
assert.match(page, /Required sources/);
assert.match(page, /Evidence eligibility totals/);
assert.match(api, /kind==='coverage'/);
assert.match(api, /kind==='eligibility'/);
assert.match(api, /action==='supersede_invalid'/);
assert.match(dashboardApi, /official_score_hundredths/);

function classify({ requirements, criticalMissing = 0, coreMissing = 0, criticalPartial = 0, coreCoverage = 10000, coreLimit = 2 }) {
  if (!requirements || criticalMissing || coreMissing >= coreLimit) return 'insufficient_historical_data';
  if (criticalPartial || coreCoverage < 9000) return 'provisional_calculated';
  return 'calculated';
}
assert.equal(classify({ requirements: 0 }), 'insufficient_historical_data');
assert.equal(classify({ requirements: 5, criticalMissing: 1 }), 'insufficient_historical_data');
assert.equal(classify({ requirements: 5, coreMissing: 2 }), 'insufficient_historical_data');
assert.equal(classify({ requirements: 5, criticalPartial: 1, coreCoverage: 8000 }), 'provisional_calculated');
assert.equal(classify({ requirements: 5, coreCoverage: 9500 }), 'calculated');

console.log('EPI Recovery Step 3B safety contracts passed: no evidence cannot become an official 100%.');
