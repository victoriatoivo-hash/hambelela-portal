import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const portal = readFileSync(new URL('../assets/js/portal.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');

assert.match(board, /bar\.dataset\.ordersBulkActions = '';/, 'The selected-orders toolbar needs a stable data hook.');
assert.match(portal, /mirror\.dataset\.ordersBottomSlider = '';/, 'The shared bottom slider needs a stable data hook.');

const fixedBottomSelector = portal.match(/const fixedBottomSelector = \[([\s\S]*?)\]\.join\(','\);/)?.[1] || '';
assert.doesNotMatch(fixedBottomSelector, /orders-packing-bulk-bar/, 'Selecting orders must not move the bottom slider.');

assert.match(
  css,
  /\.orders-packing-bulk-bar\s*\{[^}]*position:fixed;[^}]*bottom:calc\(var\(--portal-sticky-scrollbar-height, 16px\) \+ 8px\);/,
  'The selected-orders toolbar must overlay the page eight pixels above the fixed slider.'
);

console.log('orders bulk toolbar / bottom slider layout checks passed');
