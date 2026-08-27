import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync(new URL('../apps/accounts/import-vat.php', import.meta.url), 'utf8');
const js = fs.readFileSync(new URL('../assets/js/import-vat.js', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/accounts.css', import.meta.url), 'utf8');

assert.match(page, /namra-review-modal__header/);
assert.match(page, /namra-review-modal__table-wrap/);
assert.match(page, /Document \/ Reference/);
assert.match(page, /Due Date[\s\S]*Action Date/);
assert.match(page, /data-review-search/);
assert.match(page, /data-review-type/);
assert.match(page, /data-review-treatment/);

assert.match(js, /function renderReviewRows/);
assert.match(js, /shortDate/);
assert.match(js, /taxPeriodName/);
assert.match(js, /namra-status/);
assert.match(js, /data-label="Accounting Treatment"/);
assert.match(js, /data-save-row/);
assert.match(js, /is-refreshing/);

assert.match(css, /width:min\(1480px,calc\(100vw - 48px\)\)/);
assert.match(css, /height:min\(900px,calc\(100dvh - 40px\)\)/);
assert.match(css, /#inputVatPage\.import-vat-page \.accounts-dialog\.import-vat-review-dialog\{width:min\(1480px/);
assert.match(css, /#inputVatPage\.import-vat-page \.import-vat-review-toolbar input[\s\S]*min-height:32px/);
assert.match(css, /@media\(max-width:600px\)[\s\S]*\.namra-review-table td input[\s\S]*box-sizing:border-box/);
assert.match(css, /\.namra-review-modal__table-wrap\{flex:1 1 auto;min-height:0;overflow:auto/);
assert.match(css, /\.namra-review-table thead th\{position:sticky/);
assert.match(css, /@media\(max-width:600px\)[\s\S]*\.namra-review-table thead\{display:none\}/);
assert.match(css, /accounts-hero-copy>p:nth-of-type\(2\)\{display:none\}/);
assert.match(css, /import-vat-refresh-spin/);
assert.match(css, /rgb\(171 54 25 \/ 24%\)/);
assert.match(css, /portal-button\[data-add\].*background:#f07420/);
assert.match(css, /accounts-table-card thead th\{height:34px/);
assert.match(css, /accounts-toolbar \.portal-custom-select-trigger\{box-sizing:border-box;width:100%;height:32px/);
assert.match(css, /import-vat-statement-history>header \[data-refresh-history\]\{border-color:#721b1a;background:#721b1a;color:#fff\}/);
assert.match(css, /import-vat-statement-dialog>form>header \.eyebrow\{color:#ab3619;font-size:12px;font-weight:800/);
assert.match(css, /import-vat-statement-dialog>form>header h2\{color:#721b1a;font-size:14px;font-weight:600/);
assert.match(css, /import-vat-statement-dialog \[name="statement_period"\]\{height:32px;min-height:32px;font-size:12px;font-weight:400/);
assert.match(css, /import-vat-statement-dialog \.import-vat-statement-steps span\{height:32px;min-height:32px/);
assert.match(css, /import-vat-statement-dialog \.import-vat-statement-drop svg\{width:20px;height:20px/);
assert.match(css, /import-vat-statement-dialog>form>p\.output-vat-help\{font-family:Figtree,system-ui,sans-serif;font-size:10px;line-height:15px/);
assert.match(css, /import-vat-statement-dialog>form>footer \.portal-button--primary svg\{width:18px;height:18px/);
assert.doesNotMatch(css.slice(css.indexOf('.import-vat-page .import-vat-review-dialog')), /#007bff|#0d6efd|dodgerblue/i);

console.log('Import VAT NamRA review UI static checks passed');
