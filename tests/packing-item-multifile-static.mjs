import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync(new URL('../apps/operations/consignments.php', import.meta.url), 'utf8');
const api = fs.readFileSync(new URL('../apps/operations/packing-item-files.php', import.meta.url), 'utf8');
const file = fs.readFileSync(new URL('../apps/operations/packing-item-file.php', import.meta.url), 'utf8');
const js = fs.readFileSync(new URL('../assets/js/packing-list.js', import.meta.url), 'utf8');

assert.doesNotMatch(page, /File storage will be linked in the next storage step/);
assert.match(page, /name="files\[\]"[^>]*multiple/);
assert.match(page, /Choose files/);
assert.match(page, /maximum 10 MB per file/);
assert.match(api, /count\(\$names\) > 10/);
assert.match(api, /new finfo\(FILEINFO_MIME_TYPE\)/);
assert.match(api, /bin2hex\(random_bytes\(24\)\)/);
assert.match(api, /packing_item_id = \?/);
assert.match(api, /hash_equals/);
assert.match(file, /a\.packing_item_id=\?/);
assert.match(js, /Array\.from\(fileList \|\| \[\]\)/);
assert.match(js, /runPackingUploadQueue\(uploadItemId, files, 2/);
assert.match(js, /data\.append\('file', file, file\.name\)/);
assert.doesNotMatch(js, /files\.forEach\(\(file\) => data\.append\('files\[\]'/);
assert.match(api, /isset\(\$_FILES\['file'\]\)/);
assert.match(js, /packingFileInput\.value = ''/);
assert.match(js, /data-retry-packing-file/);
assert.match(js, /activePackingFileItemId/);
assert.match(js, /new AbortController\(\)/);
assert.match(js, /requestVersion !== packingFileRequestVersion/);
assert.match(js, /data-packing-item-id/);

console.log('Packing item multi-file checks passed.');
