import assert from 'node:assert/strict';
import fs from 'node:fs';

const css = fs.readFileSync('assets/css/accounts.css', 'utf8');
const js = fs.readFileSync('assets/js/input-vat.js', 'utf8');

assert.match(css, /\.accounts-dialog\.input-vat-purchase-dialog\{[^}]*height:auto;[^}]*max-height:calc\(100dvh - 32px\)/, 'Desktop purchase dialog must be content-sized up to the dynamic viewport limit.');
assert.match(css, /\.input-vat-purchase-dialog>form\{display:flex;[^}]*max-height:calc\(100dvh - 32px\);[^}]*flex-direction:column/, 'The purchase form must use a content-sized header/body/footer column.');
assert.doesNotMatch(css, /\.input-vat-purchase-dialog>form\{height:min\(82vh,760px\)/, 'The old forced desktop form height must not return.');
assert.match(css, /\.input-vat-purchase-dialog \.accounts-form-grid\{[^}]*flex:0 1 auto;[^}]*min-height:0;[^}]*overflow-y:auto/, 'The form body must be the single desktop overflow owner and shrink only when necessary.');
assert.match(css, /@media\(max-width:600px\)\{#inputVatPage \.accounts-dialog\.input-vat-purchase-dialog\{[^}]*height:100dvh;[^}]*max-height:100dvh/, 'The mobile override must equal the desktop selector specificity and use the full dynamic viewport.');
assert.match(css, /@media\(max-width:600px\)[\s\S]*\.input-vat-purchase-dialog \.accounts-form-grid\{flex:1 1 auto;/, 'The mobile form body must receive the remaining viewport height.');
assert.match(css, /\.input-vat-purchase-dialog>form>footer\{position:sticky;bottom:0;/, 'The footer must remain reachable when the form body scrolls.');
assert.match(js, /function openPurchaseModal\(\)[\s\S]*dialog\.querySelector\('\.accounts-form-grid'\)[\s\S]*modalBody\.scrollTop = 0;[\s\S]*dialog\.showModal\(\)/, 'Every purchase-modal open must reset the single form-body scroller to the top.');

console.log('Input VAT purchase modal responsive layout checks passed.');
