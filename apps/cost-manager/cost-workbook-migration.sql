-- Hambelela Cost Workbook Phase 1, schema version 1.
-- Additive only: legacy procurement tables and uploaded files are intentionally untouched.

CREATE TABLE IF NOT EXISTS cw_supplier_invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT NULL,
  supplier_name VARCHAR(190) NOT NULL DEFAULT '',
  invoice_number VARCHAR(100) NULL,
  invoice_date DATE NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  uploaded_by BIGINT NULL,
  uploaded_by_name VARCHAR(190) NOT NULL DEFAULT '',
  original_filename VARCHAR(255) NOT NULL,
  stored_file VARCHAR(255) NOT NULL,
  file_type VARCHAR(80) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'NAD',
  exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1,
  vat_treatment ENUM('unconfirmed','inclusive','exclusive','exempt','mixed') NOT NULL DEFAULT 'unconfirmed',
  subtotal DECIMAL(14,2) NULL,
  vat_amount DECIMAL(14,2) NULL,
  invoice_total DECIMAL(14,2) NULL,
  extraction_status ENUM('not_started','manual_review','complete','failed') NOT NULL DEFAULT 'not_started',
  review_status ENUM('needs_review','reviewed') NOT NULL DEFAULT 'needs_review',
  approval_status ENUM('draft','approved','archived') NOT NULL DEFAULT 'draft',
  approved_by BIGINT NULL,
  approved_by_name VARCHAR(190) NULL,
  approved_at DATETIME NULL,
  notes TEXT NULL,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cw_invoice_status (approval_status, review_status),
  KEY idx_cw_invoice_date (invoice_date),
  KEY idx_cw_invoice_duplicate (supplier_name, invoice_number, invoice_date, invoice_total)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_supplier_invoice_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_invoice_id BIGINT UNSIGNED NOT NULL,
  raw_description VARCHAR(500) NOT NULL DEFAULT '',
  product_description VARCHAR(255) NOT NULL DEFAULT '',
  supplier_sku VARCHAR(100) NULL,
  quantity DECIMAL(18,6) NULL,
  purchase_unit VARCHAR(20) NULL,
  pack_size DECIMAL(18,6) NULL,
  base_quantity DECIMAL(18,6) NULL,
  base_unit ENUM('kg','g','L','ml','unit','pack') NULL,
  unit_price DECIMAL(18,6) NULL,
  line_subtotal DECIMAL(14,2) NULL,
  vat_amount DECIMAL(14,2) NULL,
  line_total DECIMAL(14,2) NULL,
  discount DECIMAL(14,2) NULL,
  woo_product_id BIGINT UNSIGNED NULL,
  woo_variation_id BIGINT UNSIGNED NULL,
  item_type ENUM('unclassified','supplier_raw_material','website_product','website_variation','packaging','additional_cost') NOT NULL DEFAULT 'unclassified',
  match_status ENUM('unmatched','suggested','confirmed','not_applicable') NOT NULL DEFAULT 'unmatched',
  review_status ENUM('needs_review','valid','invalid') NOT NULL DEFAULT 'needs_review',
  owner_corrections JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cw_line_invoice FOREIGN KEY (supplier_invoice_id) REFERENCES cw_supplier_invoices(id),
  KEY idx_cw_line_invoice (supplier_invoice_id),
  KEY idx_cw_line_match (woo_product_id, woo_variation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_sync_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  status ENUM('running','complete','failed') NOT NULL DEFAULT 'running',
  started_by BIGINT NULL,
  started_by_name VARCHAR(190) NOT NULL DEFAULT '',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  next_page INT NOT NULL DEFAULT 1,
  total_products INT NOT NULL DEFAULT 0,
  success_count INT NOT NULL DEFAULT 0,
  error_count INT NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  KEY idx_cw_sync_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_product_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  parent_product_id BIGINT UNSIGNED NULL,
  product_name VARCHAR(255) NOT NULL,
  variation_name VARCHAR(255) NOT NULL DEFAULT '',
  sku VARCHAR(100) NULL,
  category VARCHAR(255) NULL,
  product_type VARCHAR(40) NOT NULL,
  attributes JSON NULL,
  regular_price_inc_vat DECIMAL(14,2) NULL,
  sale_price_inc_vat DECIMAL(14,2) NULL,
  active_price_inc_vat DECIMAL(14,2) NULL,
  stock_quantity DECIMAL(18,3) NULL,
  stock_status VARCHAR(40) NULL,
  manage_stock TINYINT(1) NOT NULL DEFAULT 0,
  website_status VARCHAR(40) NULL,
  permalink VARCHAR(500) NULL,
  sync_batch_id BIGINT UNSIGNED NOT NULL,
  synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cw_snapshot_batch FOREIGN KEY (sync_batch_id) REFERENCES cw_sync_batches(id),
  UNIQUE KEY uniq_cw_snapshot_batch_product (sync_batch_id, product_id, variation_id),
  KEY idx_cw_snapshot_sku (sku),
  KEY idx_cw_snapshot_product (product_id, variation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_product_matches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_invoice_line_id BIGINT UNSIGNED NOT NULL,
  woo_product_id BIGINT UNSIGNED NULL,
  woo_variation_id BIGINT UNSIGNED NULL,
  match_method ENUM('previous','variation_id','sku','name','manual','classification') NOT NULL DEFAULT 'manual',
  match_confidence DECIMAL(5,2) NULL,
  confirmed_by BIGINT NULL,
  confirmed_by_name VARCHAR(190) NOT NULL DEFAULT '',
  confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cw_match_line FOREIGN KEY (supplier_invoice_line_id) REFERENCES cw_supplier_invoice_lines(id),
  KEY idx_cw_match_line (supplier_invoice_line_id),
  KEY idx_cw_match_product (woo_product_id, woo_variation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cw_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(255) NULL,
  updated_by BIGINT NULL,
  updated_by_name VARCHAR(190) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO cw_settings (setting_key, setting_value) VALUES
('base_currency','NAD'),('vat_rate','15'),('retail_target_margin',NULL),
('wholesale_target_margin',NULL),('reseller_target_margin',NULL),
('low_margin_threshold',NULL),('default_allocation_method','invoice_value'),
('last_website_sync',NULL),('schema_version','1');
