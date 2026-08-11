import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync(new URL('../apps/operations/errors.php', import.meta.url), 'utf8');
const attachment = fs.readFileSync(new URL('../apps/operations/error-attachment.php', import.meta.url), 'utf8');
const instructions = fs.readFileSync(new URL('../apps/operations/error-instructions.php', import.meta.url), 'utf8');
const instructionService = fs.readFileSync(new URL('../shared/error-instructions.php', import.meta.url), 'utf8');

for (const source of [page, attachment, instructions, instructionService]) {
  assert.doesNotMatch(source, /\bmatch\s*\(/, 'Error Log must not use PHP 8 match expressions');
  assert.doesNotMatch(source, /\bstr_starts_with\s*\(/, 'Error Log must not require PHP 8 str_starts_with');
  assert.doesNotMatch(source, /catch\s*\(\s*Throwable\s*\)\s*\{/, 'Throwable catches must remain compatible with production PHP');
}

assert.match(page, /require_role\('owner_admin', 'front_desk_admin', 'front_desk_admin_employee'\)/);
assert.match(attachment, /require_role\('owner_admin', 'front_desk_admin', 'front_desk_admin_employee'\)/);
assert.match(instructions, /Content-Type: application\/json/);
assert.match(instructions, /register_shutdown_function/);
assert.match(page, /function error_path_starts_with/);
assert.match(attachment, /function error_attachment_path_starts_with/);

console.log('Error Log production PHP compatibility checks passed.');
