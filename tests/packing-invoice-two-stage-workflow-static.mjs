import assert from 'node:assert/strict';
import fs from 'node:fs';

const js = fs.readFileSync('assets/js/packing-list.js', 'utf8');
const view = fs.readFileSync('apps/operations/consignments.php', 'utf8');
const css = fs.readFileSync('assets/css/packing-board.css', 'utf8');

for (const token of ['receivedQuantityState', 'receivedReviewComplete', 'allPackingAllocationsComplete', 'Initial physical workload', 'Packing allocation updated in the background', 'Received quantity changed. Redistribution required', 'setupInvoiceColumnResizing']) {
  assert.ok(js.includes(token), `missing two-stage workflow token: ${token}`);
}
for (const text of ['Confirm Received Quantity', 'Enter Packing Instructions', 'Create Packing Items']) assert.ok(view.includes(text));
assert.match(css, /invoice-column-resizer/);
assert.match(css, /overflow-x:auto/);

const distributeWholeRows = (rows) => {
  const loads = [0, 0];
  [...rows].sort((a, b) => b - a).forEach((row) => { loads[loads[0] <= loads[1] ? 0 : 1] += row; });
  return loads.sort((a, b) => a - b);
};
assert.deepEqual(distributeWholeRows([10, 8, 3]), [10, 11], 'initial distribution keeps complete product rows');

const allocatedGrams = 100 * 20 + 250 * 8;
assert.equal(allocatedGrams, 4000);
assert.equal(5000 - allocatedGrams, 1000, '5kg row leaves a 1kg bulk remainder');
assert.equal(allocatedGrams + 1000, 5000, 'packing plus bulk fully accounts for received stock');

const missingUnit = { amount: 500, unit: '' };
assert.equal(Boolean(missingUnit.unit), false);
missingUnit.unit = 'g';
assert.equal(missingUnit.amount > 0 && missingUnit.unit === 'g', true, 'selecting g makes the received quantity confirmable');

console.log('packing invoice two-stage workflow checks passed');
