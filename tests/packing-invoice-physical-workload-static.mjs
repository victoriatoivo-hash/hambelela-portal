import assert from 'node:assert/strict';
import fs from 'node:fs';

const js = fs.readFileSync('assets/js/packing-list.js', 'utf8');
const php = fs.readFileSync('apps/operations/packing-list-action.php', 'utf8');
const view = fs.readFileSync('apps/operations/consignments.php', 'utf8');

for (const token of ['quantityAccounting', 'draftPhysical', 'formatPhysical', 'weightDifference', 'volumeDifference', 'unitDifference', 'Weighted workload', 'Bulk remainder']) {
  assert.ok(js.includes(token), `missing client physical-workload token: ${token}`);
}
assert.match(view, /Unit \*/);
assert.match(view, /data-invoice-draft-head/);
assert.match(view, /Packing review/);
assert.match(php, /packing_invoice_allocation_validation/);
assert.match(php, /Unit mismatch/);
assert.match(php, /Under allocated quantity must be accounted for/);

const parse = (expression) => {
  const totals = { weight: 0, volume: 0, count: 0 };
  const re = /(\d+(?:\.\d+)?)\s*(kg|g|ml|l|units?)\s*\(?(\d+)?\)?/gi;
  const meta = { kg: ['weight', 1000], g: ['weight', 1], l: ['volume', 1000], ml: ['volume', 1], unit: ['count', 1], units: ['count', 1] };
  for (const match of expression.matchAll(re)) { const [dimension, factor] = meta[match[2].toLowerCase()]; totals[dimension] += Number(match[1]) * factor * Number(match[3] || 1); }
  return totals;
};
assert.equal(parse('100g(20) 250g(8)').weight, 4000);
assert.equal(parse('500g(10)').weight, 5000);
assert.equal(parse('1kg(6)').weight, 6000);
assert.equal(parse('500ml(2) 1L(1)').volume, 2000);

const greedyWholeRows = (rows) => { const loads = [0, 0]; [...rows].sort((a, b) => b - a).forEach((row) => { const index = loads[0] <= loads[1] ? 0 : 1; loads[index] += row; }); return loads.sort((a, b) => a - b); };
assert.deepEqual(greedyWholeRows([10, 8, 3]), [10, 11]);

console.log('packing invoice physical and weighted workload checks passed');
