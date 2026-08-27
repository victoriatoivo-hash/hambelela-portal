import fs from 'node:fs';
import assert from 'node:assert/strict';

const js = fs.readFileSync(new URL('../assets/js/input-vat.js', import.meta.url), 'utf8');
const api = fs.readFileSync(new URL('../apps/accounts/input-vat-api.php', import.meta.url), 'utf8');
const shared = fs.readFileSync(new URL('../shared/accounts-input-vat.php', import.meta.url), 'utf8');
const page = fs.readFileSync(new URL('../apps/accounts/input-vat.php', import.meta.url), 'utf8');

assert.match(page, /value="mixed" data-mixed-rate-option/);
assert.match(page, /name="zero_rated_amount"/);
assert.match(page, /data-mixed-standard/);
assert.match(js, /Zero-rated amount cannot be greater than the invoice total\./);
assert.match(js, /zero_rated_amount: row\.zero_rated_amount/);
assert.match(js, /input-vat-treatment-badge/);
assert.match(api, /\['standard','zero_rated','no_vat','mixed','manual_vat','review_required'\]/);
assert.match(api, /standard_rated_incl_vat/);
assert.match(api, /zero_rated_value/);
assert.match(shared, /standard_rated_excl_vat/);
assert.match(shared, /zero_rated_amount/);

console.log('Input VAT mixed UI/persistence static tests passed.');
