import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../apps/operations/errors.php', import.meta.url), 'utf8');
const endpoint = readFileSync(new URL('../apps/operations/error-attachment.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');
const form = page.slice(page.indexOf('<form id="logErrorForm"'), page.indexOf('</form>', page.indexOf('<form id="logErrorForm"')));

assert.match(form, /enctype="multipart\/form-data"/);
assert.match(form, /name="attachments\[\]" multiple/);
for (const extension of ['jpg','jpeg','png','webp','pdf','doc','docx','xls','xlsx','csv','txt']) assert.match(form, new RegExp(`\\.${extension}`));
assert.match(page, /const errorEvidenceState = \{ files: \[\]/);
assert.match(page, /new DataTransfer\(\)/);
assert.match(page, /data-remove-error-file/);
assert.match(page, /URL\.createObjectURL\(file\)/);
assert.match(page, /error_attachment_records/);
assert.match(page, /new finfo\(FILEINFO_MIME_TYPE\)/);
assert.match(page, /10 \* 1024 \* 1024/);
assert.match(page, /array_merge\(\$existingAttachments, \$attachments\)/);
assert.match(page, /Only an owner\/admin may remove error evidence/);
assert.match(page, /error_attachment_removed/);
assert.match(endpoint, /require_role\('owner_admin', 'front_desk_admin', 'front_desk_admin_employee'\)/);
assert.match(endpoint, /hash_equals\(\$path, \$requestedPath\)/);
assert.match(endpoint, /error_attachment_path_starts_with\(\$absolutePath, \$uploadRoot \. DIRECTORY_SEPARATOR\)/);
assert.match(endpoint, /X-Content-Type-Options: nosniff/);
assert.match(endpoint, /Content-Disposition:/);
assert.match(css, /\.error-selected-file\{/);
assert.match(css, /\.incident-attachment-preview img\{/);

console.log('Error Log multi-file upload, validation, display, and access checks passed.');

