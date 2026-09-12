import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync('assets/css/task-essentials.css', 'utf8');
const js = readFileSync('assets/js/task-essentials.js', 'utf8');
for (const token of ['task-filter-shell', 'task-filter-toolbar', 'task-active-filters', 'task-filter-popover', 'task-chip-remove']) {
  assert(css.includes(token) && js.includes(token), token);
}
assert(js.includes('row.hidden=!values.length'));
assert(js.includes("more?.classList.toggle('has-filters',values.length>0)"));
assert(js.includes("trigger.setAttribute('aria-controls',id)"));
assert(js.includes("e.stopPropagation();pop.hidePopover();trigger.focus()"));
assert(js.includes("window.completedTaskWorkspaceController.requestWorkspace(target)"));
assert(css.includes('.task-filter-control:is(.is-active,[aria-expanded=true])'));
assert(css.includes('prefers-reduced-motion:reduce'));
assert(css.includes('stroke:currentColor!important'));
console.log('Compact filter structure, state styling, native query delegation and keyboard contracts passed.');
