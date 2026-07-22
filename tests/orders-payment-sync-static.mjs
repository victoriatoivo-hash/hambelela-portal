import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const operations = readFileSync(new URL('../apps/operations/operations.php', import.meta.url), 'utf8');
const action = readFileSync(new URL('../apps/operations/orders-board-action.php', import.meta.url), 'utf8');
const manualSync = readFileSync(new URL('../apps/operations/sync-orders.php', import.meta.url), 'utf8');
const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');

for (const method of ['Cash', 'Card/Swipe', 'EFT', 'FNB eWallet', 'EasyWallet', 'Blue Wallet', 'Nedbank', 'NetBank Wallet', 'Pay2Cell', 'PayToday', 'DPO']) {
  assert.ok(operations.includes(`'${method}'`), `Website method ${method} must be canonical.`);
  assert.ok(action.includes(`['${method}',`), `Board option ${method} must match the website.`);
}

assert.match(operations, /function ops_wc_payment_method\(array \$order\): string/);
assert.match(operations, /function ops_wc_payment_allocations\(array \$order\): array/);
assert.match(operations, /function ops_replace_order_payment_allocations/);
assert.match(operations, /'dpo_paygate' => 'dpo'/);
assert.doesNotMatch(action, /\['Split Payment',/);
assert.match(action, /unset\(\$saved\['payment_method'\]\)/);
assert.match(action, /CASE WHEN VALUES\(payment_method\) <> '' THEN VALUES\(payment_method\) ELSE payment_method END/);
assert.match(manualSync, /ops_wc_payment_method\(\$order\)/);
assert.match(board, /function syncPaymentFilterOptions\(\)/);
assert.match(board, /ordersCache\.forEach\(\(order\) =>/);
assert.match(board, /function renderPaymentBadge\(order\)/);
assert.match(board, /data-order-payment-edit/);

console.log('Orders payment synchronization checks passed.');
