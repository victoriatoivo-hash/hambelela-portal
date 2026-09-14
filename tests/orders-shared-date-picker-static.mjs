import fs from 'node:fs';
import assert from 'node:assert/strict';

const sharedJs = fs.readFileSync('assets/js/portal-date-picker.js', 'utf8');
const sharedCss = fs.readFileSync('assets/css/portal-date-picker.css', 'utf8');
const ordersJs = fs.readFileSync('assets/js/orders-board.js', 'utf8');
const ordersCss = fs.readFileSync('assets/css/orders-board.css', 'utf8');

assert.match(sharedJs, /document\.body\.appendChild\(popup\)/, 'Shared popup must be appended to document.body.');
assert.match(sharedJs, /getBoundingClientRect\(\)/, 'Shared popup must use viewport positioning.');
assert.match(sharedCss, /z-index:\s*70000/, 'Shared popup must sit above slide-in panels and the Orders board.');
assert.match(sharedCss, /background(?:-color)?:\s*#FFFFFF/i, 'Shared popup must have an opaque white surface.');
assert.match(sharedCss, /isolation:\s*isolate/, 'Shared popup must isolate its stacking context.');
assert.match(sharedCss, /repeat\(7,\s*minmax\(0,\s*1fr\)\)/, 'Calendar must preserve seven equal columns.');
assert.match(sharedCss, /rgba\(168,\s*202,\s*25,\s*\.16\)/, 'Selected day must use the portal green theme.');
assert.match(ordersJs, /type="datetime-local" class="orders-date-trigger" data-orders-date-input/, 'Orders Date cells must use the shared date-time enhancer.');
assert.match(ordersJs, /updateOrdersField\(ids, 'created_at'/, 'Orders Date changes must use the existing AJAX endpoint.');
assert.doesNotMatch(ordersJs, /order-date-picker-popover|openOrderDatePicker|renderOrderDatePicker/, 'Legacy Orders calendar code must be removed.');
assert.doesNotMatch(ordersCss, /order-date-picker-popover/, 'Legacy Orders calendar CSS must be removed.');
assert.doesNotMatch(sharedCss, /!important/, 'The isolated shared date picker must not rely on !important.');

console.log('Orders shared date picker checks passed.');
