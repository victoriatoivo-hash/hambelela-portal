<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_role('owner_admin');

$pageTitle = 'KPI Dashboard | ' . APP_NAME;
$activeApp = 'kpi';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$csrf = (string) ($_SESSION['kpi_reports_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['kpi_reports_csrf'] = $csrf;
}

$settingDefaults = [
    'data_start_date' => '2026-07-01',
    'adoption_date' => '2026-07-14',
    'target_fulfilment_hours' => '6',
    'on_time_dispatch_target_hours' => '6',
    'waybill_overdue_threshold_hours' => '24',
    'website_update_lag_target_minutes' => '60',
    'stale_work_threshold_days' => '2',
    'working_days' => '1,2,3,4,5',
    'shift_start' => '08:00',
    'shift_end' => '17:00',
    'late_grace_minutes' => '10',
];

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Your session token is invalid.');
        $action = ops_post_string('kpi_action', 40);
        if ($action === 'save_settings') {
            $stmt = db()->prepare("INSERT INTO kpi_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
            foreach ($settingDefaults as $key => $default) {
                $value = substr(trim((string) ($_POST[$key] ?? $default)), 0, 255);
                if (in_array($key, ['data_start_date', 'adoption_date'], true) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) throw new RuntimeException('Enter valid KPI dates.');
                $stmt->execute([$key, $value, ops_current_employee_id() ?: null]);
            }
            $message = 'KPI settings saved.';
        } elseif ($action === 'add_holiday') {
            $date = ops_post_string('holiday_date', 20);
            $name = ops_post_string('holiday_name', 160);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $name === '') throw new RuntimeException('Enter a holiday date and name.');
            db()->prepare("INSERT INTO kpi_holidays (holiday_date, holiday_name, active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name), active = 1")->execute([$date, $name]);
            $message = 'Holiday saved.';
        } else {
            throw new RuntimeException('Unknown KPI action.');
        }
    } catch (Throwable $error) {
        $message = $error->getMessage();
        $messageType = 'error';
    }
}

$settings = $settingDefaults;
$events = [];
$sessions = [];
$holidays = [];
$summary = ['events_today' => 0, 'active_sessions' => 0, 'employees_today' => 0, 'modules_today' => 0];
if ($ready) {
    foreach (ops_rows('SELECT setting_key, setting_value FROM kpi_settings') as $row) {
        if (array_key_exists((string) $row['setting_key'], $settings)) $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
    $events = ops_rows("SELECT se.*, e.full_name AS changed_by_name FROM kpi_status_events se LEFT JOIN ops_employees e ON e.id = se.changed_by ORDER BY se.changed_at DESC, se.id DESC LIMIT 100");
    $sessions = ops_rows("SELECT s.*, e.full_name FROM kpi_sessions s LEFT JOIN ops_employees e ON e.id = s.user_id ORDER BY s.login_at DESC, s.id DESC LIMIT 50");
    $holidays = ops_rows('SELECT * FROM kpi_holidays WHERE active = 1 ORDER BY holiday_date ASC');
    $summaryRow = ops_rows("SELECT COUNT(*) AS events_today, COUNT(DISTINCT changed_by) AS employees_today, COUNT(DISTINCT module) AS modules_today FROM kpi_status_events WHERE changed_at >= CURDATE()")[0] ?? [];
    $activeRow = ops_rows("SELECT COUNT(*) AS active_sessions FROM kpi_sessions WHERE logout_at IS NULL AND last_seen_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 HOUR)")[0] ?? [];
    $summary = [
        'events_today' => (int) ($summaryRow['events_today'] ?? 0),
        'active_sessions' => (int) ($activeRow['active_sessions'] ?? 0),
        'employees_today' => (int) ($summaryRow['employees_today'] ?? 0),
        'modules_today' => (int) ($summaryRow['modules_today'] ?? 0),
    ];
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<style>
.kpi1{padding:24px;font-family:Figtree,system-ui,sans-serif;color:#1a1a1a}.kpi1 h1{margin:0;color:#721b1a;font-size:26px}.kpi1-sub{margin:5px 0 20px;color:#6b4c3b;font-size:12px}.kpi1-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.kpi1-card,.kpi1-panel{padding:16px;border:1px solid #ede3d8;border-radius:12px;background:#fff;box-shadow:0 8px 22px rgba(114,27,26,.05)}.kpi1-card span{display:block;color:#a08070;font-size:10px;text-transform:uppercase}.kpi1-card strong{display:block;margin-top:7px;color:#721b1a;font-size:24px}.kpi1-layout{margin-top:16px;display:grid;grid-template-columns:minmax(0,1.5fr) minmax(300px,.8fr);gap:16px}.kpi1-panel h2{margin:0 0 12px;color:#721b1a;font-size:15px}.kpi1-table{width:100%;border-collapse:collapse;font-size:11px}.kpi1-table th,.kpi1-table td{padding:9px 8px;border-bottom:1px solid #ede3d8;text-align:left}.kpi1-table th{color:#6b4c3b;font-size:10px}.kpi1-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.kpi1-form label{display:grid;gap:4px;color:#6b4c3b;font-size:10px}.kpi1-form input{height:34px;padding:0 9px;border:1px solid #ede3d8;border-radius:8px}.kpi1-button{height:34px;padding:0 13px;border:0;border-radius:8px;background:#ab3619;color:#fff;cursor:pointer}.kpi1-message{margin-bottom:14px;padding:10px 12px;border-radius:9px;background:#f2f8df;color:#3d5c00;font-size:12px}.kpi1-message.error{background:#fff0f0;color:#bb1b21}.kpi1-stack{display:grid;gap:16px}@media(max-width:900px){.kpi1-grid{grid-template-columns:repeat(2,1fr)}.kpi1-layout{grid-template-columns:1fr}}@media(max-width:520px){.kpi1-grid,.kpi1-form{grid-template-columns:1fr}}
</style>
<main class="kpi1">
  <h1>KPI Dashboard</h1>
  <p class="kpi1-sub">Phase 1 evidence foundation · status transitions, sessions, settings and working-calendar controls.</p>
  <?php if ($message): ?><div class="kpi1-message <?= $messageType === 'error' ? 'error' : '' ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <section class="kpi1-grid">
    <article class="kpi1-card"><span>Status events today</span><strong><?= $summary['events_today'] ?></strong></article>
    <article class="kpi1-card"><span>Employees measured</span><strong><?= $summary['employees_today'] ?></strong></article>
    <article class="kpi1-card"><span>Modules reporting</span><strong><?= $summary['modules_today'] ?></strong></article>
    <article class="kpi1-card"><span>Open sessions</span><strong><?= $summary['active_sessions'] ?></strong></article>
  </section>
  <section class="kpi1-layout">
    <div class="kpi1-stack">
      <article class="kpi1-panel"><h2>Recent status evidence</h2><div style="overflow:auto"><table class="kpi1-table"><thead><tr><th>When</th><th>Module</th><th>Record</th><th>Change</th><th>Employee</th></tr></thead><tbody><?php foreach ($events as $row): ?><tr><td><?= htmlspecialchars((string) $row['changed_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $row['module'], ENT_QUOTES, 'UTF-8') ?></td><td>#<?= (int) $row['record_id'] ?></td><td><?= htmlspecialchars((string) ($row['old_status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> → <?= htmlspecialchars((string) $row['new_status'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($row['changed_by_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?><?php if (!$events): ?><tr><td colspan="5">No status events recorded yet.</td></tr><?php endif; ?></tbody></table></div></article>
      <article class="kpi1-panel"><h2>Recent sessions</h2><div style="overflow:auto"><table class="kpi1-table"><thead><tr><th>Employee</th><th>Login</th><th>Last seen</th><th>Logout</th></tr></thead><tbody><?php foreach ($sessions as $row): ?><tr><td><?= htmlspecialchars((string) ($row['full_name'] ?? ('Employee #' . $row['user_id'])), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $row['login_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $row['last_seen_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($row['logout_at'] ?? 'Open'), ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?><?php if (!$sessions): ?><tr><td colspan="4">No sessions recorded yet.</td></tr><?php endif; ?></tbody></table></div></article>
    </div>
    <div class="kpi1-stack">
      <article class="kpi1-panel"><h2>Phase 1 settings</h2><form method="post" class="kpi1-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="kpi_action" value="save_settings"><?php foreach ($settings as $key => $value): ?><label><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key)), ENT_QUOTES, 'UTF-8') ?><input name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= strpos($key, 'date') !== false ? 'type="date"' : '' ?>></label><?php endforeach; ?><div><button class="kpi1-button" type="submit">Save settings</button></div></form></article>
      <article class="kpi1-panel"><h2>Working holidays</h2><form method="post" class="kpi1-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="kpi_action" value="add_holiday"><label>Date<input type="date" name="holiday_date" required></label><label>Name<input name="holiday_name" required></label><div><button class="kpi1-button" type="submit">Save holiday</button></div></form><table class="kpi1-table" style="margin-top:12px"><tbody><?php foreach ($holidays as $row): ?><tr><td><?= htmlspecialchars((string) $row['holiday_date'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $row['holiday_name'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?></tbody></table></article>
    </div>
  </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
