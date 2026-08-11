import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const page = await readFile(new URL('../apps/cost-manager/workbook.php', import.meta.url), 'utf8');
const client = await readFile(new URL('../assets/js/cost-workbook.js', import.meta.url), 'utf8');
const css = await readFile(new URL('../assets/css/cost-workbook-invoice.css', import.meta.url), 'utf8');

assert.match(page, /id="reviewExtractionNotice"[^>]*role="status"[^>]*hidden/u, 'manual-review notice starts hidden until invoice data loads');
assert.match(page, /No invoice lines were extracted from this file\./u, 'zero extraction is stated explicitly');
assert.match(page, /Manual line entry is required before the invoice can be reviewed or approved\./u, 'the owner receives the required next step');
assert.match(client, /hasExtractedLines=d\.lines\.length>0/u, 'the notice follows the actual saved line count');
assert.match(client, /\$\('#reviewExtractionNotice'\)\.hidden=hasExtractedLines/u, 'zero-line drafts display the notice and populated drafts hide it');
assert.match(client, /hasExtractedLines\?d\.lines\.forEach\(addLine\):addLine\(\)/u, 'the existing blank manual-entry row fallback is preserved');
assert.equal((client.match(/uploadForm\.addEventListener\('submit'/gu) || []).length, 1, 'upload behavior is not duplicated or redesigned');
assert.match(page, /cost-workbook\.js\?v=7/u, 'the corrected script has a fresh cache version');
assert.match(page, /cost-workbook-invoice\.css\?v=3/u, 'the corrected notice CSS has a fresh cache version');
assert.match(css, /#reviewDialog \.cw-manual-review-notice/u, 'notice styling is exclusive to the Cost Workbook review dialog');
assert.doesNotMatch(css, /(^|\n)(?:body|\.modal|\.dialog|\[role="dialog"\])\s/u, 'notice styling does not affect other dialogs or modules');

console.log('Cost Workbook manual-review notice checks passed.');
