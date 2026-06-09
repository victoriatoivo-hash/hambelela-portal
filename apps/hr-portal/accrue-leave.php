<?php
if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($key !== 'HamOrg2026') { http_response_code(403); die('Forbidden'); }
}

require_once __DIR__ . '/config.php';

date_default_timezone_set('Africa/Windhoek');

$db    = db();
$year  = (int)date('Y');
$month = (int)date('n');
$accrued = min($month * 2, 24); // total earned so far this year

$employees = $db->query("SELECT id, first_name, last_name FROM employees WHERE status='active'")->fetchAll();
$updated = [];

foreach ($employees as $emp) {
    // Get how many days already USED this year
    $usedRow = $db->prepare("SELECT COALESCE(SUM(days_taken),0) as used FROM leave_requests WHERE employee_id=? AND YEAR(start_date)=? AND status='approved'");
    $usedRow->execute([$emp['id'], $year]);
    $used = (float)$usedRow->fetchColumn();

    // Available = accrued so far minus days already taken (minimum 0)
    $available = max(0, $accrued - $used);

    // Update or insert the balance record
    $exists = $db->prepare("SELECT id FROM leave_balances WHERE employee_id=? AND leave_type='Annual Leave' AND year=?");
    $exists->execute([$emp['id'], $year]);
    if ($exists->fetchColumn()) {
        $db->prepare("UPDATE leave_balances SET balance_days=?, used_days=? WHERE employee_id=? AND leave_type='Annual Leave' AND year=?")
           ->execute([$available, $used, $emp['id'], $year]);
    } else {
        $db->prepare("INSERT INTO leave_balances (employee_id,leave_type,balance_days,used_days,year) VALUES (?,'Annual Leave',?,?,?)")
           ->execute([$emp['id'], $available, $used, $year]);
    }
    $updated[] = $emp['first_name'] . ' ' . $emp['last_name'] . ': accrued=' . $accrued . ', used=' . $used . ', available=' . $available;
}

try {
    $db->prepare("INSERT INTO audit_log (action,description) VALUES ('leave_accrual',?)")
       ->execute(["Accrual run: $accrued earned, adjusted for usage. " . count($employees) . " employees — " . date('F Y')]);
} catch(Exception $e) {}

if (php_sapi_name() === 'cli') {
    echo "Done: " . count($employees) . " employees. Accrued=$accrued days.\n";
    foreach ($updated as $u) echo "  $u\n";
} else {
    echo "<div style='font-family:sans-serif;max-width:560px;margin:40px auto;padding:30px;border:1px solid #ddd;border-radius:8px'>";
    echo "<h2 style='color:#2d6a4f'>Accrual Complete</h2>";
    echo "<p><strong>" . count($employees) . " employees</strong> updated for <strong>" . date('F Y') . "</strong></p>";
    echo "<p>Total accrued this year (month $month &times; 2): <strong>$accrued days</strong></p>";
    echo "<table style='width:100%;border-collapse:collapse;font-size:13px;margin-top:16px'>";
    echo "<tr style='background:#2d6a4f;color:#fff'><th style='padding:8px;text-align:left'>Employee</th><th style='padding:8px;text-align:center'>Earned</th><th style='padding:8px;text-align:center'>Used</th><th style='padding:8px;text-align:center'>Available</th></tr>";
    foreach ($updated as $i => $u) {
        // parse the string for display
        preg_match('/^(.*): accrued=([\d.]+), used=([\d.]+), available=([\d.]+)$/', $u, $m);
        if ($m) {
            $bg = $i%2===0 ? '#f9f9f9' : '#fff';
            echo "<tr style='background:$bg'><td style='padding:8px'>{$m[1]}</td><td style='padding:8px;text-align:center'>{$m[2]}</td><td style='padding:8px;text-align:center;color:#dc2626'>{$m[3]}</td><td style='padding:8px;text-align:center;font-weight:700;color:#2d6a4f'>{$m[4]}</td></tr>";
        }
    }
    echo "</table>";
    echo "<a href='leave.php' style='display:inline-block;margin-top:20px;padding:10px 20px;background:#2d6a4f;color:#fff;border-radius:6px;text-decoration:none'>Go to Leave Management</a>";
    echo "</div>";
}
