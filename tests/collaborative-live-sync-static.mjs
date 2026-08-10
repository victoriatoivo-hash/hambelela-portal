import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const packing = read('assets/js/packing-list.js');
assert.match(packing, /packingRefreshRequest/);
assert.match(packing, /packingHasActiveEditor/);
assert.match(packing, /document\.hidden \|\| packingHasActiveEditor\(\)/);
assert.match(packing, /visibilitychange/);
assert.match(packing, /addEventListener\('online'/);
assert.match(packing, /state = \{ search:/, 'Packing filters remain controller state, outside refreshed records.');

const notifications = read('assets/js/notifications-page.js');
assert.match(notifications, /loadRequest/);
assert.match(notifications, /filterState\(\)/);
assert.match(notifications, /restoreFilterState\(savedFilters\)/);
assert.match(notifications, /document\.hidden/);
assert.match(notifications, /visibilitychange/);

const courier = read('apps/operations/courier.php');
assert.match(courier, /courierRefreshRequest/);
assert.match(courier, /courierHasActiveEditor/);
assert.match(courier, /selectedBatches/, 'Courier selection remains independent of refreshed payloads.');
assert.doesNotMatch(courier, /setInterval\(\(\) => \{\s*fetchJson\(filteredRefreshUrl\(\)\)/);
assert.match(courier, /scheduleCourierRefresh\(document\.hidden \? 120000 : 30000\)/);

const tasks = read('apps/operations/checklists.php');
assert.match(tasks, /timingRequest/);
assert.match(tasks, /version!==timingVersion/);
assert.match(tasks, /if \(document\.hidden \|\| timingRequest/);
assert.match(tasks, /addEventListener\('online', refresh\)/);

const orders = read('assets/js/orders-board.js');
assert.match(orders, /visibilitychange/);
assert.match(orders, /addEventListener\('online'/);
assert.match(orders, /setInterval|setTimeout/);

console.log('Collaborative live-sync static checks passed.');
