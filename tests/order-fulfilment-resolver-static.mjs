import fs from 'node:fs';
import assert from 'node:assert/strict';

const operations = fs.readFileSync(new URL('../apps/operations/operations.php', import.meta.url), 'utf8');
const boardSync = fs.readFileSync(new URL('../apps/operations/orders-board-action.php', import.meta.url), 'utf8');
const legacySync = fs.readFileSync(new URL('../apps/operations/sync-orders.php', import.meta.url), 'utf8');
const boardData = fs.readFileSync(new URL('../apps/operations/orders-board-data.php', import.meta.url), 'utf8');

assert.match(operations, /function ops_normalize_fulfilment_mode/);
assert.match(operations, /local_pickup\|pickup\|pick_up\|collection\|collect/);
assert.ok(operations.indexOf('local_pickup|pickup') < operations.indexOf('delivery|local_delivery|flat_rate'), 'pickup must resolve before delivery');
assert.match(boardSync, /ops_pos_fulfilment_from_order\(\$order\)/);
assert.match(legacySync, /ops_pos_fulfilment_from_order\(\$order\)/);
assert.match(boardSync, /UPDATE ops_orders SET fulfilment_mode/);
assert.match(legacySync, /UPDATE ops_orders SET fulfilment_mode/);
assert.match(boardData, /fulfilmentMode/);
assert.match(boardData, /fulfilmentLabel/);
assert.match(boardData, /fulfilmentSource/);
assert.match(boardData, /fulfilmentUpdatedAt/);

const normalize = value => {
  const text=String(value??'').trim().toLowerCase().replace(/[\s-]+/g,'_');
  if(!text)return 'unknown';
  if(/courier|nampost|nam_post|pudo|shipping|\bship\b/.test(text))return 'courier';
  if(/local_pickup|pickup|pick_up|collection|collect/.test(text))return 'collection';
  if(/delivery|local_delivery|flat_rate/.test(text))return 'delivery';
  return 'unknown';
};
assert.equal(normalize('local_pickup Pickup'), 'collection');
assert.equal(normalize('flat_rate Delivery'), 'delivery');
assert.equal(normalize('custom Courier'), 'courier');
assert.equal(normalize(''), 'unknown');
console.log('Order fulfilment resolver static checks passed.');
