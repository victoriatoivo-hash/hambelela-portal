import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const action = readFileSync(new URL('../apps/operations/orders-board-action.php', import.meta.url), 'utf8');

assert.match(action, /function ops_board_safe_woocommerce_error\(Throwable \$error\): string/);
assert.match(action, /\$message !== strip_tags\(\$message\)/);
assert.match(action, /\$sourceRequestCount = 0;[\s\S]*\$sourceSuccessCount = 0;/);
assert.match(action, /\$sourceRequestCount\+\+;[\s\S]*wc_get\('orders',[\s\S]*\$sourceSuccessCount\+\+;/);
assert.match(action, /if \(\$sourceSuccessCount === 0\) \{[\s\S]*throw new RuntimeException\([\s\S]*Existing portal orders were preserved; no empty sync was recorded\./);
assert.match(action, /\$pdo = db\(\);/);

const failureGuard = action.indexOf('if ($sourceSuccessCount === 0)');
const databaseImport = action.indexOf('$pdo = db();', failureGuard);
assert.ok(failureGuard > -1 && databaseImport > failureGuard, 'Total source failure must stop before the database import starts.');

assert.match(action, /'source_requests' => \$sourceRequestCount/);
assert.match(action, /'source_successes' => \$sourceSuccessCount/);

console.log('Orders sync fail-closed static checks passed.');
