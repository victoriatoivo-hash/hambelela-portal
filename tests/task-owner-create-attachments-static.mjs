import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const endpoint = fs.readFileSync(new URL('../apps/operations/task-attachment.php', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

assert.match(page, /function checklist_store_attachment/);
assert.ok((page.match(/checklist_store_attachment\(/g) || []).length >= 3, 'Owner creation and employee uploads must share one storage function.');
assert.match(page, /function checklist_create_attachment_files/);
assert.match(page, /count\(\$files\) > 10/);
assert.match(page, /'video\/mp4' => 'mp4'/);
assert.match(page, /class="task-create-form checklist-create-form" method="post" enctype="multipart\/form-data"/);
assert.match(page, /name="task_attachments\[\]" multiple hidden data-create-task-file-input/);
assert.match(page, /Files for the employee/);
assert.match(page, /data-create-task-files-list/);
assert.match(page, /data-remove-create-task-file/);

assert.match(page, /\$createAttachmentFiles = checklist_create_attachment_files\(\)/);
assert.match(page, /foreach \(\$createAttachmentFiles as \$createAttachmentFile\)/);
assert.match(page, /attachment_count' => count\(\$createdAttachments\)/);
assert.match(page, /if \(\$taskDb->inTransaction\(\)\) \$taskDb->rollBack\(\)/);
assert.match(page, /uploads\/checklist-attachments/);
assert.match(page, /foreach \(\$createdAttachments as \$createdAttachment\)/);

assert.match(endpoint, /ops_task_scope_for_current_user/);
assert.match(endpoint, /mode === 'download'/);
assert.match(css, /\.task-create-attachments\s*\{/);
assert.match(css, /\.task-create-file\s*\{/);
assert.match(css, /\.task-create-attachments__heading \.task-files__add/);

console.log('Owner New Task secure attachment checks passed.');
