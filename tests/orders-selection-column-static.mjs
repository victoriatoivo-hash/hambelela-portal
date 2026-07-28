import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');
const js = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');

assert.match(css, /--orders-selection-column-width:56px/);
assert.match(css, /--orders-col-select:var\(--orders-selection-column-width\)/);
assert.match(css, /var\(--orders-col-select,var\(--orders-selection-column-width,56px\)\)/);
assert.match(js, /var\(--orders-col-select,var\(--orders-selection-column-width,56px\)\)/);
assert.match(css, /\.orders-grid-cell \{[^}]*border-right:1px solid #ede3d8/s);

console.log('Orders selection column width checks passed.');
