import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const php = readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

assert.match(php, /function checklist_display_task_title\(string \$value\): string/);
assert.match(php, /preg_replace\('\/\^\\s\*\\\?\\s\*\/u'/);
assert.match(php, /preg_replace\('\/\\s\+\\\?\\s\*\$\/u'/);
assert.match(php, /preg_replace\('\/\\s\+\\\?\(\?=\\s\*<\\\/\(\?:p\|li\)>\)\/iu'/);
assert.match(php, /class="task-detail-panel task-details-panel task-detail-view"/);
assert.match(php, /checklist_display_task_title\(\(string\) \$task\['task_name'\]\)/);
assert.match(php, /data-lucide="copy-plus"/);
assert.match(php, /task-schedule-button task-schedule-button--primary/);
assert.match(php, /Repair|Release Now/);

assert.match(css, /Reopened Task detail — Output VAT-aligned, panel-scoped presentation/);
assert.match(css, /\.task-detail-view \.task-details-title\{[^}]*font:600 18px/);
assert.match(css, /\.task-detail-view \.task-field input[^}]*height:32px/);
assert.match(css, /\.task-detail-view \.task-btn\{[^}]*height:34px/);
assert.match(css, /\.task-detail-view \.task-field label[^}]*font:600 11px/);
assert.match(css, /@media\(max-width:430px\)/);

console.log('Reopened Task detail redesign static checks passed.');
