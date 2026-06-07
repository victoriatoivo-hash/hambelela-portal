ALTER TABLE ops_orders
  ADD COLUMN woo_order_id BIGINT UNSIGNED NULL AFTER id,
  ADD UNIQUE KEY uniq_ops_woo_order (woo_order_id);

ALTER TABLE ops_order_items
  ADD COLUMN woo_order_line_id BIGINT UNSIGNED NULL AFTER order_id,
  ADD COLUMN woo_product_id BIGINT UNSIGNED NULL AFTER woo_order_line_id,
  ADD COLUMN woo_variation_id BIGINT UNSIGNED NULL AFTER woo_product_id,
  ADD UNIQUE KEY uniq_ops_order_woo_line (order_id, woo_order_line_id);
