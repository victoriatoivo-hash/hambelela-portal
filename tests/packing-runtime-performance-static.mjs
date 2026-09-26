import assert from 'node:assert/strict';
import fs from 'node:fs';

const endpoint = fs.readFileSync('apps/operations/packing-list-data.php', 'utf8');
const client = fs.readFileSync('assets/js/packing-list.js', 'utf8');
const operations = fs.readFileSync('apps/operations/operations.php', 'utf8');

assert.match(endpoint, /function packing_list_data_version\(/);
assert.match(endpoint, /\$_GET\['version_only'\]/);
assert.match(endpoint, /2026-09-26-packing-import-cleanup-v1/);
assert.match(endpoint, /'dataVersion' => \$dataVersion/);
assert.match(client, /let packingDataVersion = '';/);
assert.match(client, /version_only=1/);
assert.match(client, /if \(String\(versionData\.dataVersion \|\| ''\) === packingDataVersion\) return null;/);
assert.match(client, /function schedulePackingRefresh\(delay = 60000\)/);
assert.match(client, /document\.hidden \? 180000 : 60000/);
assert.match(operations, /function ops_column_exists[\s\S]*static \$confirmed = \[\];[\s\S]*if \(!empty\(\$confirmed\[\$cacheKey\]\)\) return true;/);
assert.match(operations, /function ops_table_exists[\s\S]*static \$confirmed = \[\];[\s\S]*if \(!empty\(\$confirmed\[\$table\]\)\) return true;/);

console.log('Packing runtime performance safeguards verified.');
