import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');

assert.match(board, /function formatOrderInvoiceReference\(orderReference = ''\)/);
assert.match(board, /rawReference\.match\(\/\^WEB\[-_\\s\]\*#\?\\s\*\(\.\+\)\$\/i\)/);
assert.match(board, /return orderNumber \? `INV-\$\{orderNumber\}` : rawReference/);
assert.match(board, /function getTaskOrderNumber\(orderReference = ''\)/);
assert.match(board, /function buildOrderTaskName\(order = \{\}\)/);
assert.match(board, /rawReference\.match\(\/\^\(\?:INV\|WEB\)/);
assert.match(board, /if \(field === 'customer_name'\) return buildOrderTaskName\(order\)/);
assert.match(board, /data-order-reference>\$\{esc\(buildOrderTaskName\(order\)\)\}/);
assert.doesNotMatch(board, /data-order-reference>\$\{esc\(formatOrderInvoiceReference\(order\.order_number\)\)\}/);
assert.match(board, /data-payment-order-reference>\$\{esc\(formatOrderInvoiceReference\(order\.order_number \|\| order\.id\)\)\}/);
assert.match(board, /modal\.dataset\.orderId = String\(order\.id\)/);
assert.match(board, /post\('save_payment_allocations',\{order_id:order\.id/);
assert.match(board, /order\.order_number, formatOrderInvoiceReference\(order\.order_number\)/);
assert.doesNotMatch(board, /order_number\.replace\(\/\^WEB-/);

console.log('Orders invoice display-reference checks passed.');
