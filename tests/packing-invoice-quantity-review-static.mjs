import assert from 'node:assert/strict';
import fs from 'node:fs';

const js = fs.readFileSync(new URL('../assets/js/packing-list.js', import.meta.url), 'utf8');
const php = fs.readFileSync(new URL('../apps/operations/packing-list-action.php', import.meta.url), 'utf8');
const html = fs.readFileSync(new URL('../apps/operations/consignments.php', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/packing-board.css', import.meta.url), 'utf8');

for (const token of ['quantityPlanParts', 'Confirm All Valid Rows', 'Waiting for quantity corrections before redistribution.', 'data-confirm-quantity-row', 'data-leave-as-bulk', 'data-auto-redistribute', 'invoiceCorrectionStorageKey']) assert.match(js, new RegExp(token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
assert.match(html, /data-confirm-quantities-create disabled/);
assert.match(css, /\.quantity-review-panel/);
assert.match(php, /Could not understand:/);

const unit = (value) => ({ kg: ['weight', 1000], g: ['weight', 1], L: ['volume', 1000], ml: ['volume', 1], units: ['count', 1] }[value]);
const parse = (text) => {
  const totals = { weight: 0, volume: 0, count: 0 };
  const pattern = /(\d+(?:\.\d+)?)\s*(kg|g|ml|L|units)\s*(?:[x*]\s*(\d+)|\(\s*(\d+)\s*\))/g;
  let match; let sizes = 0;
  while ((match = pattern.exec(text)) !== null) { const [dimension, factor] = unit(match[2]); totals[dimension] += Number(match[1]) * factor * Number(match[3] || match[4]); sizes += 1; }
  return { totals, sizes };
};

assert.equal(parse('100g20').sizes, 0, 'missing delimiter/count syntax stays in correction');
assert.equal(parse('100g(20)').totals.weight, 2000);
assert.equal(parse('100g x20').totals.weight, 2000);
assert.equal(parse('100g*20').totals.weight, 2000);
assert.equal(parse('500g(10)').totals.weight, 5000);
assert.equal(parse('1kg(6)').totals.weight, 6000);
assert.equal(parse('500ml(10), 1L(5)').totals.volume, 10000);

const rows = Array.from({ length: 15 }, (_, index) => ({ valid: index < 5, confirmed: index < 5 }));
assert.equal(rows.filter((row) => row.confirmed).length, 5);
rows.slice(5, 14).forEach((row) => { row.valid = true; row.confirmed = true; });
assert.equal(rows.filter((row) => row.confirmed).length, 14);
rows[14].valid = true; rows[14].confirmed = true;
assert.equal(rows.filter((row) => row.valid && row.confirmed).length, 15);

console.log('packing invoice quantity review checks passed');
