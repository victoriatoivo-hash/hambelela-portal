import fs from 'node:fs';
import assert from 'node:assert/strict';

const php=fs.readFileSync('apps/operations/orders-board-action.php','utf8');
const js=fs.readFileSync('assets/js/orders-board.js','utf8');
const page=fs.readFileSync('apps/operations/orders-board.php','utf8');
const data=fs.readFileSync('apps/operations/orders-board-data.php','utf8');

assert.ok(php.includes('ops_board_current_packing_employee'),'role-based packing eligibility helper is required');
assert.ok(php.includes("r.role_key IN ('packer','packer_production_staff')"),'packer roles must be identified by role key');
assert.ok(php.includes('packing_assignable = 1'),'explicit packing eligibility must be respected');
assert.ok(php.includes('SELECT order_number,status,assigned_packer_id FROM ops_orders WHERE id=? FOR UPDATE'),'single status update must lock the order row');
assert.ok(php.includes('SELECT id,order_number,status,assigned_packer_id FROM ops_orders WHERE id IN')&&php.includes('FOR UPDATE'),'bulk status update must lock selected order rows');
assert.ok(php.includes("if (!$lockedPackerId && in_array($value, ['in_progress','completed'], true))"),'unassigned work must be evaluated at packing start or completion');
assert.ok(php.includes('Please select who packed this order before marking it Complete.'),'single completion must reject missing attribution');
assert.ok(php.includes('Please select who packed every selected order before marking it Complete.'),'bulk completion must reject missing attribution');
assert.ok(php.includes('packer_automatically_assigned'),'automatic assignment must have a separate audit event');
assert.ok(php.includes("'assignment_method' => 'automatic_self_assignment'"),'audit metadata must identify automatic assignment');
assert.ok(php.includes("'triggered_by_status' => $targetStatus"),'audit metadata must identify the triggering status');
assert.ok(php.includes('Packing employees may assign an unassigned order only to themselves.'),'employees must not assign another packer');
assert.ok(php.includes('Packed By cannot be cleared after packing has started.'),'started work attribution must not be cleared');
assert.ok(js.includes('response?.auto_assigned_packer'),'client must consume backend assignment result');
assert.ok(js.includes("updateOrderCacheField(change.id, 'assigned_packer_id'"),'client cache must update the packer immediately');
assert.ok(js.includes("packerCell.innerHTML = renderPackerCell(order)"),'visible row must update without refresh');
assert.ok(php.includes("'attribution_review' => $attributionReview"),'owner tools must receive the historical attribution review list');
assert.ok(page.includes('data-orders-tools-tab="attribution"')&&page.includes("user_has_role('owner_admin')"),'attribution review must be owner-only');
assert.ok(js.includes("ordersToolsTab === 'attribution'"),'owner review list must render in Orders tools');
assert.ok(data.includes("'can_manage_packer_assignment'")&&js.includes('currentUser.can_manage_packer_assignment'),'client selection choices must follow backend assignment permissions');
console.log('Automatic Packed By attribution checks passed.');
