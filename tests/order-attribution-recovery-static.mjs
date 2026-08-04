import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const service = read('apps/operations/order-attribution-service.php');
const action = read('apps/operations/orders-board-action.php');
const report = read('apps/operations/historical-order-attribution-report.php');
const kpi = read('apps/operations/kpi-employee-data.php');
const migration = read('order_attribution_migrate_4ef8c6d7722e4f3a81d5369bb30efc01.php');

assert.match(migration, /CREATE TABLE IF NOT EXISTS ops_order_attribution_reviews/);
assert.match(service, /FOR UPDATE/);
assert.match(service, /assigned_packer_id IS NULL/);
assert.match(service, /count\(\$itemActors\)===1/);
assert.doesNotMatch(service, /\$confirmed=\(int\)\$completion/);
assert.match(service, /historical_packed_by_recovered/);
assert.match(service, /packed_by_auto_assigned_after_status_change/);
assert.match(action, /ops_update_order_status_with_attribution/);
assert.match(report, /Save this order review/);
assert.match(report, /Possible packers are never preselected/);
assert.doesNotMatch(report, /bulk assignment/i);
assert.match(kpi, /'weight'=>2,'result'=>\$attributionRate/);
assert.match(kpi, /Unable-to-confirm and unresolved orders remain excluded/);

console.log('Historical order attribution static checks passed.');
