import assert from 'node:assert/strict';
import fs from 'node:fs';

const php = fs.readFileSync(new URL('../apps/operations/orders-board-action.php', import.meta.url), 'utf8');
const data = fs.readFileSync(new URL('../apps/operations/orders-board-data.php', import.meta.url), 'utf8');
const js = fs.readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');

for (const role of ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'packer', 'packer_production_staff']) {
  assert.ok(php.includes(role), `backend must authorize ${role}`);
  assert.ok(data.includes(role), `Orders Board permissions must include ${role}`);
}
assert.match(php, /SELECT order_number,status,assigned_packer_id,completed_at FROM ops_orders WHERE id = \? FOR UPDATE/, 'packer correction must lock the order row');
assert.match(php, /packer_attribution_corrected/, 'completed corrections need distinct attribution evidence');
assert.match(php, /original_completion_timestamp/, 'audit metadata must preserve the original completion time');
assert.match(php, /correction_reason/, 'backend must require a correction reason');
assert.match(php, /Packed By cannot be cleared after packing has started/, 'started or completed orders cannot return to Unassigned');
assert.ok(!/UPDATE ops_orders SET assigned_packer_id[^;]+completed_at/s.test(php), 'packer-only update must not alter completed_at');
assert.match(js, /showCompletedPackerCorrection/, 'completed rows must use the compact correction popup');
assert.match(js, /correction_reason: reason, correction_note: note/, 'client must submit correction evidence');
assert.match(js, /cell\.innerHTML = renderPackerCell\(order\)/, 'visible Packed By cell must update without a page reload');

console.log('Completed-order Packed By correction static checks passed.');
