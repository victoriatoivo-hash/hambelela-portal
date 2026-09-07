import fs from 'node:fs';
import assert from 'node:assert/strict';

const board = fs.readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const requirements = fs.readFileSync(new URL('../apps/operations/courier-order-requirements.php', import.meta.url), 'utf8');

assert.match(board, /function courierDispatchDetails\(order\)/, 'courier details must use the themed floating prompt');
assert.match(board, />1\. Type of courier</);
assert.match(board, /2\. EasyBox type/);
assert.match(board, /2\. Parcel size and weight/);
assert.doesNotMatch(board, /window\.prompt\('Courier service/);
assert.doesNotMatch(board, /Courier service date \(YYYY-MM-DD\)/);
assert.match(board, /dispatch_package:details\.packageDetail/);
assert.match(board, /dispatch_date:serviceDate/, 'service date must be recorded automatically');
assert.match(requirements, /package_detail VARCHAR\(190\)/, 'parcel description must be persisted');

console.log('Courier In Progress prompt checks passed.');
