-- EPI Phase 5: Courier Waybill performance configuration.
-- Reuses the Phase 1 append-only evidence, activity, ownership, settings and grace tables.

INSERT INTO epi_employee_performance_settings (setting_key, setting_value, value_type, description) VALUES
  ('courier_module_enabled', '1', 'boolean', 'Enable the isolated EPI Courier adapter when the EPI master flag is enabled.'),
  ('courier_packer_close_weekday', '17:00', 'time', 'Normal Monday-Friday upload deadline.'),
  ('courier_packer_close_saturday', '13:00', 'time', 'Normal Saturday upload deadline.'),
  ('courier_exception_grace_time', '08:30', 'time', 'Next-business-day grace time for an explicitly approved exception.'),
  ('courier_front_desk_send_deadline', '09:00', 'time', 'Default next-business-day Front Desk send deadline.'),
  ('courier_front_desk_response_minutes', '30', 'integer', 'Response target after a waybill becomes available when upstream is late.'),
  ('courier_very_late_minutes', '180', 'integer', 'Business-minute threshold for very-late classification.'),
  ('courier_walk_in_identifiers', '["walk-in","walk in"]', 'json', 'Configurable identifiers used when classifying courier-linked orders.'),
  ('courier_type_aliases', '{}', 'json', 'Owner-configurable courier type aliases.'),
  ('courier_matching_precedence', '["order_id","exact_order_number","system_reference","owner_manual_match"]', 'json', 'Safe waybill-to-order matching precedence.'),
  ('courier_review_states', '["pending_review","confirmed","dismissed","excused","system_error","employee_error","external_dependency"]', 'json', 'Allowed append-only owner review outcomes.'),
  ('courier_default_timing_basis', 'business_time', 'string', 'Courier duration calculations use the shared Business Time Engine.')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO epi_employee_grace_periods (grace_key, module, label, minutes, uses_business_time) VALUES
  ('courier_front_desk_response', 'Courier', 'Front Desk response after availability', 30, 1),
  ('courier_very_late', 'Courier', 'Very late threshold', 180, 1)
ON DUPLICATE KEY UPDATE module=VALUES(module), label=VALUES(label);
