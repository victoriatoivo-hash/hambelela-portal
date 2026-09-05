<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/error-instructions.php';

require_role('owner_admin', 'front_desk_admin', 'front_desk_admin_employee');

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
if (($_GET['instruction_sent'] ?? '') === '1') {
    $message = 'Instruction sent successfully.';
    $messageType = 'success';
}
$currentEmployeeId = ops_current_employee_id();
$currentRoleKey = current_role_key();
$isOwnerErrorUser = user_has_role('owner_admin');
$isFrontDeskErrorUser = user_has_role('front_desk_admin', 'front_desk_admin_employee');
$canManageStatus = $isOwnerErrorUser;
$showFullErrorLog = $isOwnerErrorUser;
if (empty($_SESSION['error_attachment_csrf'])) $_SESSION['error_attachment_csrf'] = bin2hex(random_bytes(32));
$errorAttachmentCsrf = (string) $_SESSION['error_attachment_csrf'];

$severityLabels = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
$statusLabels = ['open' => 'Not Resolved', 'resolved' => 'Resolved'];
$severityChoiceColours = ['critical' => ['#BB1B21', '#FFFFFF'], 'high' => ['#F07420', '#FFFFFF'], 'medium' => ['#AB3619', '#FFFFFF'], 'low' => ['#A8CA19', '#263400']];
$statusChoiceColours = ['open' => ['#BB1B21', '#FFFFFF'], 'resolved' => ['#A8CA19', '#263400']];
$errorCategories = [
    'task_false_completion' => 'Task Marked Complete but Not Done',
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
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        http_response_code($type === 'error' ? 422 : 200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $type !== 'error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $_SESSION['error_log_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    $location = BASE_URL . '/apps/operations/errors.php' . $query;
    header('Location: ' . $location, true, 303);
    exit;
}

function error_log_created_response(int $errorId, string $status, string $occurredAt, string $occurredOn, string $submissionToken, array $warnings = []): void
{
    $payload = [
        'success' => true,
        'message' => 'Error logged successfully',
        'error_id' => $errorId,
        'status' => $status,
        'occurred_at' => $occurredAt,
        'occurred_on' => $occurredOn,
        'submission_token' => $submissionToken,
        'saved_url' => BASE_URL . '/apps/operations/errors.php?error_id=' . $errorId,
        'warnings' => array_values($warnings),
    ];
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
    error_log_redirect('Error logged and added to performance tracking.', 'success', '?saved=1&error_id=' . $errorId);
}

function error_column_exists(string $column): bool
{
    return ops_table_exists('ops_error_logs') && ops_column_exists('ops_error_logs', $column);
}

function error_parse_occurred_at(string $value): string
{
    $timezone = new DateTimeZone('Africa/Windhoek');
    $normalised = str_replace(' ', 'T', trim($value));
    $local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $normalised, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$local || (is_array($errors) && (($errors['warning_count'] ?? 0) || ($errors['error_count'] ?? 0))) || $local->format('Y-m-d\TH:i') !== $normalised) throw new RuntimeException('Enter a valid date and time when the error occurred.');
    $now = new DateTimeImmutable('now', $timezone);
    if ($local > $now) throw new RuntimeException('The error occurrence time cannot be in the future.');
    if ($local < new DateTimeImmutable('2000-01-01 00:00:00', $timezone)) throw new RuntimeException('The error occurrence time is outside the supported range.');
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function error_parse_occurred_on(string $value): string
{
    $value=trim($value);$timezone=new DateTimeZone('Africa/Windhoek');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$timezone);$errors=DateTimeImmutable::getLastErrors();
    if(!$date||(is_array($errors)&&(($errors['warning_count']??0)||($errors['error_count']??0)))||$date->format('Y-m-d')!==$value)throw new RuntimeException('Please select the date the error occurred.');
    if($date>new DateTimeImmutable('today',$timezone))throw new RuntimeException('The error occurrence date cannot be in the future.');
    return$value;
}

function error_occurrence_expression(string $alias = 'el'): string { return "{$alias}.occurred_on"; }
function error_occurred_on_label(?string $value): string {if(!$value)return'—';$time=strtotime($value);return$time?date('j M Y',$time):$value;}
function error_occurred_at_label(?string $value, ?string $dateFallback = null): string
{
    if (!$value) return error_occurred_on_label($dateFallback);
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Africa/Windhoek'))
            ->format('j M Y · H:i');
    } catch (Throwable $dateLabelError) {
        return error_occurred_on_label($dateFallback ?: $value);
    }
}
function error_logged_label(?string $value): string {if(!$value)return'—';try{return(new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Windhoek'))->format('j M Y · H:i');}catch(Throwable $dateLabelError){return$value;}}
function error_occurrence_input(?string $utc): string
{
    if (!$utc) return '';
    try { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Windhoek'))->format('Y-m-d\TH:i'); }
    catch (Throwable $e) { return ''; }
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
        'responsible_employee_id' => "ALTER TABLE ops_error_logs ADD COLUMN responsible_employee_id INT NULL AFTER employee_id",
        'attribution_type' => "ALTER TABLE ops_error_logs ADD COLUMN attribution_type VARCHAR(30) NULL AFTER responsible_employee_id",
        'attributed_employee_id' => "ALTER TABLE ops_error_logs ADD COLUMN attributed_employee_id INT NULL AFTER attribution_type",
        'original_attribution_type' => "ALTER TABLE ops_error_logs ADD COLUMN original_attribution_type VARCHAR(30) NULL AFTER attributed_employee_id",
        'original_attributed_employee_id' => "ALTER TABLE ops_error_logs ADD COLUMN original_attributed_employee_id INT NULL AFTER original_attribution_type",
        'logged_by_user_id' => "ALTER TABLE ops_error_logs ADD COLUMN logged_by_user_id INT NULL AFTER logged_by",
        'has_financial_impact' => "ALTER TABLE ops_error_logs ADD COLUMN has_financial_impact TINYINT(1) NULL AFTER financial_impact",
        'financial_impact_notes' => "ALTER TABLE ops_error_logs ADD COLUMN financial_impact_notes TEXT NULL AFTER has_financial_impact",
        'attribution_verified_by' => "ALTER TABLE ops_error_logs ADD COLUMN attribution_verified_by INT NULL AFTER accuracy_verified_at",
        'attribution_verified_at' => "ALTER TABLE ops_error_logs ADD COLUMN attribution_verified_at DATETIME NULL AFTER attribution_verified_by",
        'packing_task_id' => "ALTER TABLE ops_error_logs ADD COLUMN packing_task_id INT NULL AFTER order_id",
        'affects_kpi_accuracy' => "ALTER TABLE ops_error_logs ADD COLUMN affects_kpi_accuracy TINYINT(1) NOT NULL DEFAULT 0 AFTER packing_task_id",
        'accuracy_verified_by' => "ALTER TABLE ops_error_logs ADD COLUMN accuracy_verified_by INT NULL AFTER affects_kpi_accuracy",
        'accuracy_verified_at' => "ALTER TABLE ops_error_logs ADD COLUMN accuracy_verified_at DATETIME NULL AFTER accuracy_verified_by",
        'status' => "ALTER TABLE ops_error_logs ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'open' AFTER repeat_issue",
        'repeat_note' => "ALTER TABLE ops_error_logs ADD COLUMN repeat_note TEXT NULL AFTER repeat_issue",
        'attachment_paths' => "ALTER TABLE ops_error_logs ADD COLUMN attachment_paths TEXT NULL AFTER resolution",
        'updated_at' => "ALTER TABLE ops_error_logs ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER logged_at",
        'created_at' => "ALTER TABLE ops_error_logs ADD COLUMN created_at DATETIME NULL AFTER logged_at",
        'occurred_at' => "ALTER TABLE ops_error_logs ADD COLUMN occurred_at DATETIME NULL AFTER created_at",
        'occurred_on' => "ALTER TABLE ops_error_logs ADD COLUMN occurred_on DATE NULL AFTER occurred_at",
        'occurred_on_source' => "ALTER TABLE ops_error_logs ADD COLUMN occurred_on_source VARCHAR(40) NULL AFTER occurred_on",
        'deleted_at' => "ALTER TABLE ops_error_logs ADD COLUMN deleted_at DATETIME NULL AFTER updated_at",
        'deleted_by' => "ALTER TABLE ops_error_logs ADD COLUMN deleted_by INT NULL AFTER deleted_at",
    ];
    foreach ($columns as $column => $sql) {
        if (!error_column_exists($column)) error_try_sql($sql);
    }
    error_try_sql("UPDATE ops_error_logs SET error_title = category WHERE error_title IS NULL OR error_title = ''");
    error_try_sql("UPDATE ops_error_logs SET status = 'open' WHERE status IS NULL OR status = ''");
    error_try_sql("UPDATE ops_error_logs SET status = 'open' WHERE status = 'in_review'");
    error_try_sql("UPDATE ops_error_logs SET created_at = logged_at WHERE created_at IS NULL");
    error_try_sql("ALTER TABLE ops_error_logs MODIFY financial_impact DECIMAL(12,2) NULL DEFAULT NULL");
    error_try_sql("CREATE TABLE IF NOT EXISTS ops_error_field_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,error_id INT NOT NULL,field_name VARCHAR(60) NOT NULL,previous_value TEXT NULL,new_value TEXT NULL,changed_by_employee_id INT NULL,changed_by_role VARCHAR(80) NOT NULL,change_source VARCHAR(60) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_error_field_audit_error(error_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!ops_column_exists('ops_error_field_audit', 'changed_by_user_id')) {
        error_try_sql("ALTER TABLE ops_error_field_audit ADD COLUMN changed_by_user_id INT NULL AFTER changed_by_employee_id");
    }
    if (!ops_column_exists('ops_error_field_audit', 'changed_by_name')) {
        error_try_sql("ALTER TABLE ops_error_field_audit ADD COLUMN changed_by_name VARCHAR(190) NULL AFTER changed_by_user_id");
    }
    error_try_sql("INSERT INTO ops_error_field_audit(error_id,field_name,previous_value,new_value,changed_by_employee_id,changed_by_role,change_source) SELECT id,'occurred_on',NULL,DATE(COALESCE(occurred_at,created_at,logged_at)),NULL,'system','migrated_from_logged_date' FROM ops_error_logs WHERE occurred_on IS NULL");
    error_try_sql("UPDATE ops_error_logs SET occurred_on=DATE(COALESCE(occurred_at,created_at,logged_at)),occurred_on_source='migrated_from_logged_date' WHERE occurred_on IS NULL");
    error_try_sql("CREATE INDEX idx_error_occurred_on ON ops_error_logs (occurred_on)");
    error_try_sql("CREATE INDEX idx_error_occurred_at ON ops_error_logs (occurred_at)");
    error_try_sql("UPDATE ops_error_logs SET attribution_type='employee', attributed_employee_id=responsible_employee_id, original_attribution_type='employee', original_attributed_employee_id=responsible_employee_id WHERE attribution_type IS NULL AND responsible_employee_id IS NOT NULL");
}

function error_parse_attribution(array $employeeMap): array
{
    $type=trim((string)($_POST['attribution_type']??''));
    if(!in_array($type,['employee','delivery_driver','business'],true))throw new RuntimeException('Please select who this error is being logged for.');
    $employeeId=max(0,(int)($_POST['attributed_employee_id']??0))?:null;
    if($type==='employee'&&(!$employeeId||!isset($employeeMap[$employeeId])))throw new RuntimeException('Select a valid employee.');
    if($type!=='employee')$employeeId=null;
    return[$type,$employeeId];
}

function error_parse_financial_impact(): array
{
    $choice=(string)($_POST['has_financial_impact']??'');
    if(!in_array($choice,['unknown','0','1'],true))throw new RuntimeException('Please indicate whether this error has a financial impact.');
    if($choice==='unknown')return[null,null,''];
    if($choice==='0')return[0,'0.00',''];
    $amount=trim((string)($_POST['financial_impact_amount']??''));
    if(!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/',$amount))throw new RuntimeException('Enter the financial impact amount.');
    [$whole,$fraction]=array_pad(explode('.',$amount,2),2,'');$fraction=str_pad($fraction,2,'0');
    if(trim($whole,'0')===''&&(int)$fraction===0)throw new RuntimeException('Enter the financial impact amount.');
    $whole=ltrim($whole,'0')?:'0';return[1,$whole.'.'.$fraction,ops_post_string('financial_impact_notes',1000)];
}

function error_attribution_label(array $row,array $employeeMap): string
{
    $type=(string)($row['attribution_type']??'');$employeeId=(int)($row['attributed_employee_id']??$row['responsible_employee_id']??0);
    if($type==='delivery_driver')return'Delivery Driver';if($type==='business')return'Business Error';
    if(($type==='employee'||$type==='')&&$employeeId>0)return(string)($employeeMap[$employeeId]??'Unknown employee');
    return'Awaiting owner review';
}

function error_instruction_date_label(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp ? date('d M Y · H:i', $timestamp) : $value;
}

function error_json_array(?string $value): array
{
    if (!$value) return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? array_values(array_filter($decoded)) : [];
}

function error_attachment_records(?string $value): array
{
    $records = [];
    foreach (error_json_array($value) as $entry) {
        if (is_string($entry) && $entry !== '') {
            $records[] = ['path' => $entry, 'name' => basename($entry), 'mime' => '', 'size' => null];
        } elseif (is_array($entry) && !empty($entry['path'])) {
            $records[] = [
                'path' => (string) $entry['path'],
                'name' => (string) ($entry['name'] ?? basename((string) $entry['path'])),
                'mime' => (string) ($entry['mime'] ?? ''),
                'size' => isset($entry['size']) ? (int) $entry['size'] : null,
            ];
        }
    }
    return $records;
}

function error_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    $multiplier = 1;
    if ($unit === 'g') $multiplier = 1073741824;
    elseif ($unit === 'm') $multiplier = 1048576;
    elseif ($unit === 'k') $multiplier = 1024;
    return (int) ($number * $multiplier);
}

function error_path_starts_with(string $path, string $prefix): bool
{
    return $prefix === '' || strncmp($path, $prefix, strlen($prefix)) === 0;
}

function error_upload_files(int $errorId): array
{
    if (empty($_FILES['attachments']['name'])) return [];
    if (!is_array($_FILES['attachments']['name'])) throw new RuntimeException('The attachment request was malformed. Select the files again.');
    if (count($_FILES['attachments']['name']) > 10) throw new RuntimeException('Upload no more than 10 evidence files at a time.');
    $uploadDir = BASE_PATH . '/uploads/error-log';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) throw new RuntimeException('The evidence storage folder is unavailable.');
    if (!is_writable($uploadDir)) throw new RuntimeException('The evidence storage folder is not writable.');
    $allowed = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'], 'doc' => ['application/msword', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'txt' => ['text/plain'],
    ];
    $validated = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($_FILES['attachments']['name'] as $index => $name) {
        if (($name ?? '') === '') continue;
        $uploadError = (int) ($_FILES['attachments']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) throw new RuntimeException('Could not upload "' . basename((string) $name) . '" (upload error ' . $uploadError . ').');
        $size = (int) ($_FILES['attachments']['size'][$index] ?? 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) throw new RuntimeException('"' . basename((string) $name) . '" must be between 1 byte and 10 MB.');
        $tmpName = (string) ($_FILES['attachments']['tmp_name'][$index] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) throw new RuntimeException('The temporary upload for "' . basename((string) $name) . '" is unavailable.');
        $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        if (!isset($allowed[$extension])) throw new RuntimeException('"' . basename((string) $name) . '" is not an approved evidence file type.');
        $mime = (string) $finfo->file($tmpName);
        if (!in_array($mime, $allowed[$extension], true)) throw new RuntimeException('"' . basename((string) $name) . '" does not match its file extension.');
        $validated[] = ['tmp' => $tmpName, 'extension' => $extension, 'name' => basename((string) $name), 'mime' => $mime, 'size' => $size];
    }
    $records = [];
    $movedFiles = [];
    foreach ($validated as $file) {
        $fileName = 'error-' . $errorId . '-' . bin2hex(random_bytes(12)) . '.' . $file['extension'];
        $absolutePath = $uploadDir . '/' . $fileName;
        if (!move_uploaded_file($file['tmp'], $absolutePath)) {
            foreach ($movedFiles as $movedFile) @unlink($movedFile);
            throw new RuntimeException('The server could not store "' . $file['name'] . '". Try again.');
        }
        $movedFiles[] = $absolutePath;
        $records[] = ['path' => 'uploads/error-log/' . $fileName, 'name' => $file['name'], 'mime' => $file['mime'], 'size' => $file['size']];
    }
    return $records;
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
    error_instructions_schema_ready();
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
    $errorPostTransaction = false;
    $errorPostUploadedFiles = [];
    try {
        $requestBytes = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postLimitBytes = error_ini_bytes((string) ini_get('post_max_size'));
        if ($postLimitBytes > 0 && $requestBytes > $postLimitBytes) throw new RuntimeException('The selected evidence exceeds the server request limit of ' . ini_get('post_max_size') . '. Choose fewer or smaller files.');
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
            $occurredAtInput = trim((string) ($_POST['occurred_at'] ?? ''));
            $occurredAt = error_parse_occurred_at($occurredAtInput);
            $occurredOn = substr($occurredAtInput, 0, 10);
            [$attributionType,$responsibleEmployeeId]=error_parse_attribution($employeeMap);
            [$hasFinancialImpact,$financialImpact,$financialImpactNotes]=error_parse_financial_impact();
            $people=$responsibleEmployeeId?[$responsibleEmployeeId]:[];
            if ($title === '') throw new RuntimeException('Error title is required.');
            if ($description === '') throw new RuntimeException('Description is required.');
            if ($category === 'other') {
                if ($otherCategory === '') throw new RuntimeException('Other category is required.');
                $category = $otherCategory;
            } elseif (!array_key_exists($category, $errorCategories)) {
                throw new RuntimeException('Choose an error category.');
            }
            if (!array_key_exists($severity, $severityLabels)) throw new RuntimeException('Choose a severity.');
            $primaryEmployeeId = $people[0] ?? null;
            $packingTaskId = max(0, (int) ($_POST['packing_task_id'] ?? 0)) ?: null;
            $affectsAccuracy = $attributionType==='employee'&&(int) ($_POST['affects_kpi_accuracy'] ?? 0) === 1 ? 1 : 0;
            $accuracyVerified = $isOwnerErrorUser && (int) ($_POST['accuracy_verified'] ?? 0) === 1 && $responsibleEmployeeId && $affectsAccuracy;
            $orderReference = ops_post_string('order_reference', 60);
            $status = ops_post_string('status', 30) ?: 'open';
            if (!array_key_exists($status, $statusLabels)) $status = 'open';
            db()->beginTransaction();
            $errorPostTransaction = true;
            $stmt = db()->prepare(
                "INSERT INTO ops_error_logs
                 (error_title, employee_id, responsible_employee_id, attribution_type, attributed_employee_id, original_attribution_type, original_attributed_employee_id, people_involved, order_id, packing_task_id, affects_kpi_accuracy, accuracy_verified_by, accuracy_verified_at, attribution_verified_by, attribution_verified_at, order_reference, category, severity, description, customer_impact, financial_impact, has_financial_impact, financial_impact_notes, resolution, repeat_issue, repeat_note, status, logged_by, logged_by_user_id, occurred_at, occurred_on, occurred_on_source, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'reported', UTC_TIMESTAMP())"
            );
            $stmt->execute([
                $title,
                $primaryEmployeeId,
                $responsibleEmployeeId,
                $attributionType,
                $responsibleEmployeeId,
                $attributionType,
                $responsibleEmployeeId,
                json_encode($people, JSON_UNESCAPED_SLASHES),
                null,
                $packingTaskId,
                $affectsAccuracy,
                $accuracyVerified ? $currentEmployeeId : null,
                $accuracyVerified ? date('Y-m-d H:i:s') : null,
                $accuracyVerified ? $currentEmployeeId : null,
                $accuracyVerified ? date('Y-m-d H:i:s') : null,
                $orderReference ?: null,
                $category,
                $severity,
                $description,
                '',
                $financialImpact,
                $hasFinancialImpact,
                $financialImpactNotes?:null,
                ops_post_string('resolution', 1500),
                (int) ($_POST['repeat_issue'] ?? 0) === 1 ? 1 : 0,
                '',
                $status,
                $currentEmployeeId,
                (int)(current_user()['id']??0)?:null,
                $occurredAt,
                $occurredOn,
            ]);
            $errorId = (int) db()->lastInsertId();
            $attachments = error_upload_files($errorId);
            $errorPostUploadedFiles = array_column($attachments, 'path');
            if ($attachments) {
                $stmt = db()->prepare('UPDATE ops_error_logs SET attachment_paths = ? WHERE id = ?');
                $stmt->execute([json_encode($attachments, JSON_UNESCAPED_SLASHES), $errorId]);
            }
            db()->commit();
            $errorPostTransaction = false;
            $errorPostUploadedFiles = [];
            unset($_SESSION['incident_submission_token']);
            $_SESSION['incident_submission_token'] = bin2hex(random_bytes(32));
            $nextSubmissionToken = (string) $_SESSION['incident_submission_token'];
            $eventMeta=['severity'=>$severity,'category'=>$category,'occurred_on'=>$occurredOn,'occurred_at'=>$occurredAt,'created_at'=>gmdate('Y-m-d H:i:s'),'logged_by_user_id'=>(int)(current_user()['id']??0),'logged_by_employee_id'=>$currentEmployeeId,'attribution_type'=>$attributionType,'attributed_employee_id'=>$responsibleEmployeeId,'has_financial_impact'=>$hasFinancialImpact,'financial_impact_amount'=>$financialImpact,'kpi_eligible'=>$attributionType==='employee'&&$accuracyVerified,'business_health_eligible'=>true];
            $postCommitWarnings = [];
            try {
                ops_activity_log('error_logged','error_log',$errorId,$eventMeta);
                ops_kpi_record_event('error_log','error',$errorId,'error_created',null,$attributionType,$currentEmployeeId,['metadata'=>$eventMeta]);
                ops_kpi_record_event('error_log','error',$errorId,'attribution_selected',null,$attributionType,$currentEmployeeId,['metadata'=>$eventMeta]);
                ops_kpi_record_event('error_log','error',$errorId,'financial_impact_selected',null,$hasFinancialImpact?'yes':'no',$currentEmployeeId,['metadata'=>$eventMeta]);
            } catch (Throwable $evidenceError) {
                error_log('Error Log post-commit evidence failure for error ' . $errorId . ': ' . $evidenceError->getMessage());
                $postCommitWarnings[] = 'The error was saved, but supporting activity evidence needs owner review.';
            }
            try {
                notifications_create_for_roles([
                    'title' => $severity === 'critical' ? 'Critical error logged' : 'New error logged',
                    'message' => $title,
                    'module' => 'errors',
                    'priority' => $severity === 'critical' ? 'urgent' : ($severity === 'high' ? 'important' : 'normal'),
                    'related_type' => 'error_log',
                    'related_id' => $errorId,
                    'action_link' => BASE_URL . '/apps/operations/errors.php?error_id=' . $errorId,
                ], ['owner_admin', 'front_desk_admin', 'supervisor_manager']);
            } catch (Throwable $notificationError) {
                error_log('Error Log post-commit notification failure for error ' . $errorId . ': ' . $notificationError->getMessage());
                $postCommitWarnings[] = 'The error was saved, but its notification needs owner review.';
            }
            error_log_created_response($errorId, $status, $occurredAt, $occurredOn, $nextSubmissionToken, $postCommitWarnings);
        }

        if ($action === 'update_error') {
            if (!$isOwnerErrorUser) throw new RuntimeException('Only the owner can perform a full error edit.');
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
            $submittedOccurredAtInput = trim((string) ($_POST['occurred_at'] ?? ''));
            $submittedOccurredAt = error_parse_occurred_at($submittedOccurredAtInput);
            $submittedOccurredOn = substr($submittedOccurredAtInput, 0, 10);
            [$attributionType,$responsibleEmployeeId]=error_parse_attribution($employeeMap);
            [$hasFinancialImpact,$financialImpact,$financialImpactNotes]=error_parse_financial_impact();
            $people=$responsibleEmployeeId?[$responsibleEmployeeId]:[];
            if ($title === '') throw new RuntimeException('Error title is required.');
            if ($description === '') throw new RuntimeException('Description is required.');
            if ($category === 'other') {
                if ($otherCategory === '') throw new RuntimeException('Other category is required.');
                $category = $otherCategory;
            } elseif (!array_key_exists($category, $errorCategories)) {
                throw new RuntimeException('Choose an error category.');
            }
            if (!array_key_exists($severity, $severityLabels)) throw new RuntimeException('Choose a severity.');
            $existingOccurredAt = (string) (($existing['occurred_at'] ?? null) ?: ($existing['created_at'] ?? null) ?: ($existing['logged_at'] ?? ''));
            $existingOccurredOn = (string)($existing['occurred_on']??substr($existingOccurredAt,0,10));
            $occurrenceChanged = $existingOccurredAt !== $submittedOccurredAt;
            $occurrenceChangeReason = ops_post_string('occurred_at_change_reason', 1000);
            if ($occurrenceChanged && !$isOwnerErrorUser) throw new RuntimeException('Only an owner/admin may correct when an error occurred.');
            if ($occurrenceChanged && $occurrenceChangeReason === '') throw new RuntimeException('An owner/admin reason is required to change the occurrence time.');
            $status = ops_post_string('status', 30) ?: 'open';
            if (!array_key_exists($status, $statusLabels)) $status = 'open';
            $oldAttribution=(string)($existing['attribution_type']??'');$oldAttributedEmployee=(int)($existing['attributed_employee_id']??$existing['responsible_employee_id']??0)?:null;
            $attributionChanged=$oldAttribution!==$attributionType||$oldAttributedEmployee!==$responsibleEmployeeId;
            $attributionNote=ops_post_string('attribution_change_note',1000);
            if($attributionChanged&&!$isOwnerErrorUser)throw new RuntimeException('Only an owner/admin may correct error attribution.');
            if($attributionChanged&&$attributionNote==='')throw new RuntimeException('An owner/admin note is required to change attribution.');
            $packingTaskId = max(0, (int) ($_POST['packing_task_id'] ?? 0)) ?: null;
            $affectsAccuracy = $attributionType==='employee'&&(int) ($_POST['affects_kpi_accuracy'] ?? 0) === 1 ? 1 : 0;
            $accuracyVerified = $isOwnerErrorUser && (int) ($_POST['accuracy_verified'] ?? 0) === 1 && $responsibleEmployeeId && $affectsAccuracy;
            $newData = [
                'error_title' => $title,
                'order_reference' => ops_post_string('order_reference', 60) ?: null,
                'category' => $category,
                'severity' => $severity,
                'people_involved' => $people,
                'responsible_employee_id' => $responsibleEmployeeId,
                'attribution_type'=>$attributionType,
                'attributed_employee_id'=>$responsibleEmployeeId,
                'packing_task_id' => $packingTaskId,
                'affects_kpi_accuracy' => $affectsAccuracy,
                'description' => $description,
                'financial_impact'=>$financialImpact,
                'has_financial_impact'=>$hasFinancialImpact,
                'financial_impact_notes'=>$financialImpactNotes,
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

            db()->beginTransaction();
            $errorPostTransaction = true;
            $stmt = db()->prepare(
                "UPDATE ops_error_logs
                 SET error_title = ?, employee_id = ?, responsible_employee_id = ?, attribution_type=?, attributed_employee_id=?, people_involved = ?, packing_task_id = ?, affects_kpi_accuracy = ?, accuracy_verified_by = ?, accuracy_verified_at = ?, attribution_verified_by=?, attribution_verified_at=?, order_reference = ?, category = ?, severity = ?, description = ?, financial_impact = ?, has_financial_impact=?, financial_impact_notes=?, resolution = ?, repeat_issue = ?, status = ?, occurred_at = ?, occurred_on=?, occurred_on_source='corrected_by_owner'
                 WHERE id = ? AND deleted_at IS NULL"
            );
            $stmt->execute([
                $newData['error_title'],
                $people[0] ?? null,
                $responsibleEmployeeId,
                $attributionType,
                $responsibleEmployeeId,
                json_encode($people, JSON_UNESCAPED_SLASHES),
                $packingTaskId,
                $affectsAccuracy,
                $accuracyVerified ? $currentEmployeeId : null,
                $accuracyVerified ? date('Y-m-d H:i:s') : null,
                ($accuracyVerified||$attributionChanged)?$currentEmployeeId:null,
                ($accuracyVerified||$attributionChanged)?date('Y-m-d H:i:s'):null,
                $newData['order_reference'],
                $newData['category'],
                $newData['severity'],
                $newData['description'],
                $newData['financial_impact'],
                $hasFinancialImpact,
                $financialImpactNotes?:null,
                $newData['resolution'],
                $newData['repeat_issue'],
                $newData['status'],
                $submittedOccurredAt,
                $submittedOccurredOn,
                $errorId,
            ]);
            $attachments = error_upload_files($errorId);
            $errorPostUploadedFiles = array_column($attachments, 'path');
            if ($attachments) {
                $existingAttachments = error_attachment_records((string) ($existing['attachment_paths'] ?? ''));
                $mergedAttachments = array_merge($existingAttachments, $attachments);
                $stmt = db()->prepare('UPDATE ops_error_logs SET attachment_paths = ? WHERE id = ? AND deleted_at IS NULL');
                $stmt->execute([json_encode($mergedAttachments, JSON_UNESCAPED_SLASHES), $errorId]);
                $changes['attachments'] = ['added' => array_column($attachments, 'path')];
            }
            db()->commit();
            $errorPostTransaction = false;
            $errorPostUploadedFiles = [];
            if ($occurrenceChanged) ops_activity_log('error_occurrence_date_changed', 'error_log', $errorId, ['previous_value'=>$existingOccurredAt, 'new_value'=>$submittedOccurredAt, 'previous_date'=>$existingOccurredOn, 'new_date'=>$submittedOccurredOn, 'reason'=>$occurrenceChangeReason, 'actor_employee_id'=>$currentEmployeeId, 'timezone'=>'Africa/Windhoek']);
            if($attributionChanged){$audit=['previous_attribution_type'=>$oldAttribution?:'awaiting_owner_review','previous_attributed_employee_id'=>$oldAttributedEmployee,'new_attribution_type'=>$attributionType,'new_attributed_employee_id'=>$responsibleEmployeeId,'reason'=>$attributionNote,'actor_employee_id'=>$currentEmployeeId,'kpi_eligible'=>$attributionType==='employee'&&$accuracyVerified,'business_health_eligible'=>true];ops_activity_log('error_attribution_corrected','error_log',$errorId,$audit);ops_kpi_record_event('error_log','error',$errorId,'attribution_corrected',$oldAttribution?:null,$attributionType,$currentEmployeeId,['reason_note'=>$attributionNote,'metadata'=>$audit]);}
            if(isset($changes['financial_impact'])||isset($changes['has_financial_impact'])){$financialAudit=['has_financial_impact'=>$hasFinancialImpact,'previous_financial_amount'=>(string)($existing['financial_impact']??''),'new_financial_amount'=>$financialImpact,'actor_employee_id'=>$currentEmployeeId,'business_health_eligible'=>true];ops_activity_log('error_financial_impact_changed','error_log',$errorId,$financialAudit);ops_kpi_record_event('error_log','error',$errorId,'financial_impact_changed',(string)($existing['financial_impact']??''),$financialImpact,$currentEmployeeId,['metadata'=>$financialAudit]);}
            ops_activity_log('error_updated', 'error_log', $errorId, ['fields_changed' => array_keys($changes), 'changes' => $changes]);
            error_log_redirect('Error updated.', 'success', '?updated=1&error_id=' . $errorId);
        }

        if ($action === 'remove_attachment') {
            if (!$isOwnerErrorUser) throw new RuntimeException('Only an owner/admin may remove error evidence.');
            $submittedToken = (string) ($_POST['csrf_token'] ?? '');
            if ($submittedToken === '' || !hash_equals($errorAttachmentCsrf, $submittedToken)) throw new RuntimeException('Your session expired. Refresh and try again.');
            $errorId = max(0, (int) ($_POST['error_id'] ?? 0));
            $attachmentPath = trim((string) ($_POST['attachment_path'] ?? ''));
            $rows = ops_rows('SELECT attachment_paths FROM ops_error_logs WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$errorId]);
            if (!$rows) throw new RuntimeException('Incident not found.');
            $attachments = error_attachment_records((string) ($rows[0]['attachment_paths'] ?? ''));
            $remaining = [];
            $matched = false;
            foreach ($attachments as $attachment) {
                if (!$matched && hash_equals((string) $attachment['path'], $attachmentPath)) { $matched = true; continue; }
                $remaining[] = $attachment;
            }
            if (!$matched) throw new RuntimeException('Attachment not found.');
            $uploadRoot = realpath(BASE_PATH . '/uploads/error-log');
            $absolutePath = realpath(BASE_PATH . '/' . ltrim($attachmentPath, '/'));
            if ($uploadRoot && $absolutePath && error_path_starts_with($absolutePath, $uploadRoot . DIRECTORY_SEPARATOR) && is_file($absolutePath) && !unlink($absolutePath)) {
                throw new RuntimeException('The attachment could not be removed from storage.');
            }
            db()->prepare('UPDATE ops_error_logs SET attachment_paths = ? WHERE id = ? AND deleted_at IS NULL')->execute([json_encode($remaining, JSON_UNESCAPED_SLASHES), $errorId]);
            ops_activity_log('error_attachment_removed', 'error_log', $errorId, ['attachment_path' => $attachmentPath, 'actor_employee_id' => $currentEmployeeId]);
            error_log_redirect('Attachment removed.', 'success', '?error_id=' . $errorId);
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
            if (!$isOwnerErrorUser) throw new RuntimeException('Only the owner can change error status.');
            $errorId = (int) ($_POST['error_id'] ?? 0);
            $status = ops_post_string('status', 30);
            if (!array_key_exists($status, $statusLabels)) throw new RuntimeException('Choose a valid status.');
            $permissionRows = ops_rows('SELECT logged_by, status FROM ops_error_logs WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$errorId]);
            $loggedBy = (int) ($permissionRows[0]['logged_by'] ?? 0);
            $previousStatus = (string) ($permissionRows[0]['status'] ?? '');
            if (!$isOwnerErrorUser && !($isFrontDeskErrorUser && $loggedBy === (int) $currentEmployeeId)) {
                throw new RuntimeException('You can only update errors you logged yourself.');
            }
            $stmt = db()->prepare('UPDATE ops_error_logs SET status = ? WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$status, $errorId]);
            ops_activity_log('error_status_updated', 'error_log', $errorId, [
                'previous_status' => $previousStatus,
                'new_status' => $status,
                'previous_value' => $previousStatus,
                'new_value' => $status,
                'reason' => ops_post_string('status_reason', 1000),
            ]);
            error_log_redirect('Error status updated.', 'success', '?status_updated=1');
        }
    } catch (Throwable $e) {
        if ($errorPostTransaction && db()->inTransaction()) db()->rollBack();
        foreach ($errorPostUploadedFiles as $uploadedPath) {
            $absoluteUploadedPath = BASE_PATH . '/' . ltrim((string) $uploadedPath, '/');
            if (is_file($absoluteUploadedPath)) @unlink($absoluteUploadedPath);
        }
        error_log('Error Log request failed: ' . get_class($e) . ': ' . $e->getMessage());
        $publicMessage = $e instanceof RuntimeException && !($e instanceof PDOException)
            ? $e->getMessage()
            : 'Error could not be saved. Please try again or ask the owner to review the Error Log server log.';
        error_log_redirect($publicMessage, 'error', '?form_error=1');
    }
}

if ($ready && empty($_SESSION['incident_submission_token'])) {
    $_SESSION['incident_submission_token'] = bin2hex(random_bytes(32));
}
$incidentSubmissionToken = (string) ($_SESSION['incident_submission_token'] ?? '');
if (empty($_SESSION['error_instruction_csrf_token'])) $_SESSION['error_instruction_csrf_token'] = bin2hex(random_bytes(32));
if (empty($_SESSION['error_instruction_submission_token'])) $_SESSION['error_instruction_submission_token'] = bin2hex(random_bytes(32));
if (empty($_SESSION['error_limited_edit_csrf'])) $_SESSION['error_limited_edit_csrf'] = bin2hex(random_bytes(32));
$errorInstructionCsrfToken = (string) $_SESSION['error_instruction_csrf_token'];
$errorInstructionSubmissionToken = (string) $_SESSION['error_instruction_submission_token'];
$errorLimitedEditCsrf=(string)$_SESSION['error_limited_edit_csrf'];

$defaultErrorMonth = date('Y-m');
$requestedDateMode = trim((string) ($_GET['date_mode'] ?? ''));
if ($requestedDateMode === '') {
    $requestedDateMode = (!empty($_GET['date_from']) || !empty($_GET['date_to'])) ? 'custom' : 'month';
}
if (!in_array($requestedDateMode, ['month', 'custom'], true)) $requestedDateMode = 'month';
$filters = [
    'date_mode' => $requestedDateMode,
    'month' => trim((string) ($_GET['month'] ?? $defaultErrorMonth)),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'date_basis'=>'occurred',
    'sort'=>trim((string)($_GET['sort']??'occurred_newest')),
    'severity' => trim((string) ($_GET['severity'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'employee_id' => trim((string) ($_GET['employee_id'] ?? '')),
    'logged_for'=>trim((string)($_GET['logged_for']??'')),
    'financial_impact_filter'=>trim((string)($_GET['financial_impact_filter']??'')),
    'repeat_issue' => trim((string) ($_GET['repeat_issue'] ?? '')),
    'customer_impacted' => trim((string) ($_GET['customer_impacted'] ?? '')),
    'order_reference' => trim((string) ($_GET['order_reference'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
$filtersAreActive = ($filters['date_mode'] === 'custom' && ($filters['date_from'] !== '' || $filters['date_to'] !== '')) || ($filters['date_mode'] === 'month' && $filters['month'] !== $defaultErrorMonth) || $filters['sort']!=='occurred_newest' || $filters['severity'] !== '' || $filters['category'] !== '' || $filters['employee_id'] !== '' || $filters['logged_for']!=='' || $filters['financial_impact_filter']!=='' || $filters['repeat_issue'] !== '' || $filters['customer_impacted'] !== '' || $filters['order_reference'] !== '' || $filters['status'] !== '' || $filters['search'] !== '';

$where = ['el.deleted_at IS NULL'];
$params = [];
$requestedErrorId = max(0, (int) ($_GET['error_id'] ?? 0));
$dateExpression=error_occurrence_expression('el');
if ($requestedErrorId > 0) {
    $where[] = 'el.id = ?';
    $params[] = $requestedErrorId;
} elseif ($filters['date_mode'] === 'custom') {
    if ($filters['date_from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
        $where[] = $dateExpression . ' >= ?';
        $params[] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
        $where[] = $dateExpression . ' <= ?';
        $params[] = $filters['date_to'];
    }
} elseif (preg_match('/^\d{4}-\d{2}$/', $filters['month'])) {
    $monthStart = $filters['month'] . '-01';
    $monthEnd = (new DateTimeImmutable($monthStart, new DateTimeZone('Africa/Windhoek')))->modify('last day of this month')->format('Y-m-d');
    $where[] = $dateExpression . ' BETWEEN ? AND ?';
    array_push($params, $monthStart, $monthEnd);
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
if(in_array($filters['logged_for'],['employee','delivery_driver','business'],true)){$where[]=$filters['logged_for']==='employee'?"(el.attribution_type='employee' OR (el.attribution_type IS NULL AND el.responsible_employee_id IS NOT NULL))":'el.attribution_type=?';if($filters['logged_for']!=='employee')$params[]=$filters['logged_for'];}
if($filters['financial_impact_filter']==='yes')$where[]='el.has_financial_impact=1';elseif($filters['financial_impact_filter']==='no')$where[]='el.has_financial_impact=0';
if ($isFrontDeskErrorUser && !$isOwnerErrorUser) {
    [$frontPersonSql, $frontPersonParams] = error_person_filter_sql('el', (int) $currentEmployeeId);
    $where[] = "({$frontPersonSql} OR el.logged_by = ? OR EXISTS (
        SELECT 1 FROM ops_error_instruction_reads instruction_read
        JOIN ops_error_instructions instruction ON instruction.id=instruction_read.instruction_id
        WHERE instruction.error_id=el.id AND instruction_read.recipient_user_id=?
    ))";
    array_push($params, ...$frontPersonParams);
    $params[] = (int) $currentEmployeeId;
    $params[] = (int) $currentEmployeeId;
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
if ($filters['search'] !== '') {
    $where[] = '(el.error_title LIKE ? OR el.description LIKE ? OR el.category LIKE ? OR el.order_reference LIKE ?)';
    $term = '%' . $filters['search'] . '%';
    array_push($params, $term, $term, $term, $term);
}
if (array_key_exists($filters['status'], $statusLabels)) {
    $where[] = 'el.status = ?';
    $params[] = $filters['status'];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sortSql=['occurred_newest'=>error_occurrence_expression('el').' DESC','occurred_oldest'=>error_occurrence_expression('el').' ASC','logged_newest'=>'COALESCE(el.created_at,el.logged_at) DESC','logged_oldest'=>'COALESCE(el.created_at,el.logged_at) ASC','financial_highest'=>'el.financial_impact DESC','financial_lowest'=>'el.financial_impact ASC'][$filters['sort']]??error_occurrence_expression('el').' DESC';
$errors = $ready ? ops_rows(
    "SELECT el.*, e.full_name AS primary_employee_name, lb.full_name AS logged_by_name
     FROM ops_error_logs el
     LEFT JOIN ops_employees e ON e.id = el.employee_id
     LEFT JOIN ops_employees lb ON lb.id = el.logged_by
     {$whereSql}
     ORDER BY {$sortSql},el.id DESC
     LIMIT 300",
    $params
) : [];

$metrics = [
    'month_total' => count($errors),
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
foreach ($errors as $row) {
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

$activeFilterChips = [];
$addErrorFilterChip = static function (string $key, string $label, array $removeKeys = []) use (&$activeFilterChips): void {
    $query = $_GET;
    unset($query['error_id']);
    foreach (array_merge([$key], $removeKeys) as $removeKey) unset($query[$removeKey]);
    $activeFilterChips[] = ['key' => $key, 'label' => $label, 'url' => 'errors.php' . ($query ? '?' . http_build_query($query) : '')];
};
if ($filters['date_mode'] === 'custom' && ($filters['date_from'] !== '' || $filters['date_to'] !== '')) {
    $addErrorFilterChip('date_mode', 'Occurred: ' . ($filters['date_from'] ?: 'Any date') . ' to ' . ($filters['date_to'] ?: 'Today'), ['date_from', 'date_to']);
} elseif ($filters['date_mode'] === 'month' && $filters['month'] !== $defaultErrorMonth) {
    $monthLabel = DateTimeImmutable::createFromFormat('!Y-m', $filters['month'], new DateTimeZone('Africa/Windhoek'));
    $addErrorFilterChip('month', $monthLabel ? $monthLabel->format('F Y') : $filters['month'], ['date_mode']);
}
if ($filters['sort'] !== 'occurred_newest') $addErrorFilterChip('sort', 'Sort: ' . str_replace('_', ' ', $filters['sort']));
if ($filters['severity'] !== '') $addErrorFilterChip('severity', 'Severity: ' . ($severityLabels[$filters['severity']] ?? $filters['severity']));
if ($filters['category'] !== '') $addErrorFilterChip('category', 'Category: ' . ($errorCategories[$filters['category']] ?? $filters['category']));
if ((int) $filters['employee_id'] > 0) $addErrorFilterChip('employee_id', 'Person: ' . ($employeeMap[(int) $filters['employee_id']] ?? 'Employee'));
if ($filters['logged_for'] !== '') $addErrorFilterChip('logged_for', 'Logged for: ' . ucwords(str_replace('_', ' ', $filters['logged_for'])));
if ($filters['financial_impact_filter'] !== '') $addErrorFilterChip('financial_impact_filter', $filters['financial_impact_filter'] === 'yes' ? 'Has financial impact' : 'No financial impact');
if ($filters['repeat_issue'] !== '') $addErrorFilterChip('repeat_issue', 'Repeat: ' . ($filters['repeat_issue'] === '1' ? 'Yes' : 'No'));
if ($filters['customer_impacted'] !== '') $addErrorFilterChip('customer_impacted', 'Customer impacted: ' . ($filters['customer_impacted'] === '1' ? 'Yes' : 'No'));
if ($filters['order_reference'] !== '') $addErrorFilterChip('order_reference', 'Order: ' . $filters['order_reference']);
if ($filters['status'] !== '') $addErrorFilterChip('status', 'Status: ' . ($statusLabels[$filters['status']] ?? $filters['status']));
if ($filters['search'] !== '') $addErrorFilterChip('search', 'Search: ' . $filters['search']);
$activeFilterCount = count($activeFilterChips);

$errorIds = array_map(static fn(array $row): int => (int) $row['id'], $errors);
$instructionsByError = error_instructions_for_errors($errorIds);
$instructionUnreadByError = $isOwnerErrorUser ? [] : error_instruction_unread_counts((int) $currentEmployeeId);

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
<main class="workspace module error-log-page" id="error-task-details">
    <section class="error-log-header">
        <div>
            <p class="error-log-kicker">Operations</p>
            <h1 class="error-log-title">Error Log</h1>
        </div>
        <div class="error-log-header-actions" data-portal-header-status-target>
            <button class="button primary error-log-btn-primary" type="button" data-error-modal-open><i data-lucide="plus"></i> Log Error</button>
        </div>
    </section>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <?php if ($showFullErrorLog): ?>
        <?php
        $errorStats = [
            ['icon' => 'calendar-days', 'label' => 'Errors in Selected View', 'value' => number_format($metrics['month_total']), 'colour' => 'var(--bk-orange-red)'],
            ['icon' => 'siren', 'label' => 'Critical Errors', 'value' => number_format($metrics['critical']), 'colour' => 'var(--bk-red)'],
            ['icon' => 'triangle-alert', 'label' => 'High Severity', 'value' => number_format($metrics['high']), 'colour' => 'var(--bk-amber)'],
            ['icon' => 'info', 'label' => 'Medium Severity', 'value' => number_format($metrics['medium']), 'colour' => 'var(--bk-orange-red)'],
            ['icon' => 'badge-check', 'label' => 'Low Severity', 'value' => number_format($metrics['low']), 'colour' => 'var(--bk-olive)'],
            ['icon' => 'repeat-2', 'label' => 'Repeat Errors', 'value' => number_format($metrics['repeat']), 'colour' => 'var(--bk-burgundy)'],
            ['icon' => 'check-circle-2', 'label' => 'Errors Resolved', 'value' => number_format($metrics['resolved']), 'colour' => 'var(--bk-olive)'],
            ['icon' => 'layers', 'label' => 'Most Common Category', 'value' => (string) $metrics['common_category'], 'colour' => 'var(--bk-orange-red)'],
            ['icon' => 'user-round-x', 'label' => 'Employee With Most Logged Errors', 'value' => (string) $metrics['top_employee'], 'colour' => 'var(--bk-burgundy)'],
        ];
        ?>
        <section class="error-stats-shell" aria-label="Error log metrics" data-error-filter-metrics>
            <div class="error-stats-grid">
                <?php foreach ($errorStats as $stat): ?>
                    <article class="error-stat-card" style="--stat-colour: <?= htmlspecialchars($stat['colour'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="error-stat-icon"><i data-lucide="<?= htmlspecialchars($stat['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                        <div>
                            <span class="error-stat-label"><?= htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="error-stat-value"><?= htmlspecialchars((string) $stat['value'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <details class="error-filter-card" data-portal-view-filter <?= $filtersAreActive ? 'open' : '' ?>>
        <summary class="error-filter-header"><span><i data-lucide="sliders-horizontal"></i><span>Filters<small><?= $activeFilterCount ? $activeFilterCount . ' filter' . ($activeFilterCount === 1 ? '' : 's') . ' active' : 'No additional filters active' ?></small></span></span><strong data-error-filter-summary><?= $activeFilterCount ? (string) $activeFilterCount : 'Clear' ?></strong></summary>
        <form class="error-filter-body" method="get" data-error-filter-form>
            <div class="error-filter-grid">
                <label class="span-2">Search<input type="search" name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search errors, descriptions, categories or orders"></label>
                <label>Date period<select name="date_mode" data-portal-custom-select data-error-date-mode><?php ops_select_options(['month'=>'Selected Month','custom'=>'Custom Date Range'],$filters['date_mode']);?></select></label>
                <label data-error-month-field <?= $filters['date_mode'] === 'custom' ? 'hidden' : '' ?>>Month<input type="month" name="month" value="<?= htmlspecialchars($filters['month'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label data-error-custom-date <?= $filters['date_mode'] === 'month' ? 'hidden' : '' ?>>Date from<input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label data-error-custom-date <?= $filters['date_mode'] === 'month' ? 'hidden' : '' ?>>Date to<input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <div class="error-filter-date-basis"><span>Date field</span><strong>Date Error Occurred</strong><small>Africa/Windhoek boundaries</small></div>
                <label>Sort<select name="sort" data-portal-custom-select><?php ops_select_options(['occurred_newest'=>'Error Occurred — Newest','occurred_oldest'=>'Error Occurred — Oldest','logged_newest'=>'Date Logged — Newest','logged_oldest'=>'Date Logged — Oldest','financial_highest'=>'Financial Impact — Highest','financial_lowest'=>'Financial Impact — Lowest'],$filters['sort']);?></select></label>
                <label>Severity<select name="severity" data-portal-custom-select><option value="">All severity</option><?php ops_select_options($severityLabels, $filters['severity']); ?></select></label>
                <label>Category<select name="category" data-portal-custom-select><option value="">All categories</option><?php ops_select_options($errorCategories, $filters['category']); ?></select></label>
                <?php if ($showFullErrorLog): ?><label>Person involved<select name="employee_id" data-portal-custom-select><option value="">All people</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $filters['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <label>Logged for<select name="logged_for" data-portal-custom-select><?php ops_select_options([''=>'All','employee'=>'Employee','delivery_driver'=>'Delivery Driver','business'=>'Business Error'],$filters['logged_for']); ?></select></label>
                <label>Financial impact<select name="financial_impact_filter" data-portal-custom-select><?php ops_select_options([''=>'All','yes'=>'Has financial impact','no'=>'No financial impact'],$filters['financial_impact_filter']); ?></select></label>
                <label>Repeat error<select name="repeat_issue" data-portal-custom-select><?php ops_select_options(['' => 'All', '1' => 'Yes', '0' => 'No'], $filters['repeat_issue']); ?></select></label>
                <label>Customer impacted<select name="customer_impacted" data-portal-custom-select><?php ops_select_options(['' => 'All', '1' => 'Yes', '0' => 'No'], $filters['customer_impacted']); ?></select></label>
                <label>Order ID<input name="order_reference" value="<?= htmlspecialchars($filters['order_reference'], ENT_QUOTES, 'UTF-8') ?>" placeholder="#33863 or WEB-33780"></label>
                <label>Resolution status<select name="status" data-portal-custom-select><option value="">All statuses</option><?php ops_select_options($statusLabels, $filters['status']); ?></select></label>
            </div>
            <p class="error-filter-feedback" data-error-filter-feedback role="status" aria-live="polite"></p>
            <div class="ops-form-actions error-filter-actions"><button class="button" type="button" data-error-filter-clear>Clear All</button><button class="button primary" type="submit">Apply Filters</button></div>
        </form>
    </details>

    <div data-error-results>
    <div class="error-filter-chips-shell" data-error-filter-chips-shell>
    <?php if ($activeFilterChips): ?>
        <nav class="error-filter-chips" aria-label="Active Error Log filters">
            <?php foreach ($activeFilterChips as $chip): ?><button type="button" data-filter-url="<?= htmlspecialchars($chip['url'], ENT_QUOTES, 'UTF-8') ?>" data-error-filter-chip><?= htmlspecialchars($chip['label'], ENT_QUOTES, 'UTF-8') ?><i data-lucide="x" aria-hidden="true"></i></button><?php endforeach; ?>
            <button type="button" class="error-filter-clear-link" data-filter-url="errors.php" data-error-filter-clear-link>Clear all</button>
        </nav>
    <?php endif; ?>
    </div>

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
                <col class="col-date">
                <col class="col-title">
                <col class="col-order">
                <?php if ($showFullErrorLog): ?>
                    <col class="col-category">
                <?php endif; ?>
                <col class="col-severity">
                <?php if ($showFullErrorLog): ?>
                    <col class="col-person">
                    <col class="col-person">
                    <col class="col-impact">
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
                    <th scope="col">Date Error Occurred</th>
                    <th scope="col">Date Logged</th>
                    <th scope="col">Error Title</th>
                    <th scope="col">Order ID</th>
                    <?php if ($showFullErrorLog): ?>
                        <th scope="col">Category</th>
                    <?php endif; ?>
                    <th scope="col">Severity</th>
                    <?php if ($showFullErrorLog): ?>
                        <th scope="col">Person Involved</th>
                        <th scope="col">Logged For</th>
                        <th scope="col">Financial Impact</th>
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
                        <td class="error-board-date-cell" data-error-occurred-cell="<?= (int)$error['id'] ?>"><?= htmlspecialchars(error_occurred_at_label((string)($error['occurred_at']??''),(string)($error['occurred_on']??'')),ENT_QUOTES,'UTF-8') ?></td>
                        <td class="error-board-date-cell"><?= htmlspecialchars(error_logged_label((string)(($error['created_at']??null)?:($error['logged_at']??''))),ENT_QUOTES,'UTF-8') ?></td>
                        <td><span class="error-board-title-link"><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></span><?php if (($instructionUnreadByError[(int)$error['id']] ?? 0) > 0): ?><span class="owner-instruction-unread" aria-label="<?= (int)$instructionUnreadByError[(int)$error['id']] ?> unread owner instructions"><?= (int)$instructionUnreadByError[(int)$error['id']] ?> new</span><?php endif; ?></td>
                        <td><?= htmlspecialchars((string) ($error['order_reference'] ?: $error['order_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($errorCategories[(string) $error['category']] ?? (string) $error['category'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="error-board-severity severity-<?= htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($severityLabels[$severity] ?? $severity, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars($peopleText, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(error_attribution_label($error,$employeeMap),ENT_QUOTES,'UTF-8') ?></td>
                        <td data-error-finance-cell="<?= (int)$error['id'] ?>"><?= $error['financial_impact']===null?'Not recorded':'N$'.number_format((float)$error['financial_impact'],2) ?></td>
                        <td><?= trim((string) ($error['customer_impact'] ?? '')) !== '' ? 'Yes' : 'No' ?></td>
                        <td><span class="error-board-status status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= (int) ($error['repeat_issue'] ?? 0) === 1 ? 'Yes' : 'No' ?></td>
                        <td><?= htmlspecialchars((string) ($error['logged_by_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?></td>
                    <?php else: ?>
                        <td class="error-board-date-cell"><?= htmlspecialchars(error_occurred_at_label((string)($error['occurred_at']??''),(string)($error['occurred_on']??'')),ENT_QUOTES,'UTF-8') ?></td>
                        <td class="error-board-date-cell"><?= htmlspecialchars(error_logged_label((string)(($error['created_at']??null)?:($error['logged_at']??''))),ENT_QUOTES,'UTF-8') ?></td>
                        <td><span class="error-board-title-link"><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></span><?php if (($instructionUnreadByError[(int)$error['id']] ?? 0) > 0): ?><span class="owner-instruction-unread" aria-label="<?= (int)$instructionUnreadByError[(int)$error['id']] ?> unread owner instructions"><?= (int)$instructionUnreadByError[(int)$error['id']] ?> new</span><?php endif; ?></td>
                        <td><?= htmlspecialchars((string) ($error['order_reference'] ?: $error['order_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="error-board-severity severity-<?= htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($severityLabels[$severity] ?? $severity, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><span class="error-board-status status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sectionErrors): ?><tr class="error-board-empty"><td colspan="<?= $showFullErrorLog ? 13 : 6 ?>">No Error Log records match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endforeach; ?>
    </div>

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
                        <label>Date &amp; Time Error Occurred *<span class="portal-date-field" data-portal-date-field><input id="error-occurred-at-display" class="portal-date-input" type="text" data-enable-time="true" data-submit-target="#error-occurred-at" placeholder="Select date and time" autocomplete="off"><input id="error-occurred-at" name="occurred_at" required type="hidden" value="<?= (new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d\TH:i') ?>" data-portal-date-required-message="Select the date and time the error occurred."><button type="button" class="portal-date-trigger" aria-label="Open date and time picker"><i data-lucide="calendar-clock" aria-hidden="true"></i></button></span><small>Select when the error actually happened, even if you are reporting it later.</small></label><?php if($isOwnerErrorUser):?><label>Reason for changing occurrence date and time<input name="occurred_at_change_reason" maxlength="1000" placeholder="Required only when correcting an existing error"></label><?php endif;?>
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
                    <fieldset class="incident-choice-field" id="severity-group">
                        <legend class="incident-choice-field__label">Severity <span aria-hidden="true">*</span></legend>
                        <div class="incident-choice-control incident-choice-control--severity" data-incident-choice="severity">
                            <?php foreach ($severityLabels as $value => $label): ?>
                                <?php [$choiceColour, $choiceText] = $severityChoiceColours[$value]; ?>
                                <label class="incident-choice" style="--choice-color:<?= $choiceColour ?>;--choice-text:<?= $choiceText ?>">
                                    <input class="incident-choice__input" type="radio" name="severity" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" required>
                                    <span class="incident-choice__content"><span class="incident-choice__indicator" aria-hidden="true"></span><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><span class="incident-choice__check" aria-hidden="true">&#10003;</span></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <fieldset class="incident-choice-field" id="status-group">
                        <legend class="incident-choice-field__label">Status <span aria-hidden="true">*</span></legend>
                        <div class="incident-choice-control incident-choice-control--status" data-incident-choice="status">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <?php [$choiceColour, $choiceText] = $statusChoiceColours[$value]; ?>
                                <label class="incident-choice" style="--choice-color:<?= $choiceColour ?>;--choice-text:<?= $choiceText ?>">
                                    <input class="incident-choice__input" type="radio" name="status" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" <?= $value === 'open' ? 'checked' : '' ?> required>
                                    <span class="incident-choice__content"><span class="incident-choice__indicator" aria-hidden="true"></span><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><span class="incident-choice__check" aria-hidden="true">&#10003;</span></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                </section>

                <section class="error-form-section incident-section">
                    <h3><i data-lucide="message-square-warning"></i> What Happened</h3>
                    <label for="description">Description<textarea id="description" name="description" required placeholder="Explain exactly what happened, what caused the issue, and what impact it had."></textarea></label>
                    <p class="incident-field-help">For Task Marked Complete but Not Done, include Task # followed by its ID and describe the evidence of unfinished work. The higher weight requires owner verification and a matching task completion record. This classification does not establish intent.</p>
                </section>

                <section class="error-form-section incident-section">
                    <h3><i data-lucide="users-round"></i> Responsibility</h3>
                    <fieldset class="error-attribution-field" id="error-attribution-group">
                        <legend>WHO IS THIS ERROR BEING LOGGED FOR? <span class="error-required-mark" aria-hidden="true">*</span></legend>
                        <p>Select the employee or business area responsible for the error. The person submitting the error must never be selected automatically as the responsible party.</p>
                        <div class="error-attribution-options">
                            <?php foreach ($employees as $employee): ?><label class="error-attribution-option" data-attribution-type="employee"><input type="radio" name="error_attribution" value="employee:<?= (int)$employee['id'] ?>" required><span><?= htmlspecialchars(ops_staff_display_name($employee),ENT_QUOTES,'UTF-8') ?></span></label><?php endforeach; ?>
                            <label class="error-attribution-option" data-attribution-type="delivery_driver"><input type="radio" name="error_attribution" value="delivery_driver"><span>Delivery Driver</span></label>
                            <label class="error-attribution-option" data-attribution-type="business"><input type="radio" name="error_attribution" value="business"><span>Business Error</span></label>
                        </div>
                        <input type="hidden" name="attribution_type" value=""><input type="hidden" name="attributed_employee_id" value="">
                    </fieldset>
                    <div class="form-grid compact"><label>Related Packing List row ID<input type="number" min="1" name="packing_task_id" placeholder="Optional"></label><?php if($isOwnerErrorUser): ?><label>Attribution change note<textarea name="attribution_change_note" placeholder="Required only when correcting attribution"></textarea></label><?php endif; ?></div>
                    <label><input type="checkbox" name="affects_kpi_accuracy" value="1"> This verified error affects personal accuracy</label>
                    <?php if ($isOwnerErrorUser): ?><label><input type="checkbox" name="accuracy_verified" value="1"> Owner/admin verified the attribution</label><?php endif; ?>
                    <p class="incident-field-help">The employee who logs the error is never treated automatically as the responsible employee.</p>
                    <fieldset class="incident-choice-field">
                        <legend class="incident-choice-field__label">Is this a repeat error?</legend>
                        <div class="incident-choice-control incident-choice-control--repeat" data-incident-choice="repeat">
                            <label class="incident-choice" style="--choice-color:#A8CA19;--choice-text:#263400"><input class="incident-choice__input" type="radio" name="repeat_issue" value="0" aria-label="No" checked><span class="incident-choice__content"><span class="incident-choice__indicator" aria-hidden="true"></span><span>No</span><span class="incident-choice__check" aria-hidden="true">&#10003;</span></span></label>
                            <label class="incident-choice" style="--choice-color:#F07420;--choice-text:#FFFFFF"><input class="incident-choice__input" type="radio" name="repeat_issue" value="1" aria-label="Yes"><span class="incident-choice__content"><span class="incident-choice__indicator" aria-hidden="true"></span><span>Yes</span><span class="incident-choice__check" aria-hidden="true">&#10003;</span></span></label>
                        </div>
                    </fieldset>
                </section>

                <section class="error-form-section incident-section">
                    <h3><i data-lucide="check-circle-2"></i> Resolution</h3>
                    <div class="incident-resolution-stack">
                        <div class="incident-field">
                            <label for="resolution">Resolution</label>
                            <textarea id="resolution" name="resolution" placeholder="customer contacted, stock updated, product replaced"></textarea>
                        </div>
                        <fieldset class="error-financial-impact-field" id="error-financial-impact-group"><legend>DOES THIS ERROR HAVE A FINANCIAL IMPACT? <span class="error-required-mark" aria-hidden="true">*</span></legend><div class="error-financial-impact-options"><label><input type="radio" name="has_financial_impact" value="unknown" required><span>Not recorded</span></label><label><input type="radio" name="has_financial_impact" value="0"><span>N$0.00</span></label><label><input type="radio" name="has_financial_impact" value="1"><span>Yes</span></label></div></fieldset>
                        <div class="incident-field error-financial-impact-amount" hidden><label for="financial-impact">FINANCIAL IMPACT AMOUNT (N$) <span class="error-required-mark" aria-hidden="true">*</span></label><div class="error-money-input"><span>N$</span><input id="financial-impact" inputmode="decimal" name="financial_impact_amount" pattern="^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$" disabled></div></div>
                        <div class="incident-field error-financial-impact-notes" hidden><label for="financial-impact-notes">FINANCIAL IMPACT NOTES</label><textarea id="financial-impact-notes" name="financial_impact_notes" placeholder="Briefly explain the cost, replacement, refund, damaged stock or other financial effect."></textarea></div>
                    </div>
                </section>

                <section class="error-form-section incident-section">
                    <h3><i data-lucide="paperclip"></i> Attachments</h3>
                    <label class="error-upload-zone">
                        <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" data-error-file-input>
                        <span><i data-lucide="upload-cloud"></i></span>
                        <strong>Drag files here or choose evidence files</strong>
                        <small>JPG, PNG, WEBP, PDF, Word, Excel, CSV or TXT · up to 10 MB each</small>
                    </label>
                    <div class="error-selected-files" data-error-selected-files hidden>
                        <div class="error-selected-files__heading"><strong>Evidence files</strong><span data-error-selected-count></span></div>
                        <ol data-error-selected-list></ol>
                    </div>
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
        $attachments = error_attachment_records((string) ($error['attachment_paths'] ?? ''));
        $severity = (string) ($error['severity'] ?? 'low');
        $status = (string) ($error['status'] ?? 'open');
        $canUpdateThisError = $isOwnerErrorUser;
        $canLimitedEditThisError = $isOwnerErrorUser || $isFrontDeskErrorUser;
        $canDeleteThisError = in_array($currentRoleKey, ['owner_admin', 'owner', 'admin'], true);
        $storedCategory = (string) ($error['category'] ?? '');
        $editData = [
            'id' => $errorId,
            'error_title' => (string) ($error['error_title'] ?? ''),
            'occurred_at' => error_occurrence_input((string)($error['occurred_at']??'')) ?: ((string)($error['occurred_on']??'') !== '' ? (string)$error['occurred_on'].'T00:00' : ''),
            'order_reference' => (string) ($error['order_reference'] ?? ''),
            'category' => array_key_exists($storedCategory, $errorCategories) ? $storedCategory : 'other',
            'other_category' => array_key_exists($storedCategory, $errorCategories) ? '' : $storedCategory,
            'severity' => $severity,
            'status' => $status,
            'description' => (string) ($error['description'] ?? ''),
            'people_involved' => $peopleIds,
            'responsible_employee_id' => (int) ($error['responsible_employee_id'] ?? 0),
            'attribution_type'=>(string)($error['attribution_type']??((int)($error['responsible_employee_id']??0)>0?'employee':'')),
            'attributed_employee_id'=>(int)($error['attributed_employee_id']??$error['responsible_employee_id']??0),
            'packing_task_id' => (int) ($error['packing_task_id'] ?? 0),
            'affects_kpi_accuracy' => (int) ($error['affects_kpi_accuracy'] ?? 0),
            'accuracy_verified' => !empty($error['accuracy_verified_by']),
            'repeat_issue' => (int) ($error['repeat_issue'] ?? 0),
            'resolution' => (string) ($error['resolution'] ?? ''),
            'has_financial_impact'=>$error['has_financial_impact']===null?null:(int)$error['has_financial_impact'],
            'financial_impact_amount'=>(string)($error['financial_impact']??''),
            'financial_impact_notes'=>(string)($error['financial_impact_notes']??''),
        ];
        $ownerInstructions = $instructionsByError[$errorId] ?? [];
        $latestInstruction = $ownerInstructions ? $ownerInstructions[count($ownerInstructions) - 1] : [];
        $latestInstructionId = (int) ($latestInstruction['id'] ?? 0);
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
                <?php if ($canUpdateThisError || $canLimitedEditThisError || $canDeleteThisError): ?>
                    <div class="incident-panel-actions">
                        <?php if ($canUpdateThisError): ?>
                            <button type="button" class="incident-action-btn incident-action-btn--edit" data-edit-incident="<?= $errorId ?>">Edit error</button>
                        <?php endif; ?>
                        <?php if ($canLimitedEditThisError): ?><button type="button" class="incident-action-btn incident-action-btn--edit" data-limited-error-edit data-error-id="<?= $errorId ?>" data-occurred-at="<?= htmlspecialchars(error_occurrence_input((string)($error['occurred_at']??'')) ?: ((string)($error['occurred_on']??'') !== '' ? (string)$error['occurred_on'].'T00:00' : ''),ENT_QUOTES,'UTF-8') ?>" data-financial-impact="<?= $error['financial_impact']===null?'':htmlspecialchars((string)$error['financial_impact'],ENT_QUOTES,'UTF-8') ?>" data-title="<?= htmlspecialchars((string)($error['error_title']??''),ENT_QUOTES,'UTF-8') ?>" data-employee="<?= htmlspecialchars(error_attribution_label($error,$employeeMap),ENT_QUOTES,'UTF-8') ?>" data-logged="<?= htmlspecialchars(error_date_label((string)(($error['created_at']??null)?:($error['logged_at']??''))),ENT_QUOTES,'UTF-8') ?>" data-status="<?= htmlspecialchars($statusLabels[$status]??$status,ENT_QUOTES,'UTF-8') ?>">Edit Date &amp; Financial Impact</button><?php endif; ?>
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
                        <label id="detail-status-label-<?= $errorId ?>">Status</label>
                        <div class="incident-status-controls">
                            <div class="incident-status-custom-select" data-incident-status-select>
                                <input type="hidden" name="status" class="incident-status-value-input" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="button" class="incident-status-trigger" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="detail-status-label-<?= $errorId ?> detail-status-value-<?= $errorId ?>">
                                    <span class="incident-status-trigger-value" id="detail-status-value-<?= $errorId ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?></span>
                                    <svg class="incident-status-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="M6 8l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                </button>
                                <div class="incident-status-menu" role="listbox" tabindex="-1" aria-label="Status options"></div>
                            </div>
                            <button class="incident-status-update-btn" type="submit">Update status</button>
                        </div>
                    </form>
                <?php endif; ?>
                <section class="owner-instruction-panel" id="owner-instructions-<?= $errorId ?>" tabindex="-1" data-owner-instructions data-error-id="<?= $errorId ?>" data-unread="<?= (int)($instructionUnreadByError[$errorId] ?? 0) ?>" data-csrf="<?= htmlspecialchars($errorInstructionCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <h3 class="owner-instruction-title">OWNER INSTRUCTIONS</h3>
                    <?php if ($isOwnerErrorUser): ?><p class="owner-instruction-help">Give the front person instructions for resolving this error.</p><?php endif; ?>
                    <div class="owner-instruction-history" aria-live="polite">
                        <?php foreach ($ownerInstructions as $instruction): ?>
                            <article class="owner-instruction-item<?= (int)$instruction['id'] === $latestInstructionId ? ' is-latest' : '' ?>" data-owner-instruction-item="<?= (int)$instruction['id'] ?>">
                                <div class="owner-instruction-item-head"><strong>Owner Instruction<?= (int)$instruction['id'] === $latestInstructionId ? ' · Latest' : '' ?></strong><span class="owner-instruction-status is-<?= htmlspecialchars((string)($instruction['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?>"><?= (string)($instruction['status'] ?? 'pending') === 'completed' ? 'Completed' : 'Pending' ?></span></div>
                                <p class="owner-instruction-author"><?= htmlspecialchars((string)($instruction['created_by_name'] ?? 'Owner/Admin'), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="owner-instruction-meta"><?= htmlspecialchars(error_instruction_date_label((string)$instruction['created_at']), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="owner-instruction-copy"><?= nl2br(htmlspecialchars((string)$instruction['instruction_text'], ENT_QUOTES, 'UTF-8')) ?></p>
                                <?php if ((string)($instruction['status'] ?? 'pending') === 'completed'): ?>
                                    <div class="owner-instruction-completion"><strong>Completion note</strong><p><?= nl2br(htmlspecialchars((string)($instruction['completion_note'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p><small><?= htmlspecialchars((string)($instruction['completed_by_name'] ?? 'Employee'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(error_instruction_date_label((string)($instruction['completed_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></small></div>
                                <?php elseif (!$isOwnerErrorUser): ?>
                                    <form class="owner-instruction-complete-form" data-owner-instruction-complete-form method="post" action="<?= BASE_URL ?>/apps/operations/error-instructions.php">
                                        <input type="hidden" name="action" value="complete_instruction"><input type="hidden" name="error_id" value="<?= $errorId ?>"><input type="hidden" name="instruction_id" value="<?= (int)$instruction['id'] ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($errorInstructionCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <label>Completion note</label><textarea name="completion_note" maxlength="4000" required placeholder="Explain what you completed and the outcome."></textarea><button type="submit">Mark Instruction Complete</button><p class="owner-instruction-feedback" data-owner-instruction-feedback hidden></p>
                                    </form>
                                <?php endif; ?>
                                <?php if ($isOwnerErrorUser): ?>
                                    <div class="owner-instruction-read-state">
                                    <?php foreach (($instruction['recipients'] ?? []) as $recipient): ?>
                                        <?php if (!empty($recipient['read_at'])): ?><span>Viewed by <?= htmlspecialchars((string)($recipient['recipient_name'] ?? 'Front person'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(error_instruction_date_label((string)$recipient['read_at']), ENT_QUOTES, 'UTF-8') ?></span><?php else: ?><span>Not yet viewed by <?= htmlspecialchars((string)($recipient['recipient_name'] ?? 'front person'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$ownerInstructions): ?><p class="owner-instruction-empty">No owner instructions have been sent.</p><?php endif; ?>
                    </div>
                    <?php if ($isOwnerErrorUser && $status !== 'resolved'): ?>
                        <form class="owner-instruction-form" data-owner-instruction-form method="post" action="<?= BASE_URL ?>/apps/operations/error-instructions.php">
                            <input type="hidden" name="action" value="send_instruction">
                            <input type="hidden" name="error_id" value="<?= $errorId ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($errorInstructionCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="submission_token" value="<?= htmlspecialchars($errorInstructionSubmissionToken, ENT_QUOTES, 'UTF-8') ?>">
                            <label for="owner-instruction-text-<?= $errorId ?>">Instruction</label>
                            <textarea class="owner-instruction-textarea" id="owner-instruction-text-<?= $errorId ?>" name="instruction_text" maxlength="4000" required placeholder="Explain what must be checked, corrected or communicated before this error can be resolved."></textarea>
                            <button class="owner-instruction-send" type="submit">Send Instruction</button>
                            <p class="owner-instruction-feedback" data-owner-instruction-feedback hidden></p>
                        </form>
                    <?php endif; ?>
                </section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="align-left"></i> Description</h3><p class="incident-content-text"><?= nl2br(htmlspecialchars((string) ($error['description'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="calendar-clock"></i> Timeline</h3><p class="incident-content-text">Date &amp; Time Error Occurred: <span data-error-occurred-detail="<?= $errorId ?>"><?= htmlspecialchars(error_occurred_at_label((string)($error['occurred_at']??''),(string)($error['occurred_on']??'')),ENT_QUOTES,'UTF-8') ?></span><?php if(($error['occurred_on_source']??'')==='migrated_from_logged_date'):?> <small class="error-date-source">Estimated from Date Logged</small><?php else:?><small class="error-date-source">Confirmed</small><?php endif;?><br>Date Logged: <?= htmlspecialchars(error_logged_label((string)(($error['created_at']??null)?:($error['logged_at']??''))),ENT_QUOTES,'UTF-8') ?></p></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="user-round-check"></i> Customer impact</h3><p class="incident-content-text<?= empty($error['customer_impact']) ? ' incident-empty-text' : '' ?>"><?= nl2br(htmlspecialchars((string) ($error['customer_impact'] ?: 'No customer impact recorded.'), ENT_QUOTES, 'UTF-8')) ?></p></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="badge-check"></i> Logged for</h3><p class="incident-content-text"><?= htmlspecialchars(error_attribution_label($error,$employeeMap),ENT_QUOTES,'UTF-8') ?> · <?= (string)($error['attribution_type']??'')==='employee'&&!empty($error['affects_kpi_accuracy'])&&!empty($error['accuracy_verified_by'])?'Verified for accuracy':'Not counted in personal accuracy' ?></p></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="circle-check"></i> Resolution</h3><p class="incident-content-text<?= empty($error['resolution']) ? ' incident-empty-text' : '' ?>"><?= nl2br(htmlspecialchars((string) ($error['resolution'] ?: 'No resolution recorded yet.'), ENT_QUOTES, 'UTF-8')) ?></p></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="banknote"></i> Financial impact</h3><p class="incident-content-text" data-error-finance-detail="<?= $errorId ?>"><?= $error['financial_impact']===null?'Not recorded':'N$'.number_format((float)$error['financial_impact'],2) ?></p><?php if(!empty($error['financial_impact_notes'])): ?><p class="incident-content-text"><?= nl2br(htmlspecialchars((string)$error['financial_impact_notes'],ENT_QUOTES,'UTF-8')) ?></p><?php endif; ?></section>
                <?php if (!empty($error['repeat_note'])): ?><section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="repeat-2"></i> Repeat note</h3><p class="incident-content-text"><?= nl2br(htmlspecialchars((string) $error['repeat_note'], ENT_QUOTES, 'UTF-8')) ?></p></section><?php endif; ?>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="paperclip"></i> Attachments</h3><div class="incident-attachments-list">
                    <?php foreach ($attachments as $attachment): ?><?php $attachmentPath=(string)$attachment['path'];$attachmentName=(string)$attachment['name'];$attachmentMime=(string)$attachment['mime'];$isImage=error_path_starts_with($attachmentMime,'image/')||preg_match('/\.(?:jpe?g|png|webp)$/i',$attachmentName);$attachmentUrl=BASE_URL.'/apps/operations/error-attachment.php?error_id='.$errorId.'&attachment='.rawurlencode($attachmentPath); ?><div class="incident-attachment">
                        <?php if($isImage): ?><a href="<?= htmlspecialchars($attachmentUrl,ENT_QUOTES,'UTF-8') ?>" target="_blank" rel="noopener" class="incident-attachment-preview"><img src="<?= htmlspecialchars($attachmentUrl,ENT_QUOTES,'UTF-8') ?>" alt="Preview of <?= htmlspecialchars($attachmentName,ENT_QUOTES,'UTF-8') ?>" loading="lazy"></a><?php endif; ?>
                        <span><strong><?= htmlspecialchars($attachmentName, ENT_QUOTES, 'UTF-8') ?></strong><?php if($attachment['size']!==null): ?><small><?= htmlspecialchars(number_format(((int)$attachment['size'])/1048576,2).' MB',ENT_QUOTES,'UTF-8') ?></small><?php endif; ?></span>
                        <div class="incident-attachment-actions"><a class="incident-attachment-link" href="<?= htmlspecialchars($attachmentUrl,ENT_QUOTES,'UTF-8') ?>" target="_blank" rel="noopener"><?= $isImage?'Preview':'Download' ?></a>
                        <?php if($isOwnerErrorUser): ?><form method="post" onsubmit="return confirm('Remove this attachment?');"><input type="hidden" name="action" value="remove_attachment"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($errorAttachmentCsrf,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="error_id" value="<?= $errorId ?>"><input type="hidden" name="attachment_path" value="<?= htmlspecialchars($attachmentPath,ENT_QUOTES,'UTF-8') ?>"><button type="submit" class="incident-attachment-remove">Remove</button></form><?php endif; ?></div>
                    </div><?php endforeach; ?>
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
    <dialog class="error-limited-edit" data-error-limited-dialog><form method="post" data-error-limited-form data-error-log-edit-form data-endpoint="<?= BASE_URL ?>/apps/operations/error-limited-edit.php"><input type="hidden" name="csrf" value="<?= htmlspecialchars($errorLimitedEditCsrf,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="error_id"><header><div><span>Restricted correction</span><h2>Edit Date &amp; Financial Impact</h2></div><button type="button" data-error-limited-close aria-label="Close"><i data-lucide="x"></i></button></header><div class="error-limited-edit__context"><div><span>Error ID</span><strong data-limited-context="id"></strong></div><div><span>Employee responsible</span><strong data-limited-context="employee"></strong></div><div><span>Error title/type</span><strong data-limited-context="title"></strong></div><div><span>Date Logged</span><strong data-limited-context="logged"></strong></div><div><span>Current status</span><strong data-limited-context="status"></strong></div></div><div class="error-limited-edit__field"><label for="limited-occurred-at-display">Date &amp; Time Error Occurred</label><div class="portal-date-field" data-portal-date-field><input id="limited-occurred-at-display" class="portal-date-input" type="text" data-enable-time="true" data-submit-target="#limited-occurred-at" data-error-log-datetime-display placeholder="Select date and time" autocomplete="off"><input id="limited-occurred-at" name="occurred_at" type="hidden" data-error-log-datetime data-portal-date-required-message="Select the date and time the error occurred." required><button type="button" class="portal-date-trigger" aria-label="Open date and time picker"><i data-lucide="calendar-clock" aria-hidden="true"></i></button></div><small class="error-date-source" data-limited-source></small></div><div class="error-limited-edit__field"><label for="limited-financial-impact">Financial Impact</label><div class="error-money-input"><span aria-hidden="true">N$</span><input id="limited-financial-impact" name="financial_impact" type="text" inputmode="decimal" data-error-log-financial-impact placeholder="Not recorded"></div><small>Leave blank when the impact is not yet determined.</small></div><p class="error-limited-edit__feedback error-log-field-save-note" data-limited-feedback data-error-log-save-status role="status" aria-live="polite"></p><div class="error-limited-edit__actions"><button class="button" type="button" data-error-limited-close>Cancel</button><button class="button primary error-log-edit-save" type="button" data-error-log-save disabled>Save changes</button></div></form></dialog>
</main>
<script>
let pendingDeleteForm = null;

const errorFilterForm = document.querySelector('[data-error-filter-form]');
let errorFilterRequest = null;
let errorFilterSequence = 0;

function syncErrorDateMode() {
  if (!errorFilterForm) return;
  const mode = errorFilterForm.elements.date_mode?.value || 'month';
  errorFilterForm.querySelectorAll('[data-error-month-field]').forEach((field) => { field.hidden = mode !== 'month'; });
  errorFilterForm.querySelectorAll('[data-error-custom-date]').forEach((field) => { field.hidden = mode !== 'custom'; });
}

function syncErrorFilterControls(nextDocument) {
  if (!errorFilterForm) return;
  const nextForm = nextDocument.querySelector('[data-error-filter-form]');
  if (!nextForm) return;
  [...errorFilterForm.elements].forEach((control) => {
    if (!control.name) return;
    const nextControl = nextForm.elements.namedItem(control.name);
    if (!nextControl) return;
    if (control.type === 'checkbox' || control.type === 'radio') control.checked = nextControl.checked;
    else control.value = nextControl.value;
    control.dispatchEvent(new Event('change', { bubbles: true }));
  });
  syncErrorDateMode();
  const currentSummary = document.querySelector('.error-filter-header');
  const nextSummary = nextDocument.querySelector('.error-filter-header');
  if (currentSummary && nextSummary) currentSummary.innerHTML = nextSummary.innerHTML;
}

function replaceErrorFilterResults(nextDocument) {
  const currentMetrics = document.querySelector('[data-error-filter-metrics]');
  const nextMetrics = nextDocument.querySelector('[data-error-filter-metrics]');
  if (currentMetrics && nextMetrics) currentMetrics.innerHTML = nextMetrics.innerHTML;
  const currentChips = document.querySelector('[data-error-filter-chips-shell]');
  const nextChips = nextDocument.querySelector('[data-error-filter-chips-shell]');
  if (currentChips && nextChips) currentChips.innerHTML = nextChips.innerHTML;
  ['open', 'resolved'].forEach((status) => {
    const currentSection = document.querySelector(`.error-section-${status}`);
    const nextSection = nextDocument.querySelector(`.error-section-${status}`);
    if (!currentSection || !nextSection) return;
    const currentCount = currentSection.querySelector('.error-board-count');
    const nextCount = nextSection.querySelector('.error-board-count');
    const currentBody = currentSection.querySelector('tbody');
    const nextBody = nextSection.querySelector('tbody');
    if (currentCount && nextCount) currentCount.textContent = nextCount.textContent;
    if (currentBody && nextBody) currentBody.innerHTML = nextBody.innerHTML;
  });
  document.querySelector('[data-error-filter-failure]')?.remove();
  bindErrorFilterChips();
  window.lucide?.createIcons({ attrs: { 'aria-hidden': 'true' }, strokeWidth: 1.7 });
}

function bindErrorFilterChips() {
  document.querySelectorAll('[data-error-filter-chip], [data-error-filter-clear-link]').forEach((chip) => {
    if (chip.dataset.filterBound === '1') return;
    chip.dataset.filterBound = '1';
    chip.addEventListener('click', () => loadErrorFilterView(chip.dataset.filterUrl || 'errors.php'));
  });
}

function updateErrorFilterToolbar(nextDocument) {
  const count = nextDocument.querySelectorAll('[data-error-filter-chip]').length;
  const badge = document.querySelector('[data-filter-toolbar] [data-filter-count]');
  const button = document.querySelector('[data-filter-toolbar] [data-view-action="filter"]');
  if (badge) { badge.textContent = String(count); badge.hidden = count === 0; }
  button?.classList.toggle('is-active', count > 0);
}

async function loadErrorFilterView(url, options = {}) {
  const sequence = ++errorFilterSequence;
  errorFilterRequest?.abort();
  errorFilterRequest = new AbortController();
  const feedback = errorFilterForm?.querySelector('[data-error-filter-feedback]');
  const applyButton = errorFilterForm?.querySelector('[type="submit"]');
  if (feedback) feedback.textContent = 'Loading filtered Error Log records...';
  if (applyButton) { applyButton.disabled = true; applyButton.setAttribute('aria-busy', 'true'); }
  try {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
      signal: errorFilterRequest.signal
    });
    if (!response.ok) throw new Error(`Request failed (HTTP ${response.status}).`);
    const html = await response.text();
    if (sequence !== errorFilterSequence) return;
    const nextDocument = new DOMParser().parseFromString(html, 'text/html');
    if (!nextDocument.querySelector('[data-error-results]')) throw new Error('The filtered response was incomplete.');
    replaceErrorFilterResults(nextDocument);
    syncErrorFilterControls(nextDocument);
    updateErrorFilterToolbar(nextDocument);
    if (options.push !== false) history.pushState({ errorFilters: true }, '', url);
    if (feedback) feedback.textContent = 'Filters applied.';
    return true;
  } catch (error) {
    if (error?.name === 'AbortError') return;
    const results = document.querySelector('[data-error-results]');
    let failure = document.querySelector('[data-error-filter-failure]');
    if (!failure && results) {
      failure = document.createElement('div');
      failure.className = 'error-filter-failure';
      failure.dataset.errorFilterFailure = '';
      results.prepend(failure);
    }
    if (failure) failure.innerHTML = '<strong>Could not load filtered Error Log records.</strong><span>Retry or clear the filters.</span>';
    if (feedback) feedback.textContent = error?.message || 'Could not load filtered Error Log records. Retry.';
    return false;
  } finally {
    if (sequence === errorFilterSequence && applyButton) { applyButton.disabled = false; applyButton.removeAttribute('aria-busy'); }
  }
}

errorFilterForm?.querySelector('[name="date_mode"]')?.addEventListener('change', syncErrorDateMode);
errorFilterForm?.addEventListener('change', () => queueMicrotask(() => updateErrorFilterToolbar(document)));
window.addEventListener('load', () => window.setTimeout(() => updateErrorFilterToolbar(document), 0));
syncErrorDateMode();
errorFilterForm?.addEventListener('submit', (event) => {
  event.preventDefault();
  const parameters = new URLSearchParams(new FormData(errorFilterForm));
  if ((parameters.get('date_mode') || 'month') === 'month') {
    parameters.delete('date_from');
    parameters.delete('date_to');
  } else {
    parameters.delete('month');
    const from = parameters.get('date_from') || '';
    const to = parameters.get('date_to') || '';
    if (from && to && from > to) {
      const feedback = errorFilterForm.querySelector('[data-error-filter-feedback]');
      if (feedback) feedback.textContent = 'Date From must be on or before Date To.';
      errorFilterForm.elements.date_from?.focus();
      return;
    }
  }
  [...parameters.entries()].forEach(([key, value]) => { if (value === '') parameters.delete(key); });
  loadErrorFilterView(`errors.php?${parameters.toString()}`);
});
errorFilterForm?.querySelector('[data-error-filter-clear]')?.addEventListener('click', () => loadErrorFilterView('errors.php'));
bindErrorFilterChips();
window.addEventListener('popstate', () => loadErrorFilterView(location.href, { push: false }));

async function markOwnerInstructionsRead(panel) {
  const section = panel?.querySelector('[data-owner-instructions]');
  if (!section || Number(section.dataset.unread || 0) < 1 || section.dataset.markingRead === '1') return;
  section.dataset.markingRead = '1';
  const body = new URLSearchParams({action:'mark_read', error_id:section.dataset.errorId || '', csrf_token:section.dataset.csrf || ''});
  try {
    const response = await fetch('<?= BASE_URL ?>/apps/operations/error-instructions.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'}, body:body.toString(), credentials:'same-origin'});
    const result = await response.json();
    if (response.ok && result.ok) {
      section.dataset.unread = '0';
      document.querySelector(`[data-error-open="${section.dataset.errorId}"] .owner-instruction-unread`)?.remove();
    }
  } catch (error) {
    // A temporary network failure must not falsely mark an instruction as read.
  } finally {
    section.dataset.markingRead = '0';
  }
}

async function readOwnerInstructionResponse(response) {
  const raw = await response.text();
  try { return JSON.parse(raw); }
  catch (parseError) {
    throw new Error(response.ok ? 'The server returned an invalid response. Retry.' : `The server could not process the request (HTTP ${response.status}).`);
  }
}

function ownerInstructionNode(data, isOwner) {
  const article = document.createElement('article');
  article.className = 'owner-instruction-item is-latest';
  article.dataset.ownerInstructionItem = String(data.id || '');
  const head = document.createElement('div'); head.className = 'owner-instruction-item-head';
  const title = document.createElement('strong'); title.textContent = 'Owner Instruction · Latest';
  const status = document.createElement('span'); status.className = `owner-instruction-status is-${data.status || 'pending'}`; status.textContent = data.status === 'completed' ? 'Completed' : 'Pending';
  head.append(title, status); article.append(head);
  const author = document.createElement('p'); author.className = 'owner-instruction-author'; author.textContent = data.created_by_name || 'Owner/Admin'; article.append(author);
  const meta = document.createElement('p'); meta.className = 'owner-instruction-meta'; meta.textContent = data.created_at || ''; article.append(meta);
  const copy = document.createElement('p'); copy.className = 'owner-instruction-copy'; copy.textContent = data.instruction_text || ''; article.append(copy);
  if (data.status === 'completed') appendOwnerInstructionCompletion(article, data);
  return article;
}

function appendOwnerInstructionCompletion(article, data) {
  article.querySelector('[data-owner-instruction-complete-form]')?.remove();
  const status = article.querySelector('.owner-instruction-status');
  if (status) { status.className = 'owner-instruction-status is-completed'; status.textContent = 'Completed'; }
  const completion = document.createElement('div'); completion.className = 'owner-instruction-completion';
  const heading = document.createElement('strong'); heading.textContent = 'Completion note';
  const note = document.createElement('p'); note.textContent = data.completion_note || '';
  const byline = document.createElement('small'); byline.textContent = `${data.completed_by_name || 'Employee'} · ${data.completed_at || ''}`;
  completion.append(heading, note, byline); article.append(completion);
}

const errorTaskDetails = document.getElementById('error-task-details');
errorTaskDetails?.querySelectorAll('[data-owner-instruction-form]').forEach((form) => {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const textarea = form.querySelector('.owner-instruction-textarea');
    const button = form.querySelector('.owner-instruction-send');
    const feedback = form.querySelector('[data-owner-instruction-feedback]');
    const instruction = textarea?.value.trim() || '';
    if (!instruction) {
      textarea?.setCustomValidity('Enter an instruction before sending.');
      textarea?.reportValidity();
      return;
    }
    textarea.setCustomValidity('');
    button.disabled = true;
    feedback.hidden = true;
    try {
      const response = await fetch(form.getAttribute('action'), {method:'POST', body:new URLSearchParams(new FormData(form)).toString(), credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
      const result = await readOwnerInstructionResponse(response);
      if (!response.ok || !result.ok) throw new Error(result.message || 'Unable to send the instruction.');
      const section = form.closest('[data-owner-instructions]');
      const history = section?.querySelector('.owner-instruction-history');
      history?.querySelectorAll('.owner-instruction-item').forEach((item) => {
        item.classList.remove('is-latest');
        const label = item.querySelector('.owner-instruction-item-head strong');
        if (label) label.textContent = 'Owner Instruction';
      });
      history?.querySelector('.owner-instruction-empty')?.remove();
      if (history && result.instruction) history.append(ownerInstructionNode(result.instruction, true));
      if (result.submission_token) document.querySelectorAll('[data-owner-instruction-form] [name="submission_token"]').forEach((input) => { input.value = result.submission_token; });
      textarea.value = '';
      feedback.textContent = result.message || 'Instruction sent successfully.';
      feedback.className = 'owner-instruction-feedback is-success';
      feedback.hidden = false;
      button.disabled = false;
    } catch (error) {
      feedback.textContent = `${error.message || 'Unable to send the instruction.'} Retry.`;
      feedback.className = 'owner-instruction-feedback is-error';
      feedback.hidden = false;
      button.disabled = false;
    }
  });
});

errorTaskDetails?.addEventListener('submit', async (event) => {
  const form = event.target.closest('[data-owner-instruction-complete-form]');
  if (!form) return;
  event.preventDefault();
  const textarea = form.querySelector('[name="completion_note"]');
  const button = form.querySelector('button[type="submit"]');
  const feedback = form.querySelector('[data-owner-instruction-feedback]');
  const note = textarea?.value.trim() || '';
  if (!note) { textarea?.setCustomValidity('Enter a completion note before marking this instruction complete.'); textarea?.reportValidity(); return; }
  if (note.length < 10) { textarea?.setCustomValidity('The completion note must be at least 10 characters.'); textarea?.reportValidity(); return; }
  textarea.setCustomValidity(''); button.disabled = true; feedback.hidden = true;
  try {
    const response = await fetch(form.getAttribute('action'), {method:'POST', body:new URLSearchParams(new FormData(form)).toString(), credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
    const result = await readOwnerInstructionResponse(response);
    if (!response.ok || !result.ok) throw new Error(result.message || 'Unable to complete the instruction.');
    const article = form.closest('[data-owner-instruction-item]');
    if (article && result.instruction) appendOwnerInstructionCompletion(article, result.instruction);
  } catch (error) {
    feedback.textContent = `${error.message || 'Unable to complete the instruction.'} Retry.`;
    feedback.className = 'owner-instruction-feedback is-error'; feedback.hidden = false; button.disabled = false;
  }
});

function setIncidentRadioValue(name, value) {
  document.querySelectorAll(`#logErrorForm [name="${name}"]`).forEach((input) => {
    input.checked = input.value === String(value);
    input.setCustomValidity('');
  });
}

function setErrorAttribution(type='',employeeId=''){
  const form=document.getElementById('logErrorForm');if(!form)return;const value=type==='employee'?`employee:${employeeId}`:type;
  form.querySelectorAll('[name="error_attribution"]').forEach(input=>{input.checked=input.value===value;});
  form.elements.attribution_type.value=type||'';form.elements.attributed_employee_id.value=type==='employee'?String(employeeId||''):'';
}

function setFinancialImpactChoice(choice,amount='',notes=''){
  const form=document.getElementById('logErrorForm');if(!form)return;setIncidentRadioValue('has_financial_impact',choice===null?'':String(choice));
  const wrap=form.querySelector('.error-financial-impact-amount'),notesWrap=form.querySelector('.error-financial-impact-notes'),input=form.elements.financial_impact_amount;
  const yes=String(choice)==='1';wrap.hidden=!yes;notesWrap.hidden=!yes;input.disabled=!yes;input.required=yes;input.value=yes?String(amount||''):'';form.elements.financial_impact_notes.value=yes?(notes||''):'';
}

const errorEvidenceState = { files: [], previewUrls: [] };
function errorEvidenceKey(file) { return `${file.name}\u0000${file.size}\u0000${file.lastModified}`; }
function resetErrorEvidenceFiles() {
  errorEvidenceState.previewUrls.forEach((url) => URL.revokeObjectURL(url));
  errorEvidenceState.previewUrls = [];
  errorEvidenceState.files = [];
  const input = document.querySelector('[data-error-file-input]');
  if (input) input.value = '';
  renderErrorEvidenceFiles();
}
function syncErrorEvidenceInput() {
  const input = document.querySelector('[data-error-file-input]');
  if (!input) return;
  const transfer = new DataTransfer();
  errorEvidenceState.files.forEach((file) => transfer.items.add(file));
  input.files = transfer.files;
}
function formatEvidenceSize(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1048576).toFixed(2)} MB`;
}
function renderErrorEvidenceFiles() {
  errorEvidenceState.previewUrls.forEach((url) => URL.revokeObjectURL(url));
  errorEvidenceState.previewUrls = [];
  const wrapper = document.querySelector('[data-error-selected-files]');
  const list = document.querySelector('[data-error-selected-list]');
  const count = document.querySelector('[data-error-selected-count]');
  if (!wrapper || !list || !count) return;
  list.replaceChildren();
  wrapper.hidden = errorEvidenceState.files.length === 0;
  count.textContent = `${errorEvidenceState.files.length} selected`;
  errorEvidenceState.files.forEach((file, index) => {
    const item = document.createElement('li');
    item.className = 'error-selected-file';
    if (file.type.startsWith('image/')) {
      const image = document.createElement('img');
      const previewUrl = URL.createObjectURL(file);
      errorEvidenceState.previewUrls.push(previewUrl);
      image.src = previewUrl; image.alt = ''; item.appendChild(image);
    } else {
      const icon = document.createElement('span'); icon.className = 'error-selected-file__icon'; icon.textContent = file.name.split('.').pop()?.toUpperCase() || 'FILE'; item.appendChild(icon);
    }
    const details = document.createElement('span');
    const name = document.createElement('strong'); name.textContent = file.name;
    const meta = document.createElement('small'); meta.textContent = `${file.type || 'Unknown type'} · ${formatEvidenceSize(file.size)}`;
    details.append(name, meta); item.appendChild(details);
    const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Remove'; remove.dataset.removeErrorFile = String(index); remove.setAttribute('aria-label', `Remove ${file.name}`); item.appendChild(remove);
    list.appendChild(item);
  });
}
function addErrorEvidenceFiles(files) {
  const existing = new Set(errorEvidenceState.files.map(errorEvidenceKey));
  Array.from(files || []).forEach((file) => { if (!existing.has(errorEvidenceKey(file))) { errorEvidenceState.files.push(file); existing.add(errorEvidenceKey(file)); } });
  if (errorEvidenceState.files.length > 10) {
    errorEvidenceState.files = errorEvidenceState.files.slice(0, 10);
    window.alert('Only the first 10 evidence files were retained.');
  }
  syncErrorEvidenceInput(); renderErrorEvidenceFiles();
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
  resetErrorEvidenceFiles();
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
  setIncidentRadioValue('severity', mode === 'edit' ? (data.severity || '') : '');
  setIncidentRadioValue('status', mode === 'edit' ? (data.status || 'open') : 'open');
  setIncidentRadioValue('repeat_issue', mode === 'edit' ? Number(data.repeat_issue || 0) : 0);

  if (mode === 'edit') {
    form.elements.error_title.value = data.error_title || '';
    form.elements.occurred_at.value = data.occurred_at || '';
    form.elements.occurred_at.dispatchEvent(new Event('change',{bubbles:true}));
    form.elements.order_reference.value = data.order_reference || '';
    form.elements.description.value = data.description || '';
    form.elements.resolution.value = data.resolution || '';
    setFinancialImpactChoice(data.has_financial_impact,data.financial_impact_amount,data.financial_impact_notes);
    setErrorAttribution(data.attribution_type||'',data.attributed_employee_id||'');
    form.elements.packing_task_id.value = data.packing_task_id || '';
    form.elements.affects_kpi_accuracy.checked = Number(data.affects_kpi_accuracy || 0) === 1;
    if (form.elements.accuracy_verified) form.elements.accuracy_verified.checked = Boolean(data.accuracy_verified);
  } else {
    form.elements.occurred_at.value = new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(new Date()).replace(' ','T');
    form.elements.occurred_at.dispatchEvent(new Event('change',{bubbles:true}));
    setErrorAttribution('','');setFinancialImpactChoice(null,'','');
    form.elements.packing_task_id.value = '';
    form.elements.affects_kpi_accuracy.checked = false;
    if (form.elements.accuracy_verified) form.elements.accuracy_verified.checked = false;
  }

  document.querySelectorAll('.error-detail-panel.open').forEach((openPanel) => openPanel.classList.remove('open'));
  panel.classList.add('open');
  const backdrop = document.querySelector('.error-panel-backdrop');
  if (backdrop) backdrop.hidden = false;
  document.body.classList.add('error-panel-open');
}

function initIncidentStatusDropdown(root) {
  const select = root.querySelector('[data-incident-status-select]');
  if (!select || select.dataset.initialised === 'true') return;

  select.dataset.initialised = 'true';

  const trigger = select.querySelector('.incident-status-trigger');
  const valueLabel = select.querySelector('.incident-status-trigger-value');
  const hiddenInput = select.querySelector('.incident-status-value-input');
  const menu = select.querySelector('.incident-status-menu');
  if (!trigger || !valueLabel || !hiddenInput || !menu) return;

  const incidentStatusOptions = ['Not Resolved', 'Resolved'];
  const incidentStatusValueMap = { 'Not Resolved': 'open', 'Resolved': 'resolved' };
  let activeIndex = -1;

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  menu.innerHTML = incidentStatusOptions.map((label, index) => {
    const value = incidentStatusValueMap[label];
    const isSelected = value === hiddenInput.value;
    return `<button type="button" class="incident-status-option" role="option" data-value="${escapeHtml(value)}" data-index="${index}" aria-selected="${isSelected ? 'true' : 'false'}">${escapeHtml(label)}</button>`;
  }).join('');

  const optionButtons = Array.from(menu.querySelectorAll('.incident-status-option'));

  function clearActiveOption() {
    optionButtons.forEach((option) => option.classList.remove('is-active'));
  }

  function updateActiveOption() {
    clearActiveOption();
    const activeOption = optionButtons[activeIndex];
    if (!activeOption) return;
    activeOption.classList.add('is-active');
    activeOption.scrollIntoView({ block: 'nearest' });
  }

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
    hiddenInput.value = optionButton.dataset.value || '';
    valueLabel.textContent = optionButton.textContent.trim();
    hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
    closeMenu();
    trigger.focus();
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
      const activeOption = optionButtons[activeIndex];
      if (activeOption) selectOption(activeOption);
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      closeMenu();
    }
  });

  document.addEventListener('click', (event) => {
    if (!select.contains(event.target)) closeMenu();
  });
}

document.querySelectorAll('.incident-details-panel').forEach((panel) => {
  initIncidentStatusDropdown(panel);
});

document.addEventListener('click', (event) => {
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
    if (!panel) {
      const detailUrl = new URL(window.location.href);
      detailUrl.searchParams.set('error_id', detailOpen.dataset.errorOpen);
      window.location.assign(detailUrl.toString());
      return;
    }
    initIncidentStatusDropdown(panel);
    panel.classList.add('open');
    markOwnerInstructionsRead(panel);
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

(() => {
  const parameters = new URLSearchParams(window.location.search);
  const requestedErrorId = parameters.get('error_id');
  if (!requestedErrorId || !/^\d+$/.test(requestedErrorId) || parameters.get('instruction') !== '1') return;
  const row = errorTaskDetails?.querySelector(`[data-error-open="${requestedErrorId}"]`);
  const panel = errorTaskDetails?.querySelector(`[data-error-panel="${requestedErrorId}"]`);
  if (!row || !panel) return;
  row.click();
  const instructionSection = panel.querySelector('[data-owner-instructions]');
  window.setTimeout(() => instructionSection?.focus({preventScroll:false}), 0);
})();

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

document.querySelector('[data-error-file-input]')?.addEventListener('change', function() {
  addErrorEvidenceFiles(this.files);
});
const errorUploadZone = document.querySelector('.error-upload-zone');
errorUploadZone?.addEventListener('dragover', (event) => { event.preventDefault(); errorUploadZone.classList.add('is-dragover'); });
errorUploadZone?.addEventListener('dragleave', () => errorUploadZone.classList.remove('is-dragover'));
errorUploadZone?.addEventListener('drop', (event) => { event.preventDefault(); errorUploadZone.classList.remove('is-dragover'); addErrorEvidenceFiles(event.dataTransfer?.files); });
document.querySelector('[data-error-selected-list]')?.addEventListener('click', (event) => {
  const remove = event.target.closest('[data-remove-error-file]');
  if (!remove) return;
  errorEvidenceState.files.splice(Number(remove.dataset.removeErrorFile), 1);
  syncErrorEvidenceInput(); renderErrorEvidenceFiles();
});

document.getElementById('logErrorForm')?.addEventListener('change', function(event) {
  const attribution=event.target.closest('[name="error_attribution"]');
  if(attribution){const [type,employeeId='']=String(attribution.value).split(':');this.elements.attribution_type.value=type;this.elements.attributed_employee_id.value=type==='employee'?employeeId:'';document.getElementById('error-attribution-group-error')?.remove();}
  if(event.target.matches('[name="has_financial_impact"]')){setFinancialImpactChoice(event.target.value,this.elements.financial_impact_amount.value,this.elements.financial_impact_notes.value);document.getElementById('error-financial-impact-group-error')?.remove();}
  const input = event.target.closest('.incident-choice__input');
  if (!input) return;
  this.querySelectorAll(`[name="${input.name}"]`).forEach((choice) => choice.setCustomValidity(''));
  if (input.name === 'severity') document.getElementById('severity-group-error')?.remove();
  if (input.name === 'status') document.getElementById('status-group-error')?.remove();
});

document.getElementById('logErrorForm')?.addEventListener('submit', async function(event) {
  const severityValue = this.querySelector('[name="severity"]:checked');
  const severityControl = this.querySelector('[name="severity"]');
  const statusValue = this.querySelector('[name="status"]:checked');
  const statusControl = this.querySelector('[name="status"]');
  const categoryValue = document.getElementById('error-category-value');
  const otherCategoryInput = document.getElementById('incident-other-category');
  const description = this.querySelector('[name="description"]');
  const severity = String(severityValue?.value || '').trim();
  const status = String(statusValue?.value || '').trim();
  const category = String(categoryValue?.value || '').trim();
  const otherCategory = String(otherCategoryInput?.value || '').trim();
  const descriptionText = String(description?.value || '').trim();
  const attribution=this.querySelector('[name="error_attribution"]:checked');
  const financialChoice=this.querySelector('[name="has_financial_impact"]:checked');
  const financialAmount=this.elements.financial_impact_amount;

  document.getElementById('severity-group-error')?.remove();
  document.getElementById('status-group-error')?.remove();
  document.getElementById('error-category-value-error')?.remove();
  document.getElementById('description-error')?.remove();
  document.getElementById('error-attribution-group-error')?.remove();document.getElementById('error-financial-impact-group-error')?.remove();document.getElementById('financial-impact-error')?.remove();
  otherCategoryInput?.classList.remove('is-invalid');
  otherCategoryInput?.setCustomValidity('');

  let hasError = false;
  let firstInvalid=null;
  if (!category) {
    hasError = true;
    firstInvalid=firstInvalid||this.querySelector('.custom-select-trigger');
    categoryValue?.setCustomValidity('Please choose an error category.');
    showFieldError('error-category-value', 'Choose an error category.');
  }
  if(!attribution){hasError=true;showFieldError('error-attribution-group','Please select who this error is being logged for.');firstInvalid=firstInvalid||this.querySelector('[name="error_attribution"]');}
  if(!financialChoice){hasError=true;showFieldError('error-financial-impact-group','Please indicate whether this error has a financial impact.');firstInvalid=firstInvalid||this.querySelector('[name="has_financial_impact"]');}
  if(financialChoice?.value==='1'&&(!/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/.test(financialAmount.value.trim())||Number(financialAmount.value)<=0)){hasError=true;financialAmount.setCustomValidity('Enter the financial impact amount.');showFieldError('financial-impact','Enter the financial impact amount.');firstInvalid=firstInvalid||financialAmount;}
  if (category === 'other' && !otherCategory) {
    hasError = true;
    otherCategoryInput?.classList.add('is-invalid');
    otherCategoryInput?.setCustomValidity('Please enter the category.');
  }
  if (!severity) {
    hasError = true;
    severityControl?.setCustomValidity('Please select a severity level.');
    showFieldError('severity-group', 'Please select a severity level.');
  }
  if (!status) {
    hasError = true;
    statusControl?.setCustomValidity('Please select a status.');
    showFieldError('status-group', 'Please select a status.');
  }
  if (!descriptionText) {
    hasError = true;
    showFieldError('description', 'Description is required.');
  }

  if (hasError) {
    event.preventDefault();
    if(firstInvalid){firstInvalid.focus();firstInvalid.reportValidity?.();} else if (!category) {
      this.querySelector('.custom-select-trigger')?.focus();
    } else if (category === 'other' && !otherCategory) {
      otherCategoryInput?.reportValidity();
      otherCategoryInput?.focus();
    } else if (!severity) {
      severityControl?.focus();
    } else if (!status) {
      statusControl?.focus();
    } else {
      description?.focus();
    }
    return;
  }

  const saveButton = this.querySelector('[type="submit"]');
  if (this.elements.action?.value !== 'create_error') {
    if (saveButton) {
      saveButton.disabled = true;
      saveButton.dataset.originalText = saveButton.textContent;
      saveButton.textContent = 'Saving...';
    }
    return;
  }

  event.preventDefault();
  if (this.dataset.saving === '1') return;
  this.dataset.saving = '1';
  if (saveButton) {
    saveButton.disabled = true;
    saveButton.dataset.originalText = saveButton.textContent;
    saveButton.textContent = 'Saving...';
  }

  try {
    const response = await fetch(this.getAttribute('action') || window.location.href, {
      method: 'POST',
      body: new FormData(this),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || result?.success !== true) {
      throw new Error(result?.message || 'Error could not be saved. Please try again.');
    }

    const savedId = String(result.error_id || '');
    const panel = document.querySelector('[data-error-modal-panel]');
    const backdrop = document.querySelector('.error-panel-backdrop');
    panel?.classList.remove('open');
    if (backdrop) backdrop.hidden = true;
    document.body.classList.remove('error-panel-open');
    this.reset();
    if (result.submission_token) this.elements.submission_token.value = result.submission_token;
    resetErrorEvidenceFiles();
    setIncidentCategoryValue('');
    setErrorAttribution('', '');
    setFinancialImpactChoice(null, '', '');
    setIncidentRadioValue('status', 'open');
    setIncidentRadioValue('repeat_issue', 0);

    const refreshed = await loadErrorFilterView(window.location.href, { push: false });
    const savedRow = savedId ? document.querySelector(`[data-error-open="${savedId}"]`) : null;
    if (savedRow) {
      savedRow.classList.add('error-board-row--just-saved');
      savedRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
      window.setTimeout(() => savedRow.classList.remove('error-board-row--just-saved'), 3600);
      window.showPortalToast?.({ title: 'Error Log', message: 'Error logged successfully', type: 'success' });
    } else {
      const toastResult = window.showPortalToast?.({
        title: 'Error logged successfully',
        message: refreshed === false ? 'The error was saved, but the list could not refresh.' : 'The saved error is hidden by the current filters.',
        type: 'success',
        duration: 9000,
        actionsHtml: '<div class="portal-toast-actions"><button type="button" data-error-clear-after-save>Clear filters</button><button type="button" data-error-view-after-save>View saved error</button></div>'
      });
      toastResult?.toast?.querySelector('[data-error-clear-after-save]')?.addEventListener('click', () => loadErrorFilterView('errors.php'));
      toastResult?.toast?.querySelector('[data-error-view-after-save]')?.addEventListener('click', () => {
        if (result.saved_url) window.location.assign(result.saved_url);
      });
    }
    (result.warnings || []).forEach((warning) => window.showPortalToast?.({ title: 'Owner review needed', message: warning, type: 'warning', duration: 8000 }));
  } catch (error) {
    window.showPortalToast?.({ title: 'Error Log', message: error?.message || 'Error could not be saved. Please try again.', type: 'error', duration: 8000 });
  } finally {
    this.dataset.saving = '0';
    if (saveButton) {
      saveButton.disabled = false;
      saveButton.textContent = saveButton.dataset.originalText || 'Save Issue';
    }
  }
});

function showFieldError(fieldId, message) {
  const existing = document.getElementById(fieldId + '-error');
  if (existing) existing.remove();

  const el = document.getElementById(fieldId) || document.querySelector('.' + fieldId);
  if (!el || !el.parentNode) return;

  const err = document.createElement('p');
  err.id = fieldId + '-error';
  err.className='error-field-error';
  err.textContent = message;
  el.parentNode.appendChild(err);
}
const limitedDialog=errorTaskDetails?.querySelector('[data-error-limited-dialog]');
const limitedForm=limitedDialog?.querySelector('[data-error-limited-form]');
const normaliseLimitedImpact=(value)=>String(value??'').trim().replace(/^N\$\s*/i,'').replaceAll(',','');
const updateLimitedSaveState=()=>{if(!limitedForm)return;const save=limitedForm.querySelector('[data-error-log-save]');if(!save||limitedForm.dataset.saving==='1')return;const changed=limitedForm.elements.occurred_at.value!==limitedForm.dataset.initialOccurredAt||normaliseLimitedImpact(limitedForm.elements.financial_impact.value)!==normaliseLimitedImpact(limitedForm.dataset.initialFinancialImpact);save.disabled=!changed};
errorTaskDetails?.addEventListener('click',(event)=>{const open=event.target.closest('[data-limited-error-edit]');if(open&&limitedDialog){limitedForm.reset();limitedForm.elements.error_id.value=open.dataset.errorId||'';limitedForm.elements.occurred_at.value=open.dataset.occurredAt||'';limitedForm.elements.occurred_at.dispatchEvent(new Event('change',{bubbles:true}));limitedForm.elements.financial_impact.value=open.dataset.financialImpact||'';limitedForm.dataset.initialOccurredAt=open.dataset.occurredAt||'';limitedForm.dataset.initialFinancialImpact=open.dataset.financialImpact||'';for(const key of ['id','employee','title','logged','status']){const node=limitedForm.querySelector(`[data-limited-context="${key}"]`);if(node)node.textContent=key==='id'?`ERROR-${open.dataset.errorId}`:(open.dataset[key]||'—')}const source=limitedForm.querySelector('[data-limited-source]');if(source)source.textContent=open.dataset.occurredAt?'Confirmed or migrated occurrence time':'Select when the error happened';limitedForm.querySelector('[data-limited-feedback]').textContent='';const save=limitedForm.querySelector('[data-error-log-save]');save.textContent='Save changes';save.classList.remove('is-saving','is-saved','has-error');updateLimitedSaveState();limitedDialog.showModal();window.PortalDatePicker?.initialise(limitedDialog)}if(event.target.closest('[data-error-limited-close]'))limitedDialog?.close()});
limitedForm?.addEventListener('input',updateLimitedSaveState);
const saveLimitedErrorFields=async()=>{if(!limitedForm||limitedForm.dataset.saving==='1')return;const submit=limitedForm.querySelector('[data-error-log-save]'),feedback=limitedForm.querySelector('[data-limited-feedback]'),dateTimeInput=limitedForm.querySelector('[data-error-log-datetime]'),dateTimeDisplay=limitedForm.querySelector('[data-error-log-datetime-display]'),impactInput=limitedForm.querySelector('[data-error-log-financial-impact]');const occurredAt=dateTimeInput.value.trim(),impact=impactInput.value.trim();if(!occurredAt){feedback.textContent='Please select the date and time the error occurred.';dateTimeDisplay.focus();return}const normalised=normaliseLimitedImpact(impact);if(normalised!==''&&!/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/.test(normalised)){feedback.textContent='Financial impact must be zero or a valid positive amount.';impactInput.focus();return}limitedForm.dataset.saving='1';submit.disabled=true;dateTimeDisplay.disabled=true;impactInput.disabled=true;submit.classList.remove('is-saved','has-error');submit.classList.add('is-saving');submit.textContent='Saving…';feedback.textContent='Saving changes…';try{const payload={csrf:limitedForm.elements.csrf.value,error_id:Number(limitedForm.elements.error_id.value),occurred_at:occurredAt,client_timezone:window.portalClientTimezone||'Africa/Windhoek',financial_impact:impact===''?null:impact};const response=await fetch(limitedForm.dataset.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(payload)});const result=await response.json().catch(()=>null);if(!response.ok||!result?.success)throw new Error(result?.error||'The changes could not be saved. Please try again.');const id=String(result.error_id),dateCell=document.querySelector(`[data-error-occurred-cell="${id}"]`),dateDetail=document.querySelector(`[data-error-occurred-detail="${id}"]`),financeCell=document.querySelector(`[data-error-finance-cell="${id}"]`),financeDetail=document.querySelector(`[data-error-finance-detail="${id}"]`),editButton=document.querySelector(`[data-limited-error-edit][data-error-id="${id}"]`);if(dateCell)dateCell.textContent=result.error_occurred_at_display;if(dateDetail)dateDetail.textContent=result.error_occurred_at_display;if(financeCell)financeCell.textContent=result.financial_impact_display;if(financeDetail)financeDetail.textContent=result.financial_impact_display;if(editButton){editButton.dataset.occurredAt=result.error_occurred_at;editButton.dataset.financialImpact=result.financial_impact??''}dateTimeInput.value=result.error_occurred_at;dateTimeInput.dispatchEvent(new Event('change',{bubbles:true}));impactInput.value=result.financial_impact??'';limitedForm.dataset.initialOccurredAt=result.error_occurred_at;limitedForm.dataset.initialFinancialImpact=result.financial_impact??'';submit.classList.remove('is-saving','has-error');submit.classList.add('is-saved');submit.textContent='Saved';feedback.textContent=result.changed?'Error Log changes saved.':'No changes were needed.';setTimeout(()=>{submit.classList.remove('is-saved');submit.textContent='Save changes';updateLimitedSaveState()},1600)}catch(error){submit.classList.remove('is-saving','is-saved');submit.classList.add('has-error');submit.textContent='Try again';feedback.textContent=error?.message||'The changes could not be saved. Please try again.'}finally{limitedForm.dataset.saving='0';dateTimeDisplay.disabled=false;impactInput.disabled=false;if(!submit.classList.contains('is-saved')&&!submit.classList.contains('has-error'))updateLimitedSaveState()}};
limitedForm?.querySelector('[data-error-log-save]')?.addEventListener('click',saveLimitedErrorFields);
limitedForm?.addEventListener('submit',(event)=>{event.preventDefault();saveLimitedErrorFields()});

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  document.querySelectorAll('.error-log-panel.open, .error-detail-panel.open').forEach((panel) => panel.classList.remove('open'));
  const backdrop = document.querySelector('.error-panel-backdrop');
  if (backdrop) backdrop.hidden = true;
  document.body.classList.remove('error-panel-open');
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
