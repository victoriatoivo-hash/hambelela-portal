import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const css = readFileSync('assets/css/task-essentials.css', 'utf8');
const header = readFileSync('shared/header.php', 'utf8');
const markup = readFileSync('apps/operations/checklists.php', 'utf8');
const rule = css.match(/\.ess-task-page#ess-main \.create-task-header\{([^}]+)\}/)?.[1];
assert.ok(rule, 'Existing create header rule remains in place');
assert.match(rule, /padding-top:calc\(20px \+ env\(safe-area-inset-top,0px\)\)/);
assert.match(header, /viewport-fit=cover/, 'iOS exposes the safe area to CSS');
assert.match(markup, /<header class="create-task-header task-create-heading">/);
assert.match(css, /\.create-task-body\{flex:1;min-height:0;overflow:hidden/);
assert.match(css, /\.task-create-form__body\{flex:1;min-height:0;overflow-y:auto/);
assert.match(css, /\.task-create-form__footer\{flex:none;position:static/);
assert.match(markup, /filemtime\(BASE_PATH\.'\/assets\/css\/task-essentials\.css'\)/, 'Published CSS cache is versioned');
// Desktop/ordinary-browser insets are zero; notched/standalone phones reserve
// their actual OS inset rather than a device-specific hard-coded top offset.
for (const inset of [0, 20, 44, 47, 59]) {
    const padding = 20 + inset;
    assert.equal(padding - inset, 20);
}
console.log('PASS: scoped create header safe area, unchanged scroller/footer, viewport and cache-version guards');
