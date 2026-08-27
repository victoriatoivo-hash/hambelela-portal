<?php
declare(strict_types=1);

require_once BASE_PATH . '/shared/accounts-input-vat.php';

function accounts_assets_require_access(): void { accounts_require_workspace_access(); }

function accounts_assets_schema_ready(): bool
{
    static $ready = false;
    if ($ready) return true;
    accounts_input_vat_schema_ready();
    $queries = [
        "CREATE TABLE IF NOT EXISTS accounts_assets (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, asset_name VARCHAR(190) NOT NULL, category VARCHAR(60) NOT NULL, internal_reference VARCHAR(100) NULL, source_purchase_id BIGINT UNSIGNED NULL, purchase_date DATE NOT NULL, supplier VARCHAR(190) NULL, purchase_price DECIMAL(13,2) NOT NULL DEFAULT 0, vat_amount DECIMAL(13,2) NOT NULL DEFAULT 0, purchase_price_excl_vat DECIMAL(13,2) NOT NULL DEFAULT 0, serial_number VARCHAR(190) NULL, model VARCHAR(190) NULL, brand VARCHAR(190) NULL, location_name VARCHAR(120) NOT NULL, assigned_to VARCHAR(190) NULL, assigned_date DATE NULL, condition_key VARCHAR(40) NOT NULL DEFAULT 'good', asset_status VARCHAR(40) NOT NULL DEFAULT 'active', warranty_start DATE NULL, warranty_expiry DATE NULL, useful_life_months INT NULL, depreciation_method VARCHAR(60) NULL, depreciation_start_date DATE NULL, accumulated_depreciation DECIMAL(13,2) NOT NULL DEFAULT 0, book_value DECIMAL(13,2) NOT NULL DEFAULT 0, notes TEXT NULL, disposal_date DATE NULL, disposal_reason VARCHAR(190) NULL, disposal_proceeds DECIMAL(13,2) NULL, disposal_notes TEXT NULL, created_by INT NOT NULL, created_by_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_by INT NULL, updated_by_name VARCHAR(190) NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, archived_at DATETIME NULL, archived_by INT NULL, KEY idx_asset_status (asset_status,archived_at), KEY idx_asset_category (category), KEY idx_asset_location (location_name), KEY idx_asset_purchase (source_purchase_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_asset_attachments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, asset_id BIGINT UNSIGNED NOT NULL, document_type VARCHAR(40) NOT NULL DEFAULT 'evidence', original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(190) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_size BIGINT UNSIGNED NOT NULL, is_main_photo TINYINT(1) NOT NULL DEFAULT 0, uploaded_by INT NOT NULL, uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, deleted_by INT NULL, UNIQUE KEY uq_asset_file (stored_filename), KEY idx_asset_attachment (asset_id,deleted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_asset_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, asset_id BIGINT UNSIGNED NOT NULL, action_key VARCHAR(50) NOT NULL, before_json LONGTEXT NULL, after_json LONGTEXT NULL, note TEXT NULL, actor_id INT NOT NULL, actor_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_asset_history (asset_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts_asset_maintenance (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, asset_id BIGINT UNSIGNED NOT NULL, maintenance_date DATE NOT NULL, description TEXT NOT NULL, cost DECIMAL(13,2) NOT NULL DEFAULT 0, supplier VARCHAR(190) NULL, notes TEXT NULL, created_by INT NOT NULL, created_by_name VARCHAR(190) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_asset_maintenance (asset_id,maintenance_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    foreach ($queries as $query) db()->exec($query);
    $ready = true;
    return true;
}

function accounts_asset_options(): array
{
    return [
        'categories' => ['Equipment','Furniture','Computer & IT','Vehicle','Machinery','Tools','Leasehold Improvement','Other'],
        'conditions' => ['new'=>'New','excellent'=>'Excellent','good'=>'Good','fair'=>'Fair','poor'=>'Poor','requires_attention'=>'Requires attention'],
        'statuses' => ['active'=>'Active','in_repair'=>'In repair','stored'=>'Stored','disposed'=>'Disposed'],
        'locations' => ['Office','Front Desk','Production','Packing Area','Warehouse','Vehicle','Off-site','Other'],
        'depreciation_methods' => [''=>'Not configured','straight_line'=>'Straight line','reducing_balance'=>'Reducing balance','other'=>'Other']
    ];
}

function accounts_asset_find(int $id): ?array
{
    $stmt=db()->prepare('SELECT * FROM accounts_assets WHERE id=? AND archived_at IS NULL LIMIT 1');
    $stmt->execute([$id]); $row=$stmt->fetch(); return $row ?: null;
}

function accounts_asset_audit(int $id, string $action, ?array $before, ?array $after, string $note=''): void
{
    $user=current_user();
    db()->prepare('INSERT INTO accounts_asset_history(asset_id,action_key,before_json,after_json,note,actor_id,actor_name) VALUES(?,?,?,?,?,?,?)')->execute([$id,$action,$before?json_encode($before,JSON_UNESCAPED_UNICODE):null,$after?json_encode($after,JSON_UNESCAPED_UNICODE):null,$note,(int)($user['id']??0),(string)($user['name']??'Portal user')]);
}

function accounts_asset_payload(array $row): array
{
    $row['id']=(int)$row['id']; $row['source_purchase_id']=$row['source_purchase_id']===null?null:(int)$row['source_purchase_id'];
    foreach (['purchase_price','vat_amount','purchase_price_excl_vat','accumulated_depreciation','book_value','disposal_proceeds'] as $field) $row[$field]=$row[$field]===null?null:(float)$row[$field];
    $stmt=db()->prepare('SELECT id,document_type,original_filename,mime_type,file_size,is_main_photo,uploaded_at FROM accounts_asset_attachments WHERE asset_id=? AND deleted_at IS NULL ORDER BY is_main_photo DESC,id');$stmt->execute([(int)$row['id']]);
    $row['attachments']=array_map(function(array $file): array { $file['id']=(int)$file['id'];$file['file_size']=(int)$file['file_size'];$file['is_main_photo']=(bool)$file['is_main_photo'];$file['view_url']='asset-register-file.php?id='.$file['id'].'&mode=view';$file['download_url']='asset-register-file.php?id='.$file['id'].'&mode=download';return $file; },$stmt->fetchAll());
    return $row;
}
