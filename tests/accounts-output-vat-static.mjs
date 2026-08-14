import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL('../' + path, import.meta.url), 'utf8');
const helper = read('shared/accounts-output-vat.php');
const api = read('apps/accounts/output-vat-api.php');
const page = read('apps/accounts/output-vat.php');
const accounts = read('apps/accounts/index.php');
const client = read('assets/js/output-vat.js');

assert.match(helper, /function output_vat_require_owner/);
assert.match(helper, /if \(!accounts_is_owner\(\)\)/);
assert.match(page, /output_vat_require_owner\(\)/);
assert.match(api, /output_vat_require_owner\(\)/);
assert.match(helper, /wc_get\('orders'/);
assert.doesNotMatch(helper + api, /wc_put\s*\(/);
assert.doesNotMatch(helper + api, /CURLOPT_CUSTOMREQUEST\s*=>\s*'PUT'/);
assert.match(helper, /snapshot_hash/);
assert.match(helper, /historical_change/);
assert.match(api, /period_completed/);
assert.match(api, /Content-Disposition: attachment/);
assert.match(accounts, /href="output-vat\.php"/);
assert.match(page, /Read-only WooCommerce source/);
assert.match(page, /data-adjust-form/);
assert.match(client, /data-complete/);

console.log('Output VAT static security and workflow checks passed.');
