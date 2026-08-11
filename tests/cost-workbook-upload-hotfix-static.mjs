import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const page = await readFile(new URL('../apps/cost-manager/workbook.php', import.meta.url), 'utf8');
const client = await readFile(new URL('../assets/js/cost-workbook.js', import.meta.url), 'utf8');
const css = await readFile(new URL('../assets/css/cost-workbook-invoice.css', import.meta.url), 'utf8');

assert.match(page, /id="invoiceFiles"[^>]*name="invoice_files\[\]"[^>]*multiple[^>]*accept="\.pdf,\.jpg,\.jpeg,\.png,\.xlsx,\.csv"/u, 'the live input accepts the established invoice types');
assert.match(page, /id="uploadSelection"[^>]*aria-live="polite"/u, 'selected files have a visible live region');
assert.match(page, /id="uploadProgress"[^>]*role="status"/u, 'upload progress has a visible status region');
assert.match(page, /type="submit" disabled>Upload selected files/u, 'upload starts disabled');
assert.match(page, /cost-workbook\.js\?v=6/u, 'the corrected asset has a new cache version');
assert.match(page, /cost-workbook-invoice\.css\?v=2/u, 'the corrected modal CSS has a new cache version');

assert.match(client, /uploadInput\.addEventListener\('change'/u, 'the live input owns one explicit change handler');
assert.equal((client.match(/uploadInput\.addEventListener\('change'/gu) || []).length, 1, 'the change handler is bound exactly once');
assert.equal((client.match(/uploadForm\.addEventListener\('submit'/gu) || []).length, 1, 'the submit handler is bound exactly once');
assert.match(client, /selectedUploadFiles\.push\(file\)/u, 'valid PDF and image selections are retained');
assert.match(client, /renderUploadSelection\(rejections\)/u, 'valid and invalid selections render separately');
assert.match(client, /file\.type\|\|'Type unavailable'.*readableSize\(file\.size\)/u, 'each valid file displays type and readable size');
assert.match(client, /selectedUploadFiles\.splice/u, 'a selected file can be explicitly removed');
assert.match(client, /if\(!uploadDialog\.open\)uploadDialog\.showModal/u, 'reopening does not recreate the form or duplicate handlers');
assert.match(client, /if\(uploading\|\|selectedUploadFiles\.length===0\)return/u, 'double submission is blocked');
assert.match(client, /form\.append\('invoice_files\[\]'/u, 'each retained file uses the secure endpoint field name');
assert.match(client, /request\('upload',\{method:'POST',form\}\)/u, 'uploads use the established request helper and CSRF header');
assert.match(client, /uploadResults\.innerHTML=.*x\.ok\?'cw-success':'cw-error'/u, 'server results stay separate per file');
assert.match(client, /uploadProgress\.textContent='Upload failed\.'/u, 'request failures have visible feedback');
assert.match(client, /File must be 15 MB or smaller/u, 'oversized files are rejected visibly');
assert.match(client, /Unsupported file type/u, 'unsupported files are rejected visibly');
assert.doesNotMatch(client, /window\.(?:onchange|onsubmit)\s*=/u, 'the fix does not install global portal handlers');
assert.match(css, /#uploadDialog\.cw-upload-dialog \.cw-upload-selection/u, 'all new upload CSS remains scoped to the Cost Workbook dialog');
assert.doesNotMatch(css, /(^|\n)body\b/u, 'the hotfix does not add global body styles');

console.log('Cost Workbook upload hotfix checks passed.');
