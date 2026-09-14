import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const orders = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const portal = readFileSync(new URL('../assets/js/portal.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');

assert.match(orders, /className = 'payment-editor orders-payment-modal'/);
assert.match(orders, /data-portal-custom-select data-portal-select-variant="payment-method"/);
assert.match(orders, /data-payment-option="\$\{esc\(normaliseOrderColourKey\(code\)\)\}"/);
assert.match(orders, /window\.PortalCustomSelect\?\.initialise\(rows\)/, 'Every dynamically rendered split row must initialize through the shared listbox.');
assert.match(orders, /payments\[index\]\.method=event\.target\.value;updateTotals\(\)/, 'Method changes must preserve the existing totals refresh.');

assert.match(portal, /popupElement\.dataset\.portalSelectVariant = selectVariant/);
assert.match(portal, /button\.dataset\.paymentOption = option\.dataset\.paymentOption/);
assert.match(portal, /customSelect\.dataset\.paymentValue = selectedOption\?\.dataset\.paymentOption/);
assert.match(portal, /Math\.min\(240, Math\.floor\(viewport\.height \* 0\.45\)\)/, 'Mobile payment menus must honor the 240px / 45vh cap in inline positioning.');

const expectedColours = {
  cash: '#C4C4C4',
  eft: '#784BD1',
  'fnb-ewallet': '#009999',
  easywallet: '#DF2F4A',
  'blue-wallet': '#0033A1',
  nedbank: '#008533',
  pay2cell: '#FF9900',
  paytoday: '#00FFD7',
  dpo: '#007EB5',
};

for (const [method, colour] of Object.entries(expectedColours)) {
  assert.match(css, new RegExp(`data-payment-option=\\"${method}\\"[^}]+${colour}`, 'i'), `${method} must use ${colour}.`);
}

assert.match(css, /payment-method-trigger[^}]+height:35px[^}]+color:#fff[^}]+background:var\(--payment-bg,#7F5347\)/);
assert.match(css, /data-payment-option[^}]+color:#fff[^}]+background:var\(--payment-bg,#7F5347\)/);
assert.doesNotMatch(css, /data-payment-option[^}]+opacity:\s*\.(?:[0-9]+)/, 'Payment options must not use translucent colours.');

console.log('Orders payment method custom-select checks passed');
