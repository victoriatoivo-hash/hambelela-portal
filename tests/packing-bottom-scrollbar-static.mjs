import assert from 'node:assert/strict';
import fs from 'node:fs';
const css=fs.readFileSync(new URL('../assets/css/portal-sticky-scrollbar.css',import.meta.url),'utf8');
const js=fs.readFileSync(new URL('../assets/js/portal.js',import.meta.url),'utf8');
assert.match(css,/height: 12px !important/);assert.match(css,/height:5px/);assert.match(css,/height: 9px/);assert.match(css,/160ms/);
assert.match(css,/min-width:42px/);assert.match(css,/pointer-events: auto/);assert.match(css,/touch-action: pan-x/);assert.doesNotMatch(css,/packing-bottom-scrollbar\s*\{[^}]*display:\s*flex/);
assert.match(js,/dataset\.expandBound/);assert.match(js,/is-scrollbar-active/);assert.match(js,/pointercancel/);assert.match(js,/syncMirrorFromSource/);assert.match(js,/syncSourceFromMirror/);assert.match(js,/Math\.max\(source\.scrollWidth, source\.clientWidth\)/);
console.log('Packing bottom scrollbar expansion checks passed.');
