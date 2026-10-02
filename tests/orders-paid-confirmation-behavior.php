<?php
// Execute the real Paid action and persistence helper with an in-memory database.
// Never connects to production or changes a customer record.
$root = dirname(__DIR__);
$action = file_get_contents($root . '/apps/operations/orders-board-action.php');
$operations = file_get_contents($root . '/apps/operations/operations.php');
$marker = "} elseif (\$field === 'payment_status') {";
$start = strpos($action, $marker) + strlen($marker);
$paidAction = substr($action, $start, strpos($action, '        } else {', $start) - $start);
$helperStart = strpos($operations, 'function ops_set_portal_paid_confirmation(');
// Delimit on the next named function, allowing both CRLF and LF checkouts.
$helper = substr($operations, $helperStart, strpos($operations, 'function ops_apply_initial_portal_paid_confirmation(', $helperStart) - $helperStart);
eval($helper);

function expect($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function ops_ensure_order_payment_schema() { return true; }
function ops_column_exists($table, $column) { return $GLOBALS['storage']; }
function ops_can_update_order_paid_status() { return $GLOBALS['permission']; }
function ops_current_employee_id() { return 42; }
function current_user() { return ['name' => 'Test Employee', 'role_key' => 'packer']; }
function current_role_key() { return 'packer'; }
function ops_portal_paid_status($row) { return !empty($row['portal_paid_confirmed']) ? 'paid' : 'unpaid'; }
function ops_activity_log(...$args) { $GLOBALS['audit'][] = $args; }
function ops_kpi_record_event(...$args) { $GLOBALS['kpi'][] = $args; }
function ops_order_payment_allocations($id) { throw new RuntimeException('Manual Paid must not read/gate financial allocations.'); }
function db() {
    return new class {
        public function prepare($sql) {
            expect(str_contains($sql, 'SET portal_paid_confirmed = ?'), 'Only manual confirmation may be written');
            expect(!str_contains($sql, 'SET payment_status'), 'Financial settlement must not be written');
            return new class {
                public function execute($params) {
                    [$confirmed, $source, $actor, $id] = $params;
                    $GLOBALS['record']['portal_paid_confirmed'] = $confirmed;
                    $GLOBALS['record']['portal_paid_source'] = $source;
                    $GLOBALS['record']['portal_paid_decided_by_employee_id'] = $actor;
                }
            };
        }
    };
}

foreach ([0, 14500, 22000] as $allocation) {
    $record = ['portal_paid_confirmed' => 0, 'total_amount' => 220, 'payments' => [['amount_cents' => $allocation]], 'payment_status' => $allocation >= 22000 ? 'paid' : ($allocation ? 'partial' : 'unpaid')];
    $financial = [$record['payments'], $record['payment_status']];
    $storage = $permission = true;
    $_SESSION = ['orders_csrf_token' => 'test-token'];
    $_POST = ['csrf_token' => 'test-token'];
    $orderId = 37376;
    foreach (['paid', 'unpaid'] as $value) {
        $previousOrder = $record;
        eval($paidAction);
        expect($record['portal_paid_confirmed'] === ($value === 'paid' ? 1 : 0), 'Paid must persist');
        expect([$record['payments'], $record['payment_status']] === $financial, 'Payment allocations/status must remain unchanged');
        expect($record['portal_paid_decided_by_employee_id'] === 42, 'Authenticated employee must be attributed');
        expect(end($audit)[3]['field'] === 'portal_paid_confirmation', 'Audit must identify manual confirmation');
    }
}
foreach (['csrf', 'permission', 'storage', 'value'] as $failure) {
    $storage = $permission = true;
    $_POST['csrf_token'] = $failure === 'csrf' ? 'bad-token' : 'test-token';
    if ($failure === 'permission') $permission = false;
    if ($failure === 'storage') $storage = false;
    $value = $failure === 'value' ? 'partial' : 'paid';
    $before = $record;
    $thrown = false;
    try { eval($paidAction); } catch (RuntimeException $error) { $thrown = true; }
    expect($thrown && $record === $before, 'Invalid request must fail without mutation: ' . $failure);
}
expect(!str_contains($action, 'HAVING SUM(p.amount_cents) < ROUND(o.total_amount * 100)'), 'Bulk Paid must not gate allocations');
expect(!str_contains($action, "? 'portal_paid_confirmed' : 'payment_status'"), 'Missing schema must never fall back to financial status');
echo "Paid confirmation behavior passed: zero/partial/full allocations, untick, audit, CSRF, permissions, unavailable schema.\n";
