CREATE TABLE IF NOT EXISTS system_issues (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_key VARCHAR(20) NULL UNIQUE,
  reporter_employee_id INT NOT NULL,
  reported_by_user_id INT NULL,
  workflow_stage VARCHAR(40) NOT NULL DEFAULT 'reported',
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
  INDEX idx_system_issues_reported_by_user (reported_by_user_id, created_at),
  INDEX idx_system_issues_module (location, created_at),
  CONSTRAINT fk_system_issues_duplicate FOREIGN KEY (duplicate_of_id) REFERENCES system_issues(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE system_issues ADD COLUMN IF NOT EXISTS reported_by_user_id INT NULL AFTER reporter_employee_id;
ALTER TABLE system_issues ADD COLUMN IF NOT EXISTS workflow_stage VARCHAR(40) NOT NULL DEFAULT 'awaiting_approval' AFTER reported_by_user_id;
ALTER TABLE system_issues ADD COLUMN IF NOT EXISTS workflow_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER workflow_stage;
ALTER TABLE system_issues ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER deferred_reason;
ALTER TABLE system_issues ADD COLUMN IF NOT EXISTS approved_by INT NULL AFTER approved_at;
ALTER TABLE system_issues ADD COLUMN IF NOT EXISTS done_at DATETIME NULL AFTER verified_by;
ALTER TABLE system_issues ADD INDEX IF NOT EXISTS idx_system_issues_reported_by_user (reported_by_user_id, created_at);
UPDATE system_issues SET reported_by_user_id=reporter_employee_id WHERE reported_by_user_id IS NULL AND reporter_employee_id IS NOT NULL AND reporter_employee_id>0;
UPDATE system_issues SET workflow_stage=CASE internal_status WHEN 'brief_ready' THEN 'awaiting_approval' WHEN 'codex_queued' THEN 'repair_queued' WHEN 'codex_running' THEN 'repair_in_progress' WHEN 'pr_open' THEN 'pr_open' WHEN 'testing' THEN 'testing' WHEN 'deployed' THEN 'deployed' WHEN 'verified' THEN 'verified' WHEN 'done' THEN 'done' WHEN 'deferred' THEN 'deferred' WHEN 'reopened' THEN 'reopened' ELSE workflow_stage END WHERE workflow_stage='awaiting_approval' AND internal_status<>'brief_ready';
UPDATE system_issues SET workflow_stage=CASE workflow_stage WHEN 'awaiting_approval' THEN 'awaiting_owner_approval' WHEN 'owner_approval_required' THEN 'awaiting_owner_approval' WHEN 'approved_for_repair' THEN 'approved' WHEN 'repair_queued' THEN 'codex_queued' WHEN 'repair_in_progress' THEN 'codex_running' WHEN 'pr_open' THEN 'pr_ready' WHEN 'tests_passed' THEN 'ready_to_deploy' WHEN 'deployed' THEN 'verification_pending' WHEN 'verified' THEN IF(verified_at IS NULL,'verification_pending','done') ELSE workflow_stage END;

CREATE TABLE IF NOT EXISTS system_issue_workflow_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(80) NOT NULL,
  command VARCHAR(50) NOT NULL,
  response_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_system_issue_workflow_action (issue_id,idempotency_key),
  CONSTRAINT fk_system_issue_workflow_action_issue FOREIGN KEY (issue_id) REFERENCES system_issues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_issue_workflow_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  payload_json JSON NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  INDEX idx_system_issue_outbox_pending (status,available_at),
  CONSTRAINT fk_system_issue_outbox_issue FOREIGN KEY (issue_id) REFERENCES system_issues(id) ON DELETE CASCADE
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
  tests_confirmed_by_user_id INT NULL,
  test_confirmation_note TEXT NULL,
  deployment_confirmed_by_user_id INT NULL,
  deployment_note TEXT NULL,
  verification_confirmed_by_user_id INT NULL,
  verification_note TEXT NULL,
  rollback_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_system_issue_integrations_issue (issue_id, provider),
  CONSTRAINT fk_system_issue_integrations_issue FOREIGN KEY (issue_id) REFERENCES system_issues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE system_issues i SET workflow_stage='needs_information',workflow_version=workflow_version+1 WHERE i.internal_status='needs_information' AND EXISTS(SELECT 1 FROM system_issue_information_requests r WHERE r.issue_id=i.id AND r.status='pending') AND i.workflow_stage<>'needs_information';
UPDATE system_issues i SET workflow_stage='awaiting_owner_approval',workflow_version=workflow_version+1,internal_status='brief_ready',employee_status='under_review' WHERE i.workflow_stage='needs_information' AND NOT EXISTS(SELECT 1 FROM system_issue_information_requests r WHERE r.issue_id=i.id AND r.status='pending');

ALTER TABLE system_issue_integrations ADD COLUMN IF NOT EXISTS tests_confirmed_by_user_id INT NULL AFTER tests_passed_at;
ALTER TABLE system_issue_integrations ADD COLUMN IF NOT EXISTS test_confirmation_note TEXT NULL AFTER tests_confirmed_by_user_id;
ALTER TABLE system_issue_integrations ADD COLUMN IF NOT EXISTS deployment_confirmed_by_user_id INT NULL AFTER deployed_at;
ALTER TABLE system_issue_integrations ADD COLUMN IF NOT EXISTS deployment_note TEXT NULL AFTER deployment_confirmed_by_user_id;
ALTER TABLE system_issue_integrations ADD COLUMN IF NOT EXISTS verification_confirmed_by_user_id INT NULL AFTER live_verified_at;
ALTER TABLE system_issue_integrations ADD COLUMN IF NOT EXISTS verification_note TEXT NULL AFTER verification_confirmed_by_user_id;
ALTER TABLE system_issue_integrations ADD COLUMN IF NOT EXISTS rollback_active TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_note;
