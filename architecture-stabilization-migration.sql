CREATE TABLE IF NOT EXISTS formula_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  recipe_id INT,
  version_code VARCHAR(40) NOT NULL,
  status ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
  notes TEXT,
  effective_from DATE,
  locked_at DATETIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES finished_products(id),
  FOREIGN KEY (recipe_id) REFERENCES product_recipes(id),
  UNIQUE KEY uniq_formula_product_version (product_id, version_code)
);

CREATE TABLE IF NOT EXISTS product_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  formula_version_id INT,
  batch_code VARCHAR(100),
  production_date DATE,
  yield_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  yield_unit VARCHAR(40) NOT NULL DEFAULT 'unit',
  status ENUM('draft', 'finalized', 'cancelled') NOT NULL DEFAULT 'draft',
  finalized_at DATETIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES finished_products(id),
  FOREIGN KEY (formula_version_id) REFERENCES formula_versions(id),
  UNIQUE KEY uniq_batch_code (batch_code)
);

CREATE TABLE IF NOT EXISTS final_product_costs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  sku VARCHAR(100),
  formula_version_id INT,
  formula_version VARCHAR(40),
  packaging_version VARCHAR(40),
  raw_ingredient_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
  landed_ingredient_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
  packaging_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
  transport_allocation DECIMAL(12,4) NOT NULL DEFAULT 0,
  labor_allocation DECIMAL(12,4) NOT NULL DEFAULT 0,
  overhead_allocation DECIMAL(12,4) NOT NULL DEFAULT 0,
  vat_allocation DECIMAL(12,4) NOT NULL DEFAULT 0,
  total_cogs DECIMAL(12,4) NOT NULL DEFAULT 0,
  recommended_retail_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  recommended_wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  target_margin_percent DECIMAL(8,3) NOT NULL DEFAULT 0,
  actual_margin_percent DECIMAL(8,3) NOT NULL DEFAULT 0,
  minimum_margin_threshold DECIMAL(8,3) NOT NULL DEFAULT 0,
  pricing_status ENUM('missing_cost', 'needs_review', 'ready', 'underpriced') NOT NULL DEFAULT 'missing_cost',
  profitability_status ENUM('unknown', 'loss', 'low_margin', 'healthy') NOT NULL DEFAULT 'unknown',
  calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME,
  FOREIGN KEY (product_id) REFERENCES finished_products(id),
  FOREIGN KEY (formula_version_id) REFERENCES formula_versions(id),
  UNIQUE KEY uniq_final_product_cost_current (product_id, formula_version_id)
);

CREATE TABLE IF NOT EXISTS product_cost_snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  batch_id INT,
  formula_version_id INT,
  formula_version VARCHAR(40),
  ingredient_costs_json JSON,
  packaging_costs_json JSON,
  transport_allocations_json JSON,
  overhead_allocation DECIMAL(12,4) NOT NULL DEFAULT 0,
  labor_allocation DECIMAL(12,4) NOT NULL DEFAULT 0,
  total_cogs DECIMAL(12,4) NOT NULL DEFAULT 0,
  wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  retail_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  margin_percent DECIMAL(8,3) NOT NULL DEFAULT 0,
  vat_values_json JSON,
  production_date DATE,
  locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES finished_products(id),
  FOREIGN KEY (batch_id) REFERENCES product_batches(id),
  FOREIGN KEY (formula_version_id) REFERENCES formula_versions(id)
);

CREATE TABLE IF NOT EXISTS cost_history_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  component_type ENUM('raw_material', 'packaging', 'transport', 'product', 'manual_override') NOT NULL,
  component_id INT,
  supplier_id INT,
  old_unit_cost DECIMAL(12,4),
  new_unit_cost DECIMAL(12,4),
  old_landed_unit_cost DECIMAL(12,4),
  new_landed_unit_cost DECIMAL(12,4),
  change_reason VARCHAR(255),
  source_type ENUM('supplier_invoice', 'transport_invoice', 'manual_adjustment', 'formula_update', 'system') NOT NULL DEFAULT 'system',
  source_id INT,
  created_by VARCHAR(190),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

UPDATE transport_invoices
SET allocation_basis = 'invoice_value'
WHERE allocation_basis IS NULL OR allocation_basis <> 'invoice_value';

UPDATE transport_allocations
SET allocation_basis = 'invoice_value'
WHERE allocation_basis IS NULL OR allocation_basis <> 'invoice_value';

ALTER TABLE transport_invoices
  MODIFY allocation_basis ENUM('invoice_value') DEFAULT 'invoice_value';

ALTER TABLE transport_allocations
  MODIFY allocation_basis ENUM('invoice_value') DEFAULT 'invoice_value';

CREATE OR REPLACE VIEW ingredient_costs_master AS
SELECT
  rm.id AS component_id,
  rm.invoice_id,
  rm.supplier_id,
  s.name AS supplier_name,
  rm.name AS ingredient_name,
  LOWER(TRIM(rm.name)) AS ingredient_key,
  rm.quantity,
  rm.unit,
  CASE
    WHEN LOWER(TRIM(rm.unit)) IN ('kg', 'kgs', 'kilogram', 'kilograms', 'g', 'gram', 'grams') THEN 'g'
    WHEN LOWER(TRIM(rm.unit)) IN ('l', 'litre', 'litres', 'liter', 'liters', 'ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters') THEN 'ml'
    ELSE 'unit'
  END AS base_unit,
  CASE
    WHEN LOWER(TRIM(rm.unit)) IN ('kg', 'kgs', 'kilogram', 'kilograms') THEN rm.quantity * 1000
    WHEN LOWER(TRIM(rm.unit)) IN ('l', 'litre', 'litres', 'liter', 'liters') THEN rm.quantity * 1000
    ELSE rm.quantity
  END AS base_quantity,
  rm.unit_cost AS raw_unit_cost,
  rm.total_cost AS raw_total_cost,
  COALESCE(SUM(ta.allocated_cost), 0) AS transport_allocated,
  CASE
    WHEN rm.quantity > 0 THEN COALESCE(SUM(ta.allocated_cost), 0) / rm.quantity
    ELSE 0
  END AS transport_unit_cost,
  rm.unit_cost + CASE
    WHEN rm.quantity > 0 THEN COALESCE(SUM(ta.allocated_cost), 0) / rm.quantity
    ELSE 0
  END AS landed_unit_cost,
  CASE
    WHEN rm.quantity > 0 THEN
      (rm.total_cost + COALESCE(SUM(ta.allocated_cost), 0)) /
      NULLIF(CASE
        WHEN LOWER(TRIM(rm.unit)) IN ('kg', 'kgs', 'kilogram', 'kilograms') THEN rm.quantity * 1000
        WHEN LOWER(TRIM(rm.unit)) IN ('l', 'litre', 'litres', 'liter', 'liters') THEN rm.quantity * 1000
        ELSE rm.quantity
      END, 0)
    ELSE 0
  END AS landed_cost_per_base_unit,
  rm.total_cost + COALESCE(SUM(ta.allocated_cost), 0) AS landed_total_cost,
  0 AS vat_unit_cost,
  rm.unit_cost + CASE
    WHEN rm.quantity > 0 THEN COALESCE(SUM(ta.allocated_cost), 0) / rm.quantity
    ELSE 0
  END AS vat_adjusted_landed_unit_cost,
  rm.created_at
FROM raw_materials rm
JOIN suppliers s ON s.id = rm.supplier_id
LEFT JOIN transport_allocations ta ON ta.component_type = 'raw_material' AND ta.component_id = rm.id
GROUP BY rm.id, rm.invoice_id, rm.supplier_id, s.name, rm.name, rm.quantity, rm.unit, rm.unit_cost, rm.total_cost, rm.created_at;

CREATE OR REPLACE VIEW packaging_costs_master AS
SELECT
  p.id AS component_id,
  p.invoice_id,
  p.supplier_id,
  s.name AS supplier_name,
  p.name AS packaging_name,
  LOWER(TRIM(p.name)) AS packaging_key,
  p.quantity,
  p.unit,
  CASE
    WHEN LOWER(TRIM(p.unit)) IN ('kg', 'kgs', 'kilogram', 'kilograms', 'g', 'gram', 'grams') THEN 'g'
    WHEN LOWER(TRIM(p.unit)) IN ('l', 'litre', 'litres', 'liter', 'liters', 'ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters') THEN 'ml'
    ELSE 'unit'
  END AS base_unit,
  CASE
    WHEN LOWER(TRIM(p.unit)) IN ('kg', 'kgs', 'kilogram', 'kilograms') THEN p.quantity * 1000
    WHEN LOWER(TRIM(p.unit)) IN ('l', 'litre', 'litres', 'liter', 'liters') THEN p.quantity * 1000
    ELSE p.quantity
  END AS base_quantity,
  p.unit_cost AS raw_unit_cost,
  p.total_cost AS raw_total_cost,
  COALESCE(SUM(ta.allocated_cost), 0) AS transport_allocated,
  CASE
    WHEN p.quantity > 0 THEN COALESCE(SUM(ta.allocated_cost), 0) / p.quantity
    ELSE 0
  END AS transport_unit_cost,
  p.unit_cost + CASE
    WHEN p.quantity > 0 THEN COALESCE(SUM(ta.allocated_cost), 0) / p.quantity
    ELSE 0
  END AS landed_unit_cost,
  CASE
    WHEN p.quantity > 0 THEN
      (p.total_cost + COALESCE(SUM(ta.allocated_cost), 0)) /
      NULLIF(CASE
        WHEN LOWER(TRIM(p.unit)) IN ('kg', 'kgs', 'kilogram', 'kilograms') THEN p.quantity * 1000
        WHEN LOWER(TRIM(p.unit)) IN ('l', 'litre', 'litres', 'liter', 'liters') THEN p.quantity * 1000
        ELSE p.quantity
      END, 0)
    ELSE 0
  END AS landed_cost_per_base_unit,
  p.total_cost + COALESCE(SUM(ta.allocated_cost), 0) AS landed_total_cost,
  0 AS vat_unit_cost,
  p.unit_cost + CASE
    WHEN p.quantity > 0 THEN COALESCE(SUM(ta.allocated_cost), 0) / p.quantity
    ELSE 0
  END AS vat_adjusted_landed_unit_cost,
  p.created_at
FROM packaging p
JOIN suppliers s ON s.id = p.supplier_id
LEFT JOIN transport_allocations ta ON ta.component_type = 'packaging' AND ta.component_id = p.id
GROUP BY p.id, p.invoice_id, p.supplier_id, s.name, p.name, p.quantity, p.unit, p.unit_cost, p.total_cost, p.created_at;
