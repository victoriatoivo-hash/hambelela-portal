import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const client = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');
const action = readFileSync(new URL('../apps/operations/orders-board-action.php', import.meta.url), 'utf8');

// One semantic editor maps the visible Task and Mobile fields to their existing
// operational sources without exposing arbitrary database column names.
assert.match(client, /const ordersInlineFieldDefinitions = \{[\s\S]*task_name: \{ sourceField:'customer_name', apiField:'task_name'/);
assert.match(client, /mobile_number: \{ sourceField:'customer_contact', apiField:'mobile_number'[\s\S]*inputType:'tel', inputMode:'tel'/);
assert.match(action, /'task_name' => 'customer_name'/);
assert.match(action, /'mobile_number' => 'customer_contact'/);
assert.match(action, /if \(!portal_user_can_access_feature\('orders'\)\)/);

// Task remains in the original cell, preserves the order-number prefix, and
// only sends the editable customer portion.
assert.match(client, /data-editable-order-field="task_name"[\s\S]*buildOrderTaskName\(order\)/);
assert.match(client, /definition\.preserveOrderNumber[\s\S]*orders-inline-prefix[\s\S]*getTaskOrderNumber/);
assert.match(client, /data-editable-order-field="mobile_number"/);

// Autosave is debounced, while blur/Enter/Escape retain their explicit rules.
assert.match(client, /debounceTimer = window\.setTimeout\(\(\) => commit\(\)\.catch\(showError\), 600\)/);
assert.match(client, /control\.addEventListener\('blur', \(\) => commit\(\{ exit:true \}\)/);
assert.match(client, /event\.key === 'Enter' \|\| event\.key === 'Tab'/);
assert.match(client, /event\.key === 'Escape'[\s\S]*cancel\(\)/);
assert.match(client, /Couldn’t save \$\{definition\.label\}\. Please try again\./);

// Refreshes cannot replace an active or just-confirmed inline edit.
assert.match(client, /const ordersInlineEditSessions = new Map\(\)/);
assert.match(client, /const ordersInlineConfirmedUntil = new Map\(\)/);
assert.match(client, /preserveInlineFieldsFromCurrent\(incomingOrder, currentById\)/);
assert.match(client, /customer_name: current\.customer_name,[\s\S]*customer_contact: current\.customer_contact/);

// Existing orders retain portal corrections during WooCommerce refreshes;
// Woo values remain present in the INSERT path for newly imported orders.
assert.match(action, /INSERT INTO ops_orders \([\s\S]*customer_name, customer_contact/);
assert.doesNotMatch(action, /customer_name = VALUES\(customer_name\)/);
assert.doesNotMatch(action, /customer_contact = VALUES\(customer_contact\)/);

assert.match(styles, /\.orders-inline-input\{[\s\S]*height:32px[\s\S]*font:400 12px\/1\.35 'Jost'/);
assert.match(styles, /\.board-mobile-card \.orders-inline-input\{height:42px[\s\S]*font-size:13\.5px/);
assert.match(styles, /@media \(prefers-reduced-motion:reduce\)/);

console.log('Orders inline editing persistence safeguards verified.');
