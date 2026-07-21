import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const portalCss = read('assets/css/portal.css');
const ordersCss = read('assets/css/orders-board.css');
const packingCss = read('assets/css/packing-board.css');
const orders = read('apps/operations/orders-board.php');
const packing = read('apps/operations/consignments.php');
const tasks = read('apps/operations/checklists.php');
const courier = read('apps/operations/courier.php');
const bookkeeping = read('apps/operations/bookkeeping.php');

for (const [name, source] of Object.entries({ orders, packing, tasks, courier, bookkeeping })) {
  assert.match(source, /portal-panel-tabs/, `${name} must use the shared panel tab navigation`);
  assert.match(source, /data-lucide=/, `${name} panel navigation must contain outline icons`);
}

assert.match(portalCss, /\.portal-panel-tab\[aria-selected="true"\]::after/, 'active shared tabs need the underline');
assert.match(portalCss, /overflow-x:auto/, 'shared tabs must remain scrollable on narrow panels');
assert.match(ordersCss, /\.orders-tools-record\{min-height:70px[^}]*border-bottom:1px/, 'Orders Trash must render as compact list rows');
assert.match(packingCss, /\.packing-trash-list\{display:flex;flex-direction:column;gap:0\}/, 'Packing Trash must render as a compact list');
assert.match(portalCss, /\.task-tools-card-list \{ display:flex; flex-direction:column; gap:0; \}/, 'Task Trash must render as a compact list');
assert.match(portalCss, /\.courier-tools-list\{display:flex;flex-direction:column;gap:0\}/, 'Courier Trash must render as a compact list');
assert.match(bookkeeping, /\.bk-trash-list \{ display: flex; flex-direction: column; gap: 0; \}/, 'Bookkeeping Trash must render as a compact list');
assert.match(bookkeeping, /bk-tabs portal-panel-tabs/, 'Cash Tools must use shared icon tabs');
assert.match(tasks, /portal-panel-tab is-active" aria-selected="true" data-task-panel-jump/, 'Task detail tabs need an accessible selected state');
assert.match(portalCss, /task-panel-tabs\.portal-panel-tabs > button\[aria-selected="true"\]/, 'Task detail tabs must keep the shared underline navigation treatment');

for (const oldPill of [
  '.orders-tools-tabs .orders-tools-tab{height:32px',
  '.packing-tools-panel .packing-tools-tab{height:32px',
  '.courier-tools-tabs button{height:32px'
]) {
  assert.ok(!`${ordersCss}\n${packingCss}\n${portalCss}`.includes(oldPill), `legacy pill rule must be removed: ${oldPill}`);
}

console.log('Shared portal panel navigation and compact Trash checks passed.');
