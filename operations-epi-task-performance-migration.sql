-- EPI Phase 4: Task Management performance configuration.
-- Reuses the Phase 1 append-only evidence, activity, ownership, settings and grace tables.

INSERT INTO epi_employee_performance_settings (setting_key, setting_value, value_type, description) VALUES
  ('task_module_enabled', '1', 'boolean', 'Enable the isolated EPI Task adapter when the EPI master flag is enabled.'),
  ('task_response_minutes', '{"urgent":30,"important":90,"normal":240}', 'json', 'Business-minute response thresholds by task priority.'),
  ('task_completion_minutes', '{"urgent":120,"important":360,"normal":960}', 'json', 'Business-minute completion thresholds by task priority.'),
  ('task_category_rules', '{}', 'json', 'Configured task category mappings; configured metadata takes precedence over text inference.'),
  ('task_grace_precedence', '["individual_task","template","category","priority","global_default"]', 'json', 'Most-specific-first task grace precedence.'),
  ('task_default_timing_basis', 'business_time', 'string', 'Default task timing basis when no explicit task rule exists.')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO epi_employee_grace_periods (grace_key, module, label, minutes, uses_business_time) VALUES
  ('task_response_default', 'Tasks', 'Task response default', 240, 1),
  ('task_response_urgent', 'Tasks', 'Urgent task response', 30, 1),
  ('task_response_important', 'Tasks', 'Important task response', 90, 1),
  ('task_response_normal', 'Tasks', 'Normal task response', 240, 1),
  ('task_completion_default', 'Tasks', 'Task completion default', 960, 1)
ON DUPLICATE KEY UPDATE module=VALUES(module), label=VALUES(label);
