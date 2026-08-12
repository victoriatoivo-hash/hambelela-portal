import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const page = await readFile(new URL('../shared/cost-workbook-sections.php', import.meta.url), 'utf8');
const client = await readFile(new URL('../assets/js/cost-workbook-pages.js', import.meta.url), 'utf8');
const css = await readFile(new URL('../assets/css/cost-workbook-invoice.css', import.meta.url), 'utf8');

assert.match(page, /<dialog id="uploadDialog" class="cw-dialog cw-upload-dialog"/u, 'the namespace class is attached to the live dialog');
for (const className of ['cw-upload-dialog__panel','cw-upload-dialog__header','cw-upload-dialog__body','cw-upload-dialog__footer','cw-upload-dialog__submit','cw-upload-dialog__cancel']) {
  assert.match(page, new RegExp(`class="[^"]*${className}`,'u'), `${className} is present in the live modal`);
  assert.match(css, new RegExp(`#uploadDialog\\.cw-upload-dialog \\.${className}`,'u'), `${className} is directly scoped to the live modal`);
}
assert.doesNotMatch(css, /\.cw\s+#uploadDialog|\.cw\s+\.cw-upload-(?:selection|file|dialog)/u, 'required modal rules do not depend on a .cw ancestor');
assert.match(client, /cw-selected-file[^`]*cw-selected-file__details[^`]*cw-selected-file__name[^`]*cw-selected-file__meta[^`]*cw-selected-file__remove/u, 'each valid file renders one structured row with distinct details and removal control');
assert.match(css, /\.cw-selected-file \{[\s\S]*grid-template-columns:\s*minmax\(0, 1fr\) 40px/u, 'selected file rows keep details and removal controls aligned');
assert.match(css, /\.cw-selected-file__name \{[\s\S]*overflow-wrap:\s*anywhere/u, 'long filenames remain visually contained');
assert.match(css, /\.cw-selected-file__meta \{[\s\S]*font-size:\s*12px[\s\S]*line-height:\s*1\.4/u, 'file type and readable size remain visually distinct');
assert.match(page, /<div class="cw-upload-dialog__footer">[\s\S]*id="cwUploadSelectedFiles"[^>]*disabled/u, 'the persistent footer contains the one initially disabled submit button');
assert.match(client, /submit=\$\('#cwUploadSelectedFiles'\)/u, 'JavaScript targets the visible namespaced submit button');
assert.match(client, /submit\.disabled=uploading\|\|!selected\.length/u, 'a valid retained PDF or image enables the button while an empty selection disables it');
assert.match(css, /\.cw-upload-dialog__panel \{[\s\S]*max-height:\s*calc\(100dvh - 32px\)[\s\S]*overflow:\s*hidden/u, 'desktop modal height is bounded');
assert.match(css, /\.cw-upload-dialog__body \{[\s\S]*overflow-y:\s*auto[\s\S]*overflow-x:\s*hidden/u, 'the body scrolls internally without horizontal overflow');
assert.match(css, /@media \(max-width: 600px\)[\s\S]*max-height:\s*calc\(100dvh - 16px\)[\s\S]*flex-direction:\s*column-reverse/u, 'mobile height and reachable stacked footer are covered');
assert.equal((client.match(/\$\('#uploadForm'\)\?\.addEventListener\('submit'/gu) || []).length, 1, 'exactly one submit handler remains');
assert.match(client, /if\(uploading\|\|!selected\.length\)return/u, 'double submission remains blocked');
assert.doesNotMatch(client, /retainSelectedFiles[\s\S]{0,400}request\('upload'/u, 'rendering or selecting files never triggers upload');
assert.doesNotMatch(css, /(^|\n)(?:body|\.modal|\.dialog|\[role="dialog"\])\s/u, 'the correction does not style other dialogs or portal modules');

console.log('Cost Workbook upload dialog scope checks passed.');
