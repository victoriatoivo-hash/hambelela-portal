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

$severityLabels = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
$statusLabels = ['open' => 'Not Resolved', 'resolved' => 'Resolved'];
$severityChoiceColours = ['critical' => ['#BB1B21', '#FFFFFF'], 'high' => ['#F07420', '#FFFFFF'], 'medium' => ['#AB3619', '#FFFFFF'], 'low' => ['#A8CA19', '#263400']];
$statusChoiceColours = ['open' => ['#BB1B21', '#FFFFFF'], 'resolved' => ['#A8CA19', '#263400']];
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
        'deleted_at' => "ALTER TABLE ops_error_logs ADD COLUMN deleted_at DATETIME NULL AFTER updated_at",
        'deleted_by' => "ALTER TABLE ops_error_logs ADD COLUMN deleted_by INT NULL AFTER deleted_at",
    ];
    foreach ($columns as $column => $sql) {
        if (!error_column_exists($column)) error_try_sql($sql);
    }
    error_try_sql("UPDATE ops_error_logs SET error_title = category WHERE error_title IS NULL OR error_title = ''");
    error_try_sql("UPDATE ops_error_logs SET status = 'open' WHERE status IS NULL OR status = ''");
    error_try_sql("UPDATE ops_error_logs SET status = 'open' WHERE status = 'in_review'");
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
    if(!in_array($choice,['0','1'],true))throw new RuntimeException('Please indicate whether this error has a financial impact.');
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
            $stmt = db()->prepare(
                "INSERT INTO ops_error_logs
                 (error_title, employee_id, responsible_employee_id, attribution_type, attributed_employee_id, original_attribution_type, original_attributed_employee_id, people_involved, order_id, packing_task_id, affects_kpi_accuracy, accuracy_verified_by, accuracy_verified_at, attribution_verified_by, attribution_verified_at, order_reference, category, severity, description, customer_impact, financial_impact, has_financial_impact, financial_impact_notes, resolution, repeat_issue, repeat_note, status, logged_by, logged_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
            ]);
            $errorId = (int) db()->lastInsertId();
            $paths = error_upload_files($errorId);
            if ($paths) {
                $stmt = db()->prepare('UPDATE ops_error_logs SET attachment_paths = ? WHERE id = ?');
                $stmt->execute([json_encode($paths, JSON_UNESCAPED_SLASHES), $errorId]);
            }
            $eventMeta=['severity'=>$severity,'category'=>$category,'logged_by_user_id'=>(int)(current_user()['id']??0),'logged_by_employee_id'=>$currentEmployeeId,'attribution_type'=>$attributionType,'attributed_employee_id'=>$responsibleEmployeeId,'has_financial_impact'=>$hasFinancialImpact,'financial_impact_amount'=>$financialImpact,'kpi_eligible'=>$attributionType==='employee'&&$accuracyVerified,'business_health_eligible'=>true];
            ops_activity_log('error_logged','error_log',$errorId,$eventMeta);ops_kpi_record_event('error_log','error',$errorId,'error_created',null,$attributionType,$currentEmployeeId,['metadata'=>$eventMeta]);ops_kpi_record_event('error_log','error',$errorId,'attribution_selected',null,$attributionType,$currentEmployeeId,['metadata'=>$eventMeta]);ops_kpi_record_event('error_log','error',$errorId,'financial_impact_selected',null,$hasFinancialImpact?'yes':'no',$currentEmployeeId,['metadata'=>$eventMeta]);
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

            $stmt = db()->prepare(
                "UPDATE ops_error_logs
                 SET error_title = ?, employee_id = ?, responsible_employee_id = ?, attribution_type=?, attributed_employee_id=?, people_involved = ?, packing_task_id = ?, affects_kpi_accuracy = ?, accuracy_verified_by = ?, accuracy_verified_at = ?, attribution_verified_by=?, attribution_verified_at=?, order_reference = ?, category = ?, severity = ?, description = ?, financial_impact = ?, has_financial_impact=?, financial_impact_notes=?, resolution = ?, repeat_issue = ?, status = ?
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
                $errorId,
            ]);
            if($attributionChanged){$audit=['previous_attribution_type'=>$oldAttribution?:'awaiting_owner_review','previous_attributed_employee_id'=>$oldAttributedEmployee,'new_attribution_type'=>$attributionType,'new_attributed_employee_id'=>$responsibleEmployeeId,'reason'=>$attributionNote,'actor_employee_id'=>$currentEmployeeId,'kpi_eligible'=>$attributionType==='employee'&&$accuracyVerified,'business_health_eligible'=>true];ops_activity_log('error_attribution_corrected','error_log',$errorId,$audit);ops_kpi_record_event('error_log','error',$errorId,'attribution_corrected',$oldAttribution?:null,$attributionType,$currentEmployeeId,['reason_note'=>$attributionNote,'metadata'=>$audit]);}
            if(isset($changes['financial_impact'])||isset($changes['has_financial_impact'])){$financialAudit=['has_financial_impact'=>$hasFinancialImpact,'previous_financial_amount'=>(string)($existing['financial_impact']??''),'new_financial_amount'=>$financialImpact,'actor_employee_id'=>$currentEmployeeId,'business_health_eligible'=>true];ops_activity_log('error_financial_impact_changed','error_log',$errorId,$financialAudit);ops_kpi_record_event('error_log','error',$errorId,'financial_impact_changed',(string)($existing['financial_impact']??''),$financialImpact,$currentEmployeeId,['metadata'=>$financialAudit]);}

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
if (empty($_SESSION['error_instruction_csrf_token'])) $_SESSION['error_instruction_csrf_token'] = bin2hex(random_bytes(32));
if (empty($_SESSION['error_instruction_submission_token'])) $_SESSION['error_instruction_submission_token'] = bin2hex(random_bytes(32));
$errorInstructionCsrfToken = (string) $_SESSION['error_instruction_csrf_token'];
$errorInstructionSubmissionToken = (string) $_SESSION['error_instruction_submission_token'];

$filters = [
    'month' => trim((string) ($_GET['month'] ?? date('Y-m'))),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
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
$filtersAreActive = $filters['date_from'] !== '' || $filters['date_to'] !== '' || $filters['severity'] !== '' || $filters['category'] !== '' || $filters['employee_id'] !== '' || $filters['logged_for']!=='' || $filters['financial_impact_filter']!=='' || $filters['repeat_issue'] !== '' || $filters['customer_impacted'] !== '' || $filters['order_reference'] !== '' || $filters['status'] !== '' || $filters['search'] !== '';

$where = ['el.deleted_at IS NULL'];
$params = [];
$requestedErrorId = max(0, (int) ($_GET['error_id'] ?? 0));
if ($requestedErrorId > 0) {
    $where[] = 'el.id = ?';
    $params[] = $requestedErrorId;
} elseif ($filters['date_from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
    $where[] = 'DATE(el.logged_at) >= ?';
    $params[] = $filters['date_from'];
}
if ($filters['date_to'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
    $where[] = 'DATE(el.logged_at) <= ?';
    $params[] = $filters['date_to'];
}
if ($requestedErrorId <= 0 && !$filters['date_from'] && !$filters['date_to'] && preg_match('/^\d{4}-\d{2}$/', $filters['month'])) {
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
            ['icon' => 'calendar-days', 'label' => 'Total Errors This Month', 'value' => number_format($metrics['month_total']), 'colour' => 'var(--bk-orange-red)'],
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
        <section class="error-stats-shell" aria-label="Error log metrics">
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
        <summary class="error-filter-header"><span><i data-lucide="sliders-horizontal"></i> Filters</span><strong><?= $filtersAreActive ? 'Active' : 'Collapsed' ?></strong></summary>
        <form class="error-filter-body" method="get">
            <div class="error-filter-grid">
                <label class="span-2">Search<input type="search" name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search errors, descriptions, categories or orders"></label>
                <label>Month<input type="month" name="month" value="<?= htmlspecialchars($filters['month'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Date from<input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Date to<input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') ?>"></label>
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
                    <th scope="col">Date</th>
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
                        <td><?= error_date_label((string) ($error['logged_at'] ?? '')) ?></td>
                        <td><span class="error-board-title-link"><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></span><?php if (($instructionUnreadByError[(int)$error['id']] ?? 0) > 0): ?><span class="owner-instruction-unread" aria-label="<?= (int)$instructionUnreadByError[(int)$error['id']] ?> unread owner instructions"><?= (int)$instructionUnreadByError[(int)$error['id']] ?> new</span><?php endif; ?></td>
                        <td><?= htmlspecialchars((string) ($error['order_reference'] ?: $error['order_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($errorCategories[(string) $error['category']] ?? (string) $error['category'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="error-board-severity severity-<?= htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($severityLabels[$severity] ?? $severity, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars($peopleText, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(error_attribution_label($error,$employeeMap),ENT_QUOTES,'UTF-8') ?></td>
                        <td><?= $error['has_financial_impact']===null?'Not historically captured':((int)$error['has_financial_impact']===1?'N$'.number_format((float)$error['financial_impact'],2):'No impact') ?></td>
                        <td><?= trim((string) ($error['customer_impact'] ?? '')) !== '' ? 'Yes' : 'No' ?></td>
                        <td><span class="error-board-status status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= (int) ($error['repeat_issue'] ?? 0) === 1 ? 'Yes' : 'No' ?></td>
                        <td><?= htmlspecialchars((string) ($error['logged_by_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?></td>
                    <?php else: ?>
                        <td><?= error_date_label((string) ($error['logged_at'] ?? '')) ?></td>
                        <td><span class="error-board-title-link"><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></span><?php if (($instructionUnreadByError[(int)$error['id']] ?? 0) > 0): ?><span class="owner-instruction-unread" aria-label="<?= (int)$instructionUnreadByError[(int)$error['id']] ?> unread owner instructions"><?= (int)$instructionUnreadByError[(int)$error['id']] ?> new</span><?php endif; ?></td>
                        <td><?= htmlspecialchars((string) ($error['order_reference'] ?: $error['order_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="error-board-severity severity-<?= htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($severityLabels[$severity] ?? $severity, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><span class="error-board-status status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sectionErrors): ?><tr class="error-board-empty"><td colspan="<?= $showFullErrorLog ? 12 : 5 ?>">No <?= strtolower(htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8')) ?> found for the selected filters.</td></tr><?php endif; ?>
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
                        <fieldset class="error-financial-impact-field" id="error-financial-impact-group"><legend>DOES THIS ERROR HAVE A FINANCIAL IMPACT? <span class="error-required-mark" aria-hidden="true">*</span></legend><div class="error-financial-impact-options"><label><input type="radio" name="has_financial_impact" value="0" required><span>No</span></label><label><input type="radio" name="has_financial_impact" value="1"><span>Yes</span></label></div></fieldset>
                        <div class="incident-field error-financial-impact-amount" hidden><label for="financial-impact">FINANCIAL IMPACT AMOUNT (N$) <span class="error-required-mark" aria-hidden="true">*</span></label><div class="error-money-input"><span>N$</span><input id="financial-impact" inputmode="decimal" name="financial_impact_amount" pattern="^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$" disabled></div></div>
                        <div class="incident-field error-financial-impact-notes" hidden><label for="financial-impact-notes">FINANCIAL IMPACT NOTES</label><textarea id="financial-impact-notes" name="financial_impact_notes" placeholder="Briefly explain the cost, replacement, refund, damaged stock or other financial effect."></textarea></div>
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
                            <article class="owner-instruction-item<?= (int)$instruction['id'] === $latestInstructionId ? ' is-latest' : '' ?>">
                                <div class="owner-instruction-item-head"><strong>Owner Instruction<?= (int)$instruction['id'] === $latestInstructionId ? ' · Latest' : '' ?></strong><span><?= htmlspecialchars((string)($instruction['created_by_name'] ?? 'Owner/Admin'), ENT_QUOTES, 'UTF-8') ?></span></div>
                                <p class="owner-instruction-meta"><?= htmlspecialchars(error_instruction_date_label((string)$instruction['created_at']), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="owner-instruction-copy"><?= nl2br(htmlspecialchars((string)$instruction['instruction_text'], ENT_QUOTES, 'UTF-8')) ?></p>
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
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="user-round-check"></i> Customer impact</h3><p class="incident-content-text<?= empty($error['customer_impact']) ? ' incident-empty-text' : '' ?>"><?= nl2br(htmlspecialchars((string) ($error['customer_impact'] ?: 'No customer impact recorded.'), ENT_QUOTES, 'UTF-8')) ?></p></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="badge-check"></i> Logged for</h3><p class="incident-content-text"><?= htmlspecialchars(error_attribution_label($error,$employeeMap),ENT_QUOTES,'UTF-8') ?> · <?= (string)($error['attribution_type']??'')==='employee'&&!empty($error['affects_kpi_accuracy'])&&!empty($error['accuracy_verified_by'])?'Verified for accuracy':'Not counted in personal accuracy' ?></p></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="circle-check"></i> Resolution</h3><p class="incident-content-text<?= empty($error['resolution']) ? ' incident-empty-text' : '' ?>"><?= nl2br(htmlspecialchars((string) ($error['resolution'] ?: 'No resolution recorded yet.'), ENT_QUOTES, 'UTF-8')) ?></p></section>
                <section class="incident-content-card"><h3 class="incident-content-heading"><i data-lucide="banknote"></i> Financial impact</h3><p class="incident-content-text"><?= $error['has_financial_impact']===null?'Not historically captured':((int)$error['has_financial_impact']===1?'N$'.number_format((float)$error['financial_impact'],2):'No impact') ?></p><?php if(!empty($error['financial_impact_notes'])): ?><p class="incident-content-text"><?= nl2br(htmlspecialchars((string)$error['financial_impact_notes'],ENT_QUOTES,'UTF-8')) ?></p><?php endif; ?></section>
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
      const response = await fetch(form.action, {method:'POST', body:new FormData(form), credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Unable to send the instruction.');
      feedback.textContent = 'Instruction sent successfully.';
      feedback.className = 'owner-instruction-feedback is-success';
      feedback.hidden = false;
      window.location.assign(`<?= BASE_URL ?>/apps/operations/errors.php?error_id=${encodeURIComponent(result.error_id)}&instruction=1&instruction_sent=1#owner-instructions-${encodeURIComponent(result.error_id)}`);
    } catch (error) {
      feedback.textContent = `${error.message || 'Unable to send the instruction.'} Retry.`;
      feedback.className = 'owner-instruction-feedback is-error';
      feedback.hidden = false;
      button.disabled = false;
    }
  });
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
  setIncidentRadioValue('severity', mode === 'edit' ? (data.severity || '') : '');
  setIncidentRadioValue('status', mode === 'edit' ? (data.status || 'open') : 'open');
  setIncidentRadioValue('repeat_issue', mode === 'edit' ? Number(data.repeat_issue || 0) : 0);

  if (mode === 'edit') {
    form.elements.error_title.value = data.error_title || '';
    form.elements.order_reference.value = data.order_reference || '';
    form.elements.description.value = data.description || '';
    form.elements.resolution.value = data.resolution || '';
    setFinancialImpactChoice(data.has_financial_impact,data.financial_impact_amount,data.financial_impact_notes);
    setErrorAttribution(data.attribution_type||'',data.attributed_employee_id||'');
    form.elements.packing_task_id.value = data.packing_task_id || '';
    form.elements.affects_kpi_accuracy.checked = Number(data.affects_kpi_accuracy || 0) === 1;
    if (form.elements.accuracy_verified) form.elements.accuracy_verified.checked = Boolean(data.accuracy_verified);
  } else {
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
    if (panel) {
      initIncidentStatusDropdown(panel);
      panel.classList.add('open');
      markOwnerInstructionsRead(panel);
    }
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

document.getElementById('logErrorForm')?.addEventListener('submit', function(event) {
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
  err.className='error-field-error';
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
