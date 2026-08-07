import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../assets/js/portal-date-picker.js', import.meta.url), 'utf8');
assert.match(source, /event\.composedPath/);
assert.match(source, /path\.includes\(popup\)/);
assert.match(source, /data-portal-date-apply/);
assert.match(source, /control\.target\.value = next/);
assert.match(source, /closest\('dialog\[open\]'\)/, 'a picker opened from a modal dialog must join that dialog top layer');
assert.match(source, /datePopup\.parentElement !== host/, 'the shared popup must move between dialog and page hosts safely');
console.log('Portal date picker static checks passed.');
