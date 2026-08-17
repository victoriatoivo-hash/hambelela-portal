<?php
declare(strict_types=1);

function cw_install_schema(PDO $pdo): void
{
    $version = 0;
    try {
        $version = (int) $pdo->query("SELECT setting_value FROM cw_settings WHERE setting_key='schema_version'")->fetchColumn();
    } catch (Throwable $e) {
        $version = 0;
    }
    if ($version < 1) {
        $sql = file_get_contents(BASE_PATH . '/apps/cost-manager/cost-workbook-migration.sql');
        if ($sql === false) throw new RuntimeException('Cost Workbook migration file is unavailable.');
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
            $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
            if ($statement !== '') $pdo->exec($statement);
        }
        $version = 2;
    }
    if ($version < 2) { cw_upgrade_sync_schema_v2($pdo); $version = 2; }
    if ($version < 3) { cw_upgrade_phase2_schema_v3($pdo); $version = 3; }
    if ($version < 4) { cw_upgrade_size_conversion_schema_v4($pdo); $version = 4; }
    if ($version < 5) { cw_upgrade_supplier_invoice_schema_v5($pdo); $version = 5; }
    if ($version < 6) { cw_upgrade_transport_schema_v6($pdo); $version = 6; }
    if ($version < 7) { cw_upgrade_packaging_schema_v7($pdo); $version = 7; }
    if ($version < 8) { cw_upgrade_landed_product_schema_v8($pdo); $version = 8; }
    if ($version < 9) cw_upgrade_formulation_schema_v9($pdo);
}

function cw_upgrade_formulation_schema_v9(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_formulations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        formulation_name VARCHAR(190) NOT NULL,
        formulation_code VARCHAR(40) NOT NULL,
        category VARCHAR(120) NOT NULL DEFAULT '',
        formulation_type ENUM('weight','volume','count','mixed') NOT NULL DEFAULT 'weight',
        status_key ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
        current_version INT UNSIGNED NOT NULL DEFAULT 1,
        effective_date DATE NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by BIGINT NULL, updated_by_name VARCHAR(190) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cw_formulation_code(formulation_code), KEY idx_cw_formulation_list(active,status_key,formulation_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_formulation_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        formulation_id BIGINT UNSIGNED NOT NULL,
        version_no INT UNSIGNED NOT NULL,
        version_status ENUM('draft','approved','superseded') NOT NULL DEFAULT 'draft',
        effective_date DATE NULL,
        mode_key ENUM('quantity','percentage') NOT NULL DEFAULT 'quantity',
        reference_batch_size DECIMAL(18,6) NOT NULL DEFAULT 1,
        reference_batch_unit VARCHAR(20) NOT NULL DEFAULT 'kg',
        expected_yield DECIMAL(18,6) NOT NULL DEFAULT 1,
        yield_unit VARCHAR(20) NOT NULL DEFAULT 'kg',
        loss_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
        vat_rate DECIMAL(7,4) NOT NULL DEFAULT 15,
        formula_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        header_json JSON NOT NULL, ingredients_json JSON NOT NULL, production_json JSON NOT NULL,
        batches_json JSON NOT NULL, selling_sizes_json JSON NOT NULL, totals_json JSON NOT NULL,
        created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cw_formulation_version(formulation_id,version_no), KEY idx_cw_formulation_history(formulation_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_formulation_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, formulation_id BIGINT UNSIGNED NOT NULL,
        action_key VARCHAR(60) NOT NULL, before_json JSON NULL, after_json JSON NULL,
        actor_id BIGINT NULL, actor_name VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cw_formulation_audit(formulation_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['formulation_margin_defaults'=>'35,45,55','formulation_rounding_rule'=>'0.50','formulation_vat_rate'=>'15'] as $key=>$value) {
        $pdo->prepare("INSERT IGNORE INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES(?,?,'system')")->execute([$key,$value]);
    }
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','9','system') ON DUPLICATE KEY UPDATE setting_value='9',updated_by_name='system'")->execute();
}

function cw_upgrade_landed_product_schema_v8(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_cost_products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_name VARCHAR(255) NOT NULL,normalized_name VARCHAR(255) NOT NULL,short_code VARCHAR(24) NOT NULL,woo_product_id BIGINT UNSIGNED NULL,woo_variation_id BIGINT UNSIGNED NULL,woo_sku VARCHAR(100) NOT NULL DEFAULT '',product_category VARCHAR(190) NOT NULL DEFAULT '',costing_basis ENUM('weight','volume','count','ready_made') NULL,base_unit ENUM('kg','L','each','unit') NULL,preferred_supplier_id BIGINT UNSIGNED NULL,notes TEXT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_by BIGINT NULL,updated_by_name VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_cost_product_code(short_code),KEY idx_cw_cost_product_name(normalized_name,active),KEY idx_cw_cost_product_woo(woo_product_id,woo_variation_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_cost_product_sources (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_id BIGINT UNSIGNED NOT NULL,supplier_id BIGINT UNSIGNED NULL,supplier_name VARCHAR(190) NOT NULL DEFAULT '',supplier_invoice_line_id BIGINT UNSIGNED NULL,manual_reference VARCHAR(120) NULL,source_type ENUM('invoice','manual') NOT NULL DEFAULT 'invoice',cost_date DATE NOT NULL,total_purchased_quantity DECIMAL(18,8) NOT NULL,total_supplier_cost DECIMAL(18,8) NOT NULL,currency CHAR(3) NOT NULL DEFAULT 'NAD',vat_treatment VARCHAR(30) NOT NULL DEFAULT 'unconfirmed',active TINYINT(1) NOT NULL DEFAULT 1,notes TEXT NULL,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_cost_product_invoice_line(supplier_invoice_line_id),KEY idx_cw_cost_product_source(product_id,supplier_id,cost_date,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_landed_product_costs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_id BIGINT UNSIGNED NOT NULL,product_source_id BIGINT UNSIGNED NOT NULL,supplier_id BIGINT UNSIGNED NULL,supplier_invoice_line_id BIGINT UNSIGNED NULL,transport_product_allocation_id BIGINT UNSIGNED NULL,costing_basis ENUM('weight','volume','count','ready_made') NOT NULL,base_unit ENUM('kg','L','each','unit') NOT NULL,base_quantity DECIMAL(18,8) NOT NULL,supplier_cost_used DECIMAL(18,8) NOT NULL,transport_cost_used DECIMAL(18,8) NOT NULL DEFAULT 0,supplier_cost_per_base DECIMAL(18,10) NOT NULL,transport_cost_per_base DECIMAL(18,10) NOT NULL DEFAULT 0,landed_cost_per_base DECIMAL(18,10) NOT NULL,currency CHAR(3) NOT NULL DEFAULT 'NAD',cost_date DATE NOT NULL,status_key VARCHAR(50) NOT NULL,calculation_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,calculation_hash CHAR(64) NOT NULL,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_landed_product_hash(calculation_hash),KEY idx_cw_landed_product_current(product_id,supplier_id,cost_date,id),KEY idx_cw_landed_product_source(product_source_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_landed_product_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(30) NOT NULL,entity_id BIGINT UNSIGNED NOT NULL,action_key VARCHAR(60) NOT NULL,before_json JSON NULL,after_json JSON NULL,actor_id BIGINT NULL,actor_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_landed_audit(entity_type,entity_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','8','system') ON DUPLICATE KEY UPDATE setting_value='8',updated_by_name='system'")->execute();
}

function cw_upgrade_packaging_schema_v7(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_types (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_packaging_type(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $seed=$pdo->prepare("INSERT IGNORE INTO cw_packaging_types(name) VALUES(?)");
    foreach(['Bottle','Jar','Pouch','Tube','Cap','Pump','Dropper','Label','Box','Bag','Seal','Other'] as $name)$seed->execute([$name]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_name VARCHAR(190) NOT NULL,packaging_type_id BIGINT UNSIGNED NOT NULL,supplier_name VARCHAR(190) NOT NULL DEFAULT '',supplier_sku VARCHAR(120) NOT NULL DEFAULT '',notes TEXT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_by BIGINT NULL,updated_by_name VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_cw_packaging_item(active,item_name),KEY idx_cw_packaging_item_type(packaging_type_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_item_costs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,packaging_item_id BIGINT UNSIGNED NOT NULL,effective_date DATE NOT NULL,purchase_quantity DECIMAL(18,6) NOT NULL,purchase_unit VARCHAR(30) NOT NULL,cost_basis ENUM('inclusive','exclusive') NOT NULL DEFAULT 'inclusive',amount_entered DECIMAL(18,6) NOT NULL,vat_rate DECIMAL(7,4) NOT NULL DEFAULT 15,vat_treatment ENUM('standard','zero_rated','exempt') NOT NULL DEFAULT 'standard',cost_ex_vat DECIMAL(18,8) NOT NULL,cost_inc_vat DECIMAL(18,8) NOT NULL,unit_cost_ex_vat DECIMAL(18,10) NOT NULL,unit_cost_inc_vat DECIMAL(18,10) NOT NULL,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_packaging_cost(packaging_item_id,effective_date,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_setups (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,setup_name VARCHAR(190) NOT NULL,size_conversion_id BIGINT UNSIGNED NOT NULL,scope_type ENUM('default','category','product') NOT NULL DEFAULT 'default',scope_reference VARCHAR(190) NOT NULL DEFAULT '',scope_label VARCHAR(190) NOT NULL DEFAULT '',effective_date DATE NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_by BIGINT NULL,updated_by_name VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_cw_packaging_setup(active,size_conversion_id,effective_date),KEY idx_cw_packaging_scope(scope_type,scope_reference,size_conversion_id,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_setup_components (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,packaging_setup_id BIGINT UNSIGNED NOT NULL,packaging_item_id BIGINT UNSIGNED NOT NULL,packaging_cost_id BIGINT UNSIGNED NOT NULL,quantity DECIMAL(18,6) NOT NULL,waste_percent DECIMAL(9,4) NOT NULL DEFAULT 0,unit_cost_ex_snapshot DECIMAL(18,10) NOT NULL,unit_cost_inc_snapshot DECIMAL(18,10) NOT NULL,component_cost_ex DECIMAL(18,8) NOT NULL,component_cost_inc DECIMAL(18,8) NOT NULL,display_order INT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_packaging_component(packaging_setup_id),KEY idx_cw_packaging_component_item(packaging_item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_packaging_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type ENUM('item','setup') NOT NULL,entity_id BIGINT UNSIGNED NOT NULL,action_key VARCHAR(60) NOT NULL,before_json JSON NULL,after_json JSON NULL,actor_id BIGINT NULL,actor_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_packaging_audit(entity_type,entity_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','7','system') ON DUPLICATE KEY UPDATE setting_value='7',updated_by_name='system'")->execute();
}

function cw_upgrade_transport_schema_v6(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_transport_records (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entry_source ENUM('uploaded','manual') NOT NULL DEFAULT 'manual',courier_supplier_id BIGINT UNSIGNED NULL,courier_name VARCHAR(190) NOT NULL DEFAULT '',invoice_number VARCHAR(120) NOT NULL DEFAULT '',transport_date DATE NULL,uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,currency CHAR(3) NOT NULL DEFAULT 'NAD',subtotal_ex_vat DECIMAL(14,2) NULL,vat_amount DECIMAL(14,2) NULL,total_inc_vat DECIMAL(14,2) NULL,discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,non_transport_charges DECIMAL(14,2) NOT NULL DEFAULT 0,vat_treatment ENUM('recoverable','non_recoverable','exempt','unconfirmed') NOT NULL DEFAULT 'unconfirmed',allocable_amount DECIMAL(14,2) NOT NULL DEFAULT 0,actual_weight_kg DECIMAL(18,6) NULL,estimated_weight_kg DECIMAL(18,6) NULL,weight_source ENUM('Courier invoice','Waybill','Supplier invoice','Product shipping weights','Manual estimate','Unknown') NOT NULL DEFAULT 'Unknown',waybill_references TEXT NULL,notes TEXT NULL,original_filename VARCHAR(255) NULL,stored_file VARCHAR(500) NULL,file_type VARCHAR(100) NULL,file_hash CHAR(64) NULL,extraction_status ENUM('not_started','extracting','complete','manual_review','failed') NOT NULL DEFAULT 'not_started',extraction_message VARCHAR(500) NULL,allocation_method ENUM('by_weight','courier_lines','manual_amount','percentage','equal_split','mixed') NOT NULL DEFAULT 'by_weight',allocation_status ENUM('unlinked','partial','fully_allocated','missing_weight','archived') NOT NULL DEFAULT 'unlinked',created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_by BIGINT NULL,updated_by_name VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_cw_transport_date(transport_date,allocation_status),KEY idx_cw_transport_courier(courier_name,invoice_number),KEY idx_cw_transport_hash(file_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_transport_shipment_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,transport_id BIGINT UNSIGNED NOT NULL,line_reference VARCHAR(190) NULL,description VARCHAR(500) NULL,supplier_name VARCHAR(190) NULL,waybill_number VARCHAR(120) NULL,purchase_order_reference VARCHAR(120) NULL,line_amount DECIMAL(14,2) NULL,net_weight_kg DECIMAL(18,6) NULL,gross_weight_kg DECIMAL(18,6) NULL,volumetric_weight_kg DECIMAL(18,6) NULL,chargeable_weight_kg DECIMAL(18,6) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_transport_line(transport_id),CONSTRAINT fk_cw_transport_line FOREIGN KEY(transport_id) REFERENCES cw_transport_records(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_transport_allocations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,transport_id BIGINT UNSIGNED NOT NULL,supplier_invoice_id BIGINT UNSIGNED NOT NULL,shipment_line_id BIGINT UNSIGNED NULL,allocation_method VARCHAR(30) NOT NULL,weight_kg_snapshot DECIMAL(18,6) NULL,weight_status ENUM('actual','estimated','missing') NOT NULL DEFAULT 'missing',allocation_percentage DECIMAL(9,6) NULL,calculated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,override_amount DECIMAL(14,2) NULL,override_reason VARCHAR(500) NULL,calculation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_transport_invoice(transport_id,supplier_invoice_id),KEY idx_cw_transport_supplier_invoice(supplier_invoice_id),CONSTRAINT fk_cw_transport_allocation FOREIGN KEY(transport_id) REFERENCES cw_transport_records(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_transport_product_allocations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,transport_allocation_id BIGINT UNSIGNED NOT NULL,supplier_invoice_line_id BIGINT UNSIGNED NOT NULL,quantity_snapshot DECIMAL(18,6) NULL,pack_size_snapshot DECIMAL(18,6) NULL,base_quantity_snapshot DECIMAL(18,6) NULL,base_unit_snapshot VARCHAR(20) NULL,shipping_weight_kg_snapshot DECIMAL(18,6) NULL,calculated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,override_amount DECIMAL(14,2) NULL,override_reason VARCHAR(500) NULL,transport_cost_per_base DECIMAL(18,6) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_transport_product(transport_allocation_id,supplier_invoice_line_id),CONSTRAINT fk_cw_transport_product_allocation FOREIGN KEY(transport_allocation_id) REFERENCES cw_transport_allocations(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_transport_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,transport_id BIGINT UNSIGNED NOT NULL,action_key VARCHAR(50) NOT NULL,before_json JSON NULL,after_json JSON NULL,actor_id BIGINT NULL,actor_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_transport_audit(transport_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','6','system') ON DUPLICATE KEY UPDATE setting_value='6',updated_by_name='system'")->execute();
}

function cw_upgrade_supplier_invoice_schema_v5(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_suppliers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(190) NOT NULL,normalized_name VARCHAR(190) NOT NULL,supplier_type ENUM('Raw Materials','Packaging','Equipment & Accessories','Freight & Logistics','Services','Mixed','Uncategorised') NOT NULL DEFAULT 'Uncategorised',type_confirmed TINYINT(1) NOT NULL DEFAULT 0,active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_supplier_normalized(normalized_name),KEY idx_cw_supplier_type(supplier_type,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $invoiceColumns=[
      'supplier_type'=>"ADD COLUMN supplier_type VARCHAR(60) NOT NULL DEFAULT 'Uncategorised' AFTER supplier_name",
      'invoice_type'=>"ADD COLUMN invoice_type VARCHAR(60) NOT NULL DEFAULT 'Mixed' AFTER supplier_type",
      'file_hash'=>"ADD COLUMN file_hash CHAR(64) NULL AFTER file_type",
      'extraction_confidence'=>"ADD COLUMN extraction_confidence VARCHAR(20) NULL AFTER extraction_status",
      'extraction_message'=>"ADD COLUMN extraction_message VARCHAR(500) NULL AFTER extraction_confidence",
      'discount_amount'=>"ADD COLUMN discount_amount DECIMAL(14,2) NULL AFTER invoice_total",
      'shipping_amount'=>"ADD COLUMN shipping_amount DECIMAL(14,2) NULL AFTER discount_amount",
      'purchase_order_number'=>"ADD COLUMN purchase_order_number VARCHAR(100) NULL AFTER shipping_amount",
      'last_edited_by'=>"ADD COLUMN last_edited_by BIGINT NULL AFTER notes",
      'last_edited_by_name'=>"ADD COLUMN last_edited_by_name VARCHAR(190) NULL AFTER last_edited_by",
    ];
    foreach($invoiceColumns as $column=>$definition)if(!cw_column_exists($pdo,'cw_supplier_invoices',$column))$pdo->exec('ALTER TABLE cw_supplier_invoices '.$definition);
    $lineColumns=[
      'product_category'=>"ADD COLUMN product_category VARCHAR(100) NOT NULL DEFAULT 'Other' AFTER supplier_sku",
      'line_notes'=>"ADD COLUMN line_notes VARCHAR(500) NULL AFTER owner_corrections",
      'extraction_confidence'=>"ADD COLUMN extraction_confidence VARCHAR(20) NULL AFTER line_notes",
    ];
    foreach($lineColumns as $column=>$definition)if(!cw_column_exists($pdo,'cw_supplier_invoice_lines',$column))$pdo->exec('ALTER TABLE cw_supplier_invoice_lines '.$definition);
    try{$pdo->exec('ALTER TABLE cw_supplier_invoices ADD UNIQUE KEY uq_cw_invoice_file_hash(file_hash)');}catch(Throwable $e){}
    $pdo->exec("INSERT IGNORE INTO cw_suppliers(name,normalized_name,supplier_type,type_confirmed) SELECT supplier_name,LOWER(REPLACE(REPLACE(REPLACE(TRIM(supplier_name),'.',''),',',''),' ','')),COALESCE(NULLIF(supplier_type,''),'Uncategorised'),0 FROM cw_supplier_invoices WHERE supplier_name<>''");
    $pdo->exec("UPDATE cw_supplier_invoices i JOIN cw_suppliers s ON s.normalized_name=LOWER(REPLACE(REPLACE(REPLACE(TRIM(i.supplier_name),'.',''),',',''),' ','')) SET i.supplier_id=s.id WHERE i.supplier_id IS NULL");
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','5','system') ON DUPLICATE KEY UPDATE setting_value='5',updated_by_name='system'")->execute();
}

function cw_upgrade_size_conversion_schema_v4(PDO $pdo): void
{
    require_once BASE_PATH.'/shared/cost-workbook-size-conversions.php';
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_size_conversions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,label VARCHAR(60) NOT NULL,measurement_type ENUM('volume','weight') NOT NULL,quantity DECIMAL(18,6) NOT NULL,unit ENUM('ml','L','g','kg') NOT NULL,base_value DECIMAL(18,6) NOT NULL,base_unit ENUM('L','kg') NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT NULL,created_by_name VARCHAR(190) NOT NULL DEFAULT '',updated_by BIGINT NULL,updated_by_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_cw_size_conversion_base (measurement_type,base_value),KEY idx_cw_size_conversion_list (measurement_type,active,base_value)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cw_size_conversion_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversion_id BIGINT UNSIGNED NOT NULL,action_key ENUM('created','updated') NOT NULL,before_json JSON NULL,after_json JSON NOT NULL,actor_id BIGINT NULL,actor_name VARCHAR(190) NOT NULL DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_cw_size_conversion_audit (conversion_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $seed=$pdo->prepare("INSERT IGNORE INTO cw_size_conversions(label,measurement_type,quantity,unit,base_value,base_unit,created_by_name,updated_by_name) VALUES(?,?,?,?,?,?,'system','system')");
    foreach(cw_default_size_conversions() as $row)$seed->execute([$row['label'],$row['measurement_type'],$row['quantity'],$row['unit'],$row['base_value'],$row['base_unit']]);
    $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','4','system') ON DUPLICATE KEY UPDATE setting_value='4',updated_by_name='system'")->execute();
}

function cw_upgrade_phase2_schema_v3(PDO $pdo): void
{
    $lineColumns = [
        'discount_type' => "ADD COLUMN discount_type ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed' AFTER discount",
        'discount_value' => 'ADD COLUMN discount_value DECIMAL(14,4) NULL AFTER discount_type',
        'vat_rate' => 'ADD COLUMN vat_rate DECIMAL(7,4) NULL AFTER discount_value',
        'line_vat_treatment' => "ADD COLUMN line_vat_treatment ENUM('unconfirmed','inclusive','exclusive','exempt') NULL AFTER vat_rate",
        'calculated_vat_amount' => 'ADD COLUMN calculated_vat_amount DECIMAL(14,2) NULL AFTER line_vat_treatment',
        'vat_override_amount' => 'ADD COLUMN vat_override_amount DECIMAL(14,2) NULL AFTER calculated_vat_amount',
        'vat_override_reason' => 'ADD COLUMN vat_override_reason VARCHAR(500) NULL AFTER vat_override_amount',
        'vat_overridden_by' => 'ADD COLUMN vat_overridden_by BIGINT NULL AFTER vat_override_reason',
        'vat_overridden_by_name' => 'ADD COLUMN vat_overridden_by_name VARCHAR(190) NULL AFTER vat_overridden_by',
        'vat_overridden_at' => 'ADD COLUMN vat_overridden_at DATETIME NULL AFTER vat_overridden_by_name',
        'vat_source' => "ADD COLUMN vat_source ENUM('automatic','override','legacy_manual') NOT NULL DEFAULT 'legacy_manual' AFTER vat_overridden_at",
        'purchase_cost_in_landed' => 'ADD COLUMN purchase_cost_in_landed DECIMAL(14,2) NULL AFTER vat_source',
        'base_unit_cost' => 'ADD COLUMN base_unit_cost DECIMAL(18,6) NULL AFTER purchase_cost_in_landed',
        'calculation_version' => 'ADD COLUMN calculation_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER base_unit_cost',
    ];
    foreach ($lineColumns as $column => $definition) {
        if (!cw_column_exists($pdo, 'cw_supplier_invoice_lines', $column)) $pdo->exec('ALTER TABLE cw_supplier_invoice_lines ' . $definition);
    }
    $sql = file_get_contents(BASE_PATH . '/apps/cost-manager/cost-workbook-phase2-migration.sql');
    if ($sql === false) throw new RuntimeException('Cost Workbook Phase 2 migration file is unavailable.');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
        $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
        if ($statement !== '') $pdo->exec($statement);
    }
    $pdo->exec("UPDATE cw_supplier_invoice_lines SET discount_type='fixed',discount_value=COALESCE(discount,0),calculated_vat_amount=COALESCE(vat_amount,0),vat_source='legacy_manual' WHERE discount_value IS NULL");
    $stmt = $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','3','system') ON DUPLICATE KEY UPDATE setting_value='3'");
    $stmt->execute();
}

const CW_SYNC_STALE_SECONDS = 600; // Ten minutes: safely above normal batch time, short enough for abandoned-tab recovery.
const CW_SYNC_LOCK_NAME = 'hambelela_cost_workbook_website_sync';
// WooCommerce v3 returns the complete product payload. Keep reads small and give
// this administrator-triggered snapshot enough time for occasional slow pages.
const CW_SYNC_BATCH_SIZE = 10;
const CW_SYNC_READ_ATTEMPTS = 2;
const CW_SYNC_READ_TIMEOUT = 25;
const CW_SYNC_FIELDS = 'id,name,type,sku,categories,attributes,regular_price,sale_price,price,stock_quantity,stock_status,manage_stock,status,permalink';

function cw_sync_wc_get(string $path, array $query): array
{
    $lastError = null;
    for ($attempt = 1; $attempt <= CW_SYNC_READ_ATTEMPTS; $attempt++) {
        try { return wc_get($path, $query, CW_SYNC_READ_TIMEOUT); }
        catch (Throwable $e) { $lastError = $e; if ($attempt < CW_SYNC_READ_ATTEMPTS) usleep(250000); }
    }
    throw new RuntimeException('Website catalogue read failed after a bounded retry.', 0, $lastError);
}

function cw_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function cw_upgrade_sync_schema_v2(PDO $pdo): void
{
    $columns = [
        'sync_uuid' => "ADD COLUMN sync_uuid CHAR(36) NULL AFTER id",
        'queued_at' => "ADD COLUMN queued_at DATETIME NULL AFTER started_by_name",
        'heartbeat_at' => "ADD COLUMN heartbeat_at DATETIME NULL AFTER started_at",
        'updated_at' => "ADD COLUMN updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER heartbeat_at",
        'failed_at' => "ADD COLUMN failed_at DATETIME NULL AFTER completed_at",
        'recovered_at' => "ADD COLUMN recovered_at DATETIME NULL AFTER failed_at",
        'cancelled_at' => "ADD COLUMN cancelled_at DATETIME NULL AFTER recovered_at",
        'current_batch' => "ADD COLUMN current_batch INT NOT NULL DEFAULT 0 AFTER next_page",
        'current_offset' => "ADD COLUMN current_offset INT NOT NULL DEFAULT 0 AFTER current_batch",
        'expected_total' => "ADD COLUMN expected_total INT NULL AFTER total_products",
        'processed_count' => "ADD COLUMN processed_count INT NOT NULL DEFAULT 0 AFTER expected_total",
        'failure_reason' => "ADD COLUMN failure_reason VARCHAR(500) NULL AFTER error_message",
        'recovery_count' => "ADD COLUMN recovery_count INT NOT NULL DEFAULT 0 AFTER failure_reason",
        'recovery_reason' => "ADD COLUMN recovery_reason VARCHAR(500) NULL AFTER recovery_count",
        'recovered_by' => "ADD COLUMN recovered_by BIGINT NULL AFTER recovery_reason",
        'recovered_by_name' => "ADD COLUMN recovered_by_name VARCHAR(190) NOT NULL DEFAULT '' AFTER recovered_by",
        'is_successful_snapshot' => "ADD COLUMN is_successful_snapshot TINYINT(1) NOT NULL DEFAULT 0 AFTER recovered_by_name",
        'previous_successful_batch_id' => "ADD COLUMN previous_successful_batch_id BIGINT UNSIGNED NULL AFTER is_successful_snapshot",
        'last_batch_started_at' => "ADD COLUMN last_batch_started_at DATETIME NULL AFTER previous_successful_batch_id",
    ];
    foreach ($columns as $column => $sql) {
        if (!cw_column_exists($pdo, 'cw_sync_batches', $column)) $pdo->exec('ALTER TABLE cw_sync_batches ' . $sql);
    }
    $pdo->exec("ALTER TABLE cw_sync_batches MODIFY status ENUM('queued','running','complete','completed','failed','stale','recovered','cancelled') NOT NULL DEFAULT 'queued'");
    $pdo->exec("UPDATE cw_sync_batches SET status='completed' WHERE status='complete'");
    $pdo->exec("UPDATE cw_sync_batches SET sync_uuid=CONCAT('legacy-',id), queued_at=COALESCE(queued_at,started_at), heartbeat_at=COALESCE(heartbeat_at,updated_at,started_at), processed_count=COALESCE(processed_count,success_count), current_batch=GREATEST(current_batch,next_page-1) WHERE sync_uuid IS NULL OR sync_uuid=''");
    $pdo->exec('ALTER TABLE cw_sync_batches MODIFY sync_uuid CHAR(36) NOT NULL');
    $pdo->exec("UPDATE cw_sync_batches SET is_successful_snapshot=0");
    $pdo->exec("UPDATE cw_sync_batches SET is_successful_snapshot=1 WHERE id=(SELECT selected.id FROM (SELECT id FROM cw_sync_batches WHERE status='completed' ORDER BY completed_at DESC,id DESC LIMIT 1) selected)");
    try { $pdo->exec('ALTER TABLE cw_sync_batches ADD UNIQUE KEY uniq_cw_sync_uuid (sync_uuid)'); } catch (Throwable $e) { /* Idempotent upgrade. */ }
    try { $pdo->exec('ALTER TABLE cw_sync_batches ADD KEY idx_cw_sync_status (status,heartbeat_at)'); } catch (Throwable $e) { /* Idempotent upgrade. */ }
    try { $pdo->exec('ALTER TABLE cw_sync_batches ADD KEY idx_cw_sync_successful (is_successful_snapshot,completed_at)'); } catch (Throwable $e) { /* Idempotent upgrade. */ }
    $stmt = $pdo->prepare("INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES('schema_version','2','system') ON DUPLICATE KEY UPDATE setting_value='2'");
    $stmt->execute();
}

function cw_sync_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function cw_sync_timestamp(array $sync): ?string
{
    foreach (['heartbeat_at', 'updated_at', 'started_at'] as $key) if (!empty($sync[$key])) return (string) $sync[$key];
    return null;
}

function cw_sync_is_stale(array $sync, ?int $now = null): bool
{
    if (($sync['status'] ?? '') !== 'running') return false;
    $timestamp = cw_sync_timestamp($sync);
    if ($timestamp === null) return true;
    $parsed = strtotime($timestamp . ' UTC');
    return $parsed === false || (($now ?? time()) - $parsed) > CW_SYNC_STALE_SECONDS;
}

function cw_sync_acquire_lock(PDO $pdo, string $suffix = '', int $timeout = 5): string
{
    $name = CW_SYNC_LOCK_NAME . $suffix;
    $stmt = $pdo->prepare('SELECT GET_LOCK(?,?)');
    $stmt->execute([$name, $timeout]);
    if ((int) $stmt->fetchColumn() !== 1) throw new RuntimeException('Website synchronization is busy. Please try again shortly.');
    return $name;
}

function cw_sync_release_lock(PDO $pdo, string $name): void
{
    try { $stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$name]); } catch (Throwable $e) { /* Connection close also releases advisory locks. */ }
}

function cw_sync_public(array $sync): array
{
    return [
        'id'=>(int)$sync['id'],'sync_uuid'=>(string)($sync['sync_uuid']??''),'status'=>(string)$sync['status'],
        'started_at'=>$sync['started_at']??null,'heartbeat_at'=>cw_sync_timestamp($sync),'completed_at'=>$sync['completed_at']??null,
        'failed_at'=>$sync['failed_at']??null,'recovered_at'=>$sync['recovered_at']??null,'current_batch'=>(int)($sync['current_batch']??0),
        'current_offset'=>(int)($sync['current_offset']??0),'processed_count'=>(int)($sync['processed_count']??0),
        'success_count'=>(int)($sync['success_count']??0),'error_count'=>(int)($sync['error_count']??0),
        'failure_reason'=>$sync['failure_reason']??null,'recovery_reason'=>$sync['recovery_reason']??null,
        'recovery_count'=>(int)($sync['recovery_count']??0),'recovered_by_name'=>$sync['recovered_by_name']??null,'is_stale'=>cw_sync_is_stale($sync),
        'is_successful_snapshot'=>(bool)($sync['is_successful_snapshot']??false),
    ];
}

function cw_csrf_token(): string
{
    if (empty($_SESSION['cw_csrf'])) $_SESSION['cw_csrf'] = bin2hex(random_bytes(24));
    return (string) $_SESSION['cw_csrf'];
}

function cw_require_csrf(): void
{
    $token = (string) ($_SERVER['HTTP_X_CW_CSRF'] ?? $_POST['csrf'] ?? '');
    if (!hash_equals(cw_csrf_token(), $token)) throw new RuntimeException('Your session token expired. Refresh and try again.');
}

function cw_require_admin(): void
{
    if (!user_has_role('owner_admin')) {
        http_response_code(403);
        throw new RuntimeException('Owner/Admin permission is required.');
    }
}

function cw_request_period_bounds(): ?array
{
    if (!isset($_GET['year']) && !isset($_GET['month'])) return null;
    $yearRaw=(string)($_GET['year']??'');$monthRaw=(string)($_GET['month']??'');
    $year=preg_match('/^\d{4}$/',$yearRaw)?(int)$yearRaw:0;$month=preg_match('/^\d{1,2}$/',$monthRaw)?(int)$monthRaw:0;
    if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) throw new DomainException('Invalid Cost Workbook period.');
    $start = sprintf('%04d-%02d-01', $year, $month);
    return [$start, (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d')];
}

function cw_user(): array
{
    $u = current_user();
    return ['id' => isset($u['id']) ? (int) $u['id'] : null, 'name' => (string) ($u['name'] ?? 'Unknown')];
}

function cw_decimal($value): ?string
{
    if ($value === null || $value === '') return null;
    $value = str_replace([',', ' '], '', (string) $value);
    if (!is_numeric($value)) return null;
    return number_format((float) $value, 6, '.', '');
}

function cw_nonnegative_amount_cents($value, string $label): int
{
    $raw = trim((string) $value);
    if ($raw === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $raw)) {
        throw new InvalidArgumentException($label . ' must be a valid non-negative number.');
    }
    return (int) round((float) $raw * 100, 0, PHP_ROUND_HALF_UP);
}

function cw_calculate_invoice_line($quantity, $unitPrice, $discount, $vatAmount, string $vatTreatment): array
{
    $quantityRaw = trim((string) $quantity);
    if ($quantityRaw === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $quantityRaw) || (float) $quantityRaw <= 0) {
        throw new InvalidArgumentException('Quantity must be greater than zero.');
    }
    $unitPriceRaw = trim((string) $unitPrice);
    if ($unitPriceRaw === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $unitPriceRaw)) {
        throw new InvalidArgumentException('Unit cost must be a valid non-negative number.');
    }
    $grossCents = (int) round((float) $quantityRaw * (float) $unitPriceRaw * 100, 0, PHP_ROUND_HALF_UP);
    $discountCents = cw_nonnegative_amount_cents($discount === null || $discount === '' ? '0' : $discount, 'Discount');
    if ($discountCents > $grossCents) {
        throw new InvalidArgumentException('Discount cannot exceed the gross line amount.');
    }
    $vatCents = cw_nonnegative_amount_cents($vatAmount === null || $vatAmount === '' ? '0' : $vatAmount, 'VAT amount');
    $discountedCents = $grossCents - $discountCents;
    if ($vatTreatment === 'inclusive') {
        if ($vatCents > $discountedCents) throw new InvalidArgumentException('VAT amount cannot exceed the discounted VAT-inclusive line amount.');
        $subtotalCents = $discountedCents - $vatCents;
        $totalCents = $discountedCents;
    } elseif ($vatTreatment === 'exempt') {
        if ($vatCents !== 0) throw new InvalidArgumentException('VAT amount must be zero for VAT-exempt invoices.');
        $subtotalCents = $discountedCents;
        $totalCents = $discountedCents;
    } else {
        $subtotalCents = $discountedCents;
        $totalCents = $discountedCents + $vatCents;
    }
    $money = static function (int $cents): string { return number_format($cents / 100, 2, '.', ''); };
    return [
        'gross' => $money($grossCents),
        'discount' => $money($discountCents),
        'line_subtotal' => $money($subtotalCents),
        'vat_amount' => $money($vatCents),
        'line_total' => $money($totalCents),
    ];
}

function cw_normalize_quantity($quantity, string $unit, $packSize = null): array
{
    $q = cw_decimal($quantity);
    if ($q === null || (float) $q <= 0) return [null, null, 'Quantity must be greater than zero.'];
    $u = strtolower(trim($unit));
    $map = ['kg' => ['g', 1000], 'g' => ['g', 1], 'l' => ['ml', 1000], 'ml' => ['ml', 1], 'unit' => ['unit', 1]];
    if (isset($map[$u])) return [number_format((float) $q * $map[$u][1], 6, '.', ''), $map[$u][0], null];
    if ($u === 'pack') {
        $size = cw_decimal($packSize);
        if ($size === null || (float) $size <= 0) return [null, 'pack', 'Pack size must be confirmed before conversion.'];
        return [number_format((float) $q * (float) $size, 6, '.', ''), 'unit', null];
    }
    return [null, null, 'Unit is not recognized.'];
}

function cw_calculate($priceIncVat, $cost, $vatRate, $targetMargin = null): array
{
    $price = cw_decimal($priceIncVat); $unitCost = cw_decimal($cost); $vat = cw_decimal($vatRate); $target = cw_decimal($targetMargin);
    $out = ['selling_ex_vat'=>null,'output_vat'=>null,'gross_profit'=>null,'gross_margin'=>null,'markup'=>null,'recommended_price_inc_vat'=>null,'status'=>'Missing price'];
    $vatBasisPoints = $vat === null ? null : (int) round((float) $vat * 100);
    if ($price === null || $vatBasisPoints === null || 10000 + $vatBasisPoints <= 0) return $out;
    $priceCents = (int) round((float) $price * 100);
    $sellingExVatCents = cw_round_divide($priceCents * 10000, 10000 + $vatBasisPoints);
    $out['selling_ex_vat'] = $sellingExVatCents / 100;
    $out['output_vat'] = ($priceCents - $sellingExVatCents) / 100;
    if ($unitCost === null) { $out['status'] = 'Missing cost'; return $out; }
    $costCents = (int) round((float) $unitCost * 100);
    $profitCents = $sellingExVatCents - $costCents;
    $out['gross_profit'] = $profitCents / 100;
    $out['gross_margin'] = $sellingExVatCents !== 0 ? cw_round_divide($profitCents * 10000, $sellingExVatCents) / 100 : null;
    $out['markup'] = $costCents !== 0 ? cw_round_divide($profitCents * 10000, $costCents) / 100 : null;
    $targetBasisPoints = $target === null ? null : (int) round((float) $target * 100);
    if ($targetBasisPoints !== null && $targetBasisPoints < 10000) {
        $out['recommended_price_inc_vat'] = cw_round_divide($costCents * (10000 + $vatBasisPoints), 10000 - $targetBasisPoints) / 100;
    }
    $out['status'] = $profitCents < 0 ? 'Below cost' : ($targetBasisPoints !== null && $out['gross_margin'] * 100 < $targetBasisPoints ? 'Below target' : ($targetBasisPoints !== null && (int) round($out['gross_margin'] * 100) === $targetBasisPoints ? 'At target' : 'Healthy margin'));
    return $out;
}

function cw_round_divide(int $numerator, int $denominator): int
{
    if ($denominator === 0) throw new InvalidArgumentException('Cannot divide by zero.');
    $sign = (($numerator < 0) !== ($denominator < 0)) ? -1 : 1;
    return $sign * intdiv(abs($numerator) + intdiv(abs($denominator), 2), abs($denominator));
}
