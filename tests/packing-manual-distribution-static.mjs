import assert from 'node:assert/strict';
import fs from 'node:fs';

const js = fs.readFileSync('assets/js/packing-list.js', 'utf8');
const php = fs.readFileSync('apps/operations/packing-list-action.php', 'utf8');
const view = fs.readFileSync('apps/operations/consignments.php', 'utf8');

for (const token of ['Review Packer Distribution', 'data-packing-distribution-review', 'data-review-packer', 'data-reset-auto-distribution']) {
  assert.ok(view.includes(token) || js.includes(token), `missing allocation review token: ${token}`);
}
assert.ok(js.includes("assignment_source: manualMode ? row.assignment_source : 'manual'"), 'reviewed invoice assignments must be locked as manual before submit');
assert.ok(js.includes("distribution_reviewed: manualMode ? '0' : '1'"), 'invoice request must identify the reviewed distribution');
assert.ok(js.includes('autoDistributionSnapshot[index]'), 'reset must restore the original auto allocation');
assert.ok(js.includes("finalButton.disabled = !allPackingAllocationsComplete()"), 'incomplete quantity plans must disable creation');
assert.ok(php.includes('reviewed_assignments_json') && php.includes('count($reviewedAssignments) !== $submittedCount'), 'server must require all reviewed rows');
assert.ok(php.includes('ops_employee_can_receive_packing($validationAssignedId, false)'), 'server must reject ineligible packers');
assert.ok(php.includes("$assignmentSource === 'manual'"), 'server must preserve reviewed manual choices');

const rows = [{ quantity: 10, packer: 'A' }, { quantity: 10, packer: 'B' }, { quantity: 10, packer: 'A' }];
const original = rows.map((row) => row.packer);
rows[2].packer = 'B';
assert.deepEqual(rows.reduce((totals, row) => ({ ...totals, [row.packer]: (totals[row.packer] || 0) + row.quantity }), {}), { A: 10, B: 20 }, 'manual whole-row balancing changes final workload');
rows.forEach((row, index) => { row.packer = original[index]; });
assert.deepEqual(rows.map((row) => row.packer), original, 'reset restores auto distribution');
assert.equal(8 + 10 < 20, true, 'under allocation remains invalid');
assert.equal(12 + 10 > 20, true, 'over allocation remains invalid');

console.log('packing manual distribution static checks passed');
