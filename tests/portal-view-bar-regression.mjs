import fs from 'node:fs';
import assert from 'node:assert/strict';

const js = fs.readFileSync(new URL('../assets/js/portal-view-bar.js', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal-view-bar.css', import.meta.url), 'utf8');

assert.match(js, /const formAnchor = document\.createComment/);
assert.match(js, /form\.before\(formAnchor\)/);
assert.match(js, /formAnchor\.parentNode\.insertBefore\(movedForm, formAnchor\.nextSibling\)/);
assert.doesNotMatch(js, /source\.before\(home\)/);
assert.doesNotMatch(js, /form\.requestSubmit\?\.\(\);\s*\}\);\s*\}\s*else if \(action === 'group'/);
assert.match(js, /pointerdown/);
assert.match(js, /nodes: \[popover\]/);
assert.match(css, /\.portal-view-bar-source\{display:none!important\}/);
assert.match(css, /justify-content:flex-start!important/);
assert.match(css, /\.portal-view-bar__search svg\{[^}]*visibility:visible/);
console.log('portal view bar regression contract passed');
