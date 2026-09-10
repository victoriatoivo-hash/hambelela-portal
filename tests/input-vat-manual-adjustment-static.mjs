import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL('../' + path, import.meta.url), 'utf8');
const api = read('apps/accounts/input-vat-api.php');
const js = read('assets/js/input-vat.js');
const page = read('apps/accounts/input-vat.php');
const livePage = read('apps/accounts/input-vat-live.php');

for (const template of [page, livePage]) {
  assert.match(template, /name="manual_override" type="checkbox" value="1"/);
  assert.match(template, /name="manual_vat"/);
  assert.match(template, /name="manual_exclusive"/);
  assert.match(template, /name="override_reason"/);
}

assert.match(api, /function iv_request_flag\(string \$key\): bool/);
assert.match(api, /\$manualOverride=iv_request_flag\('manual_override'\)/, 'manual VAT overrides must use checkbox-presence-safe parsing');
assert.match(api, /\$calc\['vat'\]=\$manualVat/);
assert.match(api, /\$calc\['exclusive'\]=\$manualExclusive/);

assert.match(js, /function resetPurchaseForm\(\)/);
assert.match(js, /delete form\.dataset\.purchaseId/);
assert.match(js, /form\.elements\.manual_override\.value = '1'/, 'editing a record must preserve the checkbox submission value');
assert.doesNotMatch(js, /manual_override: row\.manual_override \? '1' : ''/, 'edit population must not overwrite the checkbox value');
assert.match(js, /override_reason: row\.override_reason/, 'editing an adjustment must restore its saved reason');
assert.match(js, /form\.dataset\.purchaseId = String\(row\.id\)/, 'edit identity must survive proxy-control resets');
assert.match(js, /form\.dataset\.purchaseDate = String\(row\.purchase_date\)/, 'the original edit date must survive proxy-control resets');
assert.match(js, /control\.dispatchEvent\(new Event\('change', \{bubbles: true\}\)\)/, 'programmatic values must sync portal proxy controls');
assert.match(js, /payload\.id = form\.dataset\.purchaseId \|\| payload\.id \|\| ''/);
assert.match(js, /payload\.purchase_date = form\.elements\.purchase_date\.value \|\| form\.dataset\.purchaseDate/, 'save must submit a reliable purchase date');
assert.match(js, /payload\.manual_override = form\.elements\.manual_override\.checked \? '1' : '0'/, 'save must send an explicit manual override flag');
assert.match(js, /adjusted VAT amounts were not confirmed by the server/i, 'the client must not report success unless adjusted amounts are returned');

console.log('Input VAT manual adjustment regression checks passed.');
