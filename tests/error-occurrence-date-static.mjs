import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('apps/operations/errors.php', 'utf8');
const css = fs.readFileSync('assets/css/portal.css', 'utf8');
const tableMarkup = page.slice(page.indexOf("<?php foreach (['open' => 'Not Resolved Errors'"), page.indexOf('<aside class="error-log-panel'));

assert.match(page, /name="occurred_at" required type="hidden"/);
assert.match(page, /Africa\/Windhoek/);
assert.match(page, /cannot be in the future/);
assert.match(page, /occurred_on DATE NULL AFTER occurred_at/);
assert.match(page, /COALESCE\(\{\$alias\}\.occurred_on, DATE\(\{\$alias\}\.occurred_at\), DATE\(\{\$alias\}\.created_at\), DATE\(\{\$alias\}\.logged_at\)\)/);
assert.doesNotMatch(tableMarkup, /Estimated from|Calculated from|Inferred from/);
assert.doesNotMatch(tableMarkup, /error-date-source/);
assert.equal((tableMarkup.match(/class="error-board-date-cell"/g) || []).length, 4);
assert.match(css, /\.error-board-table-wrap\s*\{[^}]*overflow-x:\s*auto;/s);
assert.match(css, /\.error-board-table \.error-board-date-cell\s*\{[^}]*white-space:\s*nowrap;[^}]*overflow:\s*hidden;[^}]*text-overflow:\s*ellipsis;/s);

console.log('Error Log table dates render without provenance text or cell overflow.');
