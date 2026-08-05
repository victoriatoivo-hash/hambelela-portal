-- Employee Performance Intelligence (EPI) foundation.
-- New, isolated, append-only structures. Safe to run repeatedly.

CREATE TABLE IF NOT EXISTS epi_employee_departments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  department_key VARCHAR(80) NOT NULL,
  department_name VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_epi_department_key (department_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_employee_performance_settings (
  setting_key VARCHAR(120) NOT NULL,
  setting_value LONGTEXT NULL,
  value_type VARCHAR(24) NOT NULL DEFAULT 'string',
  description VARCHAR(500) NULL,
  updated_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_employee_grace_periods (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  grace_key VARCHAR(120) NOT NULL,
  module VARCHAR(80) NOT NULL,
  label VARCHAR(160) NOT NULL,
  minutes INT UNSIGNED NOT NULL DEFAULT 0,
  uses_business_time TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_epi_grace_key (grace_key),
  KEY idx_epi_grace_module (module, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_employee_business_calendar (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_date DATE NOT NULL,
  calendar_type VARCHAR(32) NOT NULL DEFAULT 'holiday',
  label VARCHAR(190) NULL,
  is_working_day TINYINT(1) NOT NULL DEFAULT 0,
  opens_at TIME NULL,
  closes_at TIME NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_epi_business_date (business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_employee_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  evidence_uuid CHAR(36) NOT NULL,
  deduplication_key CHAR(64) NOT NULL,
  module VARCHAR(80) NOT NULL,
  reference_number VARCHAR(190) NOT NULL,
  employee_id INT NULL,
  employee_name VARCHAR(160) NULL,
  department VARCHAR(160) NULL,
  action VARCHAR(120) NOT NULL,
  action_description TEXT NULL,
  previous_value LONGTEXT NULL,
  new_value LONGTEXT NULL,
  status_before VARCHAR(120) NULL,
  status_after VARCHAR(120) NULL,
  priority VARCHAR(60) NULL,
  occurred_at DATETIME NOT NULL,
  business_date DATE NOT NULL,
  working_minutes DECIMAL(12,2) NULL,
  duration_seconds BIGINT NULL,
  recording_mode VARCHAR(24) NOT NULL DEFAULT 'automatic',
  activity_source VARCHAR(190) NOT NULL,
  financial_impact DECIMAL(15,2) NULL,
  score_impact DECIMAL(15,4) NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  verified_by INT NULL,
  metadata_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_epi_evidence_uuid (evidence_uuid),
  UNIQUE KEY uniq_epi_evidence_dedupe (deduplication_key),
  KEY idx_epi_evidence_employee_date (employee_id, business_date),
  KEY idx_epi_evidence_module_reference (module, reference_number),
  KEY idx_epi_evidence_action_time (action, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_employee_activity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activity_uuid CHAR(36) NOT NULL,
  deduplication_key CHAR(64) NOT NULL,
  employee_id INT NULL,
  employee_name VARCHAR(160) NULL,
  department VARCHAR(160) NULL,
  module VARCHAR(80) NOT NULL,
  reference_number VARCHAR(190) NULL,
  activity_type VARCHAR(120) NOT NULL,
  description TEXT NULL,
  activity_source VARCHAR(190) NOT NULL,
  recording_mode VARCHAR(24) NOT NULL DEFAULT 'automatic',
  occurred_at DATETIME NOT NULL,
  business_date DATE NOT NULL,
  metadata_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_epi_activity_uuid (activity_uuid),
  UNIQUE KEY uniq_epi_activity_dedupe (deduplication_key),
  KEY idx_epi_activity_employee_time (employee_id, occurred_at),
  KEY idx_epi_activity_module_reference (module, reference_number),
  KEY idx_epi_activity_type_time (activity_type, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_employee_ownership_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ownership_uuid CHAR(36) NOT NULL,
  module VARCHAR(80) NOT NULL,
  reference_number VARCHAR(190) NOT NULL,
  original_owner_id INT NULL,
  original_owner_name VARCHAR(160) NULL,
  current_owner_id INT NULL,
  current_owner_name VARCHAR(160) NULL,
  completed_by_id INT NULL,
  completed_by_name VARCHAR(160) NULL,
  verified_by_id INT NULL,
  verified_by_name VARCHAR(160) NULL,
  change_reason TEXT NULL,
  changed_by INT NULL,
  effective_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_epi_ownership_uuid (ownership_uuid),
  KEY idx_epi_ownership_reference (module, reference_number, effective_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_employee_monthly_scores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  score_status VARCHAR(32) NOT NULL DEFAULT 'not_calculated',
  score_value DECIMAL(8,4) NULL,
  framework_version VARCHAR(80) NULL,
  evidence_cutoff_at DATETIME NULL,
  metadata_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_epi_monthly_employee_period (employee_id, period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_performance_cache (
  cache_key CHAR(64) NOT NULL,
  cache_scope VARCHAR(120) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (cache_key),
  KEY idx_epi_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_performance_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  level VARCHAR(20) NOT NULL DEFAULT 'info',
  component VARCHAR(120) NOT NULL,
  message TEXT NOT NULL,
  context_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_epi_logs_component_time (component, created_at),
  KEY idx_epi_logs_level_time (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO epi_employee_performance_settings (setting_key, setting_value, value_type, description) VALUES
  ('epi_enabled', '0', 'boolean', 'Master feature flag for background EPI recording.'),
  ('business_timezone', 'Africa/Windhoek', 'string', 'Timezone used by the Business Time Engine.'),
  ('weekday_open', '08:00', 'time', 'Monday-Friday opening time.'),
  ('weekday_close', '17:00', 'time', 'Monday-Friday closing time.'),
  ('saturday_open', '09:00', 'time', 'Saturday opening time.'),
  ('saturday_close', '13:00', 'time', 'Saturday closing time.'),
  ('sunday_closed', '1', 'boolean', 'Sunday is closed.')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO epi_employee_grace_periods (grace_key, module, label, minutes, uses_business_time) VALUES
  ('walk_in_orders', 'Orders', 'Walk-in Orders', 0, 1),
  ('courier_uploads', 'Courier', 'Courier Uploads', 0, 1),
  ('courier_sending', 'Courier', 'Courier Sending', 0, 1),
  ('website_updates', 'Packing List', 'Website Updates', 0, 1),
  ('tasks', 'Tasks', 'Tasks', 0, 1),
  ('bookkeeping', 'Bookkeeping', 'Bookkeeping', 0, 1)
ON DUPLICATE KEY UPDATE grace_key = VALUES(grace_key);
