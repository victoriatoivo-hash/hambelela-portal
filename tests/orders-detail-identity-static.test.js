const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const page = fs.readFileSync(path.join(root, 'apps/operations/orders-board.php'), 'utf8');
const script = fs.readFileSync(path.join(root, 'assets/js/orders-board.js'), 'utf8');

test('order drawer keeps order number and customer in separate persistent fields', () => {
  assert.match(page, /id="panel-order-title"/);
  assert.match(page, /id="panel-order-customer"/);
  assert.match(page, />Order details</);
  assert.match(script, /function renderOrderPanelIdentity\(order\)/);
  assert.match(script, /panelTitle\.textContent = identity\.number/);
  assert.match(script, /panelCustomer\.textContent = identity\.customer/);
});

test('both open and live-sync paths render the same canonical identity', () => {
  const calls = script.match(/renderOrderPanelIdentity\(currentOrder\)/g) || [];
  assert.equal(calls.length, 2);
  assert.doesNotMatch(script, /panelMeta\.textContent = \[currentOrder\.customer_name/);
});

test('order identity comes from API order_number with a record id fallback', () => {
  assert.match(script, /order\?\.order_number/);
  assert.match(script, /`Order #\$\{order\.id\}`/);
  assert.match(script, /customer_name/);
});
