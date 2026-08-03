import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const page = read('apps/operations/checklists.php');
const backend = read('apps/operations/task-templates.php');
const migration = read('operations-task-templates-migration.sql');
const css = read('assets/css/portal.css');

assert.match(page, /data-template-load-open/);
assert.match(page, /data-template-save/);
assert.match(page, /data-template-manage/);
assert.match(page, /name="source_template_id"/);
assert.match(page, /name="template_attachment_ids"/);
assert.match(page, /name="task_attachments\[\]"/);
assert.match(page, /checklist_copy_template_attachments_to_task/);
assert.match(page, /checklist_store_new_task_uploads/);
assert.match(page, /deadline, status,[\s\S]*'new'/, 'Created tasks must continue to begin as New.');
assert.match(backend, /user_has_role\('owner_admin'\)/, 'Template endpoints must require the owner role.');
assert.match(backend, /hash_equals\(\$csrfToken, \$submitted\)/, 'Template endpoints must validate CSRF.');
assert.match(backend, /beginTransaction\(\)/);
assert.match(backend, /ops_checklist_task_template_items/);
assert.match(backend, /ops_checklist_task_template_attachments/);
assert.match(backend, /employee_unavailable/);
assert.match(backend, /uploaded_by=\? AND removed_at IS NULL/, 'Existing-task templates may only copy owner-uploaded files.');
assert.match(migration, /CREATE TABLE IF NOT EXISTS ops_checklist_task_templates/);
assert.match(migration, /CREATE TABLE IF NOT EXISTS ops_checklist_task_template_items/);
assert.match(migration, /CREATE TABLE IF NOT EXISTS ops_checklist_task_template_attachments/);
assert.match(migration, /source_template_id/);
assert.match(css, /\.task-template-toolbar/);
assert.match(css, /@media\(max-width:479px\)/);

const scripts = [...page.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)].map((match) => match[1]);
for (const [index, source] of scripts.entries()) {
  const runnable = source
    .replace(/<\?=\s*json_encode\([^?]+\)\s*\?>/g, '"test-csrf-token"')
    .replace(/<\?[\s\S]*?\?>/g, '');
  assert.doesNotThrow(() => new Function(runnable), `Inline Task Management script ${index + 1} must parse`);
}

console.log('Task Template static checks passed.');
