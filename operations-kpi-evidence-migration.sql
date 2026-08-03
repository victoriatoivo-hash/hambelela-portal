-- Append-only KPI evidence. Safe to run repeatedly.
CREATE TABLE IF NOT EXISTS kpi_activity_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  portal_section VARCHAR(80) NOT NULL,
  record_type VARCHAR(80) NOT NULL,
  record_id BIGINT NOT NULL,
  employee_id INT NULL,
  employee_name VARCHAR(160) NULL,
  action_key VARCHAR(120) NOT NULL,
  previous_status VARCHAR(120) NULL,
  new_status VARCHAR(120) NULL,
  occurred_at DATETIME NOT NULL,
  due_at DATETIME NULL,
  assigned_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  related_reference VARCHAR(190) NULL,
  source_page VARCHAR(190) NOT NULL,
  reason_note TEXT NULL,
  metadata_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_kpi_activity_record (portal_section, record_type, record_id, occurred_at),
  KEY idx_kpi_activity_employee (employee_id, occurred_at),
  KEY idx_kpi_activity_action (action_key, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO kpi_settings (setting_key, setting_value) VALUES
  ('packing_list_adoption_date', '2026-07-01'),
  ('orders_attribution_adoption_date', '2026-07-16'),
  ('tasks_adoption_date', '2026-07-14'),
  ('website_timing_adoption_date', '2026-07-15'),
  ('waybills_adoption_date', '2026-07-14'),
  ('bookkeeping_adoption_date', '2026-07-14'),
  ('error_log_adoption_date', '2026-07-14'),
  ('portal_activity_adoption_date', '2026-07-14'),
  ('attendance_adoption_date', '2026-07-14'),
  ('packing_timing_adoption_date', '2026-07-14'),
  ('activity_event_adoption_date', '2026-08-03')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
