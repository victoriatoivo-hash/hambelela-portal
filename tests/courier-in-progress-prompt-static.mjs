import fs from 'node:fs';
import assert from 'node:assert/strict';

const board = fs.readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const requirements = fs.readFileSync(new URL('../apps/operations/courier-order-requirements.php', import.meta.url), 'utf8');
const boardData = fs.readFileSync(new URL('../apps/operations/orders-board-data.php', import.meta.url), 'utf8');
const performance = fs.readFileSync(new URL('../apps/operations/kpi-courier-waybills-performance.php', import.meta.url), 'utf8');
const employeeScore = fs.readFileSync(new URL('../apps/operations/kpi-employee-data.php', import.meta.url), 'utf8');

assert.match(board, /function courierDispatchDetails\(order\)/, 'courier details must use the themed floating prompt');
assert.match(board, />1\. Type of courier</);
assert.match(board, /2\. EasyBox type/);
assert.match(board, /2\. Parcel size and weight/);
assert.doesNotMatch(board, /window\.prompt\('Courier service/);
assert.doesNotMatch(board, /Courier service date \(YYYY-MM-DD\)/);
assert.match(board, /dispatch_package:details\.packageDetail/);
assert.match(board, /dispatch_date:serviceDate/, 'service date must be recorded automatically');
assert.match(requirements, /package_detail VARCHAR\(190\)/, 'parcel description must be persisted');
assert.match(boardData, /dispatch_package_detail/, 'parcel answers must be available in order information');
assert.match(board, /EasyBox \/ parcel details/, 'order information must display the answer');
assert.match(performance, /required_uploads_missing/, 'recorded courier orders must be tallied against linked uploads');
assert.match(employeeScore, /requiredUploadControl/, 'missing required uploads must affect the packer score');
assert.match(employeeScore, /75% uploaded by the packer deadline · 15% recorded courier orders linked to an upload/, 'the performance weighting must be visible and auditable');

console.log('Courier In Progress prompt checks passed.');
