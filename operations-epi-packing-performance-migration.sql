-- EPI Phase 3: Packing / Packing List settings only.
-- Reuses Phase 1 append-only evidence, activity, ownership, settings and grace tables.

INSERT INTO epi_employee_performance_settings (setting_key, setting_value, value_type, description) VALUES
  ('packing_module_enabled', '1', 'boolean', 'Enable the isolated EPI Packing adapter when the EPI master flag is enabled.'),
  ('packing_courier_cutoff', '14:00', 'string', 'Courier packing cut-off in local business time.'),
  ('packing_priority_start_minutes', '{"urgent":30,"top_critical":30,"high":60,"important":90,"normal":240,"medium":240,"low":480}', 'json', 'Business-minute thresholds for priority start evidence.'),
  ('packing_completion_minutes', '{"urgent":120,"top_critical":120,"high":240,"important":360,"normal":960,"medium":960,"low":1440}', 'json', 'Business-minute thresholds for completion evidence.'),
  ('packing_workload_thresholds', '{"medium_volume":500,"high_volume":1000,"heavy_grams":10000,"very_heavy_grams":25000}', 'json', 'Configurable mass and volume workload thresholds in base units.'),
  ('packing_workload_points', '{"low":1,"medium":2,"high":3,"heavy_bonus":2,"very_heavy_bonus":4,"liquid_multiplier":1,"priority_multiplier":1}', 'json', 'Phase 3 workload point preparation values; no score is calculated.'),
  ('packing_website_confirmation_minutes', '60', 'integer', 'Business-minute threshold from completion to packer website confirmation.')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO epi_employee_grace_periods (grace_key, module, label, minutes, uses_business_time) VALUES
  ('packing_start', 'Packing List', 'Packing Start', 0, 1),
  ('packing_completion', 'Packing List', 'Packing Completion', 0, 1),
  ('packing_courier_cutoff', 'Packing', 'Courier Packing Cut-off', 0, 0),
  ('packing_website_confirmation', 'Packing List', 'Packer Website Update Confirmation', 60, 1)
ON DUPLICATE KEY UPDATE module=VALUES(module), label=VALUES(label);
