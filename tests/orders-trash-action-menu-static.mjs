import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');

assert.match(board, /data-orders-trash-menu-trigger/);
assert.match(board, /function openOrdersTrashMenu\(anchor, orderId, orderReference\)/);
assert.match(board, /data-orders-tools-action="restore-trash"/);
assert.match(board, /data-orders-tools-action="delete-forever"/);
assert.doesNotMatch(board, /class="orders-tools-button" data-orders-tools-action="restore-trash"/);
assert.match(board, /ordersTrashMenuTriggerId/);
assert.match(styles, /\.portal-row-actions__dots>span\{width:3px;height:3px/);
assert.match(styles, /\.portal-row-actions__menu\.orders-row-actions-menu/);

console.log('Orders Trash row action-menu checks passed.');
