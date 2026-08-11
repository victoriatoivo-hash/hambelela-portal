import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('apps/operations/system-issues.php', 'utf8');
const css = fs.readFileSync('assets/css/portal-responsive.css', 'utf8');
const js = fs.readFileSync('assets/js/portal-responsive.js', 'utf8');
const footer = fs.readFileSync('shared/footer.php', 'utf8');
const hr = fs.readFileSync('apps/hr-portal/includes/styles.css', 'utf8');

assert.match(page, /has-selected-issue/, 'System Issues must expose its selected mobile state.');
assert.match(page, /sil-mobile-back[\s\S]*Back to Issues/, 'Mobile issue detail must provide a list return action.');
assert.match(css, /has-selected-issue \.system-issues-list[\s\S]*display: none/, 'Selected mobile issue must use a single detail pane.');
assert.match(css, /system-issue-dialog[\s\S]*height: 100dvh/, 'The report dialog must fit the phone viewport.');
assert.match(css, /system-issue-dialog footer[\s\S]*position: sticky/, 'Long report forms must keep actions reachable.');
assert.match(js, /scrollWidth > scroller\.clientWidth/, 'Scroll hints must only appear for overflowing content.');
assert.match(js, /scrollLeft > 8/, 'Scroll hints must dismiss after interaction.');
assert.match(footer, /assets\/js\/portal-responsive\.js/, 'The responsive interaction asset must load portal-wide.');
assert.match(hr, /Shared HR phone contract/, 'HR must retain a shared mobile contract.');
assert.match(hr, /modal-actions[\s\S]*min-height:44px/, 'HR modal actions must remain touch accessible.');

console.log('Portal responsive interaction checks passed.');
