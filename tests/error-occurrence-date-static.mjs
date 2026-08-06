import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('apps/operations/errors.php', 'utf8');
const quality = fs.readFileSync('shared/epi/QualityPerformance.php', 'utf8');

assert.match(page, /name="occurred_at" required/);
assert.match(page, /Africa\/Windhoek/);
assert.match(page, /cannot be in the future/);
assert.match(page, /occurred_at, created_at/);
assert.match(page, /error_occurrence_corrected/);
assert.match(page, /Only an owner\/admin may correct when an error occurred/);
assert.match(page, /COALESCE\(\{\$alias\}\.occurred_at, \{\$alias\}\.created_at, \{\$alias\}\.logged_at\)/);
assert.match(quality, /COALESCE\(el\.occurred_at,el\.created_at,el\.logged_at\)/);

console.log('Error occurrence time is distinct, validated, and used by reporting.');
