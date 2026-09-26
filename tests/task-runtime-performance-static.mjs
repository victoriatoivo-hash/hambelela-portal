import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('apps/operations/checklists.php', 'utf8');
const templates = fs.readFileSync('apps/operations/task-templates.php', 'utf8');

assert.match(page, /function checklist_schema_version_applied\(string \$key\): bool/);
assert.match(page, /if \(checklist_schema_version_applied\(\$schemaVersion\)\) return;/);
assert.match(page, /checklist_mark_schema_version\(\$schemaVersion\);/);
assert.match(templates, /2026-09-26-task-template-schema-v1/);
assert.match(templates, /checklist_schema_version_applied\(\$schemaVersion\)/);
assert.doesNotMatch(page, /const prefetchViews = \(\) =>/);
assert.match(page, /tabs\.addEventListener\('pointerover', prefetchTab\)/);
assert.match(page, /document\.hidden \? 180000 : 60000/);

console.log('Task runtime performance safeguards verified.');
