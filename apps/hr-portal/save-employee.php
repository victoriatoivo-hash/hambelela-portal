<?php
require_once __DIR__ . '/config.php';
requireAdmin();

$db     = db();
$emp_id = (int)($_POST['emp_id'] ?? 0);

$fields = [
    'first_name'      => clean($_POST['first_name'] ?? ''),
    'last_name'       => clean($_POST['last_name']  ?? ''),
    'email'           => clean($_POST['email']       ?? ''),
    'phone'           => clean($_POST['phone']       ?? ''),
    'id_number'       => clean($_POST['id_number']   ?? ''),
    'emp_number'      => clean($_POST['emp_number']  ?? ''),
    'job_title'       => clean($_POST['job_title']   ?? ''),
    'department'      => clean($_POST['department']  ?? ''),
    'employment_type' => $_POST['employment_type'] ?? 'full_time',
    'start_date'      => $_POST['start_date']      ?? null,
    'basic_salary'    => (float)($_POST['basic_salary']  ?? 0),
    'hourly_rate'     => (float)($_POST['hourly_rate']   ?? 0),
    'bank_name'       => clean($_POST['bank_name']       ?? ''),
    'bank_account'    => clean($_POST['bank_account']    ?? ''),
    'tax_number'      => clean($_POST['tax_number']      ?? ''),
    'status'          => $_POST['status'] ?? 'active',
    'emergency_name'  => clean($_POST['emergency_name']  ?? ''),
    'emergency_phone' => clean($_POST['emergency_phone'] ?? ''),
    'address'         => clean($_POST['address']         ?? ''),
    'suburb'          => clean($_POST['suburb']          ?? ''),
    'city'            => clean($_POST['city']            ?? ''),
    'notes'           => clean($_POST['notes']           ?? ''),
];

if (!$fields['first_name'] || !$fields['last_name']) {
    header('Location: employees.php?msg=error'); exit;
}

if (!$fields['emp_number']) {
    $count = $db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
    $fields['emp_number'] = 'HO-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
}

if ($emp_id > 0) {
    $sql = "UPDATE employees SET
        first_name=:first_name, last_name=:last_name, email=:email, phone=:phone,
        id_number=:id_number, emp_number=:emp_number, job_title=:job_title,
        department=:department, employment_type=:employment_type, start_date=:start_date,
        basic_salary=:basic_salary, hourly_rate=:hourly_rate, bank_name=:bank_name,
        bank_account=:bank_account, tax_number=:tax_number, status=:status,
        emergency_name=:emergency_name, emergency_phone=:emergency_phone,
        address=:address, suburb=:suburb, city=:city, notes=:notes
        WHERE id=:id";
    $params = [];
    foreach ($fields as $k => $v) $params[':'.$k] = $v;
    $params[':id'] = $emp_id;
    $db->prepare($sql)->execute($params);
} else {
    $cols = implode(',', array_keys($fields));
    $phs  = ':'.implode(',:', array_keys($fields));
    $params = [];
    foreach ($fields as $k => $v) $params[':'.$k] = $v;
    $db->prepare("INSERT INTO employees ($cols) VALUES ($phs)")->execute($params);
    $emp_id = (int)$db->lastInsertId();

    // ── Namibia Labour Act leave entitlements ──
    $year = (int)date('Y');
    $month = (int)date('n');

    // Annual Leave: accrue 2 days per month from Jan 1
    // Start with days already accrued this year (month × 2, max 24)
    $annualAccrued = min($month * 2, 24);

    $leaveTypes = [
        // [type, balance, cycle_months]
        ['Annual Leave',       $annualAccrued, null],
        ['Sick Leave',         30.0,           36],   // 30 days over 36 months
        ['Compassionate Leave', 7.0,           null],
        ['Maternity Leave',    60.0,           null], // 12 weeks = ~60 working days
        ['Unpaid Leave',        0.0,           null],
    ];

    $lbStmt = $db->prepare("INSERT IGNORE INTO leave_balances (employee_id,leave_type,balance_days,used_days,year) VALUES (?,?,?,0,?)");
    foreach ($leaveTypes as $lt) {
        $lbStmt->execute([$emp_id, $lt[0], $lt[1], $year]);
    }

    // Store sick leave cycle start date
    $db->prepare("INSERT INTO settings (setting_key,setting_val) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val=?")
       ->execute(["sick_leave_cycle_start_$emp_id", date('Y-m-d'), date('Y-m-d')]);

    // Default onboarding checklist
    $tasks = [
        'Collect certified copy of ID',
        'Collect proof of address',
        'Sign employment contract',
        'Capture banking details',
        'Obtain tax number (if applicable)',
        'Issue uniform / PPE',
        'Complete company induction',
        'Set up system access / login',
        'Add to payroll',
        'Add to UIF / Social Security',
    ];
    $obStmt = $db->prepare("INSERT INTO onboarding_tasks (employee_id,task,sort_order) VALUES (?,?,?)");
    foreach ($tasks as $i => $task) $obStmt->execute([$emp_id, $task, $i]);
}

header('Location: employees.php?msg=saved');
exit;
