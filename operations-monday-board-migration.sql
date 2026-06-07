ALTER TABLE ops_orders
  ADD COLUMN total_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER payment_method;
