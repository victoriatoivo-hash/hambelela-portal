import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

assert.match(page, /class="task-section-tabs task-board-navigation"[^>]+data-task-view-tabs/);
assert.match(page, /\$tabIcons = \['active' => 'clipboard-list', 'completed' => 'check-circle-2', 'history' => 'history'\]/);
assert.match(page, /class="task-section-tab<\?= \$tabActive \? ' is-active' : '' \?>"/);
assert.match(page, /aria-selected="<\?= \$tabActive \? 'true' : 'false' \?>"/);
assert.doesNotMatch(page, /class="dtb-tab(?:\s|")/);
assert.match(css, /\.task-section-tab\{[^}]*border:0;[^}]*border-radius:0;[^}]*box-shadow:none/);
assert.match(css, /\.task-section-tab::after\{[^}]*height:1px;[^}]*background:#AB3619;[^}]*scaleX\(0\)/);
assert.match(css, /\.task-section-tab\.is-active::after[^}]*scaleX\(1\)/);
assert.match(css, /\.task-section-tab:hover \.task-section-tab__icon\{transform:translateY\(-1px\)\}/);
assert.match(css, /\.task-section-tab\{[^}]*box-shadow:none/);
assert.match(css, /@media \(max-width:600px\).*\.task-section-tab/s);

console.log('Task Management main tab presentation checks passed.');
