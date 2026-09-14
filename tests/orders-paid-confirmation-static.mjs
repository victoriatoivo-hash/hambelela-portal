import assert from 'node:assert/strict';
import fs from 'node:fs';

const operations = fs.readFileSync('apps/operations/operations.php', 'utf8');
const action = fs.readFileSync('apps/operations/orders-board-action.php', 'utf8');
const data = fs.readFileSync('apps/operations/orders-board-data.php', 'utf8');
const browser = fs.readFileSync('assets/js/orders-board.js', 'utf8');

assert.match(operations, /portal_paid_confirmed/);
assert.match(operations, /auto_pos_card_swipe/);
assert.match(operations, /count\(\$allocations\) === 1/);
assert.match(action, /ops_set_portal_paid_confirmation\(\$orderId, \$value === 'paid'/);
assert.doesNotMatch(action, /UPDATE ops_orders SET payment_status = \?, updated_at = CURRENT_TIMESTAMP' \. \$paidAuditSet/);
assert.match(data, /payment_status AS financial_payment_status/);
assert.match(data, /AS payment_status/);
assert.match(browser, /order\.financial_payment_status=data\.financial_payment_status/);
assert.doesNotMatch(browser, /order\.payment_status=data\.payment_status/);
assert.ok(action.indexOf("ops_replace_order_payment_allocations($orderId") < action.indexOf("wc_put('orders/'"), 'local save must happen before remote sync');

console.log('Orders financial payment and portal Paid confirmation remain separate.');
