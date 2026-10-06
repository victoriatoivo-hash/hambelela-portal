import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = relative => fs.readFileSync(new URL(`../${relative}`, import.meta.url), 'utf8');
const score = read('shared/epi/PerformanceScore.php');
const eligibility = read('shared/epi/EligibilityPolicy.php');
const registry = read('shared/epi/CanonicalEventRegistry.php');
const ownership = read('shared/epi/OwnershipPeriodEngine.php');
const deadlines = read('shared/epi/DeadlineEngine.php');
const quality = read('shared/epi/V2QualityBridge.php');
const worker = read('shared/epi/V2Watchdog.php');
const store = read('shared/epi/V2Store.php');
const completeness = read('shared/epi/SourceCompletenessEngine.php');
const api = read('apps/operations/epi-scoring-performance-data.php');
const page = read('apps/operations/epi-scoring-performance.php');
const migration = read('operations-epi-v2-p0-migration.sql');
const watchdog = read('scripts/epi-v2-deadline-watchdog.php');
const bridge = read('shared/epi/V2OperationalBridge.php');
const legacyOrdersBridge = read('shared/epi/OrdersActivityBridge.php');
const operations = read('apps/operations/operations.php');
const query = read('shared/epi/V2PerformanceQuery.php');

const constructor = score.match(/public function __construct\(PDO \$pdo\)\s*\{([^}]*)\}/s)?.[1] ?? '';
assert.doesNotMatch(constructor, /exec|ALTER|INSERT|UPDATE|DELETE|ensure/i, 'score constructor must be read-only');
assert.doesNotMatch(score, /ensureAutomaticScoringSchema|columnExists/, 'runtime migration helpers were removed');
assert.match(completeness, /function evaluate\([^)]*bool \$persist = false/);
assert.match(completeness, /if \(\$persist\) \$this->classifyPeriod/);
assert.match(completeness, /if \(\$persist\) \$this->storeSource/);
assert.doesNotMatch(page, /classifyPeriod|classifyEligibility|syncEvidenceEvents|calculateMonthly/);
const getBranch = api.slice(api.indexOf("$employee=(int)($_GET"));
assert.doesNotMatch(getBranch, /classifyEligibility|syncEvidenceEvents|reclassifyPeriod|calculateMonthly/);

for (const table of ['epi_v2_event_registry','epi_v2_ownership_periods','epi_v2_duty_periods','epi_v2_handovers','epi_v2_operational_deadlines','epi_v2_performance_incidents']) {
  assert.match(migration, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
}
for (const key of [
  'order_acknowledgement_sla_breached','order_completion_sla_breached','order_new_sla_breached',
  'customer_followup_breached','task_start_sla_breached','task_completion_sla_breached',
  'courier_send_sla_breached','website_stock_update_breached','cash_entry_missing','confirmed_employee_error'
]) assert.match(migration, new RegExp(`'${key}'`));

for (const field of ['effective_from','effective_to','assigned_by','accepted_by','accepted_at','shift_id','exception_id']) {
  assert.match(migration, new RegExp(field));
  assert.match(ownership, new RegExp(field));
}
assert.match(ownership, /effective_from<=\? AND \(effective_to IS NULL OR effective_to>\?\)/, 'owner is resolved at the breach timestamp');
assert.match(ownership, /accepted_by IS NOT NULL AND accepted_at<=\?/, 'acceptance must already exist at the breach time');
assert.match(ownership, /initiateHandover/);
assert.match(ownership, /acceptHandover/);
assert.match(ownership, /Cannot transfer unowned work/);
assert.match(ownership, /status='accepted'/);

assert.match(deadlines, /INSERT INTO epi_v2_performance_incidents/);
assert.match(migration, /UNIQUE KEY uq_epi_v2_incident_deadline/, 'DB uniqueness protects retries');
assert.match(deadlines, /FOR UPDATE/, 'evaluation serializes the deadline');
assert.match(deadlines, /responsible_employee_at_breach/);
assert.match(deadlines, /ownerAt\([^;]+\['due_at'\]/s, 'breach owner is looked up at due_at');
assert.match(deadlines, /current_risk_state='resolved'/, 'late fulfilment resolves current risk');
assert.match(deadlines, /state='excused'/, 'approved exceptions prevent a breach incident');
assert.match(deadlines, /historical_state/);
assert.match(watchdog, /PHP_SAPI\s*!==\s*'cli'/);
assert.match(worker, /epi_v2_watchdog_enabled/);
assert.match(watchdog, /shared\/database\.php/);
assert.match(bridge, /order_created/);
assert.match(bridge, /Progression includes acknowledgement/, 'acknowledgement and progression share one root transition');
assert.match(bridge, /order_new_sla_breached/);
assert.match(bridge, /task_start_sla_breached/);
assert.match(bridge, /task_completion_sla_breached/);
assert.match(bridge, /fulfilObject/);
assert.match(operations, /V2OperationalBridge::record/);
const walkInBody = bridge.slice(bridge.indexOf('private static function fulfilmentMode'));
assert.doesNotMatch(walkInBody, /customer_contact|customer_name|orders_walk_in_identifiers/, 'Walk-In classification cannot use customer identity/contact text');
assert.match(query, /personalRisk/);
assert.match(query, /teamRisk/);
assert.match(query, /ownerAt/, 'personal work uses current accepted ownership');
assert.match(query, /needs_attribution/);
assert.match(query, /function explain/);

for (const flag of ['excluded_from_scoring','system_error','business_error','external_dependency','approved_leave','approved_exception','duplicate','test_data','superseded','insufficient_attribution']) {
  assert.match(eligibility, new RegExp(flag));
}
assert.match(score, /EligibilityPolicy::exclusionReason/);


assert.match(quality, /reporter_employee_id/);
assert.match(quality, /actor_employee_id/);
assert.match(quality, /responsible_employee_id/);
assert.match(quality, /accuracy_verified_by/);
assert.match(quality, /attribution_verified_by/);
assert.match(quality, /confirmed_employee_error/);
assert.match(quality, /quality_attribution_pending/);
assert.match(quality, /superseded_at=NOW\(\),eligible=0/);
assert.match(migration, /UNIQUE KEY uq_epi_v2_quality_current/);
assert.match(store, /Activation is immutable/);
assert.match(migration, /epi_v2_activation_no_update/);
assert.match(migration, /epi_v2_activation_no_delete/);
assert.doesNotMatch(migration, /\b(?:UPDATE|DELETE FROM|REPLACE INTO)\s+epi_performance_score_events/i);
assert.match(migration, /'epi_v2_capture_enabled','0'/);
assert.match(deadlines, /historical_backfill/);
assert.match(deadlines, /evaluateLocked/);
assert.match(bridge, /epi_v2_outbox/);
assert.match(worker, /epi_v2_watchdog_runs/);
console.log('EPI V2 P0 remediation structural checks passed; database tests remain authoritative.');
