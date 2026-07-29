<?php
require_once __DIR__ . '/config.php';
requireLogin();
$user = currentUser();
if ($user['role'] === 'employee') { header('Location: ' . SITE_URL . '/self-service.php'); exit; }

// Get quick stats from DB
$db = db();

$totalEmployees = $db->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
$pendingLeave   = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
$pendingOT      = $db->query("SELECT COUNT(*) FROM overtime WHERE status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Dashboard — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  :root{
    --green:#2D6A4F;--green-light:#40916C;--green-pale:#D8F3DC;
    --amber:#D97706;--amber-pale:#FEF3C7;
    --red:#DC2626;--red-pale:#FEE2E2;
    --blue:#1D4ED8;--blue-pale:#DBEAFE;
    --sidebar:#111C16;--bg:#F1F5F2;--card:#fff;
    --text:#1A2E22;--text-mid:#4A6355;--border:#E2EAE5;
  }
  body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;font-size:14px}

  /* SIDEBAR */
  .sidebar{width:220px;background:var(--sidebar);display:flex;flex-direction:column;flex-shrink:0;overflow-y:auto}
  .sidebar-logo{padding:18px 18px 14px;border-bottom:1px solid rgba(255,255,255,0.07)}
  .sidebar-user{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,0.07);display:flex;align-items:center;gap:10px}
  .avatar-sm{width:34px;height:34px;border-radius:50%;background:var(--green-light);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0}
  .user-name{font-size:13px;font-weight:600;color:#fff}
  .role-badge{font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;display:inline-block;margin-top:2px;text-transform:uppercase;letter-spacing:.04em;background:var(--amber);color:#fff}
  .nav-section{font-size:10px;font-weight:700;color:rgba(255,255,255,0.28);letter-spacing:.08em;text-transform:uppercase;padding:12px 18px 5px}
  .nav-item{display:flex;align-items:center;gap:10px;padding:8px 18px;cursor:pointer;color:rgba(255,255,255,0.55);font-size:13px;font-weight:500;border-left:3px solid transparent;text-decoration:none;transition:all .15s}
  .nav-item:hover{background:rgba(255,255,255,0.05);color:rgba(255,255,255,0.85)}
  .nav-item.active{background:rgba(64,145,108,0.22);color:#fff;border-left-color:var(--green-light)}
  .nav-item i{width:18px;text-align:center;font-size:13px;opacity:.8}
  .nav-badge{margin-left:auto;background:var(--red);color:#fff;font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 5px}
  .sidebar-footer{padding:14px 18px;border-top:1px solid rgba(255,255,255,0.07);margin-top:auto}
  .logout-btn{width:100%;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:8px 12px;color:rgba(255,255,255,0.6);font-size:12px;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .15s}
  .logout-btn:hover{background:rgba(255,255,255,0.12);color:rgba(255,255,255,0.9)}

  /* MAIN */
  .main{flex:1;display:flex;flex-direction:column;overflow:hidden}
  .topbar{background:var(--card);border-bottom:1px solid var(--border);padding:0 26px;height:58px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
  .topbar-title{font-size:16px;font-weight:700;color:var(--text)}
  .content{flex:1;overflow-y:auto;padding:24px 26px}

  /* CARDS */
  .grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
  .stat-card{background:var(--card);border-radius:14px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid var(--border);cursor:pointer;transition:transform .15s}
  .stat-card:hover{transform:translateY(-2px)}
  .stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;font-size:16px}
  .stat-icon.green{background:var(--green-pale);color:var(--green)}
  .stat-icon.amber{background:var(--amber-pale);color:var(--amber)}
  .stat-icon.blue{background:var(--blue-pale);color:var(--blue)}
  .stat-icon.red{background:var(--red-pale);color:var(--red)}
  .stat-value{font-size:26px;font-weight:800;color:var(--text)}
  .stat-label{font-size:12px;color:var(--text-mid);margin-top:2px}

  .section-title{font-size:20px;font-weight:800;margin-bottom:4px}
  .section-sub{font-size:13px;color:var(--text-mid);margin-bottom:22px}

  .card{background:var(--card);border-radius:14px;padding:22px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid var(--border);margin-bottom:20px}
  .card-title{font-size:14px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px}

  .coming-soon{text-align:center;padding:40px;color:var(--text-mid)}
  .coming-soon i{font-size:36px;margin-bottom:12px;color:var(--green-light);display:block}
</style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Dashboard</div>
    <div style="font-size:13px;color:#6b8070"><?= date('l, d F Y') ?></div>
  </div>

  <div class="content">
    <div class="section-title">Welcome back, <?= htmlspecialchars(explode(' ',$user['name'])[0]) ?></div>
    <div class="section-sub">Here is an overview of HR activity at Hambelela Organic today.</div>

    <?php if ($user['role'] === 'admin'): ?>
    <div class="grid-4">
      <div class="stat-card" onclick="location.href='employees.php'">
        <div class="stat-icon green"><i class="fa-solid fa-users"></i></div>
        <div class="stat-value"><?= $totalEmployees ?></div>
        <div class="stat-label">Active Employees</div>
      </div>
      <div class="stat-card" onclick="location.href='leave.php'">
        <div class="stat-icon amber"><i class="fa-solid fa-calendar-xmark"></i></div>
        <div class="stat-value"><?= $pendingLeave ?></div>
        <div class="stat-label">Pending Leave Requests</div>
      </div>
      <div class="stat-card" onclick="location.href='overtime.php'">
        <div class="stat-icon blue"><i class="fa-regular fa-clock"></i></div>
        <div class="stat-value"><?= $pendingOT ?></div>
        <div class="stat-label">Pending Overtime</div>
      </div>
      <div class="stat-card" onclick="location.href='payroll.php'">
        <div class="stat-icon green"><i class="fa-solid fa-money-bill-wave"></i></div>
        <div class="stat-value">N$0</div>
        <div class="stat-label">Monthly Payroll</div>
      </div>
    </div>
    <?php endif ?>

    <div class="card">
      <div class="card-title"><i class="fa-solid fa-rocket" style="color:var(--green)"></i> HR Portal is Live!</div>
      <p style="color:var(--text-mid);font-size:13px;line-height:1.7">
        Stage 2 complete — your login system is working. We are now building the full HR system step by step.<br><br>
        <strong>Next up:</strong> Employee Management — add, edit and manage your team.
      </p>
    </div>

  </div>
</div>

</body>
</html>
