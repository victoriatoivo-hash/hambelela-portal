<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_role('owner_admin', 'front_desk_admin');

$pageTitle = 'Error Log | ' . APP_NAME;
$activeApp = 'operations';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$errorLogFlash = $_SESSION['error_log_flash'] ?? null;
if (is_array($errorLogFlash)) {
    $message = (string) ($errorLogFlash['message'] ?? '');
    $messageType = (string) ($errorLogFlash['type'] ?? 'success');
}
unset($_SESSION['error_log_flash']);
$currentEmployeeId = ops_current_employee_id();
$currentRoleKey = current_role_key();
$isOwnerErrorUser = user_has_role('owner_admin');
$isFrontDeskErrorUser = user_has_role('front_desk_admin');
$canManageStatus = $isOwnerErrorUser;
$showFullErrorLog = $isOwnerErrorUser;

$severityLabels = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
$statusLabels = ['open' => 'Not Resolved', 'resolved' => 'Resolved'];
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

function error_log_redirect(string $message, string $type = 'success', string $query = ''): void
{
    $_SESSION['error_log_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    $location = BASE_URL . '/apps/operations/errors.php' . $query;
    header('Location: ' . $location, true, 303);
    exit;
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
    error_try_sql("ALTER TABLE ops_error_logs MODIFY category VARCHAR(100) NOT NULL");
    error_try_sql("ALTER TABLE ops_error_logs MODIFY severity VARCHAR(20) NOT NULL DEFAULT 'low'");
    $columns = [
        'error_title' => "ALTER TABLE ops_error_logs ADD COLUMN error_title VARCHAR(190) NULL AFTER id",
        'order_reference' => "ALTER TABLE ops_error_logs ADD COLUMN order_reference VARCHAR(60) NULL AFTER order_id",
        'people_involved' => "ALTER TABLE ops_error_logs ADD COLUMN people_involved TEXT NULL AFTER employee_id",
        'status' => "ALTER TABLE ops_error_logs ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'open' AFTER repeat_issue",
        'repeat_note' => "ALTER TABLE ops_error_logs ADD COLUMN repeat_note TEXT NULL AFTER repeat_issue",
        'attachment_paths' => "ALTER TABLE ops_error_logs ADD COLUMN attachment_paths TEXT NULL AFTER resolution",
        'updated_at' => "ALTER TABLE ops_error_logs ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER logged_at",
        'deleted_at' => "ALTER TABLE ops_error_logs ADD COLUMN deleted_at DATETIME NULL AFTER updated_at",
        'deleted_by' => "ALTER TABLE ops_error_logs ADD COLUMN deleted_by INT NULL AFTER deleted_at",
    ];
    foreach ($columns as $column => $sql) {
        if (!error_column_exists($column)) error_try_sql($sql);
    }
    error_try_sql("UPDATE ops_error_logs SET error_title = category WHERE error_title IS NULL OR error_title = ''");
    error_try_sql("UPDATE ops_error_logs SET status = 'open' WHERE status IS NULL OR status = ''");
    error_try_sql("UPDATE ops_error_logs SET status = 'open' WHERE status = 'in_review'");
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

function error_array_compare_value($value): string
{
    if (is_array($value)) {
        $value = array_values($value);
        sort($value);
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }
    return (string) $value;
}

function error_person_filter_sql(string $alias, int $personId): array
{
    return [
        "({$alias}.employee_id = ? OR {$alias}.people_involved LIKE ? OR {$alias}.people_involved LIKE ? OR {$alias}.people_involved LIKE ? OR {$alias}.people_involved LIKE ?)",
        [$personId, '[' . $personId . ']', '[' . $personId . ',%', '%,' . $personId . ',%', '%,' . $personId . ']'],
    ];
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
$employees = ops_canonical_employee_rows($employees, true);
$employeeMap = [];
foreach ($employees as $employee) $employeeMap[(int) $employee['id']] = ops_staff_display_name($employee);

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40);
        if ($action === 'create_error') {
            $submittedToken = (string) ($_POST['submission_token'] ?? '');
            $sessionToken = (string) ($_SESSION['incident_submission_token'] ?? '');
            if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
                error_log_redirect('This incident has already been submitted or the form expired.', 'error', '?form_error=1');
            }

            $title = ops_post_string('error_title', 190);
            $description = ops_post_string('description', 3000);
            $category = ops_post_string('category', 100);
            $otherCategory = ops_post_string('other_category', 100);
            $severity = ops_post_string('severity', 20);
            $people = array_values(array_filter(array_map('intval', $_POST['people_involved'] ?? [])));
            if ($title === '') throw new RuntimeException('Error title is required.');
            if ($description === '') throw new RuntimeException('Description is required.');
            if ($category === 'other') {
                if ($otherCategory === '') throw new RuntimeException('Other category is required.');
                $category = $otherCategory;
            } elseif (!array_key_exists($category, $errorCategories)) {
                throw new RuntimeException('Choose an error category.');
            }
            if (!array_key_exists($severity, $severityLabels)) throw new RuntimeException('Choose a severity.');
            if (!$people) throw new RuntimeException('Select at least one person involved.');

            $primaryEmployeeId = $people[0] ?? null;
            $orderReference = ops_post_string('order_reference', 60);
            $status = ops_post_string('status', 30) ?: 'open';
            if (!array_key_exists($status, $statusLabels)) $status = 'open';
            $stmt = db()->prepare(
                "INSERT INTO ops_error_logs
                 (error_title, employee_id, people_involved, order_id, order_reference, category, severity, description, customer_impact, financial_impact, resolution, repeat_issue, repeat_note, status, logged_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
                '',
                (float) ($_POST['financial_impact'] ?? 0),
                ops_post_string('resolution', 1500),
                (int) ($_POST['repeat_issue'] ?? 0) === 1 ? 1 : 0,
                '',
                $status,
                $currentEmployeeId,
            ]);
            $errorId = (int) db()->lastInsertId();
            $paths = error_upload_files($errorId);
            if ($paths) {
                $stmt = db()->prepare('UPDATE ops_error_logs SET attachment_paths = ? WHERE id = ?');
                $stmt->execute([json_encode($paths, JSON_UNESCAPED_SLASHES), $errorId]);
            }
            ops_activity_log('error_logged', 'error_log', $errorId, ['severity' => $severity, 'category' => $category, 'people_involved' => $people]);
            notifications_create_for_roles([
                'title' => $severity === 'critical' ? 'Critical error logged' : 'New error logged',
                'message' => $title,
                'module' => 'errors',
                'priority' => $severity === 'critical' ? 'urgent' : ($severity === 'high' ? 'important' : 'normal'),
                'related_type' => 'error_log',
                'related_id' => $errorId,
                'action_link' => BASE_URL . '/apps/operations/errors.php?error_id=' . $errorId,
            ], ['owner_admin', 'front_desk_admin', 'supervisor_manager']);
            unset($_SESSION['incident_submission_token']);
            error_log_redirect('Error logged and added to KPI tracking.', 'success', '?saved=1');
        }

        if ($action === 'update_error') {
            $errorId = (int) ($_POST['incident_id'] ?? 0);
            if ($errorId <= 0) throw new RuntimeException('Invalid incident.');

            $existingRows = ops_rows('SELECT * FROM ops_error_logs WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$errorId]);
            if (!$existingRows) throw new RuntimeException('Incident not found.');
            $existing = $existingRows[0];
            $loggedBy = (int) ($existing['logged_by'] ?? 0);
            if (!$isOwnerErrorUser && !($isFrontDeskErrorUser && $loggedBy === (int) $currentEmployeeId)) {
                throw new RuntimeException('You can only edit errors you logged yourself.');
            }

            $title = ops_post_string('error_title', 190);
            $description = ops_post_string('description', 3000);
            $category = ops_post_string('category', 100);
            $otherCategory = ops_post_string('other_category', 100);
            $severity = ops_post_string('severity', 20);
            $people = array_values(array_filter(array_map('intval', $_POST['people_involved'] ?? [])));
            if ($title === '') throw new RuntimeException('Error title is required.');
            if ($description === '') throw new RuntimeException('Description is required.');
            if ($category === 'other') {
                if ($otherCategory === '') throw new RuntimeException('Other category is required.');
                $category = $otherCategory;
            } elseif (!array_key_exists($category, $errorCategories)) {
                throw new RuntimeException('Choose an error category.');
            }
            if (!array_key_exists($severity, $severityLabels)) throw new RuntimeException('Choose a severity.');
            if (!$people) throw new RuntimeException('Select at least one person involved.');

            $status = ops_post_string('status', 30) ?: 'open';
            if (!array_key_exists($status, $statusLabels)) $status = 'open';
            $newData = [
                'error_title' => $title,
                'order_reference' => ops_post_string('order_reference', 60) ?: null,
                'category' => $category,
                'severity' => $severity,
                'people_involved' => $people,
                'description' => $description,
                'financial_impact' => (float) ($_POST['financial_impact'] ?? 0),
                'resolution' => ops_post_string('resolution', 1500),
                'repeat_issue' => (int) ($_POST['repeat_issue'] ?? 0) === 1 ? 1 : 0,
                'status' => $status,
            ];
            $changes = [];
            foreach ($newData as $field => $value) {
                $oldValue = $field === 'people_involved'
                    ? error_json_array((string) ($existing[$field] ?? ''))
                    : ($existing[$field] ?? '');
                if (error_array_compare_value($oldValue) !== error_array_compare_value($value)) {
                    $changes[$field] = ['from' => $oldValue, 'to' => $value];
                }
            }

            $stmt = db()->prepare(
                "UPDATE ops_error_logs
                 SET error_title = ?, employee_id = ?, people_involved = ?, order_reference = ?, category = ?, severity = ?, description = ?, financial_impact = ?, resolution = ?, repeat_issue = ?, status = ?
                 WHERE id = ? AND deleted_at IS NULL"
            );
            $stmt->execute([
                $newData['error_title'],
                $people[0] ?? null,
                json_encode($people, JSON_UNESCAPED_SLASHES),
                $newData['order_reference'],
                $newData['category'],
                $newData['severity'],
                $newData['description'],
                $newData['financial_impact'],
                $newData['resolution'],
                $newData['repeat_issue'],
                $newData['status'],
                $errorId,
            ]);

            $paths = error_upload_files($errorId);
            if ($paths) {
                $existingPaths = error_json_array((string) ($existing['attachment_paths'] ?? ''));
                $mergedPaths = array_values(array_unique(array_merge($existingPaths, $paths)));
                $stmt = db()->prepare('UPDATE ops_error_logs SET attachment_paths = ? WHERE id = ? AND deleted_at IS NULL');
                $stmt->execute([json_encode($mergedPaths, JSON_UNESCAPED_SLASHES), $errorId]);
                $changes['attachments'] = ['added' => $paths];
            }

            ops_activity_log('error_updated', 'error_log', $errorId, ['fields_changed' => array_keys($changes), 'changes' => $changes]);
            error_log_redirect('Error updated.', 'success', '?updated=1&error_id=' . $errorId);
        }

        if ($action === 'delete_error') {
            $errorId = (int) ($_POST['error_id'] ?? 0);
            if ($errorId <= 0) throw new RuntimeException('Invalid incident.');
            if (!in_array($currentRoleKey, ['owner_admin', 'owner', 'admin'], true)) {
                http_response_code(403);
                throw new RuntimeException('You do not have permission to delete this incident.');
            }
            $stmt = db()->prepare('UPDATE ops_error_logs SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$currentEmployeeId, $errorId]);
            ops_activity_log('error_deleted', 'error_log', $errorId, ['deleted_by' => $currentEmployeeId]);
            error_log_redirect('Error deleted.', 'success', '?deleted=1');
        }

        if ($action === 'update_status') {
            $errorId = (int) ($_POST['error_id'] ?? 0);
            $status = ops_post_string('status', 30);
            if (!array_key_exists($status, $statusLabels)) throw new RuntimeException('Choose a valid status.');
            $permissionRows = ops_rows('SELECT logged_by FROM ops_error_logs WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$errorId]);
            $loggedBy = (int) ($permissionRows[0]['logged_by'] ?? 0);
            if (!$isOwnerErrorUser && !($isFrontDeskErrorUser && $loggedBy === (int) $currentEmployeeId)) {
                throw new RuntimeException('You can only update errors you logged yourself.');
            }
            $stmt = db()->prepare('UPDATE ops_error_logs SET status = ? WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$status, $errorId]);
            ops_activity_log('error_status_updated', 'error_log', $errorId, ['status' => $status]);
            error_log_redirect('Error status updated.', 'success', '?status_updated=1');
        }
    } catch (Throwable $e) {
        error_log_redirect($e->getMessage(), 'error', '?form_error=1');
    }
}

if ($ready && empty($_SESSION['incident_submission_token'])) {
    $_SESSION['incident_submission_token'] = bin2hex(random_bytes(32));
}
$incidentSubmissionToken = (string) ($_SESSION['incident_submission_token'] ?? '');

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

$where = ['el.deleted_at IS NULL'];
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
    [$personSql, $personParams] = error_person_filter_sql('el', $personId);
    $where[] = $personSql;
    array_push($params, ...$personParams);
}
if ($isFrontDeskErrorUser && !$isOwnerErrorUser) {
    [$frontPersonSql, $frontPersonParams] = error_person_filter_sql('el', (int) $currentEmployeeId);
    $where[] = $frontPersonSql;
    array_push($params, ...$frontPersonParams);
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

$monthWhere = ['el.deleted_at IS NULL', "DATE_FORMAT(el.logged_at, '%Y-%m') = ?"];
$monthParams = [$filters['month'] ?: date('Y-m')];
if ($isFrontDeskErrorUser && !$isOwnerErrorUser) {
    [$frontMonthSql, $frontMonthParams] = error_person_filter_sql('el', (int) $currentEmployeeId);
    $monthWhere[] = $frontMonthSql;
    array_push($monthParams, ...$frontMonthParams);
}
$monthRows = $ready ? ops_rows(
    "SELECT el.*, e.full_name AS primary_employee_name
     FROM ops_error_logs el
     LEFT JOIN ops_employees e ON e.id = el.employee_id
     WHERE " . implode(' AND ', $monthWhere),
    $monthParams
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

$errorsByResolution = ['open' => [], 'resolved' => []];
foreach ($errors as $error) {
    $sectionKey = (string) ($error['status'] ?? 'open') === 'resolved' ? 'resolved' : 'open';
    $errorsByResolution[$sectionKey][] = $error;
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
    <section class="error-log-header">
        <div>
            <p class="error-log-kicker">Operations</p>
            <h1 class="error-log-title">Error Log</h1>
        </div>
        <button class="button primary error-log-btn-primary" type="button" data-error-modal-open><i data-lucide="plus"></i> Log Error</button>
    </section>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <?php if ($showFullErrorLog): ?>
        <?php
        $errorStats = [
            ['icon' => 'calendar-days', 'label' => 'Total Errors This Month', 'value' => number_format($metrics['month_total']), 'colour' => 'var(--bk-orange-red)'],
            ['icon' => 'siren', 'label' => 'Critical Errors', 'value' => number_format($metrics['critical']), 'colour' => 'var(--bk-red)'],
            ['icon' => 'triangle-alert', 'label' => 'High Severity', 'value' => number_format($metrics['high']), 'colour' => 'var(--bk-amber)'],
            ['icon' => 'info', 'label' => 'Medium Severity', 'value' => number_format($metrics['medium']), 'colour' => 'var(--bk-orange-red)'],
            ['icon' => 'badge-check', 'label' => 'Low Severity', 'value' => number_format($metrics['low']), 'colour' => 'var(--bk-olive)'],
            ['icon' => 'repeat-2', 'label' => 'Repeat Errors', 'value' => number_format($metrics['repeat']), 'colour' => 'var(--bk-burgundy)'],
            ['icon' => 'message-circle-warning', 'label' => 'Customer Impacting', 'value' => number_format($metrics['customer']), 'colour' => 'var(--bk-amber)'],
            ['icon' => 'check-circle-2', 'label' => 'Errors Resolved', 'value' => number_format($metrics['resolved']), 'colour' => 'var(--bk-olive)'],
            ['icon' => 'layers', 'label' => 'Most Common Category', 'value' => (string) $metrics['common_category'], 'colour' => 'var(--bk-orange-red)'],
            ['icon' => 'user-round-x', 'label' => 'Employee With Most Logged Errors', 'value' => (string) $metrics['top_employee'], 'colour' => 'var(--bk-burgundy)'],
        ];
        ?>
        <section class="error-stats-shell" aria-label="Error log metrics">
            <div class="error-stats-grid">
                <?php foreach ($errorStats as $stat): ?>
                    <article class="error-stat-card" style="--stat-colour: <?= htmlspecialchars($stat['colour'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="error-stat-icon"><i data-lucide="<?= htmlspecialchars($stat['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                        <div>
                            <span class="error-stat-label"><?= htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <strong class="error-stat-value"><?= htmlspecialchars((string) $stat['value'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <details class="error-filter-card" <?= $filtersAreActive ? 'open' : '' ?>>
        <summary class="error-filter-header"><span><i data-lucide="sliders-horizontal"></i> Filters</span><strong><?= $filtersAreActive ? 'Active' : 'Collapsed' ?></strong></summary>
        <form class="error-filter-body" method="get">
            <div class="error-filter-grid">
                <label>Month<input type="month" name="month" value="<?= htmlspecialchars($filters['month'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Date from<input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Date to<input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Severity<select name="severity"><option value="">All severity</option><?php ops_select_options($severityLabels, $filters['severity']); ?></select></label>
                <label>Category<select name="category"><option value="">All categories</option><?php ops_select_options($errorCategories, $filters['category']); ?></select></label>
                <?php if ($showFullErrorLog): ?><label>Person involved<select name="employee_id"><option value="">All people</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $filters['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <label>Repeat error<select name="repeat_issue"><?php ops_select_options(['' => 'All', '1' => 'Yes', '0' => 'No'], $filters['repeat_issue']); ?></select></label>
                <label>Customer impacted<select name="customer_impacted"><?php ops_select_options(['' => 'All', '1' => 'Yes', '0' => 'No'], $filters['customer_impacted']); ?></select></label>
                <label>Order ID<input name="order_reference" value="<?= htmlspecialchars($filters['order_reference'], ENT_QUOTES, 'UTF-8') ?>" placeholder="#33863 or WEB-33780"></label>
                <label>Resolution status<select name="status"><option value="">All statuses</option><?php ops_select_options($statusLabels, $filters['status']); ?></select></label>
            </div>
            <div class="ops-form-actions error-filter-actions"><a class="button" href="errors.php">Clear</a><button class="button primary" type="submit">Apply filters</button></div>
        </form>
    </details>

    <?php foreach (['open' => 'Not Resolved Errors', 'resolved' => 'Resolved Errors'] as $sectionStatus => $sectionTitle): ?>
    <?php $sectionErrors = $errorsByResolution[$sectionStatus] ?? []; ?>
    <section class="error-board-section error-section-<?= htmlspecialchars($sectionStatus, ENT_QUOTES, 'UTF-8') ?>">
        <div class="error-board-section-header">
            <h2 class="error-board-section-title"><?= htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') ?></h2>
            <span class="error-board-count"><?= number_format(count($sectionErrors)) ?> shown</span>
        </div>
        <div class="error-board-table-wrap">
        <table class="error-board-table<?= $showFullErrorLog ? '' : ' error-board-table--simple' ?>">
            <colgroup>
                <col class="col-date">
                <col class="col-title">
                <col class="col-order">
                <?php if ($showFullErrorLog): ?>
                    <col class="col-category">
                <?php endif; ?>
                <col class="col-severity">
                <?php if ($showFullErrorLog): ?>
                    <col class="col-person">
                    <col class="col-impact">
                <?php endif; ?>
                <col class="col-status">
                <?php if ($showFullErrorLog): ?>
                    <col class="col-repeat">
                    <col class="col-logged-by">
                <?php endif; ?>
            </colgroup>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Error Title</th>
                    <th scope="col">Order ID</th>
                    <?php if ($showFullErrorLog): ?>
                        <th scope="col">Category</th>
                    <?php endif; ?>
                    <th scope="col">Severity</th>
                    <?php if ($showFullErrorLog): ?>
                        <th scope="col">Person Involved</th>
                        <th scope="col">Customer Impact</th>
                    <?php endif; ?>
                    <th scope="col">Status</th>
                    <?php if ($showFullErrorLog): ?>
                        <th scope="col">Repeat</th>
                        <th scope="col">Logged By</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sectionErrors as $error): ?>
                <?php
                $peopleIds = error_json_array((string) ($error['people_involved'] ?? ''));
                if (!$peopleIds && !empty($error['employee_id'])) $peopleIds = [(int) $error['employee_id']];
                $peopleText = error_people_names($peopleIds, $employeeMap, (string) ($error['primary_employee_name'] ?? ''));
                $severity = (string) ($error['severity'] ?? 'low');
                $status = (string) ($error['status'] ?? 'open');
                ?>
                <?php $errorTitle = (string) ($error['error_title'] ?: ($errorCategories[(string) $error['category']] ?? $error['category'])); ?>
                <tr class="error-board-row" data-error-open="<?= (int) $error['id'] ?>" tabindex="0" aria-label="View incident <?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($showFullErrorLog): ?>
                        <td><?= error_date_label((string) ($error['logged_at'] ?? '')) ?></td>
                        <td><span class="error-board-title-link"><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string) ($error['order_reference'] ?: $error['order_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($errorCategories[(string) $error['category']] ?? (string) $error['category'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="error-board-severity severity-<?= htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($severityLabels[$severity] ?? $severity, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars($peopleText, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= trim((string) ($error['customer_impact'] ?? '')) !== '' ? 'Yes' : 'No' ?></td>
                        <td><span class="error-board-status status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= (int) ($error['repeat_issue'] ?? 0) === 1 ? 'Yes' : 'No' ?></td>
                        <td><?= htmlspecialchars((string) ($error['logged_by_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?></td>
                    <?php else: ?>
                        <td><?= error_date_label((string) ($error['logged_at'] ?? '')) ?></td>
                        <td><span class="error-board-title-link"><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string) ($error['order_reference'] ?: $error['order_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="error-board-severity severity-<?= htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($severityLabels[$severity] ?? $severity, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><span class="error-board-status status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sectionErrors): ?><tr class="error-board-empty"><td colspan="<?= $showFullErrorLog ? 10 : 5 ?>">No <?= strtolower(htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8')) ?> found for the selected filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endforeach; ?>

    <aside class="error-log-panel incident-modal" data-error-modal-panel aria-hidden="true" role="dialog" aria-modal="true" aria-label="Log error">
            <div class="error-log-panel-head incident-header">
                <div>
                    <span class="error-panel-kicker">Incident report</span>
                    <h2>Log Error</h2>
                </div>
                <button class="panel-close-button" type="button" data-error-modal-close aria-label="Close log error"><i data-lucide="x"></i></button>
            </div>
            <form id="logErrorForm" class="ops-form error-incident-form log-error-modal incident-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_error">
                <input type="hidden" name="incident_id" value="">
                <input type="hidden" name="submission_token" value="<?= htmlspecialchars($incidentSubmissionToken, ENT_QUOTES, 'UTF-8') ?>">
                <section class="error-form-section incident-section">
                    <h3><i data-lucide="file-warning"></i> Error Information</h3>
                    <div class="form-grid compact">
                        <label class="span-2">Error Title<input name="error_title" required placeholder="Wrong product packed"></label>
                        <label>Order ID if applicable<input name="order_reference" placeholder="#33863 or WEB-33780"></label>
                        <div class="incident-category-field">
                            <label class="incident-category-label" for="error-category-value">Category</label>
                            <div class="custom-select" data-custom-select>
                                <input type="hidden" name="category" id="error-category-value" required>
                                <button type="button" class="custom-select-trigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="custom-select-value">Choose category</span>
                                    <svg class="custom-select-chevron" viewBox="0 0 20 20" aria-hidden="true">
                                        <path d="M6 8l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <div class="custom-select-menu" role="listbox" tabindex="-1">
                                    <?php foreach ($errorCategories as $categoryValue => $categoryLabel): ?>
                                        <button
                                            type="button"
                                            class="custom-select-option"
                                            role="option"
                                            data-value="<?= htmlspecialchars((string) $categoryValue, ENT_QUOTES, 'UTF-8') ?>"
                                            aria-selected="false"
                                        ><?= htmlspecialchars((string) $categoryLabel, ENT_QUOTES, 'UTF-8') ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="incident-field incident-other-category is-hidden" id="incident-other-category-field">
                                <label for="incident-other-category">Other Category <span class="required">*</span></label>
                                <input type="text" id="incident-other-category" name="other_category" maxlength="100" placeholder="Enter the category" autocomplete="off" disabled>
                            </div>
                        </div>
                    </div>
                    <div class="incident-field incident-pill-field severity-choice severity-group" id="severity-group">
                        <label class="incident-pill-label" for="severityValue">Severity <span class="required">*</span></label>
                        <input type="hidden" name="severity" id="severityValue" required>
                        <div class="incident-pill-group">
                            <?php foreach ($severityLabels as $value => $label): ?>
                                <button class="pill-option severity-btn severity-<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" type="button" data-severity="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="incident-field incident-pill-field status-choice status-group" id="status-group">
                        <label class="incident-pill-label" for="statusValue">Status <span class="required">*</span></label>
                        <input type="hidden" name="status" id="statusValue" value="open" required>
                        <div class="incident-pill-group">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <button class="pill-option status-btn status-<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?> <?= $value === 'open' ? 'active' : '' ?>" type="button" data-status="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <section class="error-form-section incident-section">
                    <h3><i data-lucide="message-square-warning"></i> What Happened</h3>
                    <label for="description">Description<textarea id="description" name="description" required placeholder="Explain exactly what happened, what caused the issue, and what impact it had."></textarea></label>
                </section>

                <section class="error-form-section incident-section">
                    <h3><i data-lucide="users-round"></i> Responsibility</h3>
                    <div class="people-chip-grid">
                        <?php foreach ($employees as $employee): ?>
                            <label><input type="checkbox" name="people_involved[]" value="<?= (int) $employee['id'] ?>"><span><?= htmlspecialchars(ucwords(strtolower((string) $employee['full_name'])), ENT_QUOTES, 'UTF-8') ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <fieldset class="repeat-choice">
                        <legend>Is this a repeat error?</legend>
                        <label><input type="radio" name="repeat_issue" value="0" checked><span>No</span></label>
                        <label><input type="radio" name="repeat_issue" value="1"><span>Yes</span></label>
                    </fieldset>
                </section>

                <section class="error-form-section incident-section">
                    <h3><i data-lucide="check-circle-2"></i> Resolution</h3>
                    <div class="incident-resolution-stack">
                        <div class="incident-field">
                            <label for="resolution">Resolution</label>
                            <textarea id="resolution" name="resolution" placeholder="customer contacted, stock updated, product replaced"></textarea>
                        </div>
                        <div class="incident-field incident-financial-field">
                            <label for="financial-impact">Financial impact</label>
                            <input id="financial-impact" type="number" min="0" step="0.01" name="financial_impact" value="0">
                        </div>
                    </div>
                </section>

                <section class="error-form-section incident-section">
                    <h3><i data-lucide="paperclip"></i> Attachments</h3>
                    <label class="error-upload-zone">
                        <input type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx">
                        <span><i data-lucide="upload-cloud"></i></span>
                        <strong>Drag files here or upload screenshot</strong>
                        <small>Images, PDFs, screenshots and proof files</small>
                    </label>
                </section>

                <div class="ops-form-actions error-panel-actions incident-footer"><button class="button incident-btn-secondary" type="button" data-error-modal-close>Cancel</button><button class="button primary incident-btn-primary" type="submit">Save Issue</button></div>
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
        $canUpdateThisError = $isOwnerErrorUser || ($isFrontDeskErrorUser && (int) ($error['logged_by'] ?? 0) === (int) $currentEmployeeId);
        $canDeleteThisError = in_array($currentRoleKey, ['owner_admin', 'owner', 'admin'], true);
        $storedCategory = (string) ($error['category'] ?? '');
        $editData = [
            'id' => $errorId,
            'error_title' => (string) ($error['error_title'] ?? ''),
            'order_reference' => (string) ($error['order_reference'] ?? ''),
            'category' => array_key_exists($storedCategory, $errorCategories) ? $storedCategory : 'other',
            'other_category' => array_key_exists($storedCategory, $errorCategories) ? '' : $storedCategory,
            'severity' => $severity,
            'status' => $status,
            'description' => (string) ($error['description'] ?? ''),
            'people_involved' => $peopleIds,
            'repeat_issue' => (int) ($error['repeat_issue'] ?? 0),
            'resolution' => (string) ($error['resolution'] ?? ''),
            'financial_impact' => (string) ($error['financial_impact'] ?? 0),
        ];
        ?>
        <aside class="error-detail-panel incident-details-panel" data-error-panel="<?= $errorId ?>" aria-hidden="true">
            <script type="application/json" id="incident-edit-data-<?= $errorId ?>"><?= json_encode($editData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            <div class="incident-details-header">
                <div>
                    <span class="incident-details-severity" data-severity="<?= htmlspecialchars(strtolower($severity), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($severityLabels[$severity] ?? $severity, ENT_QUOTES, 'UTF-8') ?></span>
                    <p class="incident-details-eyebrow">Incident detail</p>
                    <h2 class="incident-details-title"><?= htmlspecialchars((string) ($error['error_title'] ?: ($errorCategories[(string) $error['category']] ?? $error['category'])), ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <button class="incident-details-close" type="button" data-error-close aria-label="Close error details"><i data-lucide="x"></i></button>
            </div>
            <div class="incident-details-body">
                <?php if ($canUpdateThisError || $canDeleteThisError): ?>
                    <div class="incident-panel-actions">
                        <?php if ($canUpdateThisError): ?>
                            <button type="button" class="incident-action-btn incident-action-btn--edit" data-edit-incident="<?= $errorId ?>">Edit error</button>
                        <?php endif; ?>
                        <?php if ($canDeleteThisError): ?>
                            <form method="post" class="incident-delete-form" data-delete-incident-form>
                                <input type="hidden" name="action" value="delete_error">
                                <input type="hidden" name="error_id" value="<?= $errorId ?>">
                                <button type="button" class="incident-action-btn incident-action-btn--delete" data-delete-incident>Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($canUpdateThisError): ?>
                    <form method="post" class="incident-status-card">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="error_id" value="<?= $errorId ?>">
                        <label for="detail-status-<?= $errorId ?>">Status</label>
                        <div class="incident-status-controls">
                            <select class="incident-status-select" id="detail-status-<?= $errorId ?>" name="status"><?php ops_select_options($statusLabels, $status); ?></select>
                            <button class="incident-status-update-btn" type="submit">Update status</button>
                        </div>
                    </form>
                <?php endif; ?>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="align-left"></i> Description</h3><p class="incident-content-text"><?= nl2br(htmlspecialchars((string) ($error['description'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="user-round-check"></i> Customer impact</h3><p class="incident-content-text<?= empty($error['customer_impact']) ? ' incident-empty-text' : '' ?>"><?= nl2br(htmlspecialchars((string) ($error['customer_impact'] ?: 'No customer impact recorded.'), ENT_QUOTES, 'UTF-8')) ?></p></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="circle-check"></i> Resolution</h3><p class="incident-content-text<?= empty($error['resolution']) ? ' incident-empty-text' : '' ?>"><?= nl2br(htmlspecialchars((string) ($error['resolution'] ?: 'No resolution recorded yet.'), ENT_QUOTES, 'UTF-8')) ?></p></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="banknote"></i> Financial impact</h3><p class="incident-content-text">N$<?= number_format((float) ($error['financial_impact'] ?? 0), 2) ?></p></section>
                <?php if (!empty($error['repeat_note'])): ?><section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="repeat-2"></i> Repeat note</h3><p class="incident-content-text"><?= nl2br(htmlspecialchars((string) $error['repeat_note'], ENT_QUOTES, 'UTF-8')) ?></p></section><?php endif; ?>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="paperclip"></i> Attachments</h3><div class="incident-attachments-list">
                    <?php foreach ($attachments as $path): ?><div class="incident-attachment"><span><?= htmlspecialchars(basename((string) $path), ENT_QUOTES, 'UTF-8') ?></span><a class="incident-attachment-link" href="<?= BASE_URL . '/' . htmlspecialchars((string) $path, ENT_QUOTES, 'UTF-8') ?>" target="_blank">Open</a></div><?php endforeach; ?>
                    <?php if (!$attachments): ?><p class="incident-content-text incident-empty-text">No attachments uploaded.</p><?php endif; ?>
                </div></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="history"></i> Edit history</h3><div class="incident-history-list">
                    <?php foreach (($activityByError[$errorId] ?? []) as $activity): ?>
                        <article class="incident-history-item"><p class="incident-history-action"><?= htmlspecialchars((string) $activity['action'], ENT_QUOTES, 'UTF-8') ?></p><p class="incident-history-meta"><?= htmlspecialchars((string) ($activity['employee_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) $activity['created_at'], ENT_QUOTES, 'UTF-8') ?></p></article>
                    <?php endforeach; ?>
                    <?php if (empty($activityByError[$errorId])): ?><p class="incident-content-text incident-empty-text">No edit history yet.</p><?php endif; ?>
                </div></section>
            </div>
        </aside>
    <?php endforeach; ?>
    <div class="panel-backdrop error-panel-backdrop incident-panel-overlay" data-error-close data-error-modal-close hidden></div>
    <div class="incident-delete-confirm" data-delete-confirm hidden role="dialog" aria-modal="true" aria-labelledby="incidentDeleteTitle">
        <div class="incident-delete-confirm-card">
            <h3 id="incidentDeleteTitle">Delete this error?</h3>
            <p>This action cannot be undone. The incident and its history will be removed.</p>
            <div class="incident-delete-confirm-actions">
                <button type="button" class="incident-action-btn incident-action-btn--edit" data-delete-cancel>Cancel</button>
                <button type="button" class="incident-action-btn incident-action-btn--delete" data-delete-confirm-submit>Delete error</button>
            </div>
        </div>
    </div>
</main>
<script>
let pendingDeleteForm = null;

function setIncidentPillValue(selector, dataAttr, value) {
  document.querySelectorAll(`#logErrorForm ${selector}`).forEach((button) => {
    button.classList.toggle('active', button.dataset[dataAttr] === value);
  });
}

function setIncidentCategoryValue(value, otherValue = '') {
  const hiddenInput = document.getElementById('error-category-value');
  const valueLabel = document.querySelector('#logErrorForm .custom-select-value');
  const options = Array.from(document.querySelectorAll('#logErrorForm .custom-select-option'));
  const option = options.find((item) => item.dataset.value === value);
  const otherCategoryField = document.getElementById('incident-other-category-field');
  const otherCategoryInput = document.getElementById('incident-other-category');

  options.forEach((item) => item.setAttribute('aria-selected', item === option ? 'true' : 'false'));
  if (hiddenInput) hiddenInput.value = value || '';
  if (valueLabel) valueLabel.textContent = option ? option.textContent.trim() : 'Choose category';

  const isOther = value === 'other';
  if (otherCategoryField && otherCategoryInput) {
    otherCategoryField.classList.toggle('is-hidden', !isOther);
    otherCategoryInput.disabled = !isOther;
    otherCategoryInput.required = isOther;
    otherCategoryInput.value = isOther ? otherValue : '';
    otherCategoryInput.classList.remove('is-invalid');
    otherCategoryInput.setCustomValidity('');
  }
}

function openIncidentForm(mode = 'create', data = {}) {
  const panel = document.querySelector('[data-error-modal-panel]');
  const form = document.getElementById('logErrorForm');
  const actionInput = form?.querySelector('[name="action"]');
  const incidentIdInput = form?.querySelector('[name="incident_id"]');
  const headerTitle = document.querySelector('[data-error-modal-panel] .incident-header h2');
  const submitButton = form?.querySelector('.incident-btn-primary');

  if (!panel || !form || !actionInput || !incidentIdInput) return;

  form.reset();
  document.querySelectorAll('#logErrorForm .field-error').forEach((error) => error.remove());
  document.getElementById('severity-group-error')?.remove();
  document.getElementById('status-group-error')?.remove();
  document.getElementById('error-category-value-error')?.remove();
  document.getElementById('description-error')?.remove();

  actionInput.value = mode === 'edit' ? 'update_error' : 'create_error';
  incidentIdInput.value = mode === 'edit' ? String(data.id || '') : '';
  if (headerTitle) headerTitle.textContent = mode === 'edit' ? 'Edit Error' : 'Log Error';
  if (submitButton) {
    submitButton.disabled = false;
    submitButton.textContent = mode === 'edit' ? 'Update Error' : 'Save Issue';
  }

  setIncidentCategoryValue(mode === 'edit' ? (data.category || '') : '', mode === 'edit' ? (data.other_category || '') : '');
  setIncidentPillValue('.severity-btn', 'severity', mode === 'edit' ? (data.severity || '') : '');
  setIncidentPillValue('.status-btn', 'status', mode === 'edit' ? (data.status || 'open') : 'open');

  const severityValue = document.getElementById('severityValue');
  const statusValue = document.getElementById('statusValue');
  if (severityValue) severityValue.value = mode === 'edit' ? (data.severity || '') : '';
  if (statusValue) statusValue.value = mode === 'edit' ? (data.status || 'open') : 'open';

  if (mode === 'edit') {
    form.elements.error_title.value = data.error_title || '';
    form.elements.order_reference.value = data.order_reference || '';
    form.elements.description.value = data.description || '';
    form.elements.resolution.value = data.resolution || '';
    form.elements.financial_impact.value = data.financial_impact || '0';
    form.querySelectorAll('[name="people_involved[]"]').forEach((checkbox) => {
      checkbox.checked = (data.people_involved || []).map(String).includes(String(checkbox.value));
    });
    const repeat = form.querySelector(`[name="repeat_issue"][value="${Number(data.repeat_issue || 0)}"]`);
    if (repeat) repeat.checked = true;
  }

  document.querySelectorAll('.error-detail-panel.open').forEach((openPanel) => openPanel.classList.remove('open'));
  panel.classList.add('open');
  const backdrop = document.querySelector('.error-panel-backdrop');
  if (backdrop) backdrop.hidden = false;
  document.body.classList.add('error-panel-open');
}

document.addEventListener('click', (event) => {
  const severityButton = event.target.closest('#logErrorForm .severity-btn');
  const statusButton = event.target.closest('#logErrorForm .status-btn');
  const modalOpen = event.target.closest('[data-error-modal-open]');
  const modalClose = event.target.closest('[data-error-modal-close]');
  const detailOpen = event.target.closest('[data-error-open]');
  const detailClose = event.target.closest('[data-error-close]');
  const editIncident = event.target.closest('[data-edit-incident]');
  const deleteIncident = event.target.closest('[data-delete-incident]');
  const deleteCancel = event.target.closest('[data-delete-cancel]');
  const deleteConfirmSubmit = event.target.closest('[data-delete-confirm-submit]');
  const uploadZone = event.target.closest('.error-upload-zone');
  if (uploadZone) {
    uploadZone.classList.remove('is-clicking');
    void uploadZone.offsetWidth;
    uploadZone.classList.add('is-clicking');
    window.setTimeout(() => uploadZone.classList.remove('is-clicking'), 460);
  }
  if (severityButton) {
    document.querySelectorAll('#logErrorForm .severity-btn').forEach((button) => button.classList.remove('active'));
    severityButton.classList.add('active');
    const severityValue = document.getElementById('severityValue');
    if (severityValue) {
      severityValue.value = severityButton.dataset.severity || '';
      severityValue.setCustomValidity('');
    }
    const error = document.getElementById('severity-group-error');
    if (error) error.remove();
  }
  if (statusButton) {
    document.querySelectorAll('#logErrorForm .status-btn').forEach((button) => button.classList.remove('active'));
    statusButton.classList.add('active');
    const statusValue = document.getElementById('statusValue');
    if (statusValue) {
      statusValue.value = statusButton.dataset.status || 'open';
      statusValue.setCustomValidity('');
    }
    const error = document.getElementById('status-group-error');
    if (error) error.remove();
  }
  if (modalOpen) {
    openIncidentForm('create');
  }
  if (editIncident) {
    const dataElement = document.getElementById(`incident-edit-data-${editIncident.dataset.editIncident}`);
    const data = dataElement ? JSON.parse(dataElement.textContent || '{}') : {};
    openIncidentForm('edit', data);
  }
  if (deleteIncident) {
    pendingDeleteForm = deleteIncident.closest('form');
    const confirm = document.querySelector('[data-delete-confirm]');
    if (confirm) confirm.hidden = false;
  }
  if (deleteCancel) {
    pendingDeleteForm = null;
    const confirm = document.querySelector('[data-delete-confirm]');
    if (confirm) confirm.hidden = true;
  }
  if (deleteConfirmSubmit && pendingDeleteForm) {
    pendingDeleteForm.submit();
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
  const errorRow = event.target.closest('.error-board-row[data-error-open]');
  if (!errorRow || (event.key !== 'Enter' && event.key !== ' ')) return;
  event.preventDefault();
  errorRow.click();
});

document.querySelectorAll('[data-custom-select]').forEach((select) => {
  const trigger = select.querySelector('.custom-select-trigger');
  const valueLabel = select.querySelector('.custom-select-value');
  const hiddenInput = select.querySelector('input[type="hidden"]');
  const menu = select.querySelector('.custom-select-menu');
  const optionButtons = Array.from(select.querySelectorAll('.custom-select-option'));
  let activeIndex = -1;
  const otherCategoryField = document.getElementById('incident-other-category-field');
  const otherCategoryInput = document.getElementById('incident-other-category');

  if (!trigger || !valueLabel || !hiddenInput || !menu || !optionButtons.length) return;

  function openMenu() {
    select.classList.add('is-open');
    trigger.setAttribute('aria-expanded', 'true');
    const selectedIndex = optionButtons.findIndex((option) => option.getAttribute('aria-selected') === 'true');
    activeIndex = selectedIndex >= 0 ? selectedIndex : 0;
    updateActiveOption();
  }

  function closeMenu() {
    select.classList.remove('is-open');
    trigger.setAttribute('aria-expanded', 'false');
    activeIndex = -1;
    clearActiveOption();
  }

  function selectOption(optionButton) {
    optionButtons.forEach((option) => option.setAttribute('aria-selected', 'false'));
    optionButton.setAttribute('aria-selected', 'true');
    const value = optionButton.dataset.value || '';
    hiddenInput.value = value;
    hiddenInput.setCustomValidity('');
    document.getElementById(hiddenInput.id + '-error')?.remove();
    valueLabel.textContent = optionButton.textContent.trim();
    updateOtherCategoryField(value);
    hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
    closeMenu();
    trigger.focus();
  }

  function updateOtherCategoryField(selectedCategory) {
    if (!otherCategoryField || !otherCategoryInput) return;
    const isOther = selectedCategory === 'other';
    otherCategoryField.classList.toggle('is-hidden', !isOther);
    otherCategoryInput.required = isOther;
    otherCategoryInput.disabled = !isOther;
    if (!isOther) {
      otherCategoryInput.value = '';
      otherCategoryInput.classList.remove('is-invalid');
      otherCategoryInput.setCustomValidity('');
    } else {
      window.requestAnimationFrame(() => otherCategoryInput.focus());
    }
  }

  function updateActiveOption() {
    optionButtons.forEach((option, index) => option.classList.toggle('is-active', index === activeIndex));
    optionButtons[activeIndex]?.scrollIntoView({ block: 'nearest' });
  }

  function clearActiveOption() {
    optionButtons.forEach((option) => option.classList.remove('is-active'));
  }

  trigger.addEventListener('click', () => {
    select.classList.contains('is-open') ? closeMenu() : openMenu();
  });

  optionButtons.forEach((option) => {
    option.addEventListener('click', () => selectOption(option));
  });

  trigger.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (!select.classList.contains('is-open')) {
        openMenu();
        return;
      }
      activeIndex = Math.min(activeIndex + 1, optionButtons.length - 1);
      updateActiveOption();
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault();
      if (!select.classList.contains('is-open')) {
        openMenu();
        return;
      }
      activeIndex = Math.max(activeIndex - 1, 0);
      updateActiveOption();
    }

    if (event.key === 'Enter' && select.classList.contains('is-open')) {
      event.preventDefault();
      selectOption(optionButtons[activeIndex]);
    }

    if (event.key === 'Escape') {
      closeMenu();
    }
  });

  document.addEventListener('click', (event) => {
    if (!select.contains(event.target)) closeMenu();
  });

  otherCategoryInput?.addEventListener('input', () => {
    if (otherCategoryInput.value.trim()) {
      otherCategoryInput.classList.remove('is-invalid');
      otherCategoryInput.setCustomValidity('');
    }
  });
});

document.getElementById('logErrorForm')?.addEventListener('submit', function(event) {
  const severityValue = document.getElementById('severityValue');
  const statusValue = document.getElementById('statusValue');
  const categoryValue = document.getElementById('error-category-value');
  const otherCategoryInput = document.getElementById('incident-other-category');
  const description = this.querySelector('[name="description"]');
  const severity = String(severityValue?.value || '').trim();
  const status = String(statusValue?.value || '').trim();
  const category = String(categoryValue?.value || '').trim();
  const otherCategory = String(otherCategoryInput?.value || '').trim();
  const descriptionText = String(description?.value || '').trim();

  document.getElementById('severity-group-error')?.remove();
  document.getElementById('status-group-error')?.remove();
  document.getElementById('error-category-value-error')?.remove();
  document.getElementById('description-error')?.remove();
  otherCategoryInput?.classList.remove('is-invalid');
  otherCategoryInput?.setCustomValidity('');

  let hasError = false;
  if (!category) {
    hasError = true;
    categoryValue?.setCustomValidity('Please choose an error category.');
    showFieldError('error-category-value', 'Choose an error category.');
  }
  if (category === 'other' && !otherCategory) {
    hasError = true;
    otherCategoryInput?.classList.add('is-invalid');
    otherCategoryInput?.setCustomValidity('Please enter the category.');
  }
  if (!severity) {
    hasError = true;
    severityValue?.setCustomValidity('Please select a severity level.');
    showFieldError('severity-group', 'Please select a severity level.');
  }
  if (!status) {
    hasError = true;
    statusValue?.setCustomValidity('Please select a status.');
    showFieldError('status-group', 'Please select a status.');
  }
  if (!descriptionText) {
    hasError = true;
    showFieldError('description', 'Description is required.');
  }

  if (hasError) {
    event.preventDefault();
    if (!category) {
      this.querySelector('.custom-select-trigger')?.focus();
    } else if (category === 'other' && !otherCategory) {
      otherCategoryInput?.reportValidity();
      otherCategoryInput?.focus();
    } else if (!severity) {
      this.querySelector('.severity-btn')?.focus();
    } else if (!status) {
      this.querySelector('.status-btn')?.focus();
    } else {
      description?.focus();
    }
    return;
  }

  const saveButton = this.querySelector('[type="submit"]');
  if (saveButton) {
    saveButton.disabled = true;
    saveButton.dataset.originalText = saveButton.textContent;
    saveButton.textContent = 'Saving...';
  }
});

function showFieldError(fieldId, message) {
  const existing = document.getElementById(fieldId + '-error');
  if (existing) existing.remove();

  const el = document.getElementById(fieldId) || document.querySelector('.' + fieldId);
  if (!el || !el.parentNode) return;

  const err = document.createElement('p');
  err.id = fieldId + '-error';
  err.style.cssText = 'color:#BB1B21; font-size:11px; margin:4px 0 0; font-weight:500;';
  err.textContent = message;
  el.parentNode.appendChild(err);
}

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  document.querySelectorAll('.error-log-panel.open, .error-detail-panel.open').forEach((panel) => panel.classList.remove('open'));
  const backdrop = document.querySelector('.error-panel-backdrop');
  if (backdrop) backdrop.hidden = true;
  document.body.classList.remove('error-panel-open');
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
