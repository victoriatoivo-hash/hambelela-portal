<?php

declare(strict_types=1);

require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/auth.php';

function accounts_role_key(): string { return normalise_portal_role(current_role_key()); }
function accounts_is_owner(): bool { return accounts_role_key() === 'owner_admin'; }
function accounts_is_front_desk(): bool { return in_array(accounts_role_key(), ['front_desk_admin', 'front_desk_admin_employee'], true); }
function accounts_can_access_workspace(): bool { return accounts_is_owner(); }
function accounts_can_access_input_vat(): bool { return accounts_is_owner() || accounts_is_front_desk(); }
function accounts_require_workspace_access(): void { require_login(); if (!accounts_can_access_workspace()) { http_response_code(403); exit('You do not have access to the Accounts workspace.'); } }
function accounts_require_input_vat_access(): void { require_login(); if (!accounts_can_access_input_vat()) { http_response_code(403); exit('You do not have access to Input VAT.'); } }

function accounts_input_vat_schema_ready(): bool
{
    static $ready = false;
    if ($ready) return true;
    $queries = [
        "CREATE TABLE IF NOT EXISTS accounts_settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value VARCHAR(255) NOT NULL, updated_by INT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_input_vat_purchases (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, purchase_date DATE NOT NULL, supplier VARCHAR(190) NOT NULL, description TEXT NOT NULL, amount_incl_vat DECIMAL(13,2) NOT NULL, vat_rate DECIMAL(7,4) NOT NULL, vat_amount DECIMAL(13,2) NOT NULL, amount_excl_vat DECIMAL(13,2) NOT NULL, vat_treatment VARCHAR(30) NOT NULL, review_status VARCHAR(30) NOT NULL DEFAULT 'captured', review_note TEXT NULL, created_by INT NOT NULL, created_by_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_by INT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, reviewed_by INT NULL, reviewed_at DATETIME NULL, deleted_at DATETIME NULL, deleted_by INT NULL, KEY idx_input_vat_period (purchase_date, deleted_at), KEY idx_input_vat_supplier (supplier), KEY idx_input_vat_status (review_status, deleted_at), KEY idx_input_vat_creator (created_by, deleted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_input_vat_attachments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, purchase_id BIGINT UNSIGNED NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(190) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_size BIGINT UNSIGNED NOT NULL, uploaded_by INT NOT NULL, uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, deleted_by INT NULL, UNIQUE KEY uq_input_vat_stored (stored_filename), KEY idx_input_vat_attachment (purchase_id, deleted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_input_vat_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, purchase_id BIGINT UNSIGNED NOT NULL, action_key VARCHAR(40) NOT NULL, before_json LONGTEXT NULL, after_json LONGTEXT NULL, actor_id INT NOT NULL, actor_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_input_vat_audit (purchase_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($queries as $query) db()->exec($query);
    db()->exec("INSERT IGNORE INTO accounts_settings (setting_key, setting_value) VALUES ('standard_vat_rate', '15')");
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
function accounts_can_edit_purchase(array $purchase): bool
{
    return accounts_is_owner() || (accounts_is_front_desk() && (int) $purchase['created_by'] === (int) (current_user()['id'] ?? 0) && (string) $purchase['review_status'] !== 'reviewed');
}
function accounts_audit(int $id, string $action, ?array $before, ?array $after): void
{
    $user = current_user(); $stmt = db()->prepare('INSERT INTO accounts_input_vat_audit (purchase_id,action_key,before_json,after_json,actor_id,actor_name) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$id, $action, $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null, $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null, (int) ($user['id'] ?? 0), (string) ($user['name'] ?? 'Portal user')]);
}
function accounts_attachment_payload(array $row): array
{
    return ['id'=>(int)$row['id'],'name'=>(string)$row['original_filename'],'mime_type'=>(string)$row['mime_type'],'size'=>(int)$row['file_size'],'view_url'=>'input-vat-file.php?id='.(int)$row['id'].'&mode=view','download_url'=>'input-vat-file.php?id='.(int)$row['id'].'&mode=download','can_delete'=>accounts_is_owner()];
}
function accounts_purchase_payload(array $row): array
{
    $stmt=db()->prepare('SELECT * FROM accounts_input_vat_attachments WHERE purchase_id=? AND deleted_at IS NULL ORDER BY id');$stmt->execute([(int)$row['id']]);
    return ['id'=>(int)$row['id'],'purchase_date'=>(string)$row['purchase_date'],'supplier'=>(string)$row['supplier'],'description'=>(string)$row['description'],'inclusive'=>(float)$row['amount_incl_vat'],'vat'=>(float)$row['vat_amount'],'exclusive'=>(float)$row['amount_excl_vat'],'vat_rate'=>(float)$row['vat_rate'],'vat_treatment'=>(string)$row['vat_treatment'],'review_status'=>(string)$row['review_status'],'review_note'=>(string)($row['review_note']??''),'entered_by'=>(string)$row['created_by_name'],'created_by'=>(int)$row['created_by'],'updated_at'=>(string)$row['updated_at'],'can_edit'=>accounts_can_edit_purchase($row),'can_review'=>accounts_is_owner(),'can_delete'=>accounts_is_owner(),'attachments'=>array_map('accounts_attachment_payload',$stmt->fetchAll())];
}
