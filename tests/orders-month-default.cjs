const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/orders-board.js', 'utf8');
const page = fs.readFileSync('apps/operations/orders-board.php', 'utf8');
assert.match(page, /id="board-date-preset"[^>]*value="month">\s*<button[^>]+><span>This Month<\/span>/);
const init = source.slice(source.indexOf('  const windhoekDateParts'), source.indexOf('  function orderDisplayDateTime'));
const ranges = source.slice(source.indexOf('  function addUtcDays'), source.indexOf('  function updateDateFilterUi'));
const values = new Map();
const sessionStorage = {getItem: k => values.get(k), setItem: (k,v) => values.set(k,v)};
function load() {
  const ctx = vm.createContext({sessionStorage, datePreset:{}, dateFromFilter:{}, dateToFilter:{},
    Date: class extends Date { constructor(...args) { super(...(args.length ? args : ['2028-01-31T23:00:00Z'])); } }});
  vm.runInContext(init + ranges, ctx);
  assert.equal(ctx.datePreset.value, 'month');
  assert.equal(ctx.activeDateRange().from, '2028-02-01');
  assert.equal(ctx.activeDateRange().to, '2028-02-29');
  return ctx;
}
load();
for (const preset of ['today','week','all','custom']) {
  sessionStorage.setItem('hambelelaOrdersDateFilter', JSON.stringify({preset, from:'2026-11-03',to:'2026-11-05'}));
  const ctx = load();
  vm.runInContext("boardDateScope = 'custom'; persistDateFilter()", ctx);
  assert.equal(ctx.activeDateRange().from, '2026-11-03');
  load();
}
console.log('PASS: first load, reload after manual range, legacy preferences and Windhoek month boundary');
