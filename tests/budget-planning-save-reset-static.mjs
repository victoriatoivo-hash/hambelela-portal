import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../apps/operations/budget-planning.php', import.meta.url), 'utf8');

assert.match(page, /budget-planning\.php\?new='\.rawurlencode\(\$input\['kind'\]\).*month='\.rawurlencode\(\$savedMonth\).*saved=1/, 'A successful save must return to a clean form and retain the saved month.');
assert.doesNotMatch(page, /budget-planning\.php\?id='\.\$id\.'&saved=1/, 'A successful save must not reopen the saved budget in edit mode.');

console.log('Budget save reset checks passed.');
