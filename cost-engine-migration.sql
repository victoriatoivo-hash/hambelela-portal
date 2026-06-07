ALTER TABLE supplier_invoices
  ADD COLUMN IF NOT EXISTS subtotal DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER pdf_path,
  ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal,
  ADD COLUMN IF NOT EXISTS total_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER vat_amount;

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
