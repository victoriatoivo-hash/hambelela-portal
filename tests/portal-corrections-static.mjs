import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const ordersAction = read('apps/operations/orders-board-action.php');
const ordersData = read('apps/operations/orders-board-data.php');
const bookkeeping = read('apps/operations/bookkeeping.php');
const courier = read('apps/operations/courier.php');
const ordersJs = read('assets/js/orders-board.js');
const ordersCss = read('assets/css/orders-board.css');
const candidateReport = read('apps/operations/actor-attribution-candidates.php');

assert.match(ordersAction, /function ops_board_can_manage_packer_assignment\(\): bool[\s\S]*?marketing_sales/);
assert.match(ordersData, /'can_edit_packed_by'\s*=>[^\n]*marketing_sales/);
assert.match(ordersAction, /'packer_attribution_corrected', \$previousPackerName, \$packerName, ops_current_employee_id\(\)/);
assert.match(bookkeeping, /\$ledgerUserId = \(int\) \(\$employeeId \?\? 0\)/);
assert.match(bookkeeping, /SELECT full_name FROM ops_employees WHERE id = \? AND status = 'active'/);
assert.doesNotMatch(ordersAction, /function ops_board_tools_can_manage\(\): bool\s*\{[^}]*marketing_sales/);

assert.match(courier, /\$canDownloadWaybills\s*=.*\$canSendWaybills/);
assert.match(courier, /waybill_download_file'\) \{\s*if \(!\$canDownloadWaybills\)/);
assert.match(courier, /waybill_download_zip'\) \{\s*if \(!\$canDownloadWaybills\)/);
assert.match(ordersJs, /courier\.php\?action=waybill_download_zip&amp;batch_id=/);
assert.match(courier, /CREATE TABLE IF NOT EXISTS hambelela_waybill_orders/);
assert.match(courier, /UNIQUE KEY uq_waybill_order \(waybill_id, order_id\)/);
assert.match(courier, /linked_by_employee_id INT NULL/);
assert.match(courier, /\$backfill->execute\(\[\(int\) \$legacyLink\['id'\], \(int\) \$order\['id'\], null, null\]\)/);
assert.match(courier, /name="order_ids\[\]"/);
assert.match(courier, /waybill_order_search/);
assert.match(courier, /JOIN hambelela_waybill_orders link ON link\.waybill_id=sw\.id/);
assert.match(courier, /if \(!\$canUploadWaybills && !\$canManageWaybills\) wb_json\(\['success' => false/);

assert.match(ordersJs, /desktopList\.after\(list\)/);
assert.match(ordersJs, /board-mobile-card__packer/);
assert.match(ordersJs, /board-mobile-card__actions/);
assert.match(ordersJs, /orders-mobile-filter-sheet/);
assert.match(ordersCss, /\.orders-date-groups,\s*body\.ess-dashboard \.ess-orders-page \.orders-grid-scroll\{display:none\}/);
assert.match(ordersCss, /\.orders-person-popup\.is-open\{transform:translateY\(0\)\}/);

assert.match(candidateReport, /require_login\(\)/);
assert.match(candidateReport, /user_has_role\('owner_admin'\)/);
assert.doesNotMatch(candidateReport, /\b(?:INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE)\b/i);

console.log('Portal correction static safeguards passed.');
