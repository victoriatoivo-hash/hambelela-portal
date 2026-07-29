<?php
require_once __DIR__ . '/config.php';
requireAdmin();
$user = currentUser();
$db   = db();
require_once __DIR__ . '/includes/leave-reserve.php';
require_once __DIR__ . '/includes/employment-letter.php';
ensureLeaveShutdownSchema($db);

function getSetting($key, $default='') {
    global $db;
    $r = $db->prepare("SELECT setting_val FROM settings WHERE setting_key=?");
    $r->execute([$key]); $r = $r->fetchColumn();
    return $r !== false ? $r : $default;
}
function saveSetting($key, $val) {
    global $db;
    $db->prepare("INSERT INTO settings (setting_key,setting_val) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val=?")
       ->execute([$key,$val,$val]);
}

// ── Ensure public_holidays table exists ──────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS `public_holidays` (
  `id`    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `hdate` DATE NOT NULL,
  `hname` VARCHAR(120) NOT NULL,
  `year`  INT NOT NULL,
  UNIQUE KEY `date_name` (`hdate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Seed default Namibia holidays for current year if empty
$yr = (int)date('Y');
$hcount = $db->query("SELECT COUNT(*) FROM public_holidays WHERE year=$yr")->fetchColumn();
if ($hcount == 0) {
    $defaults = [
        ["$yr-01-01","New Year's Day"],["$yr-03-21","Independence Day"],
        ["$yr-04-18","Good Friday"],["$yr-04-21","Easter Monday"],
        ["$yr-05-01","Workers' Day"],["$yr-05-04","Cassinga Day"],
        ["$yr-05-25","Africa Day"],["$yr-08-26","Heroes' Day"],
        ["$yr-09-10","Day of the Namibian Women"],["$yr-12-10","Human Rights Day"],
        ["$yr-12-25","Christmas Day"],["$yr-12-26","Family Day"],
    ];
    $ins = $db->prepare("INSERT IGNORE INTO public_holidays (hdate,hname,year) VALUES (?,?,?)");
    foreach ($defaults as $h) $ins->execute([$h[0],$h[1],$yr]);
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_company') {
        foreach (['company_name','company_reg','company_address','company_city','company_country','company_phone','company_email','company_vat','company_bank'] as $f)
            saveSetting($f, clean($_POST[$f] ?? ''));
        foreach (array_keys(employmentLetterDefaults()) as $f)
            saveSetting($f, trim((string)($_POST[$f] ?? '')));
        $msg = 'company_saved';
    }


    if ($action === 'run_leave_accrual') {
        date_default_timezone_set('Africa/Windhoek');
        $accrualYear  = (int)date('Y');
        $accrualMonth = (int)date('n');
        $accrued      = min($accrualMonth * 2, 24);
        $empList      = $db->query("SELECT id FROM employees WHERE status='active'")->fetchAll();
        foreach ($empList as $emp) {
            $uq = $db->prepare("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE employee_id=? AND YEAR(start_date)=? AND status='approved' AND leave_type='Annual Leave'");
            $uq->execute([$emp['id'], $accrualYear]);
            $used = (float)$uq->fetchColumn();
            $db->prepare("DELETE FROM leave_balances WHERE employee_id=? AND leave_type='Annual Leave' AND year=?")->execute([$emp['id'], $accrualYear]);
            $db->prepare("INSERT INTO leave_balances (employee_id,leave_type,balance_days,used_days,year) VALUES (?,'Annual Leave',?,?,?)")->execute([$emp['id'], $accrued, $used, $accrualYear]);
        }
        try { $db->prepare("INSERT INTO audit_log (action,description) VALUES ('leave_accrual',?)")->execute(["Accrual: $accrued days — ".date('F Y')]); } catch(Exception $e) {}
        saveSetting('last_accrual_run', date('Y-m-d H:i:s'));
        saveSetting('last_accrual_days', $accrued);
        saveSetting('last_accrual_count', count($empList));
        $msg = 'accrual_done';
    }

    if ($action === 'save_leave') {
        foreach (['leave_annual_days','leave_sick_days','leave_family_days','leave_compassionate_days','leave_renewal_date','leave_carry_over','leave_require_certificate_days','shutdown_reserve_days','shutdown_start_md','shutdown_end_md','shutdown_allow_borrow'] as $f)
            saveSetting($f, clean($_POST[$f] ?? ''));
        $msg = 'leave_saved';
    }

    if ($action === 'save_overtime') {
        foreach (['ot_weekday_rate','ot_weekend_rate','ot_holiday_rate','ot_require_approval','ot_max_hours_day'] as $f)
            saveSetting($f, clean($_POST[$f] ?? ''));
        $msg = 'ot_saved';
    }

    if ($action === 'save_payroll') {
        foreach (['payroll_month','payroll_run_day','ssf_rate','ssf_min','ssf_max','paye_enabled'] as $f)
            saveSetting($f, clean($_POST[$f] ?? ''));
        $msg = 'payroll_saved';
    }

    if ($action === 'add_holiday') {
        $hdate = $_POST['hdate'] ?? '';
        $hname = clean($_POST['hname'] ?? '');
        $hyr   = $hdate ? (int)date('Y', strtotime($hdate)) : $yr;
        if ($hdate && $hname) {
            $db->prepare("INSERT IGNORE INTO public_holidays (hdate,hname,year) VALUES (?,?,?)")
               ->execute([$hdate,$hname,$hyr]);
        }
        $msg = 'holiday_added';
    }

    if ($action === 'delete_holiday') {
        $id = (int)($_POST['holiday_id'] ?? 0);
        if ($id) $db->prepare("DELETE FROM public_holidays WHERE id=?")->execute([$id]);
        $msg = 'holiday_deleted';
    }

    if ($action === 'save_notifications') {
        foreach (['notif_leave_approved','notif_ot_approved','notif_payslip_ready','notif_policy_uploaded','notif_leave_rejected','notif_ot_rejected'] as $f)
            saveSetting($f, isset($_POST[$f]) ? '1' : '0');
        $msg = 'notif_saved';
    }

    if ($action === 'save_documents') {
        foreach (['doc_allowed_types','doc_max_size_mb','doc_require_cert_sick'] as $f)
            saveSetting($f, clean($_POST[$f] ?? ''));
        $msg = 'doc_saved';
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $u = $db->prepare("SELECT password FROM users WHERE id=?");
        $u->execute([$user['id']]); $u = $u->fetch();
        if (!password_verify($current, $u['password']))    $msg = 'wrong_password';
        elseif ($new !== $confirm)                          $msg = 'password_mismatch';
        elseif (strlen($new) < 8)                          $msg = 'password_short';
        else {
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT),$user['id']]);
            $msg = 'password_changed';
        }
    }

    if ($action === 'reset_emp_password') {
        $emp_user_id = (int)($_POST['emp_user_id'] ?? 0);
        $new_pass    = $_POST['new_emp_password'] ?? '';
        if ($emp_user_id && strlen($new_pass) >= 6) {
            $db->prepare("UPDATE users SET password=? WHERE id=? AND role='employee'")
               ->execute([password_hash($new_pass, PASSWORD_BCRYPT),$emp_user_id]);
            $msg = 'emp_password_reset';
        }
    }

    if ($action === 'save_system') {
        foreach (['system_timezone','system_currency','system_date_format','system_portal_name'] as $f)
            saveSetting($f, clean($_POST[$f] ?? ''));
        $msg = 'system_saved';
    }

    if ($action === 'renew_leave') {
        $year = (int)date('Y');
        $employees = $db->query("SELECT id FROM employees WHERE status='active'")->fetchAll();
        $annual = (int)getSetting('leave_annual_days', 20);
        $sick   = (int)getSetting('leave_sick_days',   10);
        $family = (int)getSetting('leave_family_days',  3);
        $comp   = (int)getSetting('leave_compassionate_days', 3);
        $types  = [['Annual Leave',$annual],['Sick Leave',$sick],['Family Responsibility',$family],['Compassionate Leave',$comp],['Unpaid Leave',0]];
        foreach ($employees as $emp) {
            foreach ($types as $lt) {
                $db->prepare("INSERT INTO leave_balances (employee_id,leave_type,balance_days,used_days,year) VALUES (?,?,?,0,?) ON DUPLICATE KEY UPDATE balance_days=?,used_days=0")
                   ->execute([$emp['id'],$lt[0],$lt[1],$year,$lt[1]]);
            }
        }
        $msg = 'leave_renewed';
    }
}

// Load settings
$co = [];
foreach (['company_name','company_reg','company_address','company_city','company_country','company_phone','company_email','company_vat','company_bank'] as $k) $co[$k]=getSetting($k);
$letterSettings = employmentLetterSettings($db);

$holidays = $db->query("SELECT * FROM public_holidays ORDER BY hdate ASC")->fetchAll();
$empUsers = $db->query("SELECT u.id,u.name,u.email,e.job_title FROM users u LEFT JOIN employees e ON e.id=u.employee_id WHERE u.role='employee' ORDER BY u.name")->fetchAll();

$totalEmp  = $db->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
$totalPS   = $db->query("SELECT COUNT(*) FROM payslips")->fetchColumn();
$totalLeave= $db->query("SELECT COUNT(*) FROM leave_requests")->fetchColumn();
$totalOT   = $db->query("SELECT COUNT(*) FROM overtime")->fetchColumn();

$pendingLeave = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
$pendingOT    = $db->query("SELECT COUNT(*) FROM overtime WHERE status='pending'")->fetchColumn();
$currentPage  = 'settings.php';
$activeTab    = $_GET['tab'] ?? 'company';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Settings — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
<style>
.settings-nav{display:flex;flex-direction:column;gap:2px;width:210px;flex-shrink:0}
.settings-nav a{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;color:var(--text-mid);text-decoration:none;transition:all .15s}
.settings-nav a:hover{background:var(--green-pale);color:var(--green)}
.settings-nav a.active{background:var(--green-pale);color:var(--green);font-weight:700}
.settings-nav a i{width:16px;text-align:center;font-size:13px}
.settings-body{flex:1;min-width:0}
.settings-layout{display:flex;gap:24px;align-items:flex-start}
.toggle-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border)}
.toggle-row:last-child{border-bottom:none}
.toggle-label{font-size:13px;font-weight:500}
.toggle-sub{font-size:11px;color:var(--text-mid);margin-top:2px}
.toggle{position:relative;width:40px;height:22px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:#ccc;border-radius:11px;cursor:pointer;transition:.3s}
.toggle-slider:before{content:'';position:absolute;width:16px;height:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}
.toggle input:checked+.toggle-slider{background:var(--green)}
.toggle input:checked+.toggle-slider:before{transform:translateX(18px)}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">Settings</div>
  </div>
  <div class="content">

  <?php
  $toasts = [
    'company_saved'   => ['✓ Company information saved.', 'green'],
    'leave_saved'     => ['✓ Leave policy saved.', 'green'],
    'ot_saved'        => ['✓ Overtime settings saved.', 'green'],
    'payroll_saved'   => ['✓ Payroll settings saved.', 'green'],
    'holiday_added'   => ['✓ Public holiday added.', 'green'],
    'holiday_deleted' => ['✓ Public holiday removed.', 'green'],
    'notif_saved'     => ['✓ Notification preferences saved.', 'green'],
    'doc_saved'       => ['✓ Document rules saved.', 'green'],
    'password_changed'=> ['✓ Password changed successfully.', 'green'],
    'emp_password_reset'=>['✓ Employee password reset.', 'green'],
    'system_saved'    => ['✓ System preferences saved.', 'green'],
    'leave_renewed'   => ['✓ Leave balances renewed for all employees.', 'green'],
    'wrong_password'  => ['✗ Current password is incorrect.', 'error'],
    'password_mismatch'=>['✗ New passwords do not match.', 'error'],
    'password_short'  => ['✗ Password must be at least 8 characters.', 'error'],
  ];
  if ($msg && isset($toasts[$msg])):
    $t = $toasts[$msg];
    $cls = $t[1]==='error' ? 'error' : '';
  ?>
  <div class="toast <?=$cls?>"><?=$t[0]?></div>
  <?php endif ?>

  <div class="settings-layout">

    <!-- ── LEFT NAV ── -->
    <nav class="settings-nav">
      <?php
      $tabs = [
        ['company',   'fa-solid fa-building',        'Company Information'],
        ['leave',     'fa-solid fa-calendar-check',   'Leave Policy'],
        ['overtime',  'fa-regular fa-clock',          'Overtime Settings'],
        ['payroll',   'fa-solid fa-money-bill-wave',  'Payroll Settings'],
        ['holidays',  'fa-solid fa-flag',             'Public Holidays'],
        ['notifications','fa-regular fa-bell',        'Notifications'],
        ['documents', 'fa-solid fa-folder-open',      'Document Rules'],
        ['security',  'fa-solid fa-lock',             'Security'],
        ['system',    'fa-solid fa-sliders',          'System Preferences'],
        ['accrual',   'fa-solid fa-rotate',           'Leave Accrual'],
        ['accrual',   'fa-solid fa-rotate',           'Leave Accrual'],
        ['accrual',   'fa-solid fa-rotate',           'Leave Accrual'],
      ];
      foreach ($tabs as $t):
        $active = $activeTab===$t[0] ? ' active' : '';
      ?>
      <a href="?tab=<?=$t[0]?>" class="<?=$active?>"><i class="<?=$t[1]?>"></i> <?=$t[2]?></a>
      <?php endforeach ?>
    </nav>

    <!-- ── SETTINGS BODY ── -->
    <div class="settings-body">

    <?php if ($activeTab === 'company'): ?>
    <!-- ══ COMPANY INFORMATION ══ -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-building" style="color:var(--green)"></i> Company Information</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_company">
        <div style="padding:20px">
          <div class="form-grid">
            <div class="form-group"><label class="form-label">Company Name</label><input class="form-input" name="company_name" value="<?=htmlspecialchars($co['company_name'])?>"></div>
            <div class="form-group"><label class="form-label">Registration Number</label><input class="form-input" name="company_reg" value="<?=htmlspecialchars($co['company_reg'])?>"></div>
            <div class="form-group full"><label class="form-label">Street Address</label><input class="form-input" name="company_address" value="<?=htmlspecialchars($co['company_address'])?>"></div>
            <div class="form-group"><label class="form-label">City</label><input class="form-input" name="company_city" value="<?=htmlspecialchars($co['company_city'])?>"></div>
            <div class="form-group"><label class="form-label">Country</label><input class="form-input" name="company_country" value="<?=htmlspecialchars($co['company_country'])?>"></div>
            <div class="form-group"><label class="form-label">Phone</label><input class="form-input" name="company_phone" value="<?=htmlspecialchars($co['company_phone'])?>"></div>
            <div class="form-group"><label class="form-label">Email</label><input class="form-input" name="company_email" value="<?=htmlspecialchars($co['company_email'])?>"></div>
            <div class="form-group"><label class="form-label">VAT Number</label><input class="form-input" name="company_vat" value="<?=htmlspecialchars($co['company_vat'])?>"></div>
            <div class="form-group full"><label class="form-label">Bank Details (shown on payslips)</label><input class="form-input" name="company_bank" value="<?=htmlspecialchars($co['company_bank'])?>" placeholder="e.g. FNB Namibia — Acc: 62xxxxxxx"></div>

            <div class="section-divider">Employment Confirmation Letter</div>
            <div class="form-group"><label class="form-label">Legal Company Name</label><input class="form-input" name="letter_company_legal_name" value="<?=htmlspecialchars($letterSettings['letter_company_legal_name'])?>"></div>
            <div class="form-group"><label class="form-label">Trading Name</label><input class="form-input" name="letter_company_trading_name" value="<?=htmlspecialchars($letterSettings['letter_company_trading_name'])?>"></div>
            <div class="form-group"><label class="form-label">Registration Number</label><input class="form-input" name="letter_company_reg" value="<?=htmlspecialchars($letterSettings['letter_company_reg'])?>"></div>
            <div class="form-group"><label class="form-label">Telephone Number</label><input class="form-input" name="letter_phone" value="<?=htmlspecialchars($letterSettings['letter_phone'])?>"></div>
            <div class="form-group"><label class="form-label">Email Address</label><input class="form-input" name="letter_email" value="<?=htmlspecialchars($letterSettings['letter_email'])?>"></div>
            <div class="form-group"><label class="form-label">Website</label><input class="form-input" name="letter_website" value="<?=htmlspecialchars($letterSettings['letter_website'])?>"></div>
            <div class="form-group full"><label class="form-label">Physical Address</label><input class="form-input" name="letter_physical_address" value="<?=htmlspecialchars($letterSettings['letter_physical_address'])?>"></div>
            <div class="form-group"><label class="form-label">Signatory Name</label><input class="form-input" name="letter_signatory_name" value="<?=htmlspecialchars($letterSettings['letter_signatory_name'])?>"></div>
            <div class="form-group full">
              <label class="form-label">Default Responsibilities</label>
              <textarea class="form-input" name="letter_default_responsibilities" rows="3"><?=htmlspecialchars($letterSettings['letter_default_responsibilities'])?></textarea>
            </div>
          </div>
          <div style="margin-top:18px;text-align:right"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Company Information</button></div>
        </div>
      </form>
    </div>

    <?php elseif ($activeTab === 'leave'): ?>
    <!-- ══ LEAVE POLICY ══ -->

    <!-- Info banner -->
    <div style="background:var(--green-pale);border:1px solid var(--green-mid);border-radius:10px;padding:14px 18px;margin-bottom:18px;font-size:13px;line-height:1.7">
      <strong><i class="fa-solid fa-scale-balanced" style="color:var(--green)"></i> Namibia Labour Act Leave Provisions</strong><br>
      Annual Leave accrues at <strong>2 days per month</strong> (24 days/year). Sick Leave: <strong>30 days over a 36-month cycle</strong>. Compassionate: <strong>7 days</strong>. Maternity: <strong>12 weeks (unpaid — SSF benefit applies)</strong>.
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-calendar-check" style="color:var(--green)"></i> Leave Policy Settings</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_leave">
        <div style="padding:20px">
          <div class="form-grid">

            <div class="section-divider">Annual Leave (Accrual-Based)</div>
            <div class="form-group"><label class="form-label">Accrual Rate (days per month)</label><input class="form-input" type="number" step="0.5" name="leave_accrual_rate" value="<?=htmlspecialchars(getSetting('leave_accrual_rate','2'))?>"></div>
            <div class="form-group"><label class="form-label">Accrual Date</label>
              <select class="form-select" name="leave_accrual_day">
                <option value="1" <?=getSetting('leave_accrual_day','1')==='1'?'selected':''?>>1st of each month</option>
              </select>
            </div>
            <div class="form-group full"><label class="form-label">Annual Leave Renewal</label>
              <select class="form-select" name="leave_carry_over">
                <option value="0" <?=getSetting('leave_carry_over','0')==='0'?'selected':''?>>Reset to zero on 1 January each year</option>
                <option value="1" <?=getSetting('leave_carry_over','0')==='1'?'selected':''?>>Carry over unused days to next year</option>
              </select>
            </div>

            <div class="section-divider">December Shutdown Reserve</div>
            <div class="form-group"><label class="form-label">Shutdown Reserve Days</label><input class="form-input" type="number" step="0.5" name="shutdown_reserve_days" value="<?=htmlspecialchars(getSetting('shutdown_reserve_days','10'))?>"></div>
            <div class="form-group"><label class="form-label">Shutdown Start Date</label><input class="form-input" name="shutdown_start_md" value="<?=htmlspecialchars(getSetting('shutdown_start_md','12-19'))?>" placeholder="MM-DD, e.g. 12-19"></div>
            <div class="form-group"><label class="form-label">Shutdown End Date</label><input class="form-input" name="shutdown_end_md" value="<?=htmlspecialchars(getSetting('shutdown_end_md','01-04'))?>" placeholder="MM-DD, e.g. 01-04"></div>
            <div class="form-group"><label class="form-label">Allow Borrowing Future Leave</label>
              <select class="form-select" name="shutdown_allow_borrow">
                <option value="1" <?=getSetting('shutdown_allow_borrow','1')==='1'?'selected':''?>>Yes</option>
                <option value="0" <?=getSetting('shutdown_allow_borrow','1')==='0'?'selected':''?>>No</option>
              </select>
            </div>
            <div class="form-group full">
              <div style="padding:10px 14px;background:var(--green-pale);border-radius:8px;font-size:12px;color:var(--green)">
                <i class="fa-solid fa-circle-info"></i> Available annual leave is calculated as total remaining annual leave minus the shutdown reserve. Requests may still be submitted and approved when they use reserve days, but they are flagged for management approval.
              </div>
            </div>

            <div class="section-divider">Sick Leave (36-Month Cycle)</div>
            <div class="form-group"><label class="form-label">Sick Leave Days per Cycle</label><input class="form-input" type="number" name="leave_sick_days" value="<?=htmlspecialchars(getSetting('leave_sick_days','30'))?>"></div>
            <div class="form-group"><label class="form-label">Cycle Duration (months)</label><input class="form-input" type="number" name="leave_sick_cycle_months" value="<?=htmlspecialchars(getSetting('leave_sick_cycle_months','36'))?>"></div>
            <div class="form-group full"><label class="form-label">Medical Certificate Required After (days)</label><input class="form-input" type="number" name="leave_require_certificate_days" value="<?=htmlspecialchars(getSetting('leave_require_certificate_days','2'))?>"></div>

            <div class="section-divider">Other Leave Types</div>
            <div class="form-group"><label class="form-label">Compassionate Leave (days)</label><input class="form-input" type="number" name="leave_compassionate_days" value="<?=htmlspecialchars(getSetting('leave_compassionate_days','7'))?>"></div>
            <div class="form-group"><label class="form-label">Maternity Leave (weeks)</label><input class="form-input" type="number" name="leave_maternity_weeks" value="<?=htmlspecialchars(getSetting('leave_maternity_weeks','12'))?>"></div>
            <div class="form-group full">
              <div style="padding:10px 14px;background:var(--amber-pale);border-radius:8px;font-size:12px;color:var(--amber)">
                <i class="fa-solid fa-triangle-exclamation"></i> <strong>Maternity Leave is unpaid by the company.</strong> Benefits are paid through Social Security (SSF). The system records the leave but excludes it from payroll calculations.
              </div>
            </div>

          </div>
          <div style="margin-top:18px;display:flex;gap:10px;justify-content:space-between;align-items:center">
            <span class="form-help">Monthly accrual runs through the authenticated server task.</span>
            <div style="display:flex;gap:10px">
              <form method="POST" style="display:inline" onsubmit="return confirm('Reset ALL employee leave balances to current policy values?')">
                <input type="hidden" name="action" value="renew_leave">
                <button type="submit" class="btn btn-amber"><i class="fa-solid fa-calendar-rotate"></i> Renew All Year Balances</button>
              </form>
              <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Leave Policy</button>
            </div>
          </div>
        </div>
      </form>
    </div>

    <?php elseif ($activeTab === 'overtime'): ?>
    <!-- ══ OVERTIME SETTINGS ══ -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-regular fa-clock" style="color:var(--green)"></i> Overtime Settings</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_overtime">
        <div style="padding:20px">
          <div class="form-grid">
            <div class="form-group"><label class="form-label">Weekday / Saturday Rate</label>
              <select class="form-select" name="ot_weekday_rate">
                <option value="1.5" <?=getSetting('ot_weekday_rate','1.5')==='1.5'?'selected':''?>>1.5× (Standard)</option>
                <option value="2.0" <?=getSetting('ot_weekday_rate','1.5')==='2.0'?'selected':''?>>2.0×</option>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Sunday Rate</label>
              <select class="form-select" name="ot_weekend_rate">
                <option value="2.0" <?=getSetting('ot_weekend_rate','2.0')==='2.0'?'selected':''?>>2.0× (Standard)</option>
                <option value="1.5" <?=getSetting('ot_weekend_rate','2.0')==='1.5'?'selected':''?>>1.5×</option>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Public Holiday Rate</label>
              <select class="form-select" name="ot_holiday_rate">
                <option value="2.0" <?=getSetting('ot_holiday_rate','2.0')==='2.0'?'selected':''?>>2.0× (Standard)</option>
                <option value="3.0" <?=getSetting('ot_holiday_rate','2.0')==='3.0'?'selected':''?>>3.0×</option>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Max OT Hours Per Day</label><input class="form-input" type="number" name="ot_max_hours_day" value="<?=htmlspecialchars(getSetting('ot_max_hours_day','12'))?>"></div>
            <div class="form-group full"><label class="form-label">Require Admin Approval?</label>
              <select class="form-select" name="ot_require_approval">
                <option value="1" <?=getSetting('ot_require_approval','1')==='1'?'selected':''?>>Yes — all overtime needs approval</option>
                <option value="0" <?=getSetting('ot_require_approval','1')==='0'?'selected':''?>>No — auto-approve</option>
              </select>
            </div>
          </div>
          <div style="margin-top:18px;text-align:right"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Overtime Settings</button></div>
        </div>
      </form>
    </div>

    <?php elseif ($activeTab === 'payroll'): ?>
    <!-- ══ PAYROLL SETTINGS ══ -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-money-bill-wave" style="color:var(--green)"></i> Payroll Settings</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_payroll">
        <div style="padding:20px">
          <div class="form-grid">
            <div class="form-group"><label class="form-label">Current Payroll Period</label><input class="form-input" name="payroll_month" value="<?=htmlspecialchars(getSetting('payroll_month',date('F Y')))?>" placeholder="e.g. March 2026"></div>
            <div class="form-group"><label class="form-label">Payroll Run Day (day of month)</label><input class="form-input" type="number" name="payroll_run_day" value="<?=htmlspecialchars(getSetting('payroll_run_day','25'))?>" min="1" max="31"></div>
            <div class="form-group"><label class="form-label">SSF Rate (%)</label><input class="form-input" type="number" step="0.01" name="ssf_rate" value="<?=htmlspecialchars(getSetting('ssf_rate','0.9'))?>"></div>
            <div class="form-group"><label class="form-label">SSF Minimum (N$)</label><input class="form-input" type="number" step="0.01" name="ssf_min" value="<?=htmlspecialchars(getSetting('ssf_min','4.50'))?>"></div>
            <div class="form-group"><label class="form-label">SSF Maximum (N$)</label><input class="form-input" type="number" step="0.01" name="ssf_max" value="<?=htmlspecialchars(getSetting('ssf_max','99.00'))?>"></div>
            <div class="form-group full"><label class="form-label">PAYE Calculation</label>
              <select class="form-select" name="paye_enabled">
                <option value="1" <?=getSetting('paye_enabled','1')==='1'?'selected':''?>>Enabled — use Namibia PAYE brackets</option>
                <option value="0" <?=getSetting('paye_enabled','1')==='0'?'selected':''?>>Disabled — no PAYE deduction</option>
              </select>
            </div>
          </div>
          <div style="margin-top:18px;text-align:right"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Payroll Settings</button></div>
        </div>
      </form>
    </div>

    <?php elseif ($activeTab === 'holidays'): ?>
    <!-- ══ PUBLIC HOLIDAYS ══ -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-flag" style="color:var(--amber)"></i> Namibian Public Holidays</div>
        <button class="btn btn-primary btn-sm" onclick="openModal('addHolidayModal')"><i class="fa-solid fa-plus"></i> Add Holiday</button>
      </div>
      <table>
        <thead><tr><th>Date</th><th>Day</th><th>Holiday Name</th><th>Year</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($holidays as $h): ?>
        <tr>
          <td style="font-family:monospace;font-weight:600"><?=date('d M',strtotime($h['hdate']))?></td>
          <td style="font-size:12px;color:var(--text-mid)"><?=date('l',strtotime($h['hdate']))?></td>
          <td><?=htmlspecialchars($h['hname'])?></td>
          <td><?=$h['year']?></td>
          <td>
            <form method="POST" onsubmit="return confirm('Remove this holiday?')">
              <input type="hidden" name="action" value="delete_holiday">
              <input type="hidden" name="holiday_id" value="<?=$h['id']?>">
              <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <?php elseif ($activeTab === 'notifications'): ?>
    <!-- ══ NOTIFICATIONS ══ -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-regular fa-bell" style="color:var(--green)"></i> Notification Preferences</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_notifications">
        <div style="padding:20px">
          <p style="font-size:13px;color:var(--text-mid);margin-bottom:18px">Choose which events trigger automatic notifications to employees.</p>
          <?php
          $notifSettings = [
            ['notif_leave_approved',  'Leave Approved',      'Notify employee when their leave request is approved'],
            ['notif_leave_rejected',  'Leave Rejected',      'Notify employee when their leave request is rejected'],
            ['notif_ot_approved',     'Overtime Approved',   'Notify employee when their overtime is approved'],
            ['notif_ot_rejected',     'Overtime Rejected',   'Notify employee when their overtime is rejected'],
            ['notif_payslip_ready',   'Payslip Available',   'Notify employee when a new payslip is generated'],
            ['notif_policy_uploaded', 'New Policy Uploaded', 'Notify all employees when a new company policy is uploaded'],
          ];
          foreach ($notifSettings as $n):
            $checked = getSetting($n[0], '1') === '1';
          ?>
          <div class="toggle-row">
            <div>
              <div class="toggle-label"><?=$n[1]?></div>
              <div class="toggle-sub"><?=$n[2]?></div>
            </div>
            <label class="toggle">
              <input type="checkbox" name="<?=$n[0]?>" <?=$checked?'checked':''?>>
              <span class="toggle-slider"></span>
            </label>
          </div>
          <?php endforeach ?>
          <div style="margin-top:18px;text-align:right"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Preferences</button></div>
        </div>
      </form>
    </div>

    <?php elseif ($activeTab === 'documents'): ?>
    <!-- ══ DOCUMENT RULES ══ -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-folder-open" style="color:var(--green)"></i> Document Upload Rules</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_documents">
        <div style="padding:20px">
          <div class="form-grid">
            <div class="form-group full">
              <label class="form-label">Allowed File Types</label>
              <input class="form-input" name="doc_allowed_types" value="<?=htmlspecialchars(getSetting('doc_allowed_types','pdf,jpg,jpeg,png,doc,docx'))?>" placeholder="pdf,jpg,jpeg,png,doc,docx">
              <div style="font-size:11px;color:var(--text-mid);margin-top:3px">Comma-separated list of allowed extensions</div>
            </div>
            <div class="form-group">
              <label class="form-label">Max File Size (MB)</label>
              <input class="form-input" type="number" name="doc_max_size_mb" value="<?=htmlspecialchars(getSetting('doc_max_size_mb','5'))?>">
            </div>
            <div class="form-group">
              <label class="form-label">Require Medical Certificate for Sick Leave?</label>
              <select class="form-select" name="doc_require_cert_sick">
                <option value="1" <?=getSetting('doc_require_cert_sick','1')==='1'?'selected':''?>>Yes — always required</option>
                <option value="0" <?=getSetting('doc_require_cert_sick','1')==='0'?'selected':''?>>No — optional</option>
              </select>
            </div>
          </div>
          <div style="margin-top:18px;text-align:right"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Document Rules</button></div>
        </div>
      </form>
    </div>

    <?php elseif ($activeTab === 'security'): ?>
    <!-- ══ SECURITY ══ -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-lock" style="color:var(--blue)"></i> Change My Password</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div style="padding:20px">
          <div class="form-grid">
            <div class="form-group full"><label class="form-label">Current Password</label><input class="form-input" type="password" name="current_password" required></div>
            <div class="form-group"><label class="form-label">New Password</label><input class="form-input" type="password" name="new_password" required minlength="8"></div>
            <div class="form-group"><label class="form-label">Confirm New Password</label><input class="form-input" type="password" name="confirm_password" required></div>
          </div>
          <div style="margin-top:18px;text-align:right"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-lock"></i> Change Password</button></div>
        </div>
      </form>
    </div>

    <?php if (!empty($empUsers)): ?>
    <div class="card" style="margin-top:20px">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-key" style="color:var(--amber)"></i> Reset Employee Passwords</div></div>
      <div style="padding:0 20px 4px">
        <table>
          <thead><tr><th>Employee</th><th>Email</th><th>Job Title</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($empUsers as $eu): ?>
          <tr>
            <td style="font-weight:600"><?=htmlspecialchars($eu['name'])?></td>
            <td style="font-size:12px;color:var(--text-mid)"><?=htmlspecialchars($eu['email'])?></td>
            <td style="font-size:12px"><?=htmlspecialchars($eu['job_title']??'—')?></td>
            <td><button class="btn btn-secondary btn-sm" onclick="openReset(<?=$eu['id']?>,'<?=htmlspecialchars($eu['name'])?>')"><i class="fa-solid fa-key"></i> Reset</button></td>
          </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif ?>

    <?php elseif ($activeTab === 'system'): ?>
    <!-- ══ SYSTEM PREFERENCES ══ -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-sliders" style="color:var(--green)"></i> System Preferences</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_system">
        <div style="padding:20px">
          <div class="form-grid">
            <div class="form-group"><label class="form-label">Portal Name</label><input class="form-input" name="system_portal_name" value="<?=htmlspecialchars(getSetting('system_portal_name','Hambelela Organic HR Portal'))?>"></div>
            <div class="form-group"><label class="form-label">Currency Symbol</label><input class="form-input" name="system_currency" value="<?=htmlspecialchars(getSetting('system_currency','N$'))?>" placeholder="N$"></div>
            <div class="form-group"><label class="form-label">Timezone</label>
              <select class="form-select" name="system_timezone">
                <?php
                $currentTZ = getSetting('system_timezone','Africa/Windhoek');
                $zones = ['Africa/Windhoek'=>'Africa/Windhoek (NAT +2)','Africa/Johannesburg'=>'Africa/Johannesburg (SAST +2)','UTC'=>'UTC'];
                foreach ($zones as $v=>$l): ?>
                <option value="<?=$v?>" <?=$currentTZ===$v?'selected':''?>><?=$l?></option>
                <?php endforeach ?>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Date Format</label>
              <select class="form-select" name="system_date_format">
                <?php
                $fmt = getSetting('system_date_format','d M Y');
                foreach (['d M Y'=>'25 Mar 2026','d/m/Y'=>'25/03/2026','Y-m-d'=>'2026-03-25'] as $v=>$l): ?>
                <option value="<?=$v?>" <?=$fmt===$v?'selected':''?>><?=$l?></option>
                <?php endforeach ?>
              </select>
            </div>
          </div>
          <div style="margin-top:18px;text-align:right"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Preferences</button></div>
        </div>
      </form>
    </div>

    <!-- System Stats -->
    <div class="card" style="margin-top:20px">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-circle-info" style="color:var(--teal)"></i> System Information</div></div>
      <div style="padding:20px;display:grid;grid-template-columns:repeat(4,1fr);gap:14px;font-size:13px">
        <div style="text-align:center;padding:14px;background:var(--green-pale);border-radius:8px"><div style="font-size:22px;font-weight:800;color:var(--green)"><?=$totalEmp?></div><div style="font-size:11px;color:var(--text-mid)">Active Employees</div></div>
        <div style="text-align:center;padding:14px;background:var(--blue-pale);border-radius:8px"><div style="font-size:22px;font-weight:800;color:var(--blue)"><?=$totalPS?></div><div style="font-size:11px;color:var(--text-mid)">Payslips Generated</div></div>
        <div style="text-align:center;padding:14px;background:var(--amber-pale);border-radius:8px"><div style="font-size:22px;font-weight:800;color:var(--amber)"><?=$totalLeave?></div><div style="font-size:11px;color:var(--text-mid)">Leave Requests</div></div>
        <div style="text-align:center;padding:14px;background:var(--teal-pale);border-radius:8px"><div style="font-size:22px;font-weight:800;color:var(--teal)"><?=$totalOT?></div><div style="font-size:11px;color:var(--text-mid)">OT Records</div></div>
      </div>
      <div style="padding:0 20px 14px;font-size:11px;color:var(--text-light)">HR Portal v1.0 &mdash; Hambelela Organic &mdash; <?=date('Y')?></div>
    </div>
    

    <?php elseif ($activeTab === 'accrual'): ?>
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-calendar-check" style="color:var(--green)"></i> Leave Accrual &mdash; Manual Run</div></div>
      <div style="padding:20px">
        <?php
        $lastRun   = getSetting('last_accrual_run', '');
        $lastDays  = getSetting('last_accrual_days', '');
        $lastCount = getSetting('last_accrual_count', '');
        $thisMonth = date('F');
        $thisMonthNum = (int)date('n');
        $expectedDays = min($thisMonthNum * 2, 24);
        ?>
        <div style="background:var(--bg,#f5f5f5);border-radius:10px;padding:16px;margin-bottom:20px">
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;text-align:center">
            <div><div style="font-size:11px;text-transform:uppercase;font-weight:700;color:#888;margin-bottom:4px">Current Month</div><div style="font-size:20px;font-weight:800;color:var(--green)"><?= $thisMonth ?></div></div>
            <div><div style="font-size:11px;text-transform:uppercase;font-weight:700;color:#888;margin-bottom:4px">Days to Set</div><div style="font-size:20px;font-weight:800;color:var(--green)"><?= $expectedDays ?> days</div><div style="font-size:10px;color:#888">month <?= $thisMonthNum ?> &times; 2</div></div>
            <div><div style="font-size:11px;text-transform:uppercase;font-weight:700;color:#888;margin-bottom:4px">Last Run</div><div style="font-size:13px;font-weight:600"><?= $lastRun ? date('d M Y H:i', strtotime($lastRun)) : 'Never' ?></div><?php if ($lastRun): ?><div style="font-size:11px;color:#888"><?= $lastDays ?> days &middot; <?= $lastCount ?> employees</div><?php endif ?></div>
          </div>
        </div>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#92400e"><i class="fa-solid fa-circle-info"></i> <strong>How it works:</strong> Sets every active employee Annual Leave to <strong><?= $expectedDays ?> days</strong>. Used days preserved. Run on the <strong>1st of each month</strong>.</div>
        <?php if ($msg === 'accrual_done'): ?><div class="toast" style="margin-bottom:16px"><i class="fa-solid fa-check"></i> Leave accrual complete.</div><?php endif ?>
        <form method="POST" onsubmit="return confirm('Run leave accrual now?')"><input type="hidden" name="action" value="run_leave_accrual"><button type="submit" class="btn btn-primary" style="font-size:15px;padding:12px 28px"><i class="fa-solid fa-rotate"></i> Run Leave Accrual for <?= $thisMonth ?></button></form>
      </div>
    </div>

    
    
    <?php endif ?>

    </div><!-- end settings-body -->
  </div><!-- end settings-layout -->
  </div>
</div>

<!-- ADD HOLIDAY MODAL -->
<div class="overlay" id="addHolidayModal">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-flag"></i> Add Public Holiday</div><button class="modal-close" onclick="closeModal('addHolidayModal')"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST">
      <input type="hidden" name="action" value="add_holiday">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full"><label class="form-label">Date</label><input class="form-input" type="date" name="hdate" required></div>
          <div class="form-group full"><label class="form-label">Holiday Name</label><input class="form-input" name="hname" placeholder="e.g. Independence Day" required></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addHolidayModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Holiday</button>
      </div>
    </form>
  </div>
</div>

<!-- RESET PASSWORD MODAL -->
<div class="overlay" id="resetModal">
  <div class="modal" style="max-width:380px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-key"></i> Reset Password — <span id="resetName"></span></div><button class="modal-close" onclick="closeModal('resetModal')"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST">
      <input type="hidden" name="action" value="reset_emp_password">
      <input type="hidden" name="emp_user_id" id="resetUserId">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">New Temporary Password</label>
          <input class="form-input" type="text" name="new_emp_password" placeholder="Min. 6 characters" required minlength="6">
          <div style="font-size:11px;color:var(--text-mid);margin-top:4px">Share this with the employee so they can log in.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('resetModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-key"></i> Reset Password</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openReset(id, name) {
  document.getElementById('resetUserId').value = id;
  document.getElementById('resetName').textContent = name;
  openModal('resetModal');
}
document.querySelectorAll('.overlay').forEach(o => {
  o.addEventListener('click', e => { if(e.target===o) o.classList.remove('open'); });
});
</script>

</body>
</html>
