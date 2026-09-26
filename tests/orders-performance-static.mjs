import assert from 'node:assert/strict';
import fs from 'node:fs';

const client = fs.readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const endpoint = fs.readFileSync(new URL('../apps/operations/orders-board-data.php', import.meta.url), 'utf8');

assert.match(client, /function scheduleOrdersSearch\(value, source = null\)/);
assert.match(client, /window\.setTimeout\(\(\) => \{[\s\S]*?renderOrders\(ordersCache\);[\s\S]*?\}, 180\);/);
assert.doesNotMatch(client, /if \(search\) \{\s*boardState\.search = search\.value;\s*renderOrders\(ordersCache\);/);
assert.doesNotMatch(client, /if \(boardSearch\) \{\s*boardState\.search = boardSearch\.value;\s*renderOrders\(ordersCache\);/);
assert.match(client, /const count = ordersCache\.length;/);

assert.doesNotMatch(endpoint, /'data' => \$responseData,\s*'orders' => \$orders,/);
assert.doesNotMatch(endpoint, /SELECT order_id, COUNT\(id\) AS item_lines/);
assert.match(endpoint, /\$itemStatsByOrder\[\$itemOrderId\]\['item_quantity'\] \+=/);
assert.doesNotMatch(endpoint, /ops_count\('ops_orders'/);
assert.match(endpoint, /COUNT\(\*\) AS total_orders,[\s\S]*AS overdue_orders,[\s\S]*AS total_revenue/);

console.log('Orders performance safeguards verified.');
