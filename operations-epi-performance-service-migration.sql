-- Additive only. Execute explicitly during a separately approved migration.
-- No updates to legacy scores, events, settings, activation or locked periods.
INSERT IGNORE INTO epi_v2_event_registry
(event_key,module,description,responsibility_type,polarity,score_eligible,owner_review_required,severity_handling,sla_obligation_key,category_key,applicable_roles_json)
VALUES ('customer_response_sla_breached','Orders','Required customer response missed its configured deadline.',
'owner_at_due','negative',1,0,'capped','customer_response','customer_communication','["front_desk_admin","marketing_sales_assistant"]');
INSERT IGNORE INTO epi_v2_event_registry
(event_key,module,description,responsibility_type,polarity,score_eligible,owner_review_required,severity_handling,sla_obligation_key,category_key,applicable_roles_json)
VALUES
('start_packing_sla_breached','Packing List','Assigned packing was not started by its configured deadline.','owner_at_due','negative',1,0,'capped','start_packing','packing','["packer"]'),
('complete_packing_sla_breached','Packing List','Assigned packing was not completed by its configured deadline.','owner_at_due','negative',1,0,'capped','complete_packing','packing','["packer"]');
INSERT IGNORE INTO epi_v2_event_registry
(event_key,module,description,responsibility_type,polarity,score_eligible,owner_review_required,severity_handling,sla_obligation_key,category_key,applicable_roles_json)
VALUES
('cash_opening_sla_breached','Bookkeeping','Required opening balance was not recorded by its configured deadline.','owner_at_due','negative',1,0,'capped','record_opening_balance','bookkeeping','["front_desk_admin","marketing_sales_assistant"]'),
('cash_closing_sla_breached','Bookkeeping','Required closing reconciliation was not recorded by its configured deadline.','owner_at_due','negative',1,0,'capped','complete_cash_reconciliation','bookkeeping','["front_desk_admin","marketing_sales_assistant"]');
CREATE TABLE IF NOT EXISTS epi_v2_scorecard_documents (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    role_key VARCHAR(80) NOT NULL,
    policy_json LONGTEXT NOT NULL,
    policy_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Explicit scorecard assignment. No default role fallback or inferred marketing role.
CREATE TABLE IF NOT EXISTS epi_v2_scorecard_assignments (
    employee_id INT NOT NULL,
    period_start DATE NOT NULL,
    scorecard_version VARCHAR(100) NOT NULL,
    assigned_by INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    validation_approved_at DATETIME NULL,
    validation_approved_by INT NULL,
    official_from DATE NULL,
    PRIMARY KEY (employee_id, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Immutable calculation revisions, separated from the current publication pointer.
CREATE TABLE IF NOT EXISTS epi_v2_employee_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    period_start DATE NOT NULL,
    revision INT NOT NULL,
    scorecard_version VARCHAR(100) NOT NULL,
    input_hash CHAR(64) NOT NULL,
    result_json LONGTEXT NOT NULL,
    calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY result_revision (employee_id, period_start, revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_v2_employee_result_heads (
    employee_id INT NOT NULL,
    period_start DATE NOT NULL,
    current_result_id BIGINT UNSIGNED NULL,
    proposed_result_id BIGINT UNSIGNED NULL,
    locked_at DATETIME NULL,
    locked_by INT NULL,
    PRIMARY KEY (employee_id, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Durable invalidation queue. Generation counters prevent a concurrent change being lost.
CREATE TABLE IF NOT EXISTS epi_v2_performance_refresh_queue (
    employee_id INT NOT NULL,
    period_start DATE NOT NULL,
    requested_generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    processed_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reason VARCHAR(160) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    PRIMARY KEY (employee_id, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_v2_incident_correlations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_key VARCHAR(190) NOT NULL,
    root_incident_id VARCHAR(190) NOT NULL,
    opportunity_key VARCHAR(190) NOT NULL,
    category_key VARCHAR(80) NOT NULL,
    metric_key VARCHAR(80) NOT NULL,
    employee_id INT NOT NULL,
    reviewed_by INT NOT NULL,
    reviewed_at DATETIME NOT NULL,
    reason TEXT NOT NULL,
    superseded_at DATETIME NULL,
    active_source VARCHAR(190) GENERATED ALWAYS AS (CASE WHEN superseded_at IS NULL THEN source_key ELSE NULL END) STORED,
    UNIQUE KEY one_active_source (active_source),
    KEY root_lookup (root_incident_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_v2_completed_work_units (
    opportunity_key VARCHAR(190) NOT NULL PRIMARY KEY,
    module VARCHAR(80) NOT NULL,
    object_reference VARCHAR(190) NOT NULL,
    employee_id INT NULL,
    fulfiller_id INT NULL,
    starts_at DATETIME NOT NULL,
    completed_at DATETIME NOT NULL,
    ownership_json LONGTEXT NULL,
    source_snapshot_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY employee_period (employee_id,completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_v2_deadline_completion_eligibility (
    deadline_uuid CHAR(36) NOT NULL PRIMARY KEY,
    employee_id INT NULL,
    ownership_json LONGTEXT NULL,
    eligible_at_capture TINYINT(1) NOT NULL DEFAULT 0,
    captured_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
