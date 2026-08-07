CREATE TABLE IF NOT EXISTS system_issues (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_key VARCHAR(20) NULL UNIQUE,
  reporter_employee_id INT NOT NULL,
  title VARCHAR(190) NOT NULL,
  problem TEXT NOT NULL,
  location VARCHAR(255) NOT NULL,
  attempted_action TEXT NOT NULL,
  observed_behaviour TEXT NOT NULL,
  expected_behaviour TEXT NOT NULL,
  route VARCHAR(500) NULL,
  employee_status VARCHAR(30) NOT NULL DEFAULT 'reported',
  internal_status VARCHAR(30) NOT NULL DEFAULT 'new',
  duplicate_of_id BIGINT UNSIGNED NULL,
  affected_count INT UNSIGNED NOT NULL DEFAULT 1,
  ai_risk_level VARCHAR(20) NULL,
  codex_status VARCHAR(30) NULL,
  deferred_reason TEXT NULL,
  verified_at DATETIME NULL,
  verified_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_system_issues_status (internal_status, updated_at),
  INDEX idx_system_issues_reporter (reporter_employee_id, created_at),
  INDEX idx_system_issues_module (location, created_at),
  CONSTRAINT fk_system_issues_duplicate FOREIGN KEY (duplicate_of_id) REFERENCES system_issues(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_issue_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id BIGINT UNSIGNED NOT NULL,
  actor_employee_id INT NULL,
  event_type VARCHAR(60) NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NULL,
  message TEXT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_system_issue_events_issue (issue_id, created_at),
  CONSTRAINT fk_system_issue_events_issue FOREIGN KEY (issue_id) REFERENCES system_issues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_issue_attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id BIGINT UNSIGNED NOT NULL,
  uploaded_by INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_system_issue_attachments_issue (issue_id),
  CONSTRAINT fk_system_issue_attachments_issue FOREIGN KEY (issue_id) REFERENCES system_issues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE system_issue_attachments ADD COLUMN IF NOT EXISTS information_request_id BIGINT UNSIGNED NULL AFTER issue_id;

CREATE TABLE IF NOT EXISTS system_issue_information_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id BIGINT UNSIGNED NOT NULL,
  request_text TEXT NOT NULL,
  response_text TEXT NULL,
  attachment_allowed TINYINT(1) NOT NULL DEFAULT 0,
  requested_by_user_id INT NOT NULL,
  requested_at DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  answered_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_system_issue_info_active (issue_id,status,requested_at),
  CONSTRAINT fk_system_issue_info_issue FOREIGN KEY (issue_id) REFERENCES system_issues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_issue_ai_briefs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id BIGINT UNSIGNED NOT NULL,
  ai_brief_json JSON NULL,
  ai_risk_level VARCHAR(20) NULL,
  ai_missing_info_requests JSON NULL,
  model VARCHAR(80) NULL,
  response_id VARCHAR(120) NULL,
  error_message TEXT NULL,
  version_number INT UNSIGNED NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  source_report_version INT UNSIGNED NOT NULL DEFAULT 1,
  owner_recommendations_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_system_issue_ai_issue (issue_id, created_at),
  CONSTRAINT fk_system_issue_ai_issue FOREIGN KEY (issue_id) REFERENCES system_issues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE system_issue_ai_briefs ADD COLUMN IF NOT EXISTS version_number INT UNSIGNED NULL AFTER error_message;
ALTER TABLE system_issue_ai_briefs ADD COLUMN IF NOT EXISTS is_current TINYINT(1) NOT NULL DEFAULT 0 AFTER version_number;
ALTER TABLE system_issue_ai_briefs ADD COLUMN IF NOT EXISTS source_report_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER is_current;
ALTER TABLE system_issue_ai_briefs ADD COLUMN IF NOT EXISTS owner_recommendations_json JSON NULL AFTER source_report_version;
UPDATE system_issue_ai_briefs SET version_number=1 WHERE ai_brief_json IS NOT NULL AND version_number IS NULL;
UPDATE system_issue_ai_briefs SET is_current=0 WHERE ai_brief_json IS NOT NULL;
UPDATE system_issue_ai_briefs b JOIN (SELECT issue_id,MAX(id) id FROM system_issue_ai_briefs WHERE ai_brief_json IS NOT NULL GROUP BY issue_id) latest ON latest.id=b.id SET b.is_current=1;

CREATE TABLE IF NOT EXISTS system_issue_owner_recommendations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id BIGINT UNSIGNED NOT NULL,
  recommendation_text TEXT NOT NULL,
  created_by INT NOT NULL,
  related_brief_version INT UNSIGNED NULL,
  supersedes_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  superseded_at DATETIME NULL,
  INDEX idx_system_issue_owner_recommendations (issue_id, created_at),
  CONSTRAINT fk_system_issue_owner_recommendation_issue FOREIGN KEY (issue_id) REFERENCES system_issues(id) ON DELETE CASCADE,
  CONSTRAINT fk_system_issue_owner_recommendation_previous FOREIGN KEY (supersedes_id) REFERENCES system_issue_owner_recommendations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_issue_integrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(30) NOT NULL,
  external_id VARCHAR(120) NULL,
  external_url VARCHAR(500) NULL,
  branch_name VARCHAR(255) NULL,
  pull_request_number INT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'queued',
  requires_approval TINYINT(1) NOT NULL DEFAULT 1,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  payload_json JSON NULL,
  last_error TEXT NULL,
  tests_passed_at DATETIME NULL,
  merged_at DATETIME NULL,
  deployed_at DATETIME NULL,
  live_verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_system_issue_integrations_issue (issue_id, provider),
  CONSTRAINT fk_system_issue_integrations_issue FOREIGN KEY (issue_id) REFERENCES system_issues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
