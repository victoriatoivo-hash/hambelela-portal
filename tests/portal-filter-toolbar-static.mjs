import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const js = readFileSync(new URL('../assets/js/portal-view-bar.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/portal-view-bar.css', import.meta.url), 'utf8');
const orders = readFileSync(new URL('../apps/operations/orders-board.php', import.meta.url), 'utf8');
const bookkeeping = readFileSync(new URL('../apps/operations/bookkeeping.php', import.meta.url), 'utf8');

assert.match(js, /portal-filter-toolbar/);
assert.match(js, /portal-filter-toolbar__controls/);
assert.match(js, /data-filter-toolbar/);
assert.equal((js.match(/document\.addEventListener\('click', \(event\) => \{\s*const button = event\.target\.closest\('\[data-filter-toolbar\]/g) || []).length, 1);
for (const action of ['search', 'person', 'filter', 'sort', 'hide', 'cash-tools', 'tools']) {
  assert.match(js + orders + bookkeeping, new RegExp(`data-toolbar-action=["']${action}`));
}
assert.match(css, /border:\s*1px solid #ede3d8 !important/);
assert.match(css, /portal-toolbar-person/);
assert.match(css, /portal-toolbar-filter/);
assert.match(css, /portal-toolbar-sort/);
assert.match(css, /portal-toolbar-hide/);
assert.match(css, /portal-toolbar-cash/);
assert.match(css, /portal-toolbar-dots/);
assert.match(css, /@media \(max-width: 768px\)/);
assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
assert.match(css, /portal-filter-toolbar__controls[^}]*overflow-x:\s*auto/s);
assert.match(css, /portal-toolbar-action--more[^}]*position:\s*relative/s);
assert.match(bookkeeping, /id="bkDrawerBtn"[^>]*data-toolbar-action="cash-tools"[^>]*aria-expanded="false"/);
assert.match(bookkeeping, /bkDrawerBtn'\)\?\.setAttribute\('aria-expanded', 'true'\)/);
assert.match(bookkeeping, /bkDrawerBtn'\)\?\.setAttribute\('aria-expanded', 'false'\)/);
assert.doesNotMatch(css, /portal-view-bar__page-action[^\n]*border:1px solid rgba\(171,54,25/);
assert.match(orders, /portal-filter-toolbar/);
assert.match(orders, /portal-toolbar-action--more/);

console.log('Portal filter toolbar checks passed.');
