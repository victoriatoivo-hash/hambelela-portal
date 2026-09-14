import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (file) => fs.readFileSync(new URL(`../${file}`, import.meta.url), 'utf8');
const bridge = read('shared/epi/OrdersActivityBridge.php');
const service = read('shared/epi/OrdersPerformance.php');
const operations = read('apps/operations/operations.php');
const api = read('apps/operations/epi-orders-performance-data.php');
const page = read('apps/operations/epi-orders-performance.php');
const migration = read('operations-epi-orders-performance-migration.sql');

assert.match(operations, /OrdersActivityBridge::record\(db\(\), \$action, \$entityId, \$metadata\)/, 'Orders activity stream must feed the EPI bridge');
assert.match(operations, /catch \(Throwable \$e\)[\s\S]*Orders remains operational/, 'EPI bridge must be fail-safe');
assert.match(bridge, /Performance::enabled\(\)/, 'master EPI feature flag must guard recording');
assert.match(bridge, /orders_front_desk_module_enabled/, 'Orders EPI sub-flag must guard recording');
assert.match(bridge, /ops_current_employee_id\(\)/, 'actual authenticated employee must be used');
assert.doesNotMatch(bridge, /Cecilia|Secilia|Dineo/i, 'employee names must never be assumed');
assert.match(bridge, /walkInDueAt/, 'walk-in close rule must use a due-time helper');
assert.match(bridge, /BusinessTimeEngine/, 'business-time engine must be reused');
assert.match(bridge, /orders_walk_in_identifiers/, 'walk-in identifiers must be configurable');
assert.match(bridge, /order_reopened/, 'reopened status must be preserved');
assert.match(bridge, /payment_verified/, 'payment verification must be captured');
assert.match(bridge, /deduction_candidate_/, 'deduction candidates must be evidence only');
assert.match(bridge, /bonus_candidate_/, 'bonus candidates must be evidence only');
assert.doesNotMatch(bridge, /score_impact\s*=>\s*[1-9]/, 'bridge must not calculate a final score');
assert.match(service, /FROM epi_employee_evidence/, 'all metrics must read the Evidence Engine');
assert.doesNotMatch(service, /FROM ops_orders/, 'performance calculations must not bypass evidence');
for (const method of ['getSummary', 'getEmployee', 'getEvidence', 'getWalkIns', 'getOutstanding']) {
  assert.match(service, new RegExp(`function ${method}\\(`), `OrdersPerformance::${method} must exist`);
}
for (const period of ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'previous_month', 'quarter', 'year', 'custom']) {
  assert.ok(service.includes(`'${period}'`) || page.includes(`'${period}'`), `${period} filter must be supported`);
}
assert.match(api, /kind === 'evidence'/, 'read-only evidence API must exist');
assert.match(page, /Plain verification view/, 'Phase 2 UI must remain a placeholder');
assert.doesNotMatch(page, /Chart|canvas/, 'placeholder must not add charts');
assert.match(migration, /orders_walk_in_identifiers/, 'walk-in configuration must be seeded');
assert.match(migration, /customer_response/, 'customer response grace period must be seeded');

console.log('EPI Orders / Front Desk Phase 2 static checks passed.');
