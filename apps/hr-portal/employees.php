<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/leave-reserve.php';
requireAdmin();
$user = currentUser();
$db   = db();
ensureLeaveShutdownSchema($db);

// Handle offboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'offboard') {
    $id = (int)($_POST['emp_id'] ?? 0);
    if ($id) $db->prepare("UPDATE employees SET status='terminated' WHERE id=?")->execute([$id]);
    header('Location: employees.php?msg=offboarded'); exit;
}

$employees    = $db->query("SELECT * FROM employees WHERE LOWER(CONCAT_WS(' ', first_name, last_name, email)) NOT LIKE '%victoria%' ORDER BY status ASC, first_name ASC")->fetchAll();
$totalActive  = $db->query("SELECT COUNT(*) FROM employees WHERE status='active' AND LOWER(CONCAT_WS(' ', first_name, last_name, email)) NOT LIKE '%victoria%'")->fetchColumn();
$pendingLeave = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
$pendingOT    = $db->query("SELECT COUNT(*) FROM overtime WHERE status='pending'")->fetchColumn();
$msg = $_GET['msg'] ?? '';

// View employee detail
$viewEmp = null;
$onboardTasks = [];
if (isset($_GET['view'])) {
    $viewEmp = $db->prepare("SELECT * FROM employees WHERE id=?");
    $viewEmp->execute([(int)$_GET['view']]);
    $viewEmp = $viewEmp->fetch();
    if ($viewEmp) {
        $onboardTasks = $db->prepare("SELECT * FROM onboarding_tasks WHERE employee_id=? ORDER BY sort_order");
        $onboardTasks->execute([$viewEmp['id']]);
        $onboardTasks = $onboardTasks->fetchAll();
        $viewAnnualLeave = annualLeaveMetrics($db, (int)$viewEmp['id']);
    }
}

$colors = ['#40916C','#6D28D9','#0F766E','#D97706','#1D4ED8','#DC2626','#0369A1','#B45309'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employees — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
</head>
<body>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">Employees</div>
    <button class="btn btn-primary" onclick="openAdd()"><i class="fa-solid fa-plus"></i> Add Employee</button>
  </div>

  <div class="content">
    <?php if ($msg === 'saved'): ?>
      <div class="toast"><i class="fa-solid fa-check"></i> Employee saved successfully.</div>
    <?php elseif ($msg === 'offboarded'): ?>
      <div class="toast error"><i class="fa-solid fa-user-slash"></i> Employee offboarded and marked as terminated.</div>
    <?php elseif ($msg === 'account_created'): ?>
      <div class="toast"><i class="fa-solid fa-check"></i> Employee login account created successfully.</div>
    <?php elseif ($msg === 'email_taken'): ?>
      <div class="toast error"><i class="fa-solid fa-xmark"></i> That email is already in use. Try a different one.</div>
    <?php endif ?>

    <?php if ($viewEmp): ?>
    <!-- ── EMPLOYEE DETAIL VIEW ── -->
    <div style="margin-bottom:14px">
      <a href="employees.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Employees</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

      <!-- Profile Card -->
      <div class="card" style="overflow:visible">
        <div style="padding:24px;display:flex;align-items:center;gap:16px;border-bottom:1px solid var(--border)">
          <?php $ci=array_search($viewEmp['id'],array_column($employees,'id'))%count($colors); ?>
          <div style="width:60px;height:60px;border-radius:50%;background:<?=$colors[$ci%count($colors)]?>;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;color:#fff;flex-shrink:0">
            <?= strtoupper(substr($viewEmp['first_name'],0,1).substr($viewEmp['last_name'],0,1)) ?>
          </div>
          <div>
            <div style="font-size:18px;font-weight:800"><?= htmlspecialchars($viewEmp['first_name'].' '.$viewEmp['last_name']) ?></div>
            <div style="font-size:13px;color:var(--text-mid)"><?= htmlspecialchars($viewEmp['job_title'] ?? '') ?></div>
            <div style="margin-top:5px">
              <?php
              $sc = $viewEmp['status']==='active' ? 'badge-green' : ($viewEmp['status']==='terminated' ? 'badge-red' : 'badge-amber');
              ?>
              <span class="badge <?=$sc?>"><?=ucfirst($viewEmp['status'])?></span>
              &nbsp;<span class="badge badge-gray"><?= htmlspecialchars($viewEmp['emp_number']) ?></span>
            </div>
          </div>
        </div>
        <div style="padding:20px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
            <?php
            $rows = [
              ['Department',       $viewEmp['department']],
              ['Employment Type',  ucwords(str_replace('_',' ',$viewEmp['employment_type'] ?? ''))],
              ['Start Date',       $viewEmp['start_date'] ? date('d M Y',strtotime($viewEmp['start_date'])) : '—'],
              ['Basic Salary',     'N$ '.number_format((float)$viewEmp['basic_salary'],2)],
              ['Hourly Rate',      'N$ '.number_format((float)$viewEmp['hourly_rate'],2)],
              ['Email',            $viewEmp['email'] ?: '—'],
              ['Phone',            $viewEmp['phone'] ?: '—'],
              ['ID Number',        $viewEmp['id_number'] ?: '—'],
              ['Tax Number',       $viewEmp['tax_number'] ?: '—'],
              ['Bank',             $viewEmp['bank_name'] ?: '—'],
              ['Account',          $viewEmp['bank_account'] ?: '—'],
              ['Emergency Contact',$viewEmp['emergency_name'] ?: '—'],
              ['Emergency Phone',  $viewEmp['emergency_phone'] ?: '—'],
            ];
            foreach ($rows as $r):
            ?>
            <div>
              <div style="font-size:10px;font-weight:700;color:var(--text-mid);text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px"><?=htmlspecialchars($r[0])?></div>
              <div style="font-weight:500"><?=htmlspecialchars($r[1] ?? '—')?></div>
            </div>
            <?php endforeach ?>
          </div>
          <?php
          $leaveColor = shutdownStatusColor($viewAnnualLeave['status']);
          $leaveBadge = $viewAnnualLeave['status']==='red' ? 'badge-red' : ($viewAnnualLeave['status']==='amber' ? 'badge-amber' : 'badge-green');
          ?>
          <div style="margin-top:16px;padding:14px;border:1px solid var(--border);border-radius:10px;background:#fff">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
              <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--text-mid)">Annual Leave Reserve</div>
              <span class="badge <?=$leaveBadge?>"><?=ucfirst($viewAnnualLeave['status'])?></span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;font-size:12px">
              <div><div style="color:var(--text-mid);font-size:10px;text-transform:uppercase;font-weight:700">Accrued To Date</div><div style="font-weight:800;color:<?=$leaveColor?>"><?=number_format($viewAnnualLeave['current_accrued'],1)?> days</div></div>
              <div><div style="color:var(--text-mid);font-size:10px;text-transform:uppercase;font-weight:700">Leave Taken</div><div style="font-weight:800"><?=number_format($viewAnnualLeave['leave_taken'],1)?> days</div></div>
              <div><div style="color:var(--text-mid);font-size:10px;text-transform:uppercase;font-weight:700">Available Now</div><div style="font-weight:800"><?=number_format($viewAnnualLeave['available_now'],1)?> days</div></div>
            </div>
            <div style="margin-top:10px;font-size:12px;color:var(--text-mid)">Future shutdown reserve: <?=number_format($viewAnnualLeave['projected_reserve'],1)?> day(s). Status: <?=htmlspecialchars($viewAnnualLeave['reserve_status_text'])?>.</div>
            <?php if ($viewAnnualLeave['shortfall'] > 0): ?>
            <div style="margin-top:10px;font-size:12px;color:var(--red)">Shortfall for shutdown reserve: <?=number_format($viewAnnualLeave['shortfall'],1)?> day(s).</div>
            <?php endif ?>
          </div>
          <?php if ($viewEmp['notes']): ?>
          <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);font-size:13px;color:var(--text-mid)"><?=htmlspecialchars($viewEmp['notes'])?></div>
          <?php endif ?>
          <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-secondary btn-sm" onclick="editEmployee(<?=htmlspecialchars(json_encode($viewEmp))?>)"><i class="fa-solid fa-pen"></i> Edit</button>
            <?php if ($viewEmp['status'] !== 'terminated'): ?>
            <button class="btn btn-danger btn-sm" onclick="openOffboard(<?=$viewEmp['id']?>, '<?=htmlspecialchars($viewEmp['first_name'].' '.$viewEmp['last_name'])?>') "><i class="fa-solid fa-user-slash"></i> Offboard</button>
            <?php endif ?>
            <?php
            $hasAccount = $db->prepare("SELECT id FROM users WHERE employee_id=?");
            $hasAccount->execute([$viewEmp['id']]); $hasAccount = $hasAccount->fetchColumn();
            ?>
            <?php if (!$hasAccount): ?>
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('createAccountModal').classList.add('open')"><i class="fa-solid fa-key"></i> Create Login</button>
            <?php else: ?>
            <span class="badge badge-green" style="padding:6px 12px"><i class="fa-solid fa-check"></i> Portal Access Active</span>
            <?php endif ?>
          </div>
        </div>
      </div>

      <!-- Onboarding Checklist -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><i class="fa-solid fa-list-check" style="color:var(--green)"></i> Onboarding Checklist</div>
          <?php
          $done  = count(array_filter($onboardTasks, function($t){ return $t['completed']; }));
          $total = count($onboardTasks);
          $pct   = $total > 0 ? round($done/$total*100) : 0;
          ?>
          <span class="badge <?=$pct==100?'badge-green':'badge-amber'?>"><?=$done?>/<?=$total?> Done</span>
        </div>
        <div style="padding:0 20px 4px">
          <div style="height:4px;background:var(--border);border-radius:2px;margin:12px 0">
            <div style="height:4px;background:var(--green);border-radius:2px;width:<?=$pct?>%"></div>
          </div>
        </div>
        <div style="padding:0 20px 16px">
          <?php foreach ($onboardTasks as $task): ?>
          <div class="checklist-item">
            <form method="POST" action="toggle-task.php" style="display:contents">
              <input type="hidden" name="task_id" value="<?=$task['id']?>">
              <input type="hidden" name="redirect" value="employees.php?view=<?=$viewEmp['id']?>">
              <input class="checklist-cb" type="checkbox" <?=$task['completed']?'checked':''?> onchange="this.form.submit()">
            </form>
            <span class="checklist-label <?=$task['completed']?'done':''?>"><?=htmlspecialchars($task['task'])?></span>
          </div>
          <?php endforeach ?>
          <?php if(empty($onboardTasks)): ?>
          <div style="padding:20px 0;color:var(--text-mid);font-size:13px;text-align:center">No onboarding tasks found.</div>
          <?php endif ?>
        </div>
      </div>

    </div>

    <?php else: ?>
    <!-- ── EMPLOYEE LIST ── -->
    <div class="section-header">
      <div>
        <div class="section-title">Employee Management</div>
        <div class="section-sub"><?=$totalActive?> active employee<?=$totalActive!=1?'s':''?> at Hambelela Organic</div>
      </div>
    </div>

    <div class="card">
      <div class="search-bar">
        <i class="fa-solid fa-magnifying-glass" style="color:var(--text-mid)"></i>
        <input class="search-input" type="text" id="searchInput" placeholder="Search by name, job title or department..." oninput="filterTable()">
      </div>
      <?php if (empty($employees)): ?>
        <div class="empty-state">
          <i class="fa-solid fa-users"></i>
          <div style="font-size:16px;font-weight:700;margin-bottom:6px;color:var(--text)">No employees yet</div>
          <div>Click "Add Employee" to add your first team member.</div>
        </div>
      <?php else: ?>
      <table id="empTable">
        <thead>
          <tr><th>Employee</th><th>Job Title</th><th>Department</th><th>Start Date</th><th>Salary</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($employees as $i => $emp):
            $ini = strtoupper(substr($emp['first_name'],0,1).substr($emp['last_name'],0,1));
            $col = $colors[$i % count($colors)];
            $sc  = $emp['status']==='active' ? 'badge-green' : ($emp['status']==='terminated' ? 'badge-red' : 'badge-amber');
          ?>
          <tr>
            <td>
              <div class="emp-cell">
                <div class="emp-avatar" style="background:<?=$col?>"><?=$ini?></div>
                <div>
                  <a href="employees.php?view=<?=$emp['id']?>" style="font-weight:600;color:var(--text);text-decoration:none;hover:underline"><?=htmlspecialchars($emp['first_name'].' '.$emp['last_name'])?></a>
                  <div style="font-size:11px;color:var(--text-mid)"><?=htmlspecialchars($emp['emp_number'])?></div>
                </div>
              </div>
            </td>
            <td><?=htmlspecialchars($emp['job_title']??'—')?></td>
            <td><?=htmlspecialchars($emp['department']??'—')?></td>
            <td><?=$emp['start_date']?date('d M Y',strtotime($emp['start_date'])):'—'?></td>
            <td style="font-family:monospace;font-size:13px">N$ <?=number_format((float)$emp['basic_salary'],2)?></td>
            <td><span class="badge <?=$sc?>"><?=ucfirst($emp['status'])?></span></td>
            <td style="white-space:nowrap">
              <a href="employees.php?view=<?=$emp['id']?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-eye"></i></a>
              <button class="btn btn-secondary btn-sm" onclick="editEmployee(<?=htmlspecialchars(json_encode($emp))?>)"><i class="fa-solid fa-pen"></i></button>
              <?php if ($emp['status'] !== 'terminated'): ?>
              <button class="btn btn-danger btn-sm" onclick="openOffboard(<?=$emp['id']?>, '<?=htmlspecialchars($emp['first_name'].' '.$emp['last_name'])?>') "><i class="fa-solid fa-user-slash"></i></button>
              <?php endif ?>
            </td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>
    <?php endif ?>
  </div>
</div>

<!-- ADD / EDIT MODAL -->
<div class="overlay" id="empModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="modalTitle">Add Employee</div>
      <button class="modal-close" onclick="closeModal('empModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" action="save-employee.php">
      <input type="hidden" name="emp_id" id="empId" value="">
      <div class="modal-body">
        <div class="form-grid">
          <div class="section-divider">Personal Information</div>
          <div class="form-group"><label class="form-label">First Name *</label><input class="form-input" name="first_name" id="f_first_name" required></div>
          <div class="form-group"><label class="form-label">Last Name *</label><input class="form-input" name="last_name" id="f_last_name" required></div>
          <div class="form-group"><label class="form-label">Email Address</label><input class="form-input" type="email" name="email" id="f_email"></div>
          <div class="form-group"><label class="form-label">Phone Number</label><input class="form-input" name="phone" id="f_phone"></div>
          <div class="form-group"><label class="form-label">ID Number</label><input class="form-input" name="id_number" id="f_id_number"></div>
          <div class="form-group"><label class="form-label">Employee Number</label><input class="form-input" name="emp_number" id="f_emp_number" placeholder="Auto-generated if blank"></div>

          <div class="section-divider">Employment Details</div>
          <div class="form-group"><label class="form-label">Job Title</label><input class="form-input" name="job_title" id="f_job_title"></div>
          <div class="form-group"><label class="form-label">Department</label><input class="form-input" name="department" id="f_department"></div>
          <div class="form-group"><label class="form-label">Employment Type</label>
            <select class="form-select" name="employment_type" id="f_employment_type">
              <option value="full_time">Full Time</option><option value="part_time">Part Time</option>
              <option value="contract">Contract</option><option value="probation">Probation</option>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Start Date</label><input class="form-input" type="date" name="start_date" id="f_start_date"></div>
          <div class="form-group"><label class="form-label">Basic Salary (N$)</label><input class="form-input" type="number" step="0.01" name="basic_salary" id="f_basic_salary"></div>
          <div class="form-group"><label class="form-label">Hourly Rate (N$)</label><input class="form-input" type="number" step="0.01" name="hourly_rate" id="f_hourly_rate"></div>
          <div class="form-group"><label class="form-label">Status</label>
            <select class="form-select" name="status" id="f_status">
              <option value="active">Active</option><option value="inactive">Inactive</option><option value="terminated">Terminated</option>
            </select>
          </div>

          <div class="section-divider">Banking & Tax</div>
          <div class="form-group"><label class="form-label">Bank Name</label><input class="form-input" name="bank_name" id="f_bank_name" placeholder="e.g. FNB Namibia"></div>
          <div class="form-group"><label class="form-label">Account Number</label><input class="form-input" name="bank_account" id="f_bank_account"></div>
          <div class="form-group"><label class="form-label">Tax Number</label><input class="form-input" name="tax_number" id="f_tax_number"></div>

          <div class="section-divider">Emergency Contact</div>
          <div class="form-group"><label class="form-label">Contact Name</label><input class="form-input" name="emergency_name" id="f_emergency_name"></div>
          <div class="form-group"><label class="form-label">Contact Phone</label><input class="form-input" name="emergency_phone" id="f_emergency_phone"></div>
          <div class="section-divider">Home Address</div>
          <div class="form-group full"><label class="form-label">Street Address</label><input class="form-input" name="address" id="f_address" placeholder="e.g. 12 Independence Ave"></div>
          <div class="form-group"><label class="form-label">Suburb / Area</label><input class="form-input" name="suburb" id="f_suburb" placeholder="e.g. Pioneerspark"></div>
          <div class="form-group"><label class="form-label">City / Town</label><input class="form-input" name="city" id="f_city" placeholder="e.g. Windhoek"></div>

          <div class="section-divider">Other</div>
          <div class="form-group full"><label class="form-label">Notes</label><input class="form-input" name="notes" id="f_notes"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('empModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Employee</button>
      </div>
    </form>
  </div>
</div>

<!-- OFFBOARD MODAL -->
<div class="overlay" id="offboardModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-user-slash" style="color:var(--red)"></i> Offboard Employee</div>
      <button class="modal-close" onclick="closeModal('offboardModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p style="margin-bottom:18px;font-size:14px;color:var(--text-mid)">You are about to offboard <strong id="offboardName"></strong>. Please confirm the following checklist before proceeding:</p>
      <div style="background:#fef9f0;border:1px solid var(--amber-pale);border-radius:8px;padding:16px;margin-bottom:16px">
        <?php
        $offboardChecklist = [
          'Final payslip generated and paid',
          'Outstanding leave days calculated and paid out',
          'Company equipment / uniform returned',
          'Office / store keys returned',
          'System access and login removed',
          'Exit interview completed',
          'UIF documentation completed',
          'Reference letter issued (if applicable)',
        ];
        foreach ($offboardChecklist as $item):
        ?>
        <div class="checklist-item">
          <input class="checklist-cb" type="checkbox">
          <span class="checklist-label"><?=htmlspecialchars($item)?></span>
        </div>
        <?php endforeach ?>
      </div>
      <p style="font-size:12px;color:var(--red);font-weight:600">This will mark the employee as Terminated. This action can be reversed by editing the employee.</p>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="offboard">
      <input type="hidden" name="emp_id" id="offboardEmpId" value="">
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('offboardModal')">Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-user-slash"></i> Confirm Offboard</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAdd() {
  document.getElementById('modalTitle').textContent = 'Add Employee';
  document.getElementById('empId').value = '';
  ['first_name','last_name','email','phone','id_number','emp_number','job_title','department','start_date','basic_salary','hourly_rate','bank_name','bank_account','tax_number','emergency_name','emergency_phone','notes'].forEach(f => {
    const el = document.getElementById('f_'+f);
    if (el) el.value = '';
  });
  document.getElementById('f_employment_type').value = 'full_time';
  document.getElementById('f_status').value = 'active';
  document.getElementById('empModal').classList.add('open');
}

function editEmployee(emp) {
  document.getElementById('modalTitle').textContent = 'Edit Employee';
  document.getElementById('empId').value = emp.id;
  const map = {
    'first_name':emp.first_name,'last_name':emp.last_name,'email':emp.email||'',
    'phone':emp.phone||'','id_number':emp.id_number||'','emp_number':emp.emp_number||'',
    'job_title':emp.job_title||'','department':emp.department||'',
    'start_date':emp.start_date||'','basic_salary':emp.basic_salary||'',
    'hourly_rate':emp.hourly_rate||'','bank_name':emp.bank_name||'',
    'bank_account':emp.bank_account||'','tax_number':emp.tax_number||'',
    'emergency_name':emp.emergency_name||'','emergency_phone':emp.emergency_phone||'',
    'notes':emp.notes||'',
    'address':emp.address||'',
    'suburb':emp.suburb||'',
    'city':emp.city||''
  };
  for (const [k,v] of Object.entries(map)) {
    const el = document.getElementById('f_'+k);
    if (el) el.value = v;
  }
  document.getElementById('f_employment_type').value = emp.employment_type || 'full_time';
  document.getElementById('f_status').value = emp.status || 'active';
  document.getElementById('empModal').classList.add('open');
}

function openOffboard(id, name) {
  document.getElementById('offboardEmpId').value = id;
  document.getElementById('offboardName').textContent = name;
  document.getElementById('offboardModal').classList.add('open');
}

function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function filterTable() {
  const q = document.getElementById('searchInput').value.toLowerCase();
  document.querySelectorAll('#empTable tbody tr').forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

document.querySelectorAll('.overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
</script>
<!-- CREATE ACCOUNT MODAL -->
<div class="overlay" id="createAccountModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-key"></i> Create Employee Login</div><button class="modal-close" onclick="closeModal('createAccountModal')"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST" action="create-account.php">
      <input type="hidden" name="emp_id" value="<?=isset($viewEmp)?$viewEmp['id']:0?>">
      <div class="modal-body">
        <p style="font-size:13px;color:var(--text-mid);margin-bottom:16px">Create a login account so this employee can access their HR employee portal.</p>
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Login Email</label>
            <input class="form-input" type="email" name="email" value="<?=isset($viewEmp)?htmlspecialchars($viewEmp['email']??''):''?>" placeholder="employee@example.com" required>
          </div>
          <div class="form-group full">
            <label class="form-label">Temporary Password</label>
            <input class="form-input" type="text" name="password" placeholder="Set a temporary password" required>
            <div style="font-size:11px;color:var(--text-mid);margin-top:3px">Share this with the employee — they should change it after first login.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('createAccountModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-key"></i> Create Account</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
