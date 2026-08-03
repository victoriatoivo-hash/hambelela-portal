import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const endpoint = fs.readFileSync(new URL('../apps/operations/task-attachment.php', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

assert.doesNotMatch(page, /task-panel-tabs|data-task-panel-jump|data-task-proof-upload|initialiseTaskProofUpload/);
assert.match(page, /name="completion_note" required minlength="5" aria-required="true"/);
assert.match(page, /function checklist_require_progress_note/);
assert.ok((page.match(/checklist_require_progress_note\(/g) || []).length >= 3, 'status and progress endpoints must validate notes');
assert.match(page, /CREATE TABLE IF NOT EXISTS ops_checklist_attachments/);
assert.match(page, /task_attachment_upload/);
assert.match(page, /task_attachment_remove/);
assert.match(page, /multiple hidden accept="\.jpg,\.jpeg,\.png,\.webp,\.pdf,\.doc,\.docx,\.xls,\.xlsx,\.mp4"/);
assert.match(page, /10 \* 1024 \* 1024/);
assert.match(page, /maximum of 10 attachments/);
assert.match(page, /hash_equals\(\$taskAttachmentCsrf/);
assert.match(page, /let uploading = false/);
assert.match(endpoint, /require_login\(\)/);
assert.match(endpoint, /ops_task_scope_for_current_user/);
assert.match(endpoint, /X-Content-Type-Options: nosniff/);
assert.match(endpoint, /Content-Disposition/);
assert.match(css, /\.task-details-body\s*\{\s*display:grid;\s*gap:/);
assert.match(css, /\.task-files\{/);

console.log('Task details continuous layout, note validation and attachment checks passed.');
