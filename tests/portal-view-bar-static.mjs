import fs from 'node:fs';
import assert from 'node:assert/strict';

const footer = fs.readFileSync(new URL('../shared/footer.php', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal-view-bar.css', import.meta.url), 'utf8');
const js = fs.readFileSync(new URL('../assets/js/portal-view-bar.js', import.meta.url), 'utf8');
const orders = fs.readFileSync(new URL('../apps/operations/orders-board.php', import.meta.url), 'utf8');
const ordersJs = fs.readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');

assert.match(footer, /portal-view-bar\.css/);
assert.match(footer, /portal-view-bar\.js/);
for (const label of ['Person', 'Filter', 'Sort', 'Hide', 'Group by']) assert.match(orders, new RegExp(`\\s${label}<`));
assert.match(css, /font:400 12px\/1 Figtree/);
assert.match(css, /height:32px/);
assert.match(css, /#ab3619/i);
assert.match(js, /querySelectorAll\('\[data-portal-view-filter\], \[data-waybill-filter\]'\)/);
assert.match(js, /data-column-index/);
assert.match(js, /data-sort-field="direction"/);
assert.match(js, /data-theme-select-input/);
console.log('portal view bar contract passed');
