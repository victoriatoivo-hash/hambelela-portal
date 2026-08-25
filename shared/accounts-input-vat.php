<?php

declare(strict_types=1);

require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/accounts-input-vat-calculator.php';

function accounts_role_key(): string { return normalise_portal_role(current_role_key()); }
function accounts_is_owner(): bool { return accounts_role_key() === 'owner_admin'; }
function accounts_is_accountant(): bool { return accounts_role_key() === 'accountant'; }
function accounts_is_front_desk(): bool { return in_array(accounts_role_key(), ['front_desk_admin', 'front_desk_admin_employee'], true); }
function accounts_permissions_for_role(?string $roleKey = null): array
{
    $roleKey = normalise_portal_role($roleKey ?? accounts_role_key());
    $owner = [
        'accounts.access','input_vat.view','input_vat.create','input_vat.edit','input_vat.history','input_vat.delete','input_vat.settings',
        'output_vat.view','output_vat.sync','output_vat.adjust','output_vat.complete','output_vat.export',
        'import_vat.view','import_vat.upload_statement','import_vat.review_statement','import_vat.confirm_import','import_vat.edit','import_vat.export','import_vat.complete',
        'paye.view','paye.upload_statement','paye.export',
        'vat_reconciliation.view','vat_reconciliation.review','vat_reconciliation.adjust','vat_reconciliation.export','vat_reconciliation.file','vat_reconciliation.lock',
        'amendments.view','amendments.create','amendments.reply','amendments.resolve','amendments.attach',
    ];
    if ($roleKey === 'owner_admin') return $owner;
    if ($roleKey === 'accountant') return [
        'accounts.access','input_vat.view','input_vat.create','input_vat.edit','input_vat.history',
        'output_vat.view','output_vat.sync','output_vat.adjust','output_vat.export',
        'import_vat.view','import_vat.upload_statement','import_vat.review_statement','import_vat.confirm_import','import_vat.edit','import_vat.export',
        'paye.view','paye.upload_statement','paye.export',
        'vat_reconciliation.view','vat_reconciliation.review','vat_reconciliation.adjust','vat_reconciliation.export',
        'amendments.view','amendments.create','amendments.reply','amendments.resolve','amendments.attach',
    ];
    if (in_array($roleKey, ['front_desk_admin','front_desk_admin_employee'], true)) return ['input_vat.view','input_vat.create','input_vat.edit'];
    return [];
}
function accounts_can(string $permission): bool { return in_array($permission, accounts_permissions_for_role(), true); }
function accounts_can_access_workspace(): bool { return accounts_can('accounts.access'); }
function accounts_can_access_input_vat(): bool { return accounts_can('input_vat.view'); }
function accounts_require_workspace_access(): void { require_login(); if (!accounts_can_access_workspace()) { http_response_code(403); exit('You do not have access to the Accounts workspace.'); } }
function accounts_require_input_vat_access(): void { require_login(); if (!accounts_can_access_input_vat()) { http_response_code(403); exit('You do not have access to Input VAT.'); } }

function accounts_input_vat_schema_ready(): bool
{
    static $ready = false;
    if ($ready) return true;
    $schemaVersion = '2026-08-19.1';
    db()->exec("CREATE TABLE IF NOT EXISTS accounts_settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value VARCHAR(255) NOT NULL, updated_by INT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $versionStmt = db()->prepare("SELECT setting_value FROM accounts_settings WHERE setting_key='input_vat_schema_version'");
    $versionStmt->execute();
    if ((string) $versionStmt->fetchColumn() === $schemaVersion) { $ready = true; return true; }
    $queries = [
        "CREATE TABLE IF NOT EXISTS accounts_input_vat_purchases (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, purchase_date DATE NOT NULL, supplier VARCHAR(190) NOT NULL, description TEXT NOT NULL, amount_incl_vat DECIMAL(13,2) NOT NULL, vat_rate DECIMAL(7,4) NOT NULL, vat_amount DECIMAL(13,2) NOT NULL, amount_excl_vat DECIMAL(13,2) NOT NULL, vat_treatment VARCHAR(30) NOT NULL, review_status VARCHAR(30) NOT NULL DEFAULT 'captured', review_note TEXT NULL, created_by INT NOT NULL, created_by_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_by INT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, reviewed_by INT NULL, reviewed_at DATETIME NULL, deleted_at DATETIME NULL, deleted_by INT NULL, KEY idx_input_vat_period (purchase_date, deleted_at), KEY idx_input_vat_supplier (supplier), KEY idx_input_vat_status (review_status, deleted_at), KEY idx_input_vat_creator (created_by, deleted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_input_vat_attachments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, purchase_id BIGINT UNSIGNED NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(190) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_size BIGINT UNSIGNED NOT NULL, uploaded_by INT NOT NULL, uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, deleted_by INT NULL, UNIQUE KEY uq_input_vat_stored (stored_filename), KEY idx_input_vat_attachment (purchase_id, deleted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_input_vat_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, purchase_id BIGINT UNSIGNED NOT NULL, action_key VARCHAR(40) NOT NULL, before_json LONGTEXT NULL, after_json LONGTEXT NULL, actor_id INT NOT NULL, actor_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_input_vat_audit (purchase_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_settings_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(80) NOT NULL, old_value VARCHAR(255) NULL, new_value VARCHAR(255) NOT NULL, changed_by INT NOT NULL, changed_by_name VARCHAR(190) NOT NULL, effective_from DATETIME NOT NULL, changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_accounts_settings_audit (setting_key, changed_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_input_vat_month_status (month_key CHAR(7) PRIMARY KEY, capture_complete TINYINT(1) NOT NULL DEFAULT 0, updated_by INT NOT NULL, updated_by_name VARCHAR(190) NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($queries as $query) db()->exec($query);
    db()->exec("INSERT IGNORE INTO accounts_settings (setting_key, setting_value) VALUES ('standard_vat_rate', '15')");
    db()->exec("INSERT IGNORE INTO accounts_settings (setting_key, setting_value) VALUES ('historical_capture_start_date', '2025-03-01')");
    $captureStartFix = db()->prepare("UPDATE accounts_settings SET setting_value='2025-03-01' WHERE setting_key='historical_capture_start_date' AND setting_value='2026-03-01'");
    $captureStartFix->execute();
    if ($captureStartFix->rowCount() > 0) {
        db()->prepare('INSERT INTO accounts_settings_audit(setting_key,old_value,new_value,changed_by,changed_by_name,effective_from)VALUES(?,?,?,?,?,NOW())')->execute([
            'historical_capture_start_date', '2026-03-01', '2025-03-01', 0, 'System (schema correction)',
        ]);
    }
    $columns = [
        'invoice_reference' => "VARCHAR(190) NULL AFTER supplier",
        'notes' => "TEXT NULL AFTER description",
        'calculation_source' => "VARCHAR(20) NOT NULL DEFAULT 'inclusive' AFTER vat_treatment",
        'vat_rate_used' => "DECIMAL(7,4) NULL AFTER vat_rate",
        'automatic_vat_amount' => "DECIMAL(13,2) NULL AFTER vat_amount",
        'automatic_excl_vat' => "DECIMAL(13,2) NULL AFTER amount_excl_vat",
        'standard_rated_incl_vat' => "DECIMAL(13,2) NULL AFTER automatic_excl_vat",
        'standard_rated_excl_vat' => "DECIMAL(13,2) NULL AFTER standard_rated_incl_vat",
        'zero_rated_amount' => "DECIMAL(13,2) NULL AFTER standard_rated_excl_vat",
        'manual_override' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER calculation_source",
        'override_reason' => "VARCHAR(500) NULL AFTER manual_override",
        'override_by' => "INT NULL AFTER override_reason",
        'override_by_name' => "VARCHAR(190) NULL AFTER override_by",
        'override_at' => "DATETIME NULL AFTER override_by_name",
        'historical_back_capture' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER override_at",
    ];
    foreach ($columns as $name => $definition) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute(['accounts_input_vat_purchases', $name]);
        if (!(int) $stmt->fetchColumn()) db()->exec('ALTER TABLE accounts_input_vat_purchases ADD COLUMN '.$name.' '.$definition);
    }
    db()->prepare("INSERT INTO accounts_settings(setting_key,setting_value) VALUES('input_vat_schema_version',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$schemaVersion]);
    $ready = true;
    return true;
}

function accounts_csrf_token(): string
{
    if (empty($_SESSION['accounts_input_vat_csrf'])) $_SESSION['accounts_input_vat_csrf'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['accounts_input_vat_csrf'];
}
function accounts_verify_csrf(string $token): void
{
    if ($token === '' || !hash_equals(accounts_csrf_token(), $token)) throw new RuntimeException('Your session expired. Refresh and try again.');
}
function accounts_standard_vat_rate(): float
{
    accounts_input_vat_schema_ready();
    $stmt = db()->prepare("SELECT setting_value FROM accounts_settings WHERE setting_key='standard_vat_rate'"); $stmt->execute();
    return max(0.0, min(100.0, (float) ($stmt->fetchColumn() ?: 15)));
}
function accounts_historical_capture_start_date(): string
{
    accounts_input_vat_schema_ready();
    $stmt = db()->prepare("SELECT setting_value FROM accounts_settings WHERE setting_key='historical_capture_start_date'"); $stmt->execute();
    $value = (string) ($stmt->fetchColumn() ?: '2025-03-01');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '2025-03-01';
}
function accounts_vat_calculate_from_source(float $amount, string $source, string $treatment, float $zeroRatedAmount = 0.0): array
{
    return accounts_vat_calculate_values($amount, $source, $treatment, accounts_standard_vat_rate(), $zeroRatedAmount);
}
function accounts_vat_calculate(float $inclusive, string $treatment, ?float $manualVat = null): array
{
    $inclusive = round(max(0, $inclusive), 2); $rate = accounts_standard_vat_rate();
    if (in_array($treatment, ['zero_rated', 'no_vat'], true)) { $vat = 0.0; $appliedRate = 0.0; }
    elseif ($treatment === 'manual_vat' || $treatment === 'review_required') { $vat = round(max(0, min($inclusive, (float) $manualVat)), 2); $appliedRate = $inclusive > 0 ? round(($vat / max(0.01, $inclusive - $vat)) * 100, 4) : 0.0; }
    else { $treatment = 'standard'; $vat = round($inclusive * $rate / (100 + $rate), 2); $appliedRate = $rate; }
    return ['inclusive' => $inclusive, 'vat' => $vat, 'exclusive' => round($inclusive - $vat, 2), 'rate' => $appliedRate, 'treatment' => $treatment];
}
function accounts_purchase(int $id, bool $includeDeleted = false): ?array
{
    $sql = 'SELECT * FROM accounts_input_vat_purchases WHERE id=?' . ($includeDeleted ? '' : ' AND deleted_at IS NULL') . ' LIMIT 1';
    $stmt = db()->prepare($sql); $stmt->execute([$id]); $row = $stmt->fetch(); return $row ?: null;
}
function accounts_can_manage_input_vat_entries(): bool
{
    return accounts_can('input_vat.create') || accounts_can('input_vat.edit');
}
function accounts_can_edit_purchase(array $purchase): bool
{
    return accounts_can_manage_input_vat_entries();
}
function accounts_can_delete_purchase(array $purchase): bool
{
    return accounts_can('input_vat.delete');
}
function accounts_audit(int $id, string $action, ?array $before, ?array $after): void
{
    $user = current_user(); $stmt = db()->prepare('INSERT INTO accounts_input_vat_audit (purchase_id,action_key,before_json,after_json,actor_id,actor_name) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$id, $action, $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null, $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null, (int) ($user['id'] ?? 0), (string) ($user['name'] ?? 'Portal user')]);
}
function accounts_attachment_payload(array $row): array
{
    return ['id'=>(int)$row['id'],'name'=>(string)$row['original_filename'],'mime_type'=>(string)$row['mime_type'],'size'=>(int)$row['file_size'],'view_url'=>'input-vat-file.php?id='.(int)$row['id'].'&mode=view','download_url'=>'input-vat-file.php?id='.(int)$row['id'].'&mode=download','can_delete'=>accounts_can_manage_input_vat_entries()];
}
function accounts_purchase_payload(array $row, ?array $attachments = null): array
{
    if ($attachments === null) { $stmt=db()->prepare('SELECT * FROM accounts_input_vat_attachments WHERE purchase_id=? AND deleted_at IS NULL ORDER BY id');$stmt->execute([(int)$row['id']]);$attachments=$stmt->fetchAll(); }
    return ['id'=>(int)$row['id'],'purchase_date'=>(string)$row['purchase_date'],'supplier'=>(string)$row['supplier'],'invoice_reference'=>(string)($row['invoice_reference']??''),'description'=>(string)$row['description'],'notes'=>(string)($row['notes']??''),'inclusive'=>(float)$row['amount_incl_vat'],'vat'=>(float)$row['vat_amount'],'exclusive'=>(float)$row['amount_excl_vat'],'vat_rate'=>(float)($row['vat_rate_used']??$row['vat_rate']),'vat_treatment'=>(string)$row['vat_treatment'],'calculation_source'=>(string)($row['calculation_source']??'inclusive'),'automatic_vat'=>(float)($row['automatic_vat_amount']??$row['vat_amount']),'automatic_exclusive'=>(float)($row['automatic_excl_vat']??$row['amount_excl_vat']),'standard_rated_inclusive'=>(float)($row['standard_rated_incl_vat']??0),'standard_rated_exclusive'=>(float)($row['standard_rated_excl_vat']??0),'zero_rated_amount'=>(float)($row['zero_rated_amount']??0),'manual_override'=>(bool)($row['manual_override']??false),'override_reason'=>(string)($row['override_reason']??''),'historical_back_capture'=>(bool)($row['historical_back_capture']??false),'review_status'=>(string)$row['review_status'],'review_note'=>(string)($row['review_note']??''),'entered_by'=>(string)$row['created_by_name'],'created_by'=>(int)$row['created_by'],'captured_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at'],'can_edit'=>accounts_can_edit_purchase($row),'can_view_audit'=>accounts_can('input_vat.history'),'can_delete'=>accounts_can_delete_purchase($row),'attachments'=>array_map('accounts_attachment_payload',$attachments)];
}
