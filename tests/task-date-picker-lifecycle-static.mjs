import assert from 'node:assert/strict';
import fs from 'node:fs';

const tasks=fs.readFileSync(new URL('../apps/operations/checklists.php',import.meta.url),'utf8');
const picker=fs.readFileSync(new URL('../assets/js/portal-date-picker.js',import.meta.url),'utf8');

assert.match(picker,/const controls = new WeakMap\(\)/);
assert.equal((picker.match(/popup\.addEventListener\('click', handlePopupClick\)/g)||[]).length,1,'the picker uses one delegated singleton popup');
assert.match(picker,/activeBelongsToRoot/);
assert.match(picker,/close\(restoreFocus\)/,'scoped cleanup must close without restoring focus unless explicitly requested');
assert.match(picker,/popup\.remove\(\);\s*popup = null;/,'the body-level popup must be unmounted on modal cleanup');
assert.match(tasks,/window\.PortalDatePicker\?\.cleanup\?\.\(form, \{ restoreFocus: false, removePopup: true \}\)/);
assert.match(tasks,/taskInstructionsLayer\?\.close\(false\)/);
assert.match(tasks,/assigneeMenu\.menu\.hidden = true/);
assert.match(tasks,/document\.body\.classList\.remove\('task-instructions-expanded'\)/);
assert.match(tasks,/document\.body\.classList\.remove\('task-panel-open', 'portal-panel-open'\)/);
assert.match(tasks,/taskModeInputs\.forEach[\s\S]*PortalDatePicker\?\.cleanup/,'switching task type must close an active picker');
assert.match(tasks,/const loadTemplate = async[\s\S]*PortalDatePicker\?\.cleanup/,'loading a template must close an active picker');
assert.match(tasks,/tabs\.addEventListener\('click'[\s\S]*taskCreateLifecycle\?\.cleanup/,'switching task views must clean the Create Task overlay');

console.log('Task date-picker lifecycle checks passed.');
