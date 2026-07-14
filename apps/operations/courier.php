<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_login();

$pageTitle = 'Courier Waybills | ' . APP_NAME;
$activeApp = 'operations-courier';
$ready = ops_database_ready();
$currentUser = current_user();
$currentEmployeeId = ops_current_employee_id();
$roleKey = current_role_key();
$canUploadWaybills = in_array($roleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'packer', 'packer_production_staff', 'supervisor_manager'], true);
$canSendWaybills = in_array($roleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager'], true);
$canExportWaybills = $roleKey === 'owner_admin';
$historyDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_from'] ?? '')) ? (string) $_GET['date_from'] : date('Y-m-d', strtotime('-7 days'));
$historyDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_to'] ?? '')) ? (string) $_GET['date_to'] : date('Y-m-d');

function wb_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function wb_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function wb_try_sql(string $sql): void
{
    try {
        db()->exec($sql);
    } catch (Throwable $e) {
        error_log('Waybill schema SQL skipped: ' . $e->getMessage());
    }
}

function wb_bootstrap_schema(): void
{
    if (!ops_database_ready()) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS hambelela_waybills (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(36) NOT NULL,
            uploaded_by INT NOT NULL,
            uploaded_at DATETIME NOT NULL,
            customer_name VARCHAR(255) NULL,
            waybill_reference VARCHAR(100) NULL,
            order_id VARCHAR(50) NULL,
            sent_date DATE NULL,
            courier_names TEXT NULL,
            number_of_waybills INT NOT NULL DEFAULT 1,
            file_path VARCHAR(500) NOT NULL,
            original_filename VARCHAR(190) NULL,
            notes TEXT NULL,
            due_by DATETIME NOT NULL,
            sent_by INT NULL,
            sent_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            waybill_reminder_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_waybill_batch (batch_id),
            INDEX idx_waybill_status_due (status, due_by),
            INDEX idx_waybill_uploaded_at (uploaded_at),
            INDEX idx_waybill_uploaded_by (uploaded_by),
            INDEX idx_waybill_sent_by (sent_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $columns = [
        'original_filename' => "ALTER TABLE hambelela_waybills ADD COLUMN original_filename VARCHAR(190) NULL AFTER file_path",
        'sent_date' => "ALTER TABLE hambelela_waybills ADD COLUMN sent_date DATE NULL AFTER order_id",
        'courier_names' => "ALTER TABLE hambelela_waybills ADD COLUMN courier_names TEXT NULL AFTER sent_date",
        'number_of_waybills' => "ALTER TABLE hambelela_waybills ADD COLUMN number_of_waybills INT NOT NULL DEFAULT 1 AFTER courier_names",
        'waybill_reminder_sent' => "ALTER TABLE hambelela_waybills ADD COLUMN waybill_reminder_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
        'updated_at' => "ALTER TABLE hambelela_waybills ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($columns as $column => $sql) {
        if (!ops_column_exists('hambelela_waybills', $column)) {
            wb_try_sql($sql);
        }
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS hambelela_waybill_sla_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            waybill_id INT NOT NULL,
            user_id INT NOT NULL,
            due_by DATETIME NOT NULL,
            sent_at DATETIME NULL,
            minutes_late INT NULL,
            logged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_waybill_sla_waybill (waybill_id),
            INDEX idx_waybill_sla_user (user_id),
            INDEX idx_waybill_sla_logged (logged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_courier_waybills (
            id INT AUTO_INCREMENT PRIMARY KEY,
            waybill_reference VARCHAR(120) NULL,
            customer_name VARCHAR(190) NULL,
            sent_date DATE NULL,
            courier_names TEXT NULL,
            number_of_waybills INT NOT NULL DEFAULT 1,
            notes TEXT NULL,
            label_path VARCHAR(255) NOT NULL,
            original_filename VARCHAR(190) NULL,
            uploaded_by INT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_by INT NULL,
            sent_at DATETIME NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'uploaded',
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_waybill_uploaded_at (uploaded_at),
            INDEX idx_waybill_status (status),
            INDEX idx_waybill_uploaded_by (uploaded_by),
            INDEX idx_waybill_sent_by (sent_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $legacyColumns = [
        'waybill_reference' => "ALTER TABLE ops_courier_waybills ADD COLUMN waybill_reference VARCHAR(120) NULL AFTER id",
        'customer_name' => "ALTER TABLE ops_courier_waybills ADD COLUMN customer_name VARCHAR(190) NULL AFTER waybill_reference",
        'sent_date' => "ALTER TABLE ops_courier_waybills ADD COLUMN sent_date DATE NULL AFTER customer_name",
        'courier_names' => "ALTER TABLE ops_courier_waybills ADD COLUMN courier_names TEXT NULL AFTER sent_date",
        'number_of_waybills' => "ALTER TABLE ops_courier_waybills ADD COLUMN number_of_waybills INT NOT NULL DEFAULT 1 AFTER courier_names",
        'notes' => "ALTER TABLE ops_courier_waybills ADD COLUMN notes TEXT NULL AFTER customer_name",
        'original_filename' => "ALTER TABLE ops_courier_waybills ADD COLUMN original_filename VARCHAR(190) NULL AFTER label_path",
        'uploaded_by' => "ALTER TABLE ops_courier_waybills ADD COLUMN uploaded_by INT NULL AFTER original_filename",
        'uploaded_at' => "ALTER TABLE ops_courier_waybills ADD COLUMN uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER uploaded_by",
        'sent_by' => "ALTER TABLE ops_courier_waybills ADD COLUMN sent_by INT NULL AFTER uploaded_at",
        'sent_at' => "ALTER TABLE ops_courier_waybills ADD COLUMN sent_at DATETIME NULL AFTER sent_by",
        'status' => "ALTER TABLE ops_courier_waybills ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'uploaded' AFTER sent_at",
        'updated_at' => "ALTER TABLE ops_courier_waybills ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER status",
    ];
    foreach ($legacyColumns as $column => $sql) {
        if (!ops_column_exists('ops_courier_waybills', $column)) {
            wb_try_sql($sql);
        }
    }

    wb_import_legacy_waybills();
}

function wb_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

function wb_is_business_day(DateTimeImmutable $date): bool
{
    $day = (int) $date->format('N');
    return $day >= 1 && $day <= 5;
}

function wb_next_business_day(DateTimeImmutable $date): DateTimeImmutable
{
    $cursor = $date->modify('+1 day')->setTime(8, 30);
    for ($i = 0; $i < 10; $i++) {
        if (wb_is_business_day($cursor)) {
            return $cursor;
        }
        $cursor = $cursor->modify('+1 day')->setTime(8, 30);
    }

    return $cursor;
}

function wb_due_for_upload(DateTimeImmutable $uploadedAt): DateTimeImmutable
{
    return wb_next_business_day($uploadedAt);
}

function wb_due_label(?string $dueBy): string
{
    if (!$dueBy) {
        return '-';
    }
    try {
        return (new DateTimeImmutable($dueBy))->format('D, d M H:i');
    } catch (Throwable $e) {
        return (string) $dueBy;
    }
}

function wb_dt(?string $value): string
{
    if (!$value) {
        return '-';
    }
    try {
        return (new DateTimeImmutable($value))->format('d M H:i');
    } catch (Throwable $e) {
        return (string) $value;
    }
}

function wb_batch_id(): string
{
    $raw = bin2hex(random_bytes(16));
    return substr($raw, 0, 8) . '-' . substr($raw, 8, 4) . '-' . substr($raw, 12, 4) . '-' . substr($raw, 16, 4) . '-' . substr($raw, 20);
}

function wb_current_name(): string
{
    $user = current_user();
    $name = trim((string) ($user['name'] ?? ''));
    if ($name !== '' && strtolower($name) !== 'guest') {
        return $name;
    }

    return 'Current user';
}

function wb_allowed_couriers(): array
{
    return ['Jet-X', 'Nampost', 'Coastal Courier', 'Hardap freight', 'formula Courier'];
}

function wb_post_couriers(): array
{
    $posted = $_POST['couriers'] ?? [];
    if (!is_array($posted)) {
        $posted = [$posted];
    }

    $selected = [];
    foreach ($posted as $courier) {
        $courier = trim((string) $courier);
        if ($courier !== '') {
            $selected[] = $courier;
        }
    }

    return array_values(array_unique($selected));
}

function wb_cecilia_employee_id(): ?int
{
    $rows = ops_rows(
        "SELECT e.id, e.full_name, e.email, r.role_key
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         WHERE e.status = 'active' AND r.role_key = 'front_desk_admin'
         ORDER BY
           CASE WHEN LOWER(e.email) = 'shiwedasecilia3@gmail.com' THEN 0 ELSE 1 END,
           CASE WHEN LOWER(e.full_name) LIKE '%cecilia%' OR LOWER(e.full_name) LIKE '%shiweda%' THEN 0 ELSE 1 END,
           e.id ASC
         LIMIT 1"
    );

    return $rows ? (int) $rows[0]['id'] : null;
}

function wb_employee_names_by_id(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = ops_rows(
        "SELECT e.id, e.full_name, e.email, r.role_key
         FROM ops_employees e
         LEFT JOIN ops_roles r ON r.id = e.role_id
         WHERE e.id IN ({$placeholders})",
        $ids
    );
    $names = [];
    foreach ($rows as $row) {
        $names[(int) $row['id']] = ops_staff_display_name($row);
    }

    return $names;
}

function wb_notify_cecilia(string $title, string $message, string $priority = 'normal'): void
{
    $ceciliaId = wb_cecilia_employee_id();
    $data = [
        'title' => $title,
        'message' => $message,
        'module' => 'operations',
        'related_type' => 'courier_waybill',
        'priority' => $priority,
        'action_link' => BASE_URL . '/apps/operations/courier.php',
    ];
    if ($ceciliaId) {
        notifications_create($data, [$ceciliaId]);
        return;
    }
    notifications_create_for_roles($data, ['front_desk_admin', 'owner_admin']);
}

function wb_import_legacy_waybills(): void
{
    if (!ops_table_exists('ops_courier_waybills')) {
        return;
    }

    $rows = ops_rows(
        "SELECT old.*
         FROM ops_courier_waybills old
         LEFT JOIN hambelela_waybills nw ON nw.file_path = old.label_path
         WHERE nw.id IS NULL
         ORDER BY old.id ASC
         LIMIT 500"
    );
    if (!$rows) {
        return;
    }

    $stmt = db()->prepare(
        "INSERT INTO hambelela_waybills
            (batch_id, uploaded_by, uploaded_at, customer_name, waybill_reference, order_id, sent_date, courier_names, number_of_waybills, file_path, original_filename, notes, due_by, sent_by, sent_at, status, created_at)
         VALUES (?, ?, ?, ?, ?, NULL, ?, NULL, 1, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($rows as $row) {
        $uploadedAt = (string) ($row['uploaded_at'] ?? date('Y-m-d H:i:s'));
        try {
            $dueBy = wb_due_for_upload(new DateTimeImmutable($uploadedAt))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $dueBy = wb_due_for_upload(wb_now())->format('Y-m-d H:i:s');
        }
        $status = strtolower((string) ($row['status'] ?? 'uploaded'));
        $status = $status === 'sent' ? 'sent' : 'pending';
        $stmt->execute([
            'legacy-' . (int) $row['id'],
            max(1, (int) ($row['uploaded_by'] ?? 0)),
            $uploadedAt,
            $row['customer_name'] ?? null,
            $row['waybill_reference'] ?? null,
            substr($uploadedAt, 0, 10),
            $row['label_path'],
            $row['original_filename'] ?? basename((string) $row['label_path']),
            $row['notes'] ?? null,
            $dueBy,
            $row['sent_by'] ?? null,
            $row['sent_at'] ?? null,
            $status,
            $uploadedAt,
        ]);
    }
}

function wb_log_sla(int $waybillId, int $userId, string $dueBy, ?string $sentAt): void
{
    $existing = ops_rows(
        "SELECT id FROM hambelela_waybill_sla_log WHERE waybill_id = ? AND ((sent_at IS NULL AND ? IS NULL) OR sent_at = ?) LIMIT 1",
        [$waybillId, $sentAt, $sentAt]
    );
    if ($existing) {
        return;
    }

    $minutesLate = null;
    try {
        $due = new DateTimeImmutable($dueBy);
        $actual = $sentAt ? new DateTimeImmutable($sentAt) : wb_now();
        $minutesLate = (int) floor(($actual->getTimestamp() - $due->getTimestamp()) / 60);
    } catch (Throwable $e) {
        $minutesLate = null;
    }

    $stmt = db()->prepare(
        "INSERT INTO hambelela_waybill_sla_log (waybill_id, user_id, due_by, sent_at, minutes_late)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$waybillId, $userId, $dueBy, $sentAt, $minutesLate]);
}

function wb_update_overdue_and_reminders(): void
{
    if (!ops_table_exists('hambelela_waybills')) {
        return;
    }

    $ceciliaId = wb_cecilia_employee_id() ?: 0;
    $overdueRows = ops_rows(
        "SELECT id, due_by
         FROM hambelela_waybills
         WHERE status = 'pending' AND due_by < NOW()
         LIMIT 500"
    );
    if ($overdueRows) {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $overdueRows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("UPDATE hambelela_waybills SET status = 'overdue' WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        foreach ($overdueRows as $row) {
            if ($ceciliaId > 0) {
                wb_log_sla((int) $row['id'], $ceciliaId, (string) $row['due_by'], null);
            }
        }
    }

    $reminders = ops_rows(
        "SELECT batch_id, sent_date, courier_names, number_of_waybills, due_by, COUNT(*) AS file_count
         FROM hambelela_waybills
         WHERE status = 'pending'
           AND waybill_reminder_sent = 0
           AND due_by BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 MINUTE)
         GROUP BY batch_id, sent_date, courier_names, number_of_waybills, due_by
         LIMIT 30"
    );
    foreach ($reminders as $row) {
        $count = (int) ($row['number_of_waybills'] ?? $row['file_count'] ?? 0);
        $message = $count . ' waybill' . ($count === 1 ? '' : 's') .
            ' for ' . ((string) ($row['courier_names'] ?: 'selected courier')) .
            ' dated ' . ((string) ($row['sent_date'] ?: 'today')) .
            ' are due by ' . wb_due_label((string) $row['due_by']) . '.';
        wb_notify_cecilia('Waybill due soon', $message, 'important');
        $stmt = db()->prepare('UPDATE hambelela_waybills SET waybill_reminder_sent = 1 WHERE batch_id = ?');
        $stmt->execute([(string) $row['batch_id']]);
    }
}

function wb_safe_file_path(string $relativePath): ?string
{
    $relativePath = str_replace('\\', '/', trim($relativePath));
    if ($relativePath === '' || strpos($relativePath, '..') !== false) {
        return null;
    }

    $absolute = realpath(BASE_PATH . '/' . $relativePath);
    $uploadRoot = realpath(BASE_PATH . '/uploads');
    if (!$absolute || !$uploadRoot || strpos($absolute, $uploadRoot) !== 0 || !is_file($absolute)) {
        return null;
    }

    return $absolute;
}

function wb_files_for_batch(string $batchId): array
{
    return ops_rows(
        "SELECT id, file_path, original_filename
         FROM hambelela_waybills
         WHERE batch_id = ?
         ORDER BY id ASC",
        [$batchId]
    );
}

function wb_stream_batch_download(string $batchId): void
{
    $files = wb_files_for_batch($batchId);
    if (!$files) {
        http_response_code(404);
        exit('No files found.');
    }

    if (count($files) === 1) {
        $file = $files[0];
        $path = wb_safe_file_path((string) $file['file_path']);
        if (!$path) {
            http_response_code(404);
            exit('File not found.');
        }
        $downloadName = (string) ($file['original_filename'] ?: basename($path));
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('ZIP download is not available on this server.');
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'waybills-');
    if ($zipPath === false) {
        http_response_code(500);
        exit('Could not prepare ZIP file.');
    }
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    foreach ($files as $index => $file) {
        $path = wb_safe_file_path((string) $file['file_path']);
        if (!$path) {
            continue;
        }
        $name = (string) ($file['original_filename'] ?: basename($path));
        $zip->addFile($path, sprintf('%02d-%s', $index + 1, preg_replace('/[^A-Za-z0-9._ -]+/', '-', $name)));
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="waybills-' . preg_replace('/[^A-Za-z0-9-]+/', '-', $batchId) . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

function wb_upload_file(array $file, string $batchId, int $index): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('One of the waybill files could not be uploaded.');
    }

    $original = (string) ($file['name'] ?? 'waybill');
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
        throw new RuntimeException('Waybill files must be PDF, JPG or PNG.');
    }
    if ((int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('Each waybill file must be 10MB or smaller.');
    }

    $datePath = date('Y/m/d');
    $relativeDir = 'uploads/waybills/' . $datePath . '/' . $batchId;
    $uploadDir = BASE_PATH . '/' . $relativeDir;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Could not create the waybill upload folder.');
    }

    $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($original, PATHINFO_FILENAME));
    $fileName = sprintf('%02d-%s-%s.%s', $index + 1, $safeBase ?: 'waybill', bin2hex(random_bytes(3)), $extension);
    if (!move_uploaded_file((string) $file['tmp_name'], $uploadDir . '/' . $fileName)) {
        throw new RuntimeException('Could not save a waybill file.');
    }

    return ['path' => $relativeDir . '/' . $fileName, 'original' => $original];
}

function wb_normalize_files(array $files): array
{
    $normalized = [];
    foreach (($files['name'] ?? []) as $index => $_name) {
        $normalized[] = [
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }

    return $normalized;
}

function wb_fetch_batch_rows(array $statuses, bool $history = false, ?string $dateFrom = null, ?string $dateTo = null): array
{
    $params = $statuses;
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $where = "w.status IN ({$placeholders})";
    if ($history) {
        $where .= " AND DATE(COALESCE(w.sent_at, w.uploaded_at)) BETWEEN ? AND ?";
        $params[] = $dateFrom ?: date('Y-m-d', strtotime('-7 days'));
        $params[] = $dateTo ?: date('Y-m-d');
    }

    $rows = ops_rows(
        "SELECT
            w.batch_id,
            MIN(w.id) AS first_id,
            MIN(w.uploaded_at) AS uploaded_at,
            MAX(w.due_by) AS due_by,
            MAX(w.sent_at) AS sent_at,
            MIN(w.customer_name) AS customer_name,
            MIN(w.waybill_reference) AS waybill_reference,
            MIN(w.order_id) AS order_id,
            MIN(w.sent_date) AS sent_date,
            MIN(w.courier_names) AS courier_names,
            MAX(w.number_of_waybills) AS number_of_waybills,
            MIN(w.notes) AS notes,
            MIN(w.uploaded_by) AS uploaded_by,
            MIN(w.sent_by) AS sent_by,
            COUNT(*) AS file_count,
            SUM(CASE WHEN w.status = 'overdue' THEN 1 ELSE 0 END) AS overdue_count,
            SUM(CASE WHEN w.status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
            GROUP_CONCAT(COALESCE(w.original_filename, SUBSTRING_INDEX(w.file_path, '/', -1)) ORDER BY w.id SEPARATOR ' | ') AS file_names,
            up.full_name AS uploaded_by_name,
            ur.role_key AS uploaded_role_key,
            sp.full_name AS sent_by_name,
            sr.role_key AS sent_role_key
         FROM hambelela_waybills w
         LEFT JOIN ops_employees up ON up.id = w.uploaded_by
         LEFT JOIN ops_roles ur ON ur.id = up.role_id
         LEFT JOIN ops_employees sp ON sp.id = w.sent_by
         LEFT JOIN ops_roles sr ON sr.id = sp.role_id
         WHERE {$where}
         GROUP BY w.batch_id, up.full_name, ur.role_key, sp.full_name, sr.role_key
         ORDER BY " . ($history ? 'sent_at DESC, uploaded_at DESC' : 'due_by ASC, uploaded_at ASC'),
        $params
    );

    foreach ($rows as &$row) {
        $row['status'] = ((int) ($row['overdue_count'] ?? 0) > 0) ? 'overdue' : (((int) ($row['sent_count'] ?? 0) >= (int) ($row['file_count'] ?? 0)) ? 'sent' : 'pending');
        $uploader = ['full_name' => $row['uploaded_by_name'] ?? '', 'role_key' => $row['uploaded_role_key'] ?? ''];
        $sender = ['full_name' => $row['sent_by_name'] ?? '', 'role_key' => $row['sent_role_key'] ?? ''];
        $row['uploaded_by_display'] = $row['uploaded_by_name'] ? ops_staff_display_name($uploader) : 'Unknown';
        $row['sent_by_display'] = $row['sent_by_name'] ? ops_staff_display_name($sender) : '-';
    }
    unset($row);

    return $rows;
}

function wb_stats(): array
{
    $rows = ops_rows(
        "SELECT
            SUM(CASE WHEN DATE(uploaded_at) = CURDATE() THEN 1 ELSE 0 END) AS uploaded_today,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue,
            SUM(CASE
                WHEN status = 'sent'
                    AND sent_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                    AND sent_at < DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                THEN 1 ELSE 0
            END) AS sent_this_month
         FROM hambelela_waybills"
    );
    $row = $rows[0] ?? [];

    return [
        'uploaded_today' => (int) ($row['uploaded_today'] ?? 0),
        'pending' => (int) ($row['pending'] ?? 0),
        'overdue' => (int) ($row['overdue'] ?? 0),
        'sent_this_month' => (int) ($row['sent_this_month'] ?? 0),
    ];
}

function wb_status_badge(string $status, ?string $dueBy = null): string
{
    $class = 'ok';
    $label = 'Pending';
    if ($status === 'sent') {
        $class = 'sent';
        $label = 'Sent';
    } elseif ($status === 'overdue') {
        $class = 'overdue';
        $label = 'Overdue';
    } elseif ($dueBy) {
        try {
            $due = new DateTimeImmutable($dueBy);
            if ($due <= wb_now()->modify('+30 minutes')) {
                $class = 'duesoon';
                $label = 'Due soon';
            }
        } catch (Throwable $e) {
            $label = 'Pending';
        }
    }

    return '<span class="badge ' . $class . '">' . wb_e($label) . '</span>';
}

function wb_queue_status_class(array $row): string
{
    if ((string) ($row['status'] ?? '') === 'overdue') {
        return 'status-overdue';
    }

    try {
        $due = new DateTimeImmutable((string) ($row['due_by'] ?? ''));
        if ($due <= wb_now()->modify('+30 minutes')) {
            return 'status-duesoon';
        }
    } catch (Throwable $e) {
        return 'status-ok';
    }

    return 'status-ok';
}

function wb_queue_html(array $rows, bool $canSend): string
{
    ob_start();
    if (!$rows) {
        echo '<div class="courier-empty">No pending waybills. Secilia is clear for now.</div>';
    } else {
        foreach ($rows as $row) {
            $batchId = (string) $row['batch_id'];
            $statusClass = wb_queue_status_class($row);
            ?>
            <article class="courier-grid courier-grid-waybill courier-grid-row queue-item <?= wb_e($statusClass) ?>" data-batch-id="<?= wb_e($batchId) ?>">
                <div class="courier-cell queue-main">
                    <div class="file-count"><?= number_format((int) ($row['number_of_waybills'] ?: $row['file_count'])) ?></div>
                    <div>
                        <strong class="ref"><?= wb_e($row['courier_names'] ?: 'Courier not selected') ?></strong>
                        <span class="meta-value">Sent date: <?= wb_e($row['sent_date'] ?: 'Not set') ?></span>
                    </div>
                </div>
                <div class="courier-cell"><span class="meta-value"><?= wb_e(wb_dt((string) $row['uploaded_at'])) ?></span></div>
                <div class="courier-cell"><span class="meta-value"><?= wb_e($row['uploaded_by_display']) ?></span></div>
                <div class="courier-cell"><span class="meta-value"><?= wb_e(wb_due_label((string) $row['due_by'])) ?></span></div>
                <div class="courier-cell"><span class="meta-value"><?= wb_e((string) $row['file_names']) ?></span></div>
                <div class="courier-cell"><?= wb_status_badge((string) $row['status'], (string) $row['due_by']) ?></div>
                <div class="courier-cell queue-notes"><span class="meta-value"><?= wb_e($row['notes'] ?: 'No notes') ?></span></div>
                <div class="courier-cell courier-actions-cell queue-item-actions">
                    <a class="btn-secondary download-btn courier-secondary-btn" href="courier.php?action=waybill_download_zip&amp;batch_id=<?= wb_e($batchId) ?>"><i data-lucide="download"></i> Download<?= (int) $row['file_count'] > 1 ? ' ZIP' : '' ?></a>
                    <?php if ($canSend): ?>
                        <button class="btn-mark-sent mark-sent mark-sent-btn courier-action-btn" type="button" data-batch-id="<?= wb_e($batchId) ?>"><i data-lucide="send"></i> Mark Sent</button>
                    <?php endif; ?>
                </div>
            </article>
            <?php
        }
    }

    return (string) ob_get_clean();
}

function wb_history_html(array $rows): string
{
    ob_start();
    if (!$rows) {
        echo '<div class="courier-empty">No sent waybills this week yet.</div>';
    } else {
        foreach ($rows as $row) {
            ?>
            <article class="courier-grid courier-grid-history courier-grid-row history-row">
                <div class="courier-cell">
                    <strong><?= wb_e($row['courier_names'] ?: 'Courier not selected') ?></strong>
                    <span><?= wb_e($row['sent_date'] ?: 'No sent date') ?> - <?= number_format((int) ($row['number_of_waybills'] ?: $row['file_count'])) ?> waybill<?= (int) ($row['number_of_waybills'] ?: $row['file_count']) === 1 ? '' : 's' ?></span>
                </div>
                <div class="courier-cell"><?= wb_e($row['uploaded_by_display']) ?></div>
                <div class="courier-cell"><?= wb_e(wb_dt((string) $row['sent_at'])) ?></div>
                <div class="courier-cell"><?= wb_e($row['sent_by_display']) ?></div>
                <div class="courier-cell courier-actions-cell history-actions">
                    <a class="btn-secondary download-btn courier-secondary-btn" href="courier.php?action=waybill_download_zip&amp;batch_id=<?= wb_e((string) $row['batch_id']) ?>"><i data-lucide="download"></i> Download</a>
                </div>
            </article>
            <?php
        }
    }

    return (string) ob_get_clean();
}

function wb_dashboard_payload(bool $canSend, ?string $dateFrom = null, ?string $dateTo = null): array
{
    wb_update_overdue_and_reminders();
    $queueRows = wb_fetch_batch_rows(['pending', 'overdue']);
    $historyRows = wb_fetch_batch_rows(['sent'], true, $dateFrom, $dateTo);

    return [
        'stats' => wb_stats(),
        'queue_html' => wb_queue_html($queueRows, $canSend),
        'history_html' => wb_history_html($historyRows),
    ];
}

function wb_export_csv(?string $dateFrom = null, ?string $dateTo = null): void
{
    $dateFrom = $dateFrom ?: date('Y-m-d', strtotime('-7 days'));
    $dateTo = $dateTo ?: date('Y-m-d');
    $rows = ops_rows(
        "SELECT w.*, up.full_name AS uploaded_by_name, sp.full_name AS sent_by_name
         FROM hambelela_waybills w
         LEFT JOIN ops_employees up ON up.id = w.uploaded_by
         LEFT JOIN ops_employees sp ON sp.id = w.sent_by
         WHERE DATE(COALESCE(w.sent_at, w.uploaded_at)) BETWEEN ? AND ?
         ORDER BY w.sent_at DESC, w.id DESC",
        [$dateFrom, $dateTo]
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="waybill-sent-history-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Batch ID', 'Sent Date', 'Courier', 'Number of Waybills', 'File', 'Uploaded By', 'Uploaded At', 'Due By', 'Sent By', 'Sent At', 'Status', 'Notes']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['batch_id'],
            $row['sent_date'],
            $row['courier_names'],
            $row['number_of_waybills'],
            $row['original_filename'] ?: basename((string) $row['file_path']),
            $row['uploaded_by_name'],
            $row['uploaded_at'],
            $row['due_by'],
            $row['sent_by_name'],
            $row['sent_at'],
            $row['status'],
            $row['notes'],
        ]);
    }
    fclose($out);
    exit;
}

if ($ready) {
    wb_bootstrap_schema();
    wb_update_overdue_and_reminders();
}

if ($ready && (string) ($_GET['action'] ?? '') === 'waybill_download_zip') {
    wb_stream_batch_download(substr((string) ($_GET['batch_id'] ?? ''), 0, 60));
}

if ($ready && (string) ($_GET['action'] ?? '') === 'waybill_export_csv') {
    if (!$canExportWaybills) {
        http_response_code(403);
        exit('Not allowed.');
    }
    wb_export_csv($historyDateFrom, $historyDateTo);
}

if ($ready && (string) ($_GET['action'] ?? '') === 'waybill_queue_refresh') {
    wb_json(['success' => true] + wb_dashboard_payload($canSendWaybills, $historyDateFrom, $historyDateTo));
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = ops_post_string('action', 60);
    try {
        if ($action === 'waybill_upload') {
            if (!$canUploadWaybills) {
                throw new RuntimeException('Only packers and admin can upload waybills.');
            }
            if (!$currentEmployeeId) {
                throw new RuntimeException('Could not identify the logged-in employee.');
            }
            if (empty($_FILES['waybill_files']['name']) || !is_array($_FILES['waybill_files']['name'])) {
                throw new RuntimeException('Choose at least one waybill file.');
            }

            $sentDate = ops_post_string('sent_date', 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sentDate)) {
                $sentDate = date('Y-m-d');
            }
            $couriers = wb_post_couriers();
            if (!$couriers) {
                throw new RuntimeException('Choose at least one courier.');
            }
            $courierNames = implode(', ', $couriers);
            $numberOfWaybills = max(1, (int) ($_POST['number_of_waybills'] ?? 1));

            $batchId = wb_batch_id();
            $uploadedAt = wb_now();
            $dueBy = wb_due_for_upload($uploadedAt);
            $files = wb_normalize_files($_FILES['waybill_files']);
            $created = 0;

            $stmt = db()->prepare(
                "INSERT INTO hambelela_waybills
                    (batch_id, uploaded_by, uploaded_at, customer_name, waybill_reference, order_id, sent_date, courier_names, number_of_waybills, file_path, original_filename, notes, due_by, status)
                 VALUES (?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $legacyStmt = db()->prepare(
                "INSERT INTO ops_courier_waybills
                    (waybill_reference, customer_name, sent_date, courier_names, number_of_waybills, notes, label_path, original_filename, uploaded_by, uploaded_at, status)
                 VALUES (NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'uploaded')"
            );

            foreach ($files as $index => $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $stored = wb_upload_file($file, $batchId, $index);
                $stmt->execute([
                    $batchId,
                    $currentEmployeeId,
                    $uploadedAt->format('Y-m-d H:i:s'),
                    $sentDate,
                    $courierNames,
                    $numberOfWaybills,
                    $stored['path'],
                    $stored['original'],
                    ops_post_string('notes', 1500) ?: null,
                    $dueBy->format('Y-m-d H:i:s'),
                ]);
                $newId = (int) db()->lastInsertId();
                $legacyStmt->execute([
                    $sentDate,
                    $courierNames,
                    $numberOfWaybills,
                    ops_post_string('notes', 1500) ?: null,
                    $stored['path'],
                    $stored['original'],
                    $currentEmployeeId,
                    $uploadedAt->format('Y-m-d H:i:s'),
                ]);
                ops_activity_log('courier_waybill_uploaded', 'courier_waybill', $newId, ['batch_id' => $batchId, 'file' => $stored['original']]);
                $created++;
            }

            if ($created <= 0) {
                throw new RuntimeException('No valid waybill files were uploaded.');
            }

            wb_notify_cecilia(
                'New waybill upload',
                wb_current_name() . ' uploaded ' . $numberOfWaybills . ' waybill' . ($numberOfWaybills === 1 ? '' : 's') . ' for ' . $courierNames . ' dated ' . $sentDate . '. Due by ' . wb_due_label($dueBy->format('Y-m-d H:i:s')) . '.',
                'normal'
            );
            wb_json(['success' => true, 'message' => $created . ' waybill' . ($created === 1 ? '' : 's') . ' uploaded.', 'due_by' => wb_due_label($dueBy->format('Y-m-d H:i:s'))] + wb_dashboard_payload($canSendWaybills, $historyDateFrom, $historyDateTo));
        }

        if ($action === 'waybill_mark_sent') {
            if (!$canSendWaybills) {
                throw new RuntimeException('Only front desk or admin can mark waybills as sent.');
            }
            if (!$currentEmployeeId) {
                throw new RuntimeException('Could not identify the logged-in employee.');
            }
            $batchId = ops_post_string('batch_id', 60);
            $rows = ops_rows(
                "SELECT id, due_by, file_path
                 FROM hambelela_waybills
                 WHERE batch_id = ? AND status IN ('pending', 'overdue')",
                [$batchId]
            );
            if (!$rows) {
                throw new RuntimeException('No pending waybills found for this batch.');
            }

            $sentAt = wb_now()->format('Y-m-d H:i:s');
            $stmt = db()->prepare("UPDATE hambelela_waybills SET status = 'sent', sent_by = ?, sent_at = ? WHERE batch_id = ?");
            $stmt->execute([$currentEmployeeId, $sentAt, $batchId]);
            $legacyStmt = db()->prepare("UPDATE ops_courier_waybills SET status = 'sent', sent_by = ?, sent_at = ? WHERE label_path = ?");
            foreach ($rows as $row) {
                wb_log_sla((int) $row['id'], $currentEmployeeId, (string) $row['due_by'], $sentAt);
                $legacyStmt->execute([$currentEmployeeId, $sentAt, (string) $row['file_path']]);
            }
            ops_activity_log('courier_waybill_sent', 'courier_waybill_batch', 0, ['batch_id' => $batchId, 'count' => count($rows)]);
            wb_json(['success' => true, 'message' => count($rows) . ' waybill' . (count($rows) === 1 ? '' : 's') . ' marked as sent.'] + wb_dashboard_payload($canSendWaybills, $historyDateFrom, $historyDateTo));
        }

        throw new RuntimeException('Unknown waybill action.');
    } catch (Throwable $e) {
        wb_json(['success' => false, 'message' => $e->getMessage()], 400);
    }
}

$payload = $ready ? wb_dashboard_payload($canSendWaybills, $historyDateFrom, $historyDateTo) : ['stats' => ['uploaded_today' => 0, 'pending' => 0, 'overdue' => 0, 'sent_this_month' => 0], 'queue_html' => '', 'history_html' => ''];
$duePreview = wb_due_for_upload(wb_now())->format('Y-m-d H:i:s');

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module courier-wrap">
    <section class="module-header">
        <div>
            <h1>Courier Waybills</h1>
            <p class="page-subtitle">Upload, track and send courier waybills from one queue.</p>
        </div>
    </section>

    <?php if (!$ready) { ops_setup_notice(); } ?>

    <section class="courier-hub" data-waybill-app>
        <div class="stat-cards">
            <article class="stat-card uploaded">
                <div class="sc-head"><span class="sc-icon"><i data-lucide="upload-cloud"></i></span><span class="sc-lbl">Uploaded Today</span></div>
                <strong class="sc-num" data-stat="uploaded_today"><?= number_format($payload['stats']['uploaded_today']) ?></strong>
            </article>
            <article class="stat-card pending">
                <div class="sc-head"><span class="sc-icon"><i data-lucide="clock-3"></i></span><span class="sc-lbl">Pending Send</span></div>
                <strong class="sc-num" data-stat="pending"><?= number_format($payload['stats']['pending']) ?></strong>
            </article>
            <article class="stat-card overdue">
                <div class="sc-head"><span class="sc-icon"><i data-lucide="triangle-alert"></i></span><span class="sc-lbl">Overdue</span></div>
                <strong class="sc-num" data-stat="overdue"><?= number_format($payload['stats']['overdue']) ?></strong>
            </article>
            <article class="stat-card sent-month">
                <div class="sc-head"><span class="sc-icon"><i data-lucide="send"></i></span><span class="sc-lbl">Sent This Month</span></div>
                <strong class="sc-num" data-stat="sent_this_month"><?= number_format($payload['stats']['sent_this_month']) ?></strong>
            </article>
        </div>

        <form class="section-card courier-section filter-strip" method="get" data-waybill-filter>
            <div class="filter-date-row">
                <label class="field-label">From
                    <input type="date" name="date_from" value="<?= wb_e($historyDateFrom) ?>">
                </label>
                <label class="field-label">To
                    <input type="date" name="date_to" value="<?= wb_e($historyDateTo) ?>">
                </label>
            </div>
            <label class="field-label filter-search-row">Search
                <input type="search" name="search" placeholder="Search courier waybills">
            </label>
            <div class="filter-actions-row">
                <a class="btn-primary filter-clear-button" href="courier.php">Clear filters</a>
            </div>
        </form>

        <?php if ($canUploadWaybills): ?>
            <section class="section-card courier-section">
                <div class="card-head courier-section-header">
                    <div>
                        <h2 class="card-title">Upload Waybills</h2>
                        <p class="card-sub">Uploading as: <strong><?= wb_e(wb_current_name()) ?></strong>. Multiple files can be uploaded in one batch.</p>
                    </div>
                    <span class="due-badge"><i data-lucide="alarm-clock"></i> Due by: <?= wb_e(wb_due_label($duePreview)) ?></span>
                </div>

                <form class="upload-form" data-waybill-upload enctype="multipart/form-data">
                    <input type="hidden" name="action" value="waybill_upload">
                    <label class="field-label upload-input-field">Sent Date
                        <input name="sent_date" type="date" value="<?= wb_e(date('Y-m-d')) ?>" required>
                    </label>
                    <label class="field-label upload-input-field">Number of Waybills
                        <input name="number_of_waybills" type="number" min="1" step="1" value="1" required>
                    </label>
                    <div class="field-label span-2 courier-field">
                        <span>Courier</span>
                        <div class="courier-chips">
                            <?php foreach (wb_allowed_couriers() as $courier): ?>
                                <label class="courier-chip"><input type="checkbox" name="couriers[]" value="<?= wb_e($courier) ?>"> <?= wb_e($courier) ?></label>
                            <?php endforeach; ?>
                            <button class="btn-add-courier" type="button" data-add-courier>+ Add courier</button>
                        </div>
                        <div class="add-courier-inline" data-add-courier-inline>
                            <input type="text" data-add-courier-name placeholder="Courier name">
                            <button class="btn-secondary" type="button" data-add-courier-save>Add</button>
                        </div>
                    </div>
                    <div class="field-label span-2 waybill-files-field">
                        <span>Waybill files *</span>
                        <label class="dropzone" data-dropzone>
                            <input name="waybill_files[]" type="file" accept=".pdf,.jpg,.jpeg,.png" multiple required hidden>
                            <div class="dz-icon"><i data-lucide="paperclip"></i></div>
                            <div class="dz-main">Drag and drop waybill files here</div>
                            <div class="dz-sub">or click to browse. Supports PDF, JPG and PNG.</div>
                        </label>
                        <div data-file-chips></div>
                    </div>
                    <label class="field-label span-2 notes-field">Notes for Front Desk
                        <textarea name="notes" placeholder="Optional note for Secilia"></textarea>
                    </label>
                    <div class="span-2 form-actions">
                        <button class="btn-primary" type="submit" data-upload-submit><i data-lucide="upload"></i> Upload Waybills</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="section-card courier-section">
            <div class="courier-section-inner">
                <div class="card-head courier-section-header">
                    <div>
                        <h2 class="card-title">Waybill Queue</h2>
                    </div>
                    <button class="btn-secondary refresh-btn courier-secondary-btn" type="button" data-refresh-waybills><i data-lucide="refresh-cw"></i> Refresh</button>
                </div>
                <div class="courier-table-scroll courier-table-wrap">
                    <div class="courier-table-shell">
                        <div class="courier-grid courier-grid-waybill courier-grid-header queue-head">
                            <div class="courier-cell queue-main"><span class="queue-head-count-spacer" aria-hidden="true"></span><span>Courier</span></div><div class="courier-cell">Uploaded</div><div class="courier-cell">By</div><div class="courier-cell">Due</div><div class="courier-cell">Files</div><div class="courier-cell">Status</div><div class="courier-cell">Notes</div><div class="courier-cell">Actions</div>
                        </div>
                        <div class="queue-list" data-waybill-queue><?= $payload['queue_html'] ?></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section-card courier-section">
            <div class="courier-section-inner">
                <div class="card-head courier-section-header history-summary">
                    <div>
                        <h2 class="card-title">Sent History</h2>
                        <p class="card-sub">Waybills marked sent from <?= wb_e($historyDateFrom) ?> to <?= wb_e($historyDateTo) ?>.</p>
                    </div>
                    <?php if ($canExportWaybills): ?>
                        <a class="btn-secondary export-btn courier-secondary-btn" href="courier.php?action=waybill_export_csv&amp;date_from=<?= wb_e($historyDateFrom) ?>&amp;date_to=<?= wb_e($historyDateTo) ?>"><i data-lucide="download"></i> Export CSV</a>
                    <?php endif; ?>
                </div>
                <div class="courier-table-scroll courier-table-wrap">
                    <div class="courier-table-shell">
                        <div class="courier-grid courier-grid-history courier-grid-header history-head">
                            <div class="courier-cell">Customer</div><div class="courier-cell">Uploaded By</div><div class="courier-cell">Sent At</div><div class="courier-cell">Sent By</div><div class="courier-cell">Actions</div>
                        </div>
                        <div class="history-list" data-waybill-history><?= $payload['history_html'] ?></div>
                    </div>
                </div>
            </div>
        </section>
    </section>
    <div class="courier-toast" data-waybill-toast></div>
</main>

<script>
(function () {
    const app = document.querySelector('[data-waybill-app]');
    if (!app) return;

    const toast = document.querySelector('[data-waybill-toast]');
    const queue = document.querySelector('[data-waybill-queue]');
    const history = document.querySelector('[data-waybill-history]');
    const statEls = {
        uploaded_today: document.querySelector('[data-stat="uploaded_today"]'),
        pending: document.querySelector('[data-stat="pending"]'),
        overdue: document.querySelector('[data-stat="overdue"]'),
        sent_this_month: document.querySelector('[data-stat="sent_this_month"]')
    };

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        setTimeout(() => toast.classList.remove('is-visible'), 2800);
    }

    function refreshIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    function animateCourierButton(button, className = 'is-animating', duration = 500) {
        if (!button) return;
        button.classList.remove(className);
        void button.offsetWidth;
        button.classList.add(className);
        window.setTimeout(() => {
            button.classList.remove(className);
        }, duration);
    }

    function renderPayload(payload) {
        if (payload.queue_html !== undefined && queue) queue.innerHTML = payload.queue_html;
        if (payload.history_html !== undefined && history) history.innerHTML = payload.history_html;
        if (payload.stats) {
            Object.keys(statEls).forEach((key) => {
                if (statEls[key] && payload.stats[key] !== undefined) {
                    statEls[key].textContent = Number(payload.stats[key] || 0).toLocaleString();
                }
            });
        }
        refreshIcons();
    }

    function fetchJson(url, options) {
        return fetch(url, options || {}).then((response) => response.json().then((data) => {
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Request failed.');
            }
            return data;
        }));
    }

    function filteredRefreshUrl() {
        const filter = document.querySelector('[data-waybill-filter]');
        const params = new URLSearchParams();
        params.set('action', 'waybill_queue_refresh');
        if (filter) {
            const data = new FormData(filter);
            if (data.get('date_from')) params.set('date_from', data.get('date_from'));
            if (data.get('date_to')) params.set('date_to', data.get('date_to'));
        }
        return 'courier.php?' + params.toString();
    }

    const uploadForm = document.querySelector('[data-waybill-upload]');
    if (uploadForm) {
        const fileInput = uploadForm.querySelector('input[type="file"]');
        const dropzone = uploadForm.querySelector('[data-dropzone]');
        const chips = uploadForm.querySelector('[data-file-chips]');
        const submit = uploadForm.querySelector('[data-upload-submit]');
        const courierChips = uploadForm.querySelector('.courier-chips');
        const addCourierButton = uploadForm.querySelector('[data-add-courier]');
        const addCourierInline = uploadForm.querySelector('[data-add-courier-inline]');
        const addCourierName = uploadForm.querySelector('[data-add-courier-name]');
        const addCourierSave = uploadForm.querySelector('[data-add-courier-save]');
        let selectedFiles = [];

        function syncFiles() {
            if (!fileInput || typeof DataTransfer === 'undefined') return;
            const transfer = new DataTransfer();
            selectedFiles.forEach((file) => transfer.items.add(file));
            fileInput.files = transfer.files;
        }

        function renderFiles() {
            if (!chips || !fileInput) return;
            chips.innerHTML = '';
            selectedFiles.forEach((file, index) => {
                const chip = document.createElement('span');
                chip.className = 'file-chip';
                chip.appendChild(document.createTextNode(file.name));
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'remove';
                remove.textContent = 'x';
                remove.addEventListener('click', () => {
                    selectedFiles.splice(index, 1);
                    syncFiles();
                    renderFiles();
                });
                chip.appendChild(remove);
                chips.appendChild(chip);
            });
        }

        if (fileInput) fileInput.addEventListener('change', () => {
            selectedFiles = Array.from(fileInput.files || []);
            renderFiles();
        });

        function addCourierChip(name) {
            if (!courierChips) return;
            const courierName = String(name || '').trim();
            if (!courierName) return;
            const existing = Array.from(courierChips.querySelectorAll('input[name="couriers[]"]'))
                .some((input) => input.value.toLowerCase() === courierName.toLowerCase());
            if (existing) return;

            const label = document.createElement('label');
            label.className = 'courier-chip';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'couriers[]';
            input.value = courierName;
            input.checked = true;
            label.appendChild(input);
            label.appendChild(document.createTextNode(' ' + courierName));
            courierChips.insertBefore(label, addCourierButton || null);
        }

        if (addCourierButton && addCourierInline) {
            addCourierButton.addEventListener('click', () => {
                addCourierInline.classList.add('visible');
                if (addCourierName) addCourierName.focus();
            });
        }
        if (addCourierSave && addCourierName) {
            addCourierSave.addEventListener('click', () => {
                addCourierChip(addCourierName.value);
                addCourierName.value = '';
                if (addCourierInline) addCourierInline.classList.remove('visible');
            });
            addCourierName.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    addCourierSave.click();
                }
            });
        }

        if (dropzone) {
            ['dragenter', 'dragover'].forEach((eventName) => {
                dropzone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    dropzone.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach((eventName) => {
                dropzone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    dropzone.classList.remove('is-dragover');
                });
            });
            dropzone.addEventListener('drop', (event) => {
                if (!fileInput || !event.dataTransfer) return;
                selectedFiles = Array.from(event.dataTransfer.files || []);
                syncFiles();
                renderFiles();
            });
        }

        uploadForm.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!submit) return;
            const originalText = submit.innerHTML;
            submit.disabled = true;
            submit.innerHTML = '<i data-lucide="loader-circle"></i> Uploading...';
            refreshIcons();
            fetchJson('courier.php', { method: 'POST', body: new FormData(uploadForm) })
                .then((data) => {
                    uploadForm.reset();
                    selectedFiles = [];
                    if (chips) chips.innerHTML = '';
                    renderPayload(data);
                    showToast(data.message || 'Waybills uploaded.');
                })
                .catch((error) => showToast(error.message))
                .finally(() => {
                    submit.disabled = false;
                    submit.innerHTML = originalText;
                    refreshIcons();
                });
        });
    }

    document.addEventListener('click', (event) => {
        const refreshAnimationButton = event.target.closest('.courier-wrap .refresh-btn');
        const downloadButton = event.target.closest('.courier-wrap .download-btn');
        const exportButton = event.target.closest('.courier-wrap .export-btn');
        const markSentAnimationButton = event.target.closest('.courier-wrap .mark-sent-btn');

        if (refreshAnimationButton) animateCourierButton(refreshAnimationButton, 'is-spinning', 700);
        if (downloadButton) animateCourierButton(downloadButton, 'is-animating', 450);
        if (exportButton) animateCourierButton(exportButton, 'is-animating', 450);
        if (markSentAnimationButton) animateCourierButton(markSentAnimationButton, 'is-animating', 520);

        const markButton = event.target.closest('.mark-sent');
        if (markButton) {
            const batchId = markButton.getAttribute('data-batch-id');
            if (!batchId) return;
            markButton.disabled = true;
            const body = new FormData();
            body.append('action', 'waybill_mark_sent');
            body.append('batch_id', batchId);
            fetchJson('courier.php', { method: 'POST', body })
                .then((data) => {
                    renderPayload(data);
                    showToast(data.message || 'Marked as sent.');
                })
                .catch((error) => showToast(error.message))
                .finally(() => {
                    markButton.disabled = false;
                });
        }

        const refreshButton = event.target.closest('[data-refresh-waybills]');
        if (refreshButton) {
            refreshButton.disabled = true;
            fetchJson(filteredRefreshUrl())
                .then((data) => {
                    renderPayload(data);
                    showToast('Waybill queue refreshed.');
                })
                .catch((error) => showToast(error.message))
                .finally(() => {
                    refreshButton.disabled = false;
                });
        }
    });

    setInterval(() => {
        fetchJson(filteredRefreshUrl())
            .then(renderPayload)
            .catch(() => {});
    }, 60000);
})();
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
