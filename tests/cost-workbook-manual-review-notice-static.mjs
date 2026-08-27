import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const page = await readFile(new URL('../shared/cost-workbook-sections.php', import.meta.url), 'utf8');
const client = await readFile(new URL('../assets/js/cost-workbook-pages.js', import.meta.url), 'utf8');
const css = await readFile(new URL('../assets/css/cost-workbook-invoice.css', import.meta.url), 'utf8');

assert.match(page, /id="reviewExtractionNotice"[^>]*role="status"[^>]*hidden/u, 'manual-review notice starts hidden until invoice data loads');
assert.match(page, /No invoice lines were extracted from this file\./u, 'zero extraction is stated explicitly');
assert.match(page, /Manual line entry is required before approval\./u, 'the owner receives the required next step');
assert.match(client, /d\.lines\.length\?d\.lines:\[\{\}\]/u, 'the notice follows the saved line count and preserves a blank fallback row');
assert.match(client, /reviewExtractionNotice'\)\.hidden=!!d\.lines\.length/u, 'zero-line drafts display the notice and populated drafts hide it');
assert.equal((client.match(/#uploadForm'\)\?\.addEventListener\('submit'/gu) || []).length, 1, 'upload behavior is bound once');
assert.match(css, /#reviewDialog \.cw-manual-review-notice/u, 'notice styling is exclusive to the Cost Workbook review dialog');
assert.doesNotMatch(css, /(^|\n)(?:body|\.modal|\.dialog|\[role="dialog"\])\s/u, 'notice styling does not affect other dialogs or modules');

console.log('Cost Workbook manual-review notice checks passed.');
