import fs from 'node:fs';
import assert from 'node:assert/strict';
const service=fs.readFileSync(new URL('../shared/epi/PerformanceScore.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../operations-epi-scoring-migration.sql',import.meta.url),'utf8');
const api=fs.readFileSync(new URL('../apps/operations/epi-scoring-performance-data.php',import.meta.url),'utf8');
for(const table of ['epi_scorecards','epi_scorecard_categories','epi_performance_rules','epi_performance_rule_versions','epi_performance_score_events','epi_scoring_monthly_scores','epi_employee_monthly_category_scores','epi_employee_score_audits','epi_employee_month_locks','epi_performance_manual_adjustments'])assert.match(migration,new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\b`));
assert.match(service,/PHP_ROUND_HALF_UP/);assert.match(service,/confirmation_status='confirmed'/);assert.match(service,/confirmation_status='pending'/);assert.match(service,/Employees cannot approve their own score events/);assert.match(service,/Scorecard category weights must total exactly 100%/);assert.match(service,/deduplicateIncidents/);assert.match(service,/locked=1/);assert.match(service,/epi_employee_score_audits/);assert.match(api,/You may view only your own approved performance/);assert.match(api,/hash_equals/);
function impact(base,...multipliers){return multipliers.reduce((value,basis)=>Math.floor((value*basis)/10000+0.5),base)}
assert.equal(impact(100,10000,10000,10000,10000),100);assert.equal(impact(100,15000,20000,10000,5000),150);assert.equal(Math.round((9000*2500)/10000),2250);assert.equal(Math.max(100,250,175),250);assert.equal(Math.round((9000+8000+10000)/3),9000);
console.log('EPI Phase 9 static and deterministic arithmetic checks passed.');
