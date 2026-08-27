-- Cost Workbook Phase 2, schema version 3. Additive and forward-only.

CREATE TABLE IF NOT EXISTS cw_shipments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_reference VARCHAR(100) NOT NULL,
  shipment_name VARCHAR(190) NOT NULL,
  description TEXT NULL,
  shipment_date DATE NOT NULL,
  arrival_date DATE NULL,
  base_currency CHAR(3) NOT NULL DEFAULT 'NAD',
  transport_method VARCHAR(100) NULL,
  default_allocation_method ENUM('net_value','normalized_quantity','weight','equal','manual') NOT NULL DEFAULT 'net_value',
  notes TEXT NULL,
  status ENUM('draft','ready_for_review','confirmed','archived') NOT NULL DEFAULT 'draft',
  created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL,
  updated_by BIGINT NULL, updated_by_name VARCHAR(190) NULL,
  confirmed_by BIGINT NULL, confirmed_by_name VARCHAR(190) NULL, confirmed_at DATETIME NULL,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cw_shipment_reference (shipment_reference),
  KEY idx_cw_shipment_status (status,shipment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_shipment_invoice_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  supplier_invoice_id BIGINT UNSIGNED NOT NULL,
  invoice_currency CHAR(3) NOT NULL,
  exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1,
  exchange_rate_source VARCHAR(190) NOT NULL,
  exchange_rate_date DATE NOT NULL,
  confirmed_weight_kg DECIMAL(18,6) NULL,
  linked_by BIGINT NULL, linked_by_name VARCHAR(190) NOT NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cw_ship_link_shipment FOREIGN KEY (shipment_id) REFERENCES cw_shipments(id),
  CONSTRAINT fk_cw_ship_link_invoice FOREIGN KEY (supplier_invoice_id) REFERENCES cw_supplier_invoices(id),
  UNIQUE KEY uniq_cw_shipment_invoice (shipment_id,supplier_invoice_id),
  KEY idx_cw_ship_link_invoice (supplier_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_shipment_expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  category ENUM('international_transport','local_transport','courier_freight','customs_duty','import_vat','clearing_agent','handling','bank_fee','currency_conversion','packaging','insurance','inspection_documentation','other') NOT NULL,
  description VARCHAR(255) NOT NULL,
  original_amount DECIMAL(14,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  exchange_rate DECIMAL(18,8) NOT NULL,
  exchange_rate_source VARCHAR(190) NOT NULL,
  exchange_rate_date DATE NOT NULL,
  converted_nad_amount DECIMAL(14,2) NOT NULL,
  vat_treatment ENUM('unconfirmed','inclusive','exclusive','exempt') NOT NULL DEFAULT 'unconfirmed',
  vat_rate DECIMAL(7,4) NULL,
  calculated_vat_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  recoverable_vat DECIMAL(14,2) NOT NULL DEFAULT 0,
  recovery_evidence_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  amount_in_landed_cost DECIMAL(14,2) NOT NULL,
  notes TEXT NULL,
  created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL,
  updated_by BIGINT NULL, updated_by_name VARCHAR(190) NULL,
  removed_by BIGINT NULL, removed_by_name VARCHAR(190) NULL, removed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cw_expense_shipment FOREIGN KEY (shipment_id) REFERENCES cw_shipments(id),
  KEY idx_cw_expense_shipment (shipment_id,removed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_shipment_expense_files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_expense_id BIGINT UNSIGNED NOT NULL,
  original_filename VARCHAR(255) NOT NULL, stored_file VARCHAR(255) NOT NULL, file_type VARCHAR(80) NOT NULL, file_size BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT NULL, uploaded_by_name VARCHAR(190) NOT NULL, uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cw_expense_file FOREIGN KEY (shipment_expense_id) REFERENCES cw_shipment_expenses(id),
  KEY idx_cw_expense_file (shipment_expense_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_landed_calculations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  current_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cw_calc_shipment FOREIGN KEY (shipment_id) REFERENCES cw_shipments(id),
  UNIQUE KEY uniq_cw_calc_shipment (shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_landed_calculation_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  calculation_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
  allocation_method ENUM('net_value','normalized_quantity','weight','equal','manual') NOT NULL,
  target_margin DECIMAL(7,4) NULL, rounding_method ENUM('exact','nearest_050','nearest_1','end_99') NOT NULL DEFAULT 'nearest_1',
  input_snapshot JSON NOT NULL, totals_snapshot JSON NULL,
  status ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
  created_by BIGINT NULL, created_by_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT NULL, updated_by_name VARCHAR(190) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  confirmed_by BIGINT NULL, confirmed_by_name VARCHAR(190) NULL, confirmed_at DATETIME NULL,
  source_version_id BIGINT UNSIGNED NULL,
  CONSTRAINT fk_cw_calc_version FOREIGN KEY (calculation_id) REFERENCES cw_landed_calculations(id),
  UNIQUE KEY uniq_cw_calc_version (calculation_id,version),
  KEY idx_cw_calc_version_status (status,confirmed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_landed_calculation_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  calculation_version_id BIGINT UNSIGNED NOT NULL,
  supplier_invoice_id BIGINT UNSIGNED NOT NULL, supplier_invoice_line_id BIGINT UNSIGNED NOT NULL,
  product_description VARCHAR(255) NOT NULL,
  supplier_quantity DECIMAL(18,6) NOT NULL, supplier_unit VARCHAR(20) NOT NULL,
  normalized_quantity DECIMAL(18,6) NOT NULL, normalized_unit VARCHAR(20) NOT NULL,
  gross_purchase DECIMAL(14,2) NOT NULL, discount_amount DECIMAL(14,2) NOT NULL, net_purchase DECIMAL(14,2) NOT NULL,
  recoverable_vat DECIMAL(14,2) NOT NULL DEFAULT 0, purchase_cost_in_landed DECIMAL(14,2) NOT NULL,
  allocated_transport DECIMAL(14,2) NOT NULL DEFAULT 0, allocated_customs_fees DECIMAL(14,2) NOT NULL DEFAULT 0, allocated_other DECIMAL(14,2) NOT NULL DEFAULT 0,
  rounding_adjustment DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_landed_line_cost DECIMAL(14,2) NOT NULL, landed_cost_per_unit DECIMAL(18,6) NOT NULL,
  allocation_weight_kg DECIMAL(18,6) NULL, manual_percentage DECIMAL(9,4) NULL, manual_amount DECIMAL(14,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cw_calc_line_version FOREIGN KEY (calculation_version_id) REFERENCES cw_landed_calculation_versions(id),
  CONSTRAINT fk_cw_calc_line_invoice FOREIGN KEY (supplier_invoice_id) REFERENCES cw_supplier_invoices(id),
  CONSTRAINT fk_cw_calc_line_source FOREIGN KEY (supplier_invoice_line_id) REFERENCES cw_supplier_invoice_lines(id),
  UNIQUE KEY uniq_cw_calc_source_line (calculation_version_id,supplier_invoice_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_sale_size_costs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  calculation_line_id BIGINT UNSIGNED NOT NULL,
  sale_size DECIMAL(18,6) NOT NULL, sale_unit VARCHAR(20) NOT NULL, wastage_percent DECIMAL(7,4) NOT NULL DEFAULT 0,
  packaging_cost DECIMAL(14,4) NOT NULL DEFAULT 0, label_cost DECIMAL(14,4) NOT NULL DEFAULT 0, preparation_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  usable_quantity DECIMAL(18,6) NOT NULL, theoretical_sale_units DECIMAL(18,6) NOT NULL, remaining_quantity DECIMAL(18,6) NOT NULL,
  product_cost_per_sale_unit DECIMAL(18,6) NOT NULL, complete_cost_per_sale_unit DECIMAL(18,6) NOT NULL,
  target_margin DECIMAL(7,4) NULL, exact_recommended_inc_vat DECIMAL(18,6) NULL, rounded_recommended_inc_vat DECIMAL(14,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cw_sale_size_line FOREIGN KEY (calculation_line_id) REFERENCES cw_landed_calculation_lines(id),
  KEY idx_cw_sale_size_line (calculation_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_calculation_product_matches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_size_cost_id BIGINT UNSIGNED NOT NULL, snapshot_id BIGINT UNSIGNED NOT NULL,
  woo_product_id BIGINT UNSIGNED NULL, woo_variation_id BIGINT UNSIGNED NULL,
  classification ENUM('simple','variation','manual','unmatched') NOT NULL,
  matched_by BIGINT NULL, matched_by_name VARCHAR(190) NOT NULL, matched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cw_calc_match_sale FOREIGN KEY (sale_size_cost_id) REFERENCES cw_sale_size_costs(id),
  KEY idx_cw_calc_match_product (woo_product_id,woo_variation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_cost_audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(50) NOT NULL, entity_id BIGINT UNSIGNED NOT NULL, action_key VARCHAR(80) NOT NULL,
  before_json JSON NULL, after_json JSON NULL, reason VARCHAR(500) NULL,
  actor_id BIGINT NULL, actor_name VARCHAR(190) NOT NULL, occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cw_audit_entity (entity_type,entity_id,occurred_at), KEY idx_cw_audit_action (action_key,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES
('phase2_default_target_margin',NULL,'system'),('phase2_default_rounding','nearest_1','system'),('phase2_default_allocation','net_value','system');
