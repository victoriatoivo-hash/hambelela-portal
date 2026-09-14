import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('apps/accounts/sage-reconciliation.php', 'utf8');
const script = fs.readFileSync('assets/js/sage-reconciliation.js', 'utf8');
const css = fs.readFileSync('assets/css/accounts-sage-theme.css', 'utf8');

assert.match(page, /id="inputVatPage"[^>]*input-vat-page sage-page/);
assert.match(page, /accounts-sage-theme\.css/);
assert.match(page, /data-year data-portal-custom-select data-portal-select-variant="input-vat"/);
assert.equal((page.match(/input-vat-purchase-dialog sage-dialog/g) || []).length, 3);
assert.ok((page.match(/data-portal-select-variant="input-vat"/g) || []).length >= 4);

assert.match(script, /function themedNav\(/);
assert.match(script, /active\?`<small>\$\{esc\(status\)\}<\/small>`:''/);
assert.match(script, /aria-selected=/);
assert.match(script, /i===active\?'is-active'/);
assert.match(script, /select:not\(\[data-portal-custom-select\]\)/);

assert.match(css, /height:34px;\s*min-height:34px;\s*padding:0 11px;\s*color:#3d3d3d;\s*font:600 12px/);
assert.match(css, /input-vat-year-select \.portal-custom-select-trigger[\s\S]*height:32px/);
assert.match(css, /sage-section-heading h2[\s\S]*font:600 14px/);
assert.match(css, /td\.money[\s\S]*font:400 12px/);
assert.match(css, /@media\(max-width:1024px\)/);
assert.match(css, /@media\(max-width:600px\)/);
assert.match(css, /prefers-reduced-motion:reduce/);

console.log('Sage/Input VAT theme alignment static contracts passed.');
