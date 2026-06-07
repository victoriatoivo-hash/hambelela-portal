-- Reset Hambelela costing test data before re-uploading invoices.
-- This keeps login users, supplier profiles, and transport provider profiles.
-- It clears invoices, extracted raw materials, packaging purchases, transport allocations,
-- product costing records, formula records, snapshots, WooCommerce imported sales, and audit logs.

SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM product_cost_snapshots;
DELETE FROM final_product_costs;
DELETE FROM product_batches;
DELETE FROM formula_versions;
DELETE FROM recipe_items;
DELETE FROM product_packaging_components;
DELETE FROM product_recipes;
DELETE FROM finished_products;

DELETE FROM transport_allocations;
DELETE FROM transport_invoice_lines;
DELETE FROM transport_invoices;
DELETE FROM transport;

DELETE FROM raw_materials;
DELETE FROM packaging;
DELETE FROM supplier_invoices;

DELETE FROM woo_sales;
DELETE FROM cost_history_logs;

ALTER TABLE product_cost_snapshots AUTO_INCREMENT = 1;
ALTER TABLE final_product_costs AUTO_INCREMENT = 1;
ALTER TABLE product_batches AUTO_INCREMENT = 1;
ALTER TABLE formula_versions AUTO_INCREMENT = 1;
ALTER TABLE recipe_items AUTO_INCREMENT = 1;
ALTER TABLE product_packaging_components AUTO_INCREMENT = 1;
ALTER TABLE product_recipes AUTO_INCREMENT = 1;
ALTER TABLE finished_products AUTO_INCREMENT = 1;

ALTER TABLE transport_allocations AUTO_INCREMENT = 1;
ALTER TABLE transport_invoice_lines AUTO_INCREMENT = 1;
ALTER TABLE transport_invoices AUTO_INCREMENT = 1;
ALTER TABLE transport AUTO_INCREMENT = 1;

ALTER TABLE raw_materials AUTO_INCREMENT = 1;
ALTER TABLE packaging AUTO_INCREMENT = 1;
ALTER TABLE supplier_invoices AUTO_INCREMENT = 1;

ALTER TABLE woo_sales AUTO_INCREMENT = 1;
ALTER TABLE cost_history_logs AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;
