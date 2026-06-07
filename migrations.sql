ALTER TABLE packaging
  ADD COLUMN unit VARCHAR(40) NOT NULL DEFAULT 'unit' AFTER quantity;

ALTER TABLE recipe_items
  ADD COLUMN component_id INT NULL AFTER component_type;

ALTER TABLE transport_allocations
  ADD COLUMN component_type ENUM('raw_material', 'packaging') NOT NULL DEFAULT 'raw_material' AFTER transport_invoice_id,
  ADD COLUMN component_id INT NULL AFTER component_type;

CREATE TABLE IF NOT EXISTS transport_invoice_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transport_invoice_id INT NOT NULL,
  supplier_id INT,
  supplier_name VARCHAR(190),
  waybill_number VARCHAR(100),
  consignment_number VARCHAR(100),
  description VARCHAR(255),
  route VARCHAR(190),
  pieces DECIMAL(12,3),
  actual_weight_kg DECIMAL(12,3),
  chargeable_weight_kg DECIMAL(12,3),
  line_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (transport_invoice_id) REFERENCES transport_invoices(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
);

ALTER TABLE transport_allocations
  ADD COLUMN transport_invoice_line_id INT NULL AFTER transport_invoice_id;

ALTER TABLE woo_sales
  ADD COLUMN woo_order_line_id BIGINT UNSIGNED NULL AFTER woo_order_id,
  ADD COLUMN woo_variation_id BIGINT UNSIGNED NULL AFTER woo_product_id,
  ADD COLUMN product_name VARCHAR(190) NULL AFTER woo_variation_id,
  ADD COLUMN line_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit_price,
  ADD COLUMN tax_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER line_total;

ALTER TABLE woo_sales
  ADD UNIQUE KEY uniq_woo_order_line (woo_order_id, woo_order_line_id);

ALTER TABLE finished_products
  ADD COLUMN costing_type ENUM('recipe', 'raw_resale') NOT NULL DEFAULT 'recipe' AFTER woo_product_id,
  ADD COLUMN linked_component_type ENUM('raw_material', 'packaging') NULL AFTER costing_type,
  ADD COLUMN linked_component_id INT NULL AFTER linked_component_type,
  ADD COLUMN sales_unit_quantity DECIMAL(12,3) NOT NULL DEFAULT 1 AFTER linked_component_id,
  ADD COLUMN sales_unit VARCHAR(40) NOT NULL DEFAULT 'unit' AFTER sales_unit_quantity;

CREATE TABLE IF NOT EXISTS product_packaging_components (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  packaging_id INT NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit VARCHAR(40) NOT NULL DEFAULT 'unit',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES finished_products(id),
  FOREIGN KEY (packaging_id) REFERENCES packaging(id)
);

ALTER TABLE supplier_invoices
  ADD COLUMN subtotal DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER pdf_path,
  ADD COLUMN vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal,
  ADD COLUMN total_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER vat_amount;

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
