import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const js = readFileSync(new URL('../assets/js/portal-view-bar.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/portal-view-bar.css', import.meta.url), 'utf8');
const orders = readFileSync(new URL('../apps/operations/orders-board.php', import.meta.url), 'utf8');
const bookkeeping = readFileSync(new URL('../apps/operations/bookkeeping.php', import.meta.url), 'utf8');
const courier = readFileSync(new URL('../apps/operations/courier.php', import.meta.url), 'utf8');
const tasks = readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const presence = readFileSync(new URL('../assets/js/portal-presence.js', import.meta.url), 'utf8');

assert.match(js, /portal-filter-toolbar/);
assert.match(js, /portal-filter-toolbar__controls/);
assert.match(js, /data-filter-toolbar/);
assert.equal((js.match(/document\.addEventListener\('click', \(event\) => \{\s*const button = event\.target\.closest\('\[data-filter-toolbar\]/g) || []).length, 1);
for (const action of ['search', 'person', 'filter', 'sort', 'hide', 'group', 'sync', 'cash-tools', 'tools']) {
  assert.match(js + orders + bookkeeping, new RegExp(`data-toolbar-action=["']${action}`));
}
assert.match(css, /border:\s*1px solid #ede3d8 !important/);
assert.match(css, /portal-toolbar-person/);
assert.match(css, /portal-toolbar-filter/);
assert.match(css, /portal-toolbar-sort/);
assert.match(css, /portal-toolbar-hide/);
assert.match(css, /portal-toolbar-cash/);
assert.match(css, /portal-toolbar-dots/);
assert.match(css, /clamp\(190px,18vw,280px\)/);
assert.match(css, /portal-view-group-row/);
assert.match(css, /@media \(max-width: 768px\)/);
assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
assert.match(css, /portal-filter-toolbar__controls[^}]*overflow-x:\s*auto/s);
assert.match(css, /portal-filter-toolbar\s*>\s*\.portal-filter-toolbar__controls[^}]*flex:\s*1 1 auto !important/s);
assert.match(css, /portal-toolbar-action--more[^}]*position:sticky/);
assert.match(bookkeeping, /id="bkDrawerBtn"[^>]*data-toolbar-action="cash-tools"[^>]*aria-expanded="false"/);
assert.match(bookkeeping, /bkDrawerBtn'\)\?\.setAttribute\('aria-expanded', 'true'\)/);
assert.match(bookkeeping, /bkDrawerBtn'\)\?\.setAttribute\('aria-expanded', 'false'\)/);
assert.match(bookkeeping, /closeDrawer\(true\)/);
assert.doesNotMatch(css, /portal-view-bar__page-action[^\n]*border:1px solid rgba\(171,54,25/);
assert.match(orders, /portal-filter-toolbar/);
assert.match(orders, /portal-toolbar-action--more/);
assert.match(presence, /portal-view-bar\.css\?v=shared9/);
assert.match(presence, /portal-view-bar\.js\?v=shared9/);
assert.match(courier, /data-waybill-filter/);
assert.match(tasks, /data-portal-view-filter/);
assert.match(js, /input\[name\*="employee"\]/);
assert.match(js, /function controlOptions\(control\)/);
assert.match(js, /function groupSurface\(surface/);
assert.match(js, /function syncView\(source, button\)/);
assert.match(js, /portal-table-toolbar:/);
assert.match(js, /data-show-all-columns/);
assert.match(js, /data-toggle-portal-group/);
assert.match(courier, /data-refresh-waybills data-view-sync-action/);
assert.match(tasks, /data-task-tools-open data-view-bar-action/);
assert.match(js, /if \(group \|\| surface\)/);

console.log('Portal filter toolbar checks passed.');
