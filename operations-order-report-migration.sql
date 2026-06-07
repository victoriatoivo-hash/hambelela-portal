ALTER TABLE ops_orders
  ADD COLUMN product_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount,
  ADD COLUMN tax_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER product_total,
  ADD COLUMN shipping_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER tax_total,
  ADD COLUMN shipping_tax_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER shipping_total,
  ADD COLUMN discount_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER shipping_tax_total,
  ADD COLUMN refund_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_total;

CREATE TABLE IF NOT EXISTS ops_report_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
