<?php
require_once __DIR__ . '/config.php';
requireAdmin();

$user = currentUser();
$db = db();
hrEnsureMedicalAidSchemaSafe($db);

$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
if ($month < 1 || $month > 12) $month = (int)date('n');
if ($year < 2020 || $year > 2100) $year = (int)date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_medical_aid_month') {
    $month = (int)($_POST['period_month'] ?? $month);
    $year = (int)($_POST['period_year'] ?? $year);
    $employeeIds = $_POST['employee_ids'] ?? [];
    foreach ($employeeIds as $rawId) {
        $employeeId = (int)$rawId;
        hrSaveMedicalAidEmployeePayment($db, $employeeId, $month, $year, [
            'paid_status' => isset($_POST['paid'][$employeeId]) ? 1 : 0,
            'paid_date' => $_POST['paid_date'][$employeeId] ?? '',
            'payment_reference' => $_POST['payment_reference'][$employeeId] ?? '',
            'notes' => $_POST['notes'][$employeeId] ?? '',
        ], (int)($user['id'] ?? 0));
    }
    header('Location: medical-aid.php?month=' . $month . '&year=' . $year . '&msg=saved');
    exit;
}

$hasEmployeeMedicalAidColumns = hrHasEmployeeMedicalAidColumns($db);
$medicalAidSelect = $hasEmployeeMedicalAidColumns
    ? "medical_aid_fund, medical_aid_active, medical_aid_total, medical_aid_company, medical_aid_employee"
    : "NULL AS medical_aid_fund, 0 AS medical_aid_active, 0 AS medical_aid_total, 0 AS medical_aid_company, 0 AS medical_aid_employee";

$employees = $db->query("SELECT id, emp_number, first_name, last_name, job_title, $medicalAidSelect FROM employees WHERE status='active' ORDER BY first_name, last_name")->fetchAll();
$medicalAidMap = hrMedicalAidMap($db);
$payments = hrMedicalAidPaymentMap($db, $month, $year);
$medicalEmployees = [];

foreach ($employees as $employee) {
    $employee = hrApplyMedicalAidToEmployee($employee, $medicalAidMap);
    if (!empty($employee['medical_aid_active'])) {
        $medicalEmployees[] = $employee;
    }
}

$summary = [
    'employees' => count($medicalEmployees),
    'total' => 0,
    'company' => 0,
    'employee' => 0,
    'paid' => 0,
];

foreach ($medicalEmployees as $employee) {
    $summary['total'] += (float)$employee['medical_aid_total'];
    $summary['company'] += (float)$employee['medical_aid_company'];
    $summary['employee'] += (float)$employee['medical_aid_employee'];
    if (!empty($payments[(int)$employee['id']]['paid_status'])) {
        $summary['paid']++;
    }
}
$summary['outstanding'] = max(0, $summary['employees'] - $summary['paid']);

$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));
$msg = $_GET['msg'] ?? '';
$currentPage = 'medical-aid.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Medical Aid Management - Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
<style>
.medical-filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
.medical-filter .form-group{min-width:150px}
.medical-table-wrap{overflow-x:auto}
.medical-table td{vertical-align:top}
.medical-table .form-input,.medical-table .form-textarea{min-width:130px}
.medical-table .form-textarea{min-width:180px;min-height:42px}
.check-cell{display:flex;align-items:center;gap:8px;font-weight:700;color:var(--green)}
.medical-check{width:18px;height:18px;accent-color:var(--green);cursor:pointer}
.money{font-family:monospace;font-weight:700;white-space:nowrap}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Medical Aid Management</div>
    <a class="btn btn-secondary" href="payroll.php"><i class="fa-solid fa-money-bill-wave"></i> Payroll</a>
  </div>

  <div class="content">
    <?php if ($msg === 'saved'): ?>
      <div class="toast"><i class="fa-solid fa-check"></i> Medical Aid payment records saved for <?=htmlspecialchars($monthName . ' ' . $year)?>.</div>
    <?php endif ?>

    <div class="section-header">
      <div>
        <div class="section-title">Monthly Medical Aid Payments</div>
        <div class="section-sub">Manage employee medical aid payment status for <?=htmlspecialchars($monthName . ' ' . $year)?>.</div>
      </div>
      <form class="medical-filter" method="GET">
        <div class="form-group">
          <label class="form-label">Month</label>
          <select class="form-select" name="month">
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?=$m?>" <?=$m === $month ? 'selected' : ''?>><?=date('F', mktime(0, 0, 0, $m, 1, $year))?></option>
            <?php endfor ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Year</label>
          <input class="form-input" type="number" name="year" value="<?=$year?>" min="2020" max="2100">
        </div>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> View Month</button>
      </form>
    </div>

    <div class="grid-3" style="margin-bottom:16px">
      <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-users"></i></div><div class="stat-value"><?=$summary['employees']?></div><div class="stat-label">Employees on Medical Aid</div></div>
      <div class="stat-card"><div class="stat-icon blue"><i class="fa-solid fa-kit-medical"></i></div><div class="stat-value" style="font-size:20px">N$<?=number_format($summary['total'],2)?></div><div class="stat-label">Total Monthly Fund</div></div>
      <div class="stat-card"><div class="stat-icon teal"><i class="fa-solid fa-building"></i></div><div class="stat-value" style="font-size:20px">N$<?=number_format($summary['company'],2)?></div><div class="stat-label">Total Company Contribution</div></div>
    </div>
    <div class="grid-3">
      <div class="stat-card"><div class="stat-icon amber"><i class="fa-solid fa-user-minus"></i></div><div class="stat-value" style="font-size:20px">N$<?=number_format($summary['employee'],2)?></div><div class="stat-label">Total Employee Contributions</div></div>
      <div class="stat-card"><div class="stat-icon red"><i class="fa-regular fa-clock"></i></div><div class="stat-value"><?=$summary['outstanding']?></div><div class="stat-label">Outstanding Payments</div></div>
      <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div><div class="stat-value"><?=$summary['paid']?></div><div class="stat-label">Paid This Month</div></div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-kit-medical" style="color:var(--green)"></i> Employees on Medical Aid</div>
        <a class="btn btn-secondary btn-sm" href="employees.php"><i class="fa-solid fa-users-gear"></i> Configure Employee Profiles</a>
      </div>

      <?php if (empty($medicalEmployees)): ?>
        <div class="empty-state">
          <i class="fa-solid fa-kit-medical"></i>
          <div>No active employees are currently marked for Medical Aid.</div>
          <div style="font-size:12px;margin-top:6px">Open an employee profile and set Medical Aid Active to Yes.</div>
        </div>
      <?php else: ?>
        <form method="POST">
          <input type="hidden" name="action" value="save_medical_aid_month">
          <input type="hidden" name="period_month" value="<?=$month?>">
          <input type="hidden" name="period_year" value="<?=$year?>">
          <div class="medical-table-wrap">
            <table class="medical-table">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Medical Aid Active</th>
                  <th>Fund Name</th>
                  <th>Total Monthly Fund</th>
                  <th>Company Contribution</th>
                  <th>Employee Contribution</th>
                  <th>Paid This Month</th>
                  <th>Payment Date</th>
                  <th>Payment Reference</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($medicalEmployees as $employee):
                $employeeId = (int)$employee['id'];
                $payment = $payments[$employeeId] ?? [];
                $paid = !empty($payment['paid_status']);
              ?>
                <tr>
                  <td>
                    <input type="hidden" name="employee_ids[]" value="<?=$employeeId?>">
                    <div class="emp-cell">
                      <div class="emp-avatar" style="background:var(--green-light)"><?=htmlspecialchars(strtoupper(substr($employee['first_name'],0,1).substr($employee['last_name'],0,1)))?></div>
                      <div>
                        <div style="font-weight:800"><?=htmlspecialchars($employee['first_name'].' '.$employee['last_name'])?></div>
                        <div style="font-size:11px;color:var(--text-mid)"><?=htmlspecialchars($employee['emp_number'])?><?=!empty($employee['job_title']) ? ' - '.htmlspecialchars($employee['job_title']) : ''?></div>
                      </div>
                    </div>
                  </td>
                  <td><span class="badge badge-green">Active</span></td>
                  <td><?=htmlspecialchars($employee['medical_aid_fund'])?></td>
                  <td><span class="money">N$ <?=number_format((float)$employee['medical_aid_total'],2)?></span></td>
                  <td><span class="money">N$ <?=number_format((float)$employee['medical_aid_company'],2)?></span></td>
                  <td><span class="money">N$ <?=number_format((float)$employee['medical_aid_employee'],2)?></span></td>
                  <td>
                    <label class="check-cell">
                      <input class="medical-check" type="checkbox" name="paid[<?=$employeeId?>]" value="1" <?=$paid ? 'checked' : ''?>>
                      Paid
                    </label>
                  </td>
                  <td><input class="form-input" type="date" name="paid_date[<?=$employeeId?>]" value="<?=htmlspecialchars((string)($payment['paid_date'] ?? ''))?>"></td>
                  <td><input class="form-input" name="payment_reference[<?=$employeeId?>]" value="<?=htmlspecialchars((string)($payment['payment_reference'] ?? ''))?>" placeholder="Reference"></td>
                  <td><textarea class="form-textarea" name="notes[<?=$employeeId?>]" placeholder="Notes"><?=htmlspecialchars((string)($payment['notes'] ?? ''))?></textarea></td>
                </tr>
              <?php endforeach ?>
              </tbody>
            </table>
          </div>
          <div class="modal-footer" style="position:static">
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Medical Aid Payments</button>
          </div>
        </form>
      <?php endif ?>
    </div>
  </div>
</div>
</body>
</html>
