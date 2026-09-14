import fs from 'node:fs';
import assert from 'node:assert/strict';

const php = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

assert.match(php, /document\.body\.appendChild\(modal\)/, 'expanded editor must be portalled to the body overlay root');
assert.match(php, /panel\.inert = inactive/, 'parent task modal must be inert while the child editor is open');
assert.match(php, /event\.key === 'Escape'/, 'Escape must be handled by the child editor');
assert.match(php, /event\.key !== 'Tab'/, 'child editor must trap Tab focus');
assert.match(php, /taskInstructionsLayer\?\.open\(createExpandButton\)/, 'create editor must use the canonical child layer');
assert.match(php, /taskInstructionsLayer\?\.open\(editExpandButton\)/, 'edit editor must use the canonical child layer');
assert.match(css, /\.task-instructions-modal\{[^}]*z-index:60050/, 'child editor must sit above task action and confirmation layers');
assert.match(css, /max-height:calc\(100dvh - 48px\)/, 'desktop child editor must fit the viewport');
assert.match(css, /@media\(prefers-reduced-motion:reduce\)/, 'child modal animation must respect reduced motion');
assert.match(css, /@media\(max-width:640px\).*?width:100vw;height:100dvh/s, 'mobile child editor must occupy the viewport');

console.log('task-instructions-modal-stacking-static: ok');
