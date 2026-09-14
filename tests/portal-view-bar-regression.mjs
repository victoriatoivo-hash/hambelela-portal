import fs from 'node:fs';
import assert from 'node:assert/strict';

const js = fs.readFileSync(new URL('../assets/js/portal-view-bar.js', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal-view-bar.css', import.meta.url), 'utf8');
const footer = fs.readFileSync(new URL('../shared/footer.php', import.meta.url), 'utf8');
const presence = fs.readFileSync(new URL('../assets/js/portal-presence.js', import.meta.url), 'utf8');

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
assert.match(js, /source\.style\.setProperty\('display', 'none', 'important'\)/, 'Legacy filter layout rules must not keep the old filter visible');
assert.match(js, /movedForm\.style\.setProperty\('display', 'none', 'important'\)/, 'Closed filter popovers must restore a hidden source');
assert.match(js, /portal-panel-open/, 'All supported drawers must expose one shared open state');
assert.match(css, /body\.portal-panel-open \.portal-header-status/, 'Header status must be covered for every open drawer');
assert.match(footer, /interactive4/, 'footer pages must keep the shared filter assets');
assert.match(presence, /portal-view-bar\.css\?v=shared5/, 'legacy pages must load the shared filter styles');
assert.match(presence, /portal-view-bar\.js\?v=shared5/, 'legacy pages must load the shared filter controller');
assert.match(presence, /!document\.querySelector\('script\[src\*=/, 'the fallback loader must not duplicate assets');
console.log('portal view bar regression contract passed');
