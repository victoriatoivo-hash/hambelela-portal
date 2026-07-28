import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const tasks = read('apps/operations/checklists.php');
const picker = read('assets/js/portal-date-picker.js');
const portalCss = read('assets/css/portal.css');

const createForm = tasks.match(/<form class="task-create-form[\s\S]*?<\/form>/)?.[0] || '';
assert.match(createForm, /Due date and time/);
assert.match(createForm, /name="due_at"[^>]*data-task-due-value[^>]*required/);
assert.match(createForm, /data-enable-time="true"/);
assert.doesNotMatch(createForm, /data-task-due-date|data-task-due-time|type="date"|type="time"/);
assert.equal((createForm.match(/name="due_at"/g) || []).length, 1, 'New Task must submit exactly one due_at value');
assert.match(tasks, /function checklist_create_due_at\(array \$request\): string/);
assert.match(tasks, /new DateTimeZone\('Africa\/Windhoek'\)/);
assert.match(tasks, /This time has already passed\. Select a future time\./);
assert.match(tasks, /\$deadline = checklist_create_due_at\(\$_POST\)/);
assert.match(tasks, /const validateDueAt = \(\) =>/);
assert.doesNotMatch(tasks, /Assign the task anyway\?/);
assert.match(picker, /data-time-hour/);
assert.match(picker, /data-time-minute/);
assert.match(picker, /data-time-meridiem/);
assert.match(picker, /data-portal-date-now/);
assert.match(picker, /data-portal-date-clear/);
assert.match(picker, /data-portal-date-cancel/);
assert.match(picker, /data-portal-date-apply/);
assert.equal((picker.match(/popup\.addEventListener\('click', handlePopupClick\)/g) || []).length, 1, 'The shared picker must retain one delegated popup click listener');
assert.match(portalCss, /task-form-grid__row--assignment \{[^}]*minmax\(180px,\.8fr\)[^}]*minmax\(240px,1\.2fr\)/);
assert.match(portalCss, /@media \(max-width:700px\)[\s\S]*task-form-grid__row--assignment \{ grid-template-columns:minmax\(0,1fr\)/);

console.log('Task combined due date-time checks passed.');
