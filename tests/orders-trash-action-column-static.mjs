import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');

assert.match(board, /orders-trash-grid orders-trash-grid--header[^>]*role="row"/);
assert.match(board, /role="columnheader">Action</);
assert.match(board, /portal-trash-action-cell orders-trash-actions orders-tools-record-actions/);
assert.match(board, /data-orders-tools-action="restore-trash"/);
assert.match(board, /data-orders-tools-action="delete-forever"/);
assert.match(css, /--orders-trash-columns:minmax\(138px,.9fr\) minmax\(145px,1fr\) minmax\(210px,auto\)/);
assert.match(css, /\.orders-trash-grid\{display:grid;grid-template-columns:var\(--orders-trash-columns\)/);
assert.match(css, /\.orders-trash-actions::before\{content:"Action"/);

console.log('Orders Trash action-column checks passed.');
