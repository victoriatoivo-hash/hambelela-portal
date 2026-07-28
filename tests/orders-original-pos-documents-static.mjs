import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const endpoint = readFileSync(new URL('../apps/operations/orders-board-document.php', import.meta.url), 'utf8');
const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');

assert.match(endpoint, /\/wp-json\/hpos\/v1\/documents\//);
assert.match(endpoint, /consumer_key/);
assert.match(endpoint, /consumer_secret/);
assert.match(endpoint, /application\/pdf/);
assert.match(endpoint, /text\/html/);
assert.match(endpoint, /X-Portal-Original-Document/);
assert.match(endpoint, /current_role_key\(\)==='guest'/);
assert.match(endpoint, /woo_order_id/);
assert.match(endpoint, /source_order_not_found/);
assert.doesNotMatch(endpoint, /receipt_document_id|invoice_document_id|receipt_pdf_path|invoice_pdf_path/);
assert.match(board, /Checking POS document/);
assert.match(board, /Source order not found/);
assert.match(board, /Ready/);
assert.match(board, /Unable to load document\. Try again\./);
assert.match(board, /action === 'download' \? 'application\/pdf' : 'text\/html'/);
assert.doesNotMatch(board, /Not generated in POS\./);

console.log('Shared POS document proxy checks passed.');
