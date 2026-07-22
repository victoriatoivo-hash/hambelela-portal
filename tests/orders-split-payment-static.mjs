import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const operations = readFileSync(new URL('../apps/operations/operations.php', import.meta.url), 'utf8');
const action = readFileSync(new URL('../apps/operations/orders-board-action.php', import.meta.url), 'utf8');
const data = readFileSync(new URL('../apps/operations/orders-board-data.php', import.meta.url), 'utf8');
const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');

assert.match(operations, /CREATE TABLE IF NOT EXISTS order_payment_allocations/);
assert.match(operations, /amount_cents BIGINT NOT NULL/);
assert.match(operations, /UNIQUE KEY uq_order_payment_method/);
assert.match(operations, /CREATE TABLE IF NOT EXISTS order_payment_allocation_audit/);
assert.match(operations, /ops_wc_payment_allocations\(\$order\)/);
assert.match(operations, /'paygate' => 'dpo'/);
assert.match(operations, /'woocommerce_dpo' => 'dpo'/);
assert.match(operations, /'fnbwlt' => 'fnb_ewallet'/);
assert.match(operations, /'easywlt' => 'easywallet'/);
assert.match(operations, /'bluewlt' => 'blue_wallet'/);
assert.match(action, /save_payment_allocations/);
assert.match(action, /hash_equals\(\$sessionCsrf, \$submittedCsrf\)/);
assert.match(action, /ops_can_update_order_payment_method\(\)/);
assert.match(action, /This payment was updated elsewhere/);
assert.match(action, /Website\/POS payments are read-only/);
assert.match(data, /'payments'\] = \$paymentsByOrder/);
assert.match(data, /'payment_source_of_truth'/);
assert.match(board, /payment-badge--split/);
assert.match(board, /A payment method cannot appear twice/);
assert.match(board, /data-payment-due/);
assert.match(css, /--payment-dpo:#2563EB/);
assert.match(css, /data-payment-method=dpo/);

const allocations = [{method:'cash',amount_cents:25000},{method:'eft',amount_cents:31600}];
assert.equal(allocations.reduce((sum, item) => sum + item.amount_cents, 0), 56600);
assert.equal(new Set(allocations.map((item) => item.method)).size, 2);

console.log('Orders split-payment checks passed.');
