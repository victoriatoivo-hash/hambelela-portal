const fs = require('fs');
const assert = require('assert');

const helper = fs.readFileSync('apps/operations/kpi-bookkeeping-reconciliation.php', 'utf8');
const api = fs.readFileSync('apps/operations/kpi-employee-data.php', 'utf8');
const ui = fs.readFileSync('assets/js/kpi-employee.js', 'utf8');
const migration = fs.readFileSync('operations-bookkeeping-kpi-migration.sql', 'utf8');
const allocationEndpoint = fs.readFileSync('apps/operations/kpi-bookkeeping-allocation.php', 'utf8');

assert.match(helper, /p\.payment_method='cash'/, 'cash component must come from authoritative allocations');
assert.match(helper, /related_order_id/, 'matching must use immutable order identity');
assert.match(helper, /ambiguous_match/, 'date and amount candidates must remain ambiguous');
assert.match(helper, /partially_recorded/);
assert.match(helper, /amount_mismatch/);
assert.match(helper, /duplicate/);
assert.match(helper, /matched_late/);
assert.match(helper, /payment_updated_at/);
assert.match(helper, /created_at/);
assert.match(helper, /backdated/);
assert.match(helper, /deposit schedule not configured/i);
assert.doesNotMatch(helper, /UPDATE ops_cash_book_entries|UPDATE ops_orders|DELETE FROM ops_cash_book_entries/i, 'KPI must remain read-only');
assert.match(migration, /UNIQUE KEY uq_bookkeeping_order_allocation \(bookkeeping_entry_id, order_id\)/);
assert.match(migration, /review_status ENUM\('pending','confirmed','rejected'\)/);
assert.match(migration, /bookkeeping_order_allocation_audit/);
assert.match(api, /kpi_bookkeeping_reconciliation/);
assert.match(api, /frontdesk_weight_bookkeeping/);
assert.match(ui, /Daily cash reconciliation/);
assert.match(ui, /Order-level evidence/);
assert.match(ui, /Deposit records/);
assert.match(ui, /data-confirm-bookkeeping-allocation/);
assert.match(allocationEndpoint, /current_role_key\(\) !== 'owner_admin'/);
assert.match(allocationEndpoint, /Only the Owner\/Admin/);
assert.match(allocationEndpoint, /beginTransaction/);
assert.match(allocationEndpoint, /bookkeeping_order_allocation_audit/);

console.log('Bookkeeping KPI reconciliation contract checks passed.');
