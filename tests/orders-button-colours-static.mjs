import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');
const js = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');

for (const colour of ['#D83A52','#FDAB3D','#C4C4C4','#784BD1','#DF2F4A','#007EB5','#009999','#008533','#0033A1','#FF9900','#00FFD7','#CD9282','#7F5347','#BCA58A']) {
  assert.ok(css.includes(colour), `Missing Orders colour ${colour}`);
}
assert.match(js, /normaliseOrderColourKey/);
assert.match(js, /data-order-status=/);
assert.match(js, /data-fulfilment-mode=/);
assert.match(js, /data-payment-method=.*normaliseOrderColourKey/);
assert.doesNotMatch(css, /data-payment-method=cash\]\{color:#fff;background:#721B1A/);

console.log('Orders semantic button colour checks passed.');
