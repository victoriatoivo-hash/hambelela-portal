import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const endpoint = readFileSync(new URL('../apps/operations/orders-board-document.php', import.meta.url), 'utf8');
const helper = readFileSync(new URL('../apps/operations/lib/orders-documents.php', import.meta.url), 'utf8');
const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');

assert.doesNotMatch(endpoint, /text\/html/);
assert.match(endpoint, /Content-Type: application\/pdf/);
assert.match(endpoint, /X-Document-SHA256/);
assert.match(endpoint, /orders_document_cache/);
assert.match(endpoint, /current_role_key\(\) === 'guest'/);
assert.match(helper, /source_order_id/);
assert.match(helper, /document_id/);
assert.match(helper, /cached_checksum/);
assert.match(helper, /is_current/);
assert.match(board, /Checking POS document/);
assert.match(board, /Not generated in POS\./);
assert.match(board, /Unable to load document\. Try again\./);
assert.match(board, /headers:\{ Accept:'application\/pdf' \}/);
assert.doesNotMatch(board, /application\/pdf,text\/html/);

console.log('Original POS document proxy checks passed.');
