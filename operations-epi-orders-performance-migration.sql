-- EPI Phase 2: Orders / Front Desk configuration only.
-- Reuses Phase 1 append-only evidence, activity, ownership, settings and grace tables.

INSERT INTO epi_employee_performance_settings (setting_key, setting_value, value_type, description) VALUES
  ('orders_walk_in_identifiers', '["walk-in","walk in","walk_in","walk in customer"]', 'json', 'Configurable identifiers used to detect walk-in orders.'),
  ('orders_front_desk_module_enabled', '1', 'boolean', 'Enable the isolated EPI Orders / Front Desk adapter when the EPI master flag is enabled.'),
  ('orders_excellent_completion_minutes', '30', 'integer', 'Future bonus-candidate threshold; no score is calculated in Phase 2.'),
  ('orders_late_customer_response_minutes', '30', 'integer', 'Customer-response evidence threshold in business minutes.')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO epi_employee_grace_periods (grace_key, module, label, minutes, uses_business_time) VALUES
  ('walk_in_orders', 'Orders', 'Walk-in Orders', 0, 0),
  ('order_completion', 'Orders', 'Order Completion', 0, 1),
  ('payment_confirmation', 'Orders', 'Payment Confirmation', 0, 1),
  ('customer_response', 'Orders', 'Customer Response', 30, 1)
ON DUPLICATE KEY UPDATE module=VALUES(module), label=VALUES(label);
