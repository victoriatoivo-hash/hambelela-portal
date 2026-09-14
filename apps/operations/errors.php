<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_role('owner_admin', 'front_desk_admin');

$pageTitle = 'Error Log | ' . APP_NAME;
$activeApp = 'operations';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$currentEmployeeId = ops_current_employee_id();
$canManageStatus = user_has_role('owner_admin');

$severityLabels = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
$statusLabels = ['open' => 'Open', 'in_review' => 'In Review', 'resolved' => 'Resolved'];
$errorCategories = [
    'wrong_product_packed' => 'Wrong Product Packed',
    'wrong_quantity_packed' => 'Wrong Quantity Packed',
    'missing_item' => 'Missing Item',
    'wrong_label' => 'Wrong Label',
    'stock_not_updated' => 'Stock Not Updated',
    'website_quantity_not_updated' => 'Website Quantity Not Updated',
    'customer_complaint' => 'Customer Complaint',
    'payment_issue' => 'Payment Issue',
    'short_payment' => 'Short Payment',
    'courier_delivery_issue' => 'Courier/Delivery Issue',
    'cleaning_workstation_issue' => 'Cleaning/Workstation Issue',
    'packaging_error' => 'Packaging Error',
    'admin_error' => 'Admin Error',
    'communication_error' => 'Communication Error',
    'other' => 'Other',
];

function error_try_sql(string $sql): void
{
    try {
        db()->exec($sql);
    } catch (Throwable $e) {
        // Keep the error log page usable even when older installs already have columns.
    }
}

function error_column_exists(string $column): bool
{
    return ops_table_exists('ops_error_logs') && ops_column_exists('ops_error_logs', $column);
}

function error_bootstrap_schema(): void
{
    if (!ops_database_ready()) return;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_error_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NULL,
            order_id INT NULL,
            category VARCHAR(80) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'low',
            description TEXT NOT NULL,
            customer_impact TEXT,
            financial_impact DECIMAL(12,2) NOT NULL DEFAULT 0,
            resolution TEXT,
            repeat_issue TINYINT(1) NOT NULL DEFAULT 0,
            logged_by INT NULL,
            logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
    error_try_sql("ALTER TABLE ops_error_logs MODIFY category VARCHAR(80) NOT NULL");
    error_try_sql("ALTER TABLE ops_error_logs MODIFY severity VARCHAR(20) NOT NULL DEFAULT 'low'");
    $columns = [
        'error_title' => "ALTER TABLE ops_error_logs ADD COLUMN error_title VARCHAR(190) NULL AFTER id",
        'order_reference' => "ALTER TABLE ops_error_logs ADD COLUMN order_reference VARCHAR(60) NULL AFTER order_id",
        'people_involved' => "ALTER TABLE ops_error_logs ADD COLUMN people_involved TEXT NULL AFTER employee_id",
        'status' => "ALTER TABLE ops_error_logs ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'open' AFTER repeat_issue",
        'repeat_note' => "ALTER TABLE ops_error_logs ADD COLUMN repeat_note TEXT NULL AFTER repeat_issue",
        'attachment_paths' => "ALTER TABLE ops_error_logs ADD COLUMN attachment_paths TEXT NULL AFTER resolution",
        'updated_at' => "ALTER TABLE ops_error_logs ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER logged_at",
    ];
    foreach ($columns as $column => $sql) {
        if (!error_column_exists($column)) error_try_sql($sql);
    }
    error_try_sql("UPDATE ops_error_logs SET error_title = category WHERE error_title IS NULL OR error_title = ''");
    error_try_sql("UPDATE ops_error_logs SET status = 'open' WHERE status IS NULL OR status = ''");
}

function error_json_array(?string $value): array
{
    if (!$value) return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? array_values(array_filter($decoded)) : [];
}

function error_upload_files(int $errorId): array
{
    if (empty($_FILES['attachments']['name']) || !is_array($_FILES['attachments']['name'])) return [];
    $uploadDir = BASE_PATH . '/uploads/error-log';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $paths = [];
    foreach ($_FILES['attachments']['name'] as $index => $name) {
        if (($name ?? '') === '' || !is_uploaded_file($_FILES['attachments']['tmp_name'][$index])) continue;
        $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'], true)) continue;
        $fileName = 'error-' . $errorId . '-' . date('YmdHis') . '-' . ($index + 1) . '.' . $extension;
        if (move_uploaded_file($_FILES['attachments']['tmp_name'][$index], $uploadDir . '/' . $fileName)) {
            $paths[] = 'uploads/error-log/' . $fileName;
        }
    }
    return $paths;
}

function error_date_label(?string $value): string
{
    if (!$value) return '-';
    try { return (new DateTimeImmutable($value))->format('M j, Y H:i'); } catch (Throwable $e) { return $value; }
}

function error_people_names(array $ids, array $employeeMap, ?string $fallback = null): string
{
    $names = [];
    foreach ($ids as $id) {
        $key = (int) $id;
        if (isset($employeeMap[$key])) $names[] = $employeeMap[$key];
    }
    if (!$names && $fallback) $names[] = $fallback;
    return $names ? implode(', ', array_unique($names)) : 'Unassigned';
}

if ($ready) {
    error_bootstrap_schema();
}

$employees = $ready ? ops_rows(
    "SELECT e.id, e.full_name, r.role_key
     FROM ops_employees e
     JOIN ops_roles r ON r.id = e.role_id
     WHERE e.status = 'active'
     ORDER BY FIELD(r.role_key, 'owner_admin', 'front_desk_admin', 'supervisor_manager', 'packer'), e.full_name"
) : [];
$employeeMap = [];
foreach ($employees as $employee) $employeeMap[(int) $employee['id']] = (string) $employee['full_name'];

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40);
        if ($action === 'create_error') {
            $title = ops_post_string('error_title', 190);
            $description = ops_post_string('description', 3000);
            $category = ops_post_string('category', 80);
            $severity = ops_post_string('severity', 20);
            $people = array_values(array_filter(array_map('intval', $_POST['people_involved'] ?? [])));
            if ($title === '') throw new RuntimeException('Error title is required.');
            if ($description === '') throw new RuntimeException('Description is required.');
            if (!array_key_exists($category, $errorCategories)) throw new RuntimeException('Choose an error category.');
            if (!array_key_exists($severity, $severityLabels)) throw new RuntimeException('Choose a severity.');
            if (!$people) throw new RuntimeException('Select at least one person involved.');

            $primaryEmployeeId = $people[0] ?? null;
            $orderReference = ops_post_string('order_reference', 60);
            $stmt = db()->prepare(
                "INSERT INTO ops_error_logs
                 (error_title, employee_id, people_involved, order_id, order_reference, category, severity, description, customer_impact, financial_impact, resolution, repeat_issue, repeat_note, status, logged_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)"
            );
            $stmt->execute([
                $title,
                $primaryEmployeeId,
                json_encode($people, JSON_UNESCAPED_SLASHES),
                null,
                $orderReference ?: null,
                $category,
                $severity,
                $description,
                ops_post_string('customer_impact', 1500),
                (float) ($_POST['financial_impact'] ?? 0),
                ops_post_string('resolution', 1500),
                (int) ($_POST['repeat_issue'] ?? 0) === 1 ? 1 : 0,
                ops_post_string('repeat_note', 1000),
                $currentEmployeeId,
            ]);
            $errorId = (int) db()->lastInsertId();
            $paths = error_upload_files($errorId);
            if ($paths) {
                $stmt = db()->prepare('UPDATE ops_error_logs SET attachment_paths = ? WHERE id = ?');
                $stmt->execute([json_encode($paths, JSON_UNESCAPED_SLASHES), $errorId]);
            }
            ops_activity_log('error_logged', 'error_log', $errorId, ['severity' => $severity, 'category' => $category, 'people_involved' => $people]);
            $message = 'Error logged and added to KPI tracking.';
        }

        if ($action === 'update_status' && $canManageStatus) {
            $errorId = (int) ($_POST['error_id'] ?? 0);
            $status = ops_post_string('status', 30);
            if (!array_key_exists($status, $statusLabels)) throw new RuntimeException('Choose a valid status.');
            $stmt = db()->prepare('UPDATE ops_error_logs SET status = ? WHERE id = ?');
            $stmt->execute([$status, $errorId]);
            ops_activity_log('error_status_updated', 'error_log', $errorId, ['status' => $status]);
            $message = 'Error status updated.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$filters = [
    'month' => trim((string) ($_GET['month'] ?? date('Y-m'))),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'severity' => trim((string) ($_GET['severity'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'employee_id' => trim((string) ($_GET['employee_id'] ?? '')),
    'repeat_issue' => trim((string) ($_GET['repeat_issue'] ?? '')),
    'customer_impacted' => trim((string) ($_GET['customer_impacted'] ?? '')),
    'order_reference' => trim((string) ($_GET['order_reference'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
];
$filtersAreActive = $filters['date_from'] !== '' || $filters['date_to'] !== '' || $filters['severity'] !== '' || $filters['category'] !== '' || $filters['employee_id'] !== '' || $filters['repeat_issue'] !== '' || $filters['customer_impacted'] !== '' || $filters['order_reference'] !== '' || $filters['status'] !== '';

$where = [];
$params = [];
if ($filters['date_from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
    $where[] = 'DATE(el.logged_at) >= ?';
    $params[] = $filters['date_from'];
}
if ($filters['date_to'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
    $where[] = 'DATE(el.logged_at) <= ?';
    $params[] = $filters['date_to'];
}
if (!$filters['date_from'] && !$filters['date_to'] && preg_match('/^\d{4}-\d{2}$/', $filters['month'])) {
    $where[] = "DATE_FORMAT(el.logged_at, '%Y-%m') = ?";
    $params[] = $filters['month'];
}
if (array_key_exists($filters['severity'], $severityLabels)) {
    $where[] = 'el.severity = ?';
    $params[] = $filters['severity'];
}
if (array_key_exists($filters['category'], $errorCategories)) {
    $where[] = 'el.category = ?';
    $params[] = $filters['category'];
}
if ((int) $filters['employee_id'] > 0) {
    $personId = (int) $filters['employee_id'];
    $where[] = '(el.employee_id = ? OR el.people_involved LIKE ? OR el.people_involved LIKE ? OR el.people_involved LIKE ? OR el.people_involved LIKE ?)';
    $params[] = $personId;
    $params[] = '[' . $personId . ']';
    $params[] = '[' . $personId . ',%';
    $params[] = '%,' . $personId . ',%';
    $params[] = '%,' . $personId . ']';
}
if (in_array($filters['repeat_issue'], ['0', '1'], true)) {
    $where[] = 'el.repeat_issue = ?';
    $params[] = (int) $filters['repeat_issue'];
}
if (in_array($filters['customer_impacted'], ['0', '1'], true)) {
    $where[] = $filters['customer_impacted'] === '1' ? "TRIM(COALESCE(el.customer_impact, '')) <> ''" : "TRIM(COALESCE(el.customer_impact, '')) = ''";
}
if ($filters['order_reference'] !== '') {
    $where[] = '(el.order_reference LIKE ? OR CAST(el.order_id AS CHAR) LIKE ?)';
    $params[] = '%' . $filters['order_reference'] . '%';
    $params[] = '%' . $filters['order_reference'] . '%';
}
if (array_key_exists($filters['status'], $statusLabels)) {
    $where[] = 'el.status = ?';
    $params[] = $filters['status'];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$errors = $ready ? ops_rows(
    "SELECT el.*, e.full_name AS primary_employee_name, lb.full_name AS logged_by_name
     FROM ops_error_logs el
     LEFT JOIN ops_employees e ON e.id = el.employee_id
     LEFT JOIN ops_employees lb ON lb.id = el.logged_by
     {$whereSql}
     ORDER BY el.logged_at DESC
     LIMIT 300",
    $params
) : [];

$monthRows = $ready ? ops_rows(
    "SELECT el.*, e.full_name AS primary_employee_name
     FROM ops_error_logs el
     LEFT JOIN ops_employees e ON e.id = el.employee_id
     WHERE DATE_FORMAT(el.logged_at, '%Y-%m') = ?",
    [$filters['month'] ?: date('Y-m')]
) : [];

$metrics = [
    'month_total' => count($monthRows),
    'critical' => 0,
    'high' => 0,
    'medium' => 0,
    'low' => 0,
    'repeat' => 0,
    'customer' => 0,
    'resolved' => 0,
    'common_category' => '-',
    'top_employee' => '-',
];
$categoryCounts = [];
$employeeCounts = [];
foreach ($monthRows as $row) {
    $severity = (string) ($row['severity'] ?? 'low');
    if (isset($metrics[$severity])) $metrics[$severity]++;
    if ((int) ($row['repeat_issue'] ?? 0) === 1) $metrics['repeat']++;
    if (trim((string) ($row['customer_impact'] ?? '')) !== '') $metrics['customer']++;
    if ((string) ($row['status'] ?? 'open') === 'resolved') $metrics['resolved']++;
    $cat = (string) ($row['category'] ?? 'other');
    $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
    $people = error_json_array((string) ($row['people_involved'] ?? ''));
    if (!$people && !empty($row['employee_id'])) $people = [(int) $row['employee_id']];
    foreach ($people as $personId) $employeeCounts[(int) $personId] = ($employeeCounts[(int) $personId] ?? 0) + 1;
}
if ($categoryCounts) {
    arsort($categoryCounts);
    $metrics['common_category'] = $errorCategories[(string) array_key_first($categoryCounts)] ?? (string) array_key_first($categoryCounts);
}
if ($employeeCounts) {
    arsort($employeeCounts);
    $topId = (int) array_key_first($employeeCounts);
    $metrics['top_employee'] = ($employeeMap[$topId] ?? 'Employee') . ' (' . number_format((int) current($employeeCounts)) . ')';
}

$activityByError = [];
if ($ready && $errors && ops_table_exists('ops_activity_logs')) {
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $errors);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $activityRows = ops_rows(
        "SELECT al.*, e.full_name AS employee_name
         FROM ops_activity_logs al
         LEFT JOIN ops_employees e ON e.id = al.employee_id
         WHERE al.entity_type = 'error_log' AND al.entity_id IN ({$placeholders})
         ORDER BY al.created_at DESC
         LIMIT 300",
        $ids
    );
    foreach ($activityRows as $row) $activityByError[(int) $row['entity_id']][] = $row;
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module error-log-page">
    <a class="button back-link" href="<?= BASE_URL ?>/apps/operations/index.php"><i data-lucide="arrow-left"></i> Back to Operations</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Operations</p>
            <h1>Error Log</h1>
            <p>Track mistakes, customer impact, repeat issues, resolution and employee accountability.</p>
        </div>
        <button class="button primary" type="button" data-error-modal-open><i data-lucide="plus"></i> Log Error</button>
    </section>
    <?php ops_nav('errors'); ?>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <section class="error-metric-grid">
        <article class="error-metric red"><i data-lucide="calendar-days"></i><span>Total Errors This Month</span><strong><?= number_format($metrics['month_total']) ?></strong></article>
        <article class="error-metric red"><i data-lucide="siren"></i><span>Critical Errors</span><strong><?= number_format($metrics['critical']) ?></strong></article>
        <article class="error-metric orange"><i data-lucide="triangle-alert"></i><span>High Severity</span><strong><?= number_format($metrics['high']) ?></strong></article>
        <article class="error-metric blue"><i data-lucide="info"></i><span>Medium Severity</span><strong><?= number_format($metrics['medium']) ?></strong></article>
        <article class="error-metric green"><i data-lucide="badge-check"></i><span>Low Severity</span><strong><?= number_format($metrics['low']) ?></strong></article>
        <article class="error-metric purple"><i data-lucide="repeat-2"></i><span>Repeat Errors</span><strong><?= number_format($metrics['repeat']) ?></strong></article>
        <article class="error-metric orange"><i data-lucide="message-circle-warning"></i><span>Customer Impacting</span><strong><?= number_format($metrics['customer']) ?></strong></article>
        <article class="error-metric teal"><i data-lucide="check-circle-2"></i><span>Errors Resolved</span><strong><?= number_format($metrics['resolved']) ?></strong></article>
        <article class="error-metric blue wide"><i data-lucide="layers"></i><span>Most Common Category</span><strong><?= htmlspecialchars($metrics['common_category'], ENT_QUOTES, 'UTF-8') ?></strong></article>
        <article class="error-metric purple wide"><i data-lucide="user-round-x"></i><span>Employee With Most Logged Errors</span><strong><?= htmlspecialchars($metrics['top_employee'], ENT_QUOTES, 'UTF-8') ?></strong></article>
    </section>

    <details class="panel error-filter-panel" <?= $filtersAreActive ? 'open' : '' ?>>
        <summary><span><i data-lucide="sliders-horizontal"></i> Filters</span><strong><?= $filtersAreActive ? 'Active' : 'Collapsed' ?></strong></summary>
        <form method="get">
            <div class="form-grid compact">
                <label>Month<input type="month" name="month" value="<?= htmlspecialchars($filters['month'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Date from<input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Date to<input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Severity<select name="severity"><option value="">All severity</option><?php ops_select_options($severityLabels, $filters['severity']); ?></select></label>
                <label>Category<select name="category"><option value="">All categories</option><?php ops_select_options($errorCategories, $filters['category']); ?></select></label>
                <label>Person involved<select name="employee_id"><option value="">All people</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $filters['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                <label>Repeat error<select name="repeat_issue"><?php ops_select_options(['' => 'All', '1' => 'Yes', '0' => 'No'], $filters['repeat_issue']); ?></select></label>
                <label>Customer impacted<select name="customer_impacted"><?php ops_select_options(['' => 'All', '1' => 'Yes', '0' => 'No'], $filters['customer_impacted']); ?></select></label>
                <label>Order ID<input name="order_reference" value="<?= htmlspecialchars($filters['order_reference'], ENT_QUOTES, 'UTF-8') ?>" placeholder="#33863 or WEB-33780"></label>
                <label>Resolution status<select name="status"><option value="">All statuses</option><?php ops_select_options($statusLabels, $filters['status']); ?></select></label>
            </div>
            <div class="ops-form-actions"><a class="button" href="errors.php">Clear</a><button class="button primary" type="submit">Apply filters</button></div>
        </form>
    </details>

    <section class="panel error-list-panel">
        <div class="section-row"><h2>Recent Errors</h2><span class="status"><?= number_format(count($errors)) ?> shown</span></div>
        <div class="error-table">
            <div class="error-table-head">
                <span>Date</span><span>Error Title</span><span>Order ID</span><span>Category</span><span>Severity</span><span>Person Involved</span><span>Customer Impact</span><span>Status</span><span>Repeat</span><span>Logged By</span>
            </div>
            <?php foreach ($errors as $error): ?>
                <?php
                $peopleIds = error_json_array((string) ($error['people_involved'] ?? ''));
                if (!$peopleIds && !empty($error['employee_id'])) $peopleIds = [(int) $error['employee_id']];
                $peopleText = error_people_names($peopleIds, $employeeMap, (string) ($error['primary_employee_name'] ?? ''));
                $severity = (string) ($error['severity'] ?? 'low');
                $status = (string) ($error['status'] ?? 'open');
                ?>
                <button class="error-row" type="button" data-error-open="<?= (int) $error['id'] ?>">
                    <span><?= error_date_label((string) ($error['logged_at'] ?? '')) ?></span>
                    <strong><?= htmlspecialchars((string) ($error['error_title'] ?: ($errorCategories[(string) $error['category']] ?? $error['category'])), ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars((string) ($error['order_reference'] ?: $error['order_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= htmlspecialchars($errorCategories[(string) $error['category']] ?? (string) $error['category'], ENT_QUOTES, 'UTF-8') ?></span>
                    <em class="error-severity severity-<?= htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($severityLabels[$severity] ?? $severity, ENT_QUOTES, 'UTF-8') ?></em>
                    <span><?= htmlspecialchars($peopleText, ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= trim((string) ($error['customer_impact'] ?? '')) !== '' ? 'Yes' : 'No' ?></span>
                    <em class="error-status status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?></em>
                    <span><?= (int) ($error['repeat_issue'] ?? 0) === 1 ? 'Yes' : 'No' ?></span>
                    <span><?= htmlspecialchars((string) ($error['logged_by_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endforeach; ?>
            <?php if (!$errors): ?><p class="task-empty">No matching errors found for the selected filters.</p><?php endif; ?>
        </div>
    </section>

    <aside class="error-log-panel" data-error-modal-panel aria-hidden="true" role="dialog" aria-modal="true" aria-label="Log error">
            <div class="error-log-panel-head">
                <button class="panel-back-button" type="button" data-error-modal-close><i data-lucide="arrow-left"></i> Back</button>
                <div>
                    <span class="error-panel-kicker">Incident report</span>
                    <h2>Log Error</h2>
                    <p>Document what happened, who was involved, impact, resolution and proof.</p>
                </div>
                <button class="panel-close-button" type="button" data-error-modal-close aria-label="Close log error"><i data-lucide="x"></i></button>
            </div>
            <form class="ops-form error-incident-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_error">
                <section class="error-form-section">
                    <h3><i data-lucide="file-warning"></i> Error Information</h3>
                    <div class="form-grid compact">
                        <label class="span-2">Error Title<input name="error_title" required placeholder="Wrong product packed"></label>
                        <label>Order ID if applicable<input name="order_reference" placeholder="#33863 or WEB-33780"></label>
                        <label>Category<select name="category" required><option value="">Choose category</option><?php ops_select_options($errorCategories); ?></select></label>
                    </div>
                    <fieldset class="severity-choice">
                        <legend>Severity</legend>
                        <?php foreach ($severityLabels as $value => $label): ?>
                            <label class="severity-<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><input type="radio" name="severity" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === 'medium' ? 'checked' : '' ?>><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                </section>

                <section class="error-form-section">
                    <h3><i data-lucide="message-square-warning"></i> What Happened</h3>
                    <label>Description<textarea name="description" required placeholder="Explain exactly what happened, what caused the issue, and what impact it had."></textarea></label>
                    <label>Customer Impact<textarea name="customer_impact" placeholder="customer received wrong item, order delayed, no customer impact"></textarea></label>
                </section>

                <section class="error-form-section">
                    <h3><i data-lucide="users-round"></i> Responsibility</h3>
                    <div class="people-chip-grid">
                        <?php foreach ($employees as $employee): ?>
                            <label><input type="checkbox" name="people_involved[]" value="<?= (int) $employee['id'] ?>"><span><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <fieldset class="repeat-choice">
                        <legend>Is this a repeat error?</legend>
                        <label><input type="radio" name="repeat_issue" value="0" checked><span>No</span></label>
                        <label><input type="radio" name="repeat_issue" value="1"><span>Yes</span></label>
                    </fieldset>
                    <label>Repeat note if applicable<textarea name="repeat_note" placeholder="Briefly explain the previous occurrence."></textarea></label>
                </section>

                <section class="error-form-section">
                    <h3><i data-lucide="check-circle-2"></i> Resolution</h3>
                    <div class="form-grid compact">
                        <label class="span-2">Resolution<textarea name="resolution" placeholder="customer contacted, stock updated, product replaced"></textarea></label>
                        <label>Financial impact<input type="number" step="0.01" name="financial_impact" value="0"></label>
                    </div>
                </section>

                <section class="error-form-section">
                    <h3><i data-lucide="paperclip"></i> Attachments</h3>
                    <label class="error-upload-zone">
                        <input type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx">
                        <span><i data-lucide="upload-cloud"></i></span>
                        <strong>Drag files here or upload screenshot</strong>
                        <small>Images, PDFs, screenshots and proof files</small>
                    </label>
                </section>

                <div class="ops-form-actions error-panel-actions"><button class="button" type="button" data-error-modal-close>Cancel</button><button class="button primary" type="submit">Save Issue</button></div>
            </form>
    </aside>

    <?php foreach ($errors as $error): ?>
        <?php
        $errorId = (int) $error['id'];
        $peopleIds = error_json_array((string) ($error['people_involved'] ?? ''));
        if (!$peopleIds && !empty($error['employee_id'])) $peopleIds = [(int) $error['employee_id']];
        $peopleText = error_people_names($peopleIds, $employeeMap, (string) ($error['primary_employee_name'] ?? ''));
        $attachments = error_json_array((string) ($error['attachment_paths'] ?? ''));
        $severity = (string) ($error['severity'] ?? 'low');
        $status = (string) ($error['status'] ?? 'open');
        ?>
        <aside class="error-detail-panel" data-error-panel="<?= $errorId ?>" aria-hidden="true">
            <div class="task-detail-head">
                <button class="panel-back-button" type="button" data-error-close><i data-lucide="arrow-left"></i> Back</button>
                <div><span class="error-severity severity-<?= htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($severityLabels[$severity] ?? $severity, ENT_QUOTES, 'UTF-8') ?></span><h2><?= htmlspecialchars((string) ($error['error_title'] ?: ($errorCategories[(string) $error['category']] ?? $error['category'])), ENT_QUOTES, 'UTF-8') ?></h2></div>
                <button class="panel-close-button" type="button" data-error-close aria-label="Close error details"><i data-lucide="x"></i></button>
            </div>
            <div class="task-detail-grid">
                <div><span>Date logged</span><strong><?= error_date_label((string) ($error['logged_at'] ?? '')) ?></strong></div>
                <div><span>Logged by</span><strong><?= htmlspecialchars((string) ($error['logged_by_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><span>Order ID</span><strong><?= htmlspecialchars((string) ($error['order_reference'] ?: $error['order_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><span>People involved</span><strong><?= htmlspecialchars($peopleText, ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><span>Category</span><strong><?= htmlspecialchars($errorCategories[(string) $error['category']] ?? (string) $error['category'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><span>Repeat error</span><strong><?= (int) ($error['repeat_issue'] ?? 0) === 1 ? 'Yes' : 'No' ?></strong></div>
            </div>
            <?php if ($canManageStatus): ?>
                <form method="post" class="task-admin-edit">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="error_id" value="<?= $errorId ?>">
                    <label>Status<select name="status"><?php ops_select_options($statusLabels, $status); ?></select></label>
                    <button class="button small" type="submit">Update status</button>
                </form>
            <?php endif; ?>
            <section><h3>Description</h3><p><?= nl2br(htmlspecialchars((string) ($error['description'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p></section>
            <section><h3>Customer impact</h3><p><?= nl2br(htmlspecialchars((string) ($error['customer_impact'] ?: 'No customer impact recorded.'), ENT_QUOTES, 'UTF-8')) ?></p></section>
            <section><h3>Resolution</h3><p><?= nl2br(htmlspecialchars((string) ($error['resolution'] ?: 'No resolution recorded yet.'), ENT_QUOTES, 'UTF-8')) ?></p></section>
            <?php if (!empty($error['repeat_note'])): ?><section><h3>Repeat note</h3><p><?= nl2br(htmlspecialchars((string) $error['repeat_note'], ENT_QUOTES, 'UTF-8')) ?></p></section><?php endif; ?>
            <section><h3>Attachments</h3><div class="error-attachments">
                <?php foreach ($attachments as $path): ?><a class="button small" href="<?= BASE_URL . '/' . htmlspecialchars((string) $path, ENT_QUOTES, 'UTF-8') ?>" target="_blank">Open attachment</a><?php endforeach; ?>
                <?php if (!$attachments): ?><p>No attachments uploaded.</p><?php endif; ?>
            </div></section>
            <section><h3>Edit history</h3><div class="activity-log">
                <?php foreach (($activityByError[$errorId] ?? []) as $activity): ?>
                    <div class="activity-line"><strong><?= htmlspecialchars((string) $activity['action'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars((string) ($activity['employee_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) $activity['created_at'], ENT_QUOTES, 'UTF-8') ?></span></div>
                <?php endforeach; ?>
                <?php if (empty($activityByError[$errorId])): ?><p>No edit history yet.</p><?php endif; ?>
            </div></section>
        </aside>
    <?php endforeach; ?>
    <div class="panel-backdrop error-panel-backdrop" data-error-close data-error-modal-close hidden></div>
</main>
<script>
document.addEventListener('click', (event) => {
  const modalOpen = event.target.closest('[data-error-modal-open]');
  const modalClose = event.target.closest('[data-error-modal-close]');
  const detailOpen = event.target.closest('[data-error-open]');
  const detailClose = event.target.closest('[data-error-close]');
  if (modalOpen) {
    const panel = document.querySelector('[data-error-modal-panel]');
    if (panel) panel.classList.add('open');
    const backdrop = document.querySelector('.error-panel-backdrop');
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('error-panel-open');
  }
  if (modalClose) {
    const panel = document.querySelector('[data-error-modal-panel]');
    if (panel) panel.classList.remove('open');
    const detailOpenPanel = document.querySelector('.error-detail-panel.open');
    const backdrop = document.querySelector('.error-panel-backdrop');
    if (!detailOpenPanel && backdrop) {
      backdrop.hidden = true;
      document.body.classList.remove('error-panel-open');
    }
  }
  if (detailOpen) {
    document.querySelectorAll('.error-detail-panel.open').forEach((panel) => panel.classList.remove('open'));
    const panel = document.querySelector(`[data-error-panel="${detailOpen.dataset.errorOpen}"]`);
    if (panel) panel.classList.add('open');
    const backdrop = document.querySelector('.error-panel-backdrop');
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('error-panel-open');
  }
  if (detailClose) {
    document.querySelectorAll('.error-detail-panel.open').forEach((panel) => panel.classList.remove('open'));
    const logPanel = document.querySelector('[data-error-modal-panel]');
    const backdrop = document.querySelector('.error-panel-backdrop');
    if ((!logPanel || !logPanel.classList.contains('open')) && backdrop) {
      backdrop.hidden = true;
      document.body.classList.remove('error-panel-open');
    }
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  document.querySelectorAll('.error-log-panel.open, .error-detail-panel.open').forEach((panel) => panel.classList.remove('open'));
  const backdrop = document.querySelector('.error-panel-backdrop');
  if (backdrop) backdrop.hidden = true;
  document.body.classList.remove('error-panel-open');
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
