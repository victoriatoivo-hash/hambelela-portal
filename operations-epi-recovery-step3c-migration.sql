CREATE TABLE IF NOT EXISTS epi_historical_recovery_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_uuid CHAR(36) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  target_employee_ids VARCHAR(255) NOT NULL,
  source_summary_json LONGTEXT NULL,
  recovered_count INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
  issue_count INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_epi_recovery_run_uuid (run_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_historical_recovery_issues (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_uuid CHAR(36) NOT NULL,
  source_table VARCHAR(100) NOT NULL,
  source_record_id VARCHAR(191) NULL,
  employee_id BIGINT UNSIGNED NULL,
  issue_code VARCHAR(100) NOT NULL,
  issue_description TEXT NOT NULL,
  source_payload_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_epi_recovery_issue (run_uuid,source_table,source_record_id,issue_code),
  KEY idx_epi_recovery_issue_employee (employee_id),
  KEY idx_epi_recovery_issue_run (run_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_historical_source_audits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  source_key VARCHAR(100) NOT NULL,
  legacy_records_found INT UNSIGNED NOT NULL DEFAULT 0,
  recovered_records INT UNSIGNED NOT NULL DEFAULT 0,
  unresolved_records INT UNSIGNED NOT NULL DEFAULT 0,
  source_first_at DATETIME NULL,
  source_last_at DATETIME NULL,
  source_reliability ENUM('high','moderate','low','insufficient') NOT NULL DEFAULT 'insufficient',
  limitation_note TEXT NULL,
  audited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_epi_historical_source_audit (period_start,period_end,employee_id,source_key),
  KEY idx_epi_historical_source_employee (employee_id,period_start,period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
