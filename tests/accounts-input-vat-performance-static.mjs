import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL('../' + path, import.meta.url), 'utf8');
const helper = read('shared/accounts-input-vat.php');
const api = read('apps/accounts/input-vat-api.php');
const client = read('assets/js/input-vat.js');

assert.match(helper, /input_vat_schema_version/);
assert.match(helper, /function accounts_purchase_payload\(array \$row, \?array \$attachments = null\)/);
assert.match(api, /purchase_id IN \('/);
assert.match(api, /purchase_date>=\? AND purchase_date<\?/);
assert.match(client, /monthlyResponseCache = new Map\(\)/);
assert.match(client, /monthlyCacheTtl = 30000/);
assert.match(client, /new AbortController\(\)/);
assert.match(client, /setTimeout\(\(\) => load\(\), 250\)/);

console.log('Input VAT targeted load and cache checks passed.');
