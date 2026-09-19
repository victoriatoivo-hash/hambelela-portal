<?php
// Seeds synthetic (non-production) data for the mobile visual harness. CI database only.
declare(strict_types=1);
if (getenv('MOBILE_TEST_ENV') !== '1') exit("MOBILE_TEST_ENV not set\n");
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . getenv('HAMBELELA_DB_NAME') . ';charset=utf8mb4',
    getenv('HAMBELELA_DB_USER'), getenv('HAMBELELA_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Insert a row, filling any NOT NULL column without a default so fixtures survive schema drift.
function seed(PDO $pdo, string $table, array $values): int
{
    $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        $name = $col['Field'];
        if (array_key_exists($name, $values) || $col['Null'] === 'YES' || $col['Default'] !== null || str_contains((string) $col['Extra'], 'auto_increment')) continue;
        $type = strtolower((string) $col['Type']);
        $values[$name] = match (true) {
            str_starts_with($type, 'enum(') => trim(explode(',', substr($type, 5))[0], "')"),
            str_contains($type, 'int') || str_contains($type, 'dec') || str_contains($type, 'float') || str_contains($type, 'double') => 0,
            str_contains($type, 'date') || str_contains($type, 'time') => date('Y-m-d H:i:s'),
            default => '',
        };
    }
    foreach ($cols as $col) {
        $type = (string) $col['Type'];
        if (!str_starts_with(strtolower($type), 'enum(') || !isset($values[$col['Field']])) continue;
        $allowed = str_getcsv(substr($type, 5, -1), ',', "'");
        if (!in_array((string) $values[$col['Field']], $allowed, true)) {
            fwrite(STDERR, "seed: {$table}.{$col['Field']}={$values[$col['Field']]} not in enum, using {$allowed[0]}\n");
            $values[$col['Field']] = $allowed[0];
        }
    }
    $known = array_column($cols, 'Field');
    $values = array_intersect_key($values, array_flip($known));
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($values));
    return (int) $pdo->lastInsertId();
}

$roles = ['owner_admin' => 'Owner/Admin', 'front_desk_admin' => 'Front Desk/Admin Employee', 'marketing_sales' => 'Marketing & Sales Assistant', 'packer' => 'Packer/Production Staff'];
foreach ($roles as $key => $name) $pdo->prepare('INSERT IGNORE INTO ops_roles (role_key, name) VALUES (?, ?)')->execute([$key, $name]);
$roleId = fn(string $key): int => (int) $pdo->query('SELECT id FROM ops_roles WHERE role_key = ' . $pdo->quote($key))->fetchColumn();
$people = [901 => ['Test Owner Person', 'owner_admin'], 902 => ['Test Front Person', 'front_desk_admin'], 903 => ['Test Marketing Assistant', 'marketing_sales'], 904 => ['Test Packer Person', 'packer']];
foreach ($people as $id => [$name, $role]) {
    $pdo->prepare('DELETE FROM ops_employees WHERE id = ?')->execute([$id]);
    seed($pdo, 'ops_employees', ['id' => $id, 'role_id' => $roleId($role), 'full_name' => $name, 'email' => "test{$id}@example.invalid", 'status' => 'active', 'packing_assignable' => $role === 'packer' ? 1 : 0]);
}
if (($argv[1] ?? '') === 'people') exit("seeded people\n");

// Orders: three date groups, mixed modes/payments/statuses, one very long customer + address.
$statuses = ['new_order', 'in_progress', 'completed', 'ready_for_collection', 'completed', 'new_order', 'in_progress', 'ready_for_courier'];
$payments = ['cash', 'card', 'eft', 'cash', 'card', 'eft', 'cash', 'card'];
$types = ['collection', 'delivery', 'courier', 'collection', 'walk_in', 'delivery', 'courier', 'collection'];
for ($i = 0; $i < 8; $i++) {
    $day = date('Y-m-d', strtotime('-' . intdiv($i, 3) . ' day'));
    seed($pdo, 'ops_orders', [
        'order_number' => (string) (37040 + $i),
        'customer_name' => $i === 1 ? 'Customer With An Exceptionally Long Business Name For Wrapping Checks (Pty) Ltd' : 'Test Customer ' . ($i + 1),
        'customer_contact' => '+264 81 000 00' . $i,
        'payment_method' => $payments[$i], 'order_type' => $types[$i], 'fulfilment_mode' => $types[$i],
        'status' => $statuses[$i], 'payment_status' => $i % 3 === 0 ? 'unpaid' : 'paid',
        'total_amount' => 150 + $i * 87.5, 'priority' => 'normal', 'complexity' => 'standard',
        'assigned_packer_id' => $i % 2 === 0 ? 904 : ($i === 3 ? 902 : null),
        'notes' => $i === 1 ? 'Shipping: Plot 1234, Extremely Long Street Name Extension, Windhoek North, Khomas Region, Namibia — deliver after 14:00.' : null,
        'created_at' => $day . ' 09:' . str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT) . ':00',
        'order_datetime' => $day . ' 09:' . str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT) . ':00',
        'source' => 'portal',
    ]);
}

// Bookkeeping ledger entries (table is created lazily by the page warm-up request).
$ledger = [['Opening float', 'opening_balance', 1500, 0], ['Walk-in sale INV-00142 shea butter and hibiscus oil bundle for returning customer', 'sale', 1240, 0], ['Office supplies — printer paper, labels and courier satchels', 'expense', 0, 386.4], ['Card sale', 'sale', 725, 0], ['Petty cash transport', 'expense', 0, 120]];
foreach ($ledger as $n => [$desc, $type, $in, $out]) {
    seed($pdo, 'ops_cash_book_entries', ['transaction_date' => date('Y-m-d') . ' 0' . (8 + $n) . ':15:00', 'transaction_type' => $type, 'description' => $desc, 'cash_in' => $in, 'cash_out' => $out, 'source' => $type === 'opening_balance' ? 'opening_balance' : 'manual', 'recorded_by' => 902, 'created_by_user_id' => 902, 'created_by_name' => 'Test Front Person', 'status' => 'active']);
}

// Packing list: long product names, variations, partial progress.
$packing = [['Shea Butter Raw Unrefined Grade A · 250g amber jar with tamper seal', '20 units', '12', 'in_progress', 904], ['Hibiscus Oil', '48 x 30ml', '0', 'not_started', null], ['Black Soap Liquid Concentrate Refill Pouch Lavender & Tea Tree variation', '6 x 1L', '6', 'completed', 904], ['Castor Oil Cold Pressed', '15 x 100ml', '4', 'in_progress', 902]];
foreach ($packing as [$name, $planned, $packed, $status, $who]) {
    seed($pdo, 'ops_packing_tasks', ['item_name' => $name, 'quantity_planned' => $planned, 'quantity_packed' => $packed, 'packing_status' => $status, 'assigned_employee_id' => $who, 'priority' => 'high', 'date_loaded' => date('Y-m-d H:i:s'), 'notes' => 'Check batch number on each label before sealing.']);
}

// Tasks: one very long task (80+ char title, 500+ char instructions, 12 checklist items) plus normal ones.
$longTitle = 'Restock the front display rows, rotate older stock forward and verify every shelf label matches the price list';
$longBody = str_repeat('Start with the top shelf and remove every product, wipe the shelf surface, then return products in FIFO order so the oldest batch sits at the front. ', 3)
    . "Confirm each label shows the current price from the approved list.\nPhotograph each completed row and attach the photos before marking the task complete. If any product is below the minimum level, log it on the low-stock sheet and inform the owner immediately.";
$items = array_map(fn(int $n) => "Checklist step {$n}: inspect row {$n}, rotate stock, confirm the shelf label and note any damaged packaging", range(1, 12));
$tasks = [[$longTitle, $longBody, $items, 'in_progress', 903], ['Clean packing station', 'Wipe down all surfaces.', ['Clear bench', 'Wipe bench', 'Empty bin'], 'pending', 904], ['Update website stock', 'Sync counts for the new consignment.', ['Open WooCommerce', 'Update counts'], 'complete', 902]];
foreach ($tasks as [$title, $body, $list, $status, $who]) {
    seed($pdo, 'ops_checklist_tasks', ['task_name' => $title, 'instructions' => $body, 'checklist_items' => json_encode($list), 'checked_items' => json_encode(array_slice($list, 0, 3)), 'status' => $status, 'assigned_employee_id' => $who, 'assignment_type' => 'specific', 'employee_visible' => 1, 'priority' => 'high', 'checklist_type' => 'custom', 'deadline' => date('Y-m-d') . ' 14:00:00', 'date_assigned' => date('Y-m-d H:i:s'), 'created_by' => 901, 'released_at' => date('Y-m-d H:i:s')]);
}
echo "seeded\n";
