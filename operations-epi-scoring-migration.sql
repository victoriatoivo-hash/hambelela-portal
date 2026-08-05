-- EPI Phase 9 scoring. Additive, deterministic and decimal-safe.
INSERT INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description) VALUES
('epi_scoring_enabled','0','boolean','Owner-controlled Phase 9 scoring switch.'),
('epi_scoring_version','EPI Scoring Version 1.0','string','Current deterministic scoring calculation version.'),
('epi_positive_monthly_cap_hundredths','1000','integer','Maximum positive points per month in hundredths.'),
('epi_custom_period_mode','monthly_summary','string','Default custom-period calculation mode.'),
('epi_level_no_bonus_max','7499','integer','No Bonus maximum score in hundredths.'),
('epi_level_bronze_min','7500','integer','Bronze minimum score in hundredths.'),
('epi_level_silver_min','8500','integer','Silver minimum score in hundredths.'),
('epi_level_gold_min','9000','integer','Gold minimum score in hundredths.')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

CREATE TABLE IF NOT EXISTS epi_scorecards(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, scorecard_key VARCHAR(120) NOT NULL,
 scorecard_name VARCHAR(190) NOT NULL, role_key VARCHAR(60) NULL, employee_id INT NULL,
 department_key VARCHAR(80) NULL, effective_from DATE NOT NULL, effective_to DATE NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'active', version INT NOT NULL DEFAULT 1,
 approved_by INT NOT NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_epi_scorecard_version(scorecard_key,version),
 KEY idx_epi_scorecard_lookup(employee_id,role_key,department_key,effective_from,effective_to,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_scorecard_categories(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, scorecard_id BIGINT UNSIGNED NOT NULL,
 category_key VARCHAR(120) NOT NULL, category_name VARCHAR(190) NOT NULL,
 weight_hundredths INT UNSIGNED NOT NULL, minimum_hundredths INT UNSIGNED NOT NULL DEFAULT 0,
 maximum_hundredths INT UNSIGNED NOT NULL DEFAULT 10000, is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_epi_scorecard_category(scorecard_id,category_key), KEY idx_epi_category_scorecard(scorecard_id,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_performance_rules(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, rule_key VARCHAR(120) NOT NULL,
 rule_name VARCHAR(190) NOT NULL, rule_kind VARCHAR(20) NOT NULL,
 module VARCHAR(80) NOT NULL, category_key VARCHAR(120) NOT NULL, event_type VARCHAR(120) NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1, created_by INT NOT NULL, approved_by INT NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_epi_rule_key(rule_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_performance_rule_versions(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, rule_id BIGINT UNSIGNED NOT NULL, version INT NOT NULL,
 severity VARCHAR(30) NULL, impact_type VARCHAR(40) NOT NULL DEFAULT 'fixed_points',
 impact_hundredths INT UNSIGNED NOT NULL DEFAULT 0, severity_multiplier_basis INT UNSIGNED NOT NULL DEFAULT 10000,
 repeat_multiplier_json LONGTEXT NULL, financial_multiplier_json LONGTEXT NULL,
 maximum_per_event_hundredths INT UNSIGNED NULL, maximum_per_day_hundredths INT UNSIGNED NULL,
 maximum_per_month_hundredths INT UNSIGNED NULL, maximum_occurrences_per_day INT UNSIGNED NULL,
 maximum_occurrences_per_month INT UNSIGNED NULL, grace_rule VARCHAR(120) NULL,
 owner_confirmation_required TINYINT(1) NOT NULL DEFAULT 1, effective_from DATE NOT NULL,
 effective_to DATE NULL, approved_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_epi_rule_version(rule_id,version), KEY idx_epi_rule_effective(rule_id,effective_from,effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_performance_score_events(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_uuid CHAR(36) NOT NULL,
 employee_id INT NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL,
 evidence_uuid CHAR(36) NULL, root_incident_id VARCHAR(190) NULL, rule_id BIGINT UNSIGNED NULL,
 rule_version_id BIGINT UNSIGNED NULL, category_key VARCHAR(120) NOT NULL,
 event_kind VARCHAR(20) NOT NULL, base_hundredths INT UNSIGNED NOT NULL,
 severity_multiplier_basis INT UNSIGNED NOT NULL DEFAULT 10000,
 repeat_multiplier_basis INT UNSIGNED NOT NULL DEFAULT 10000,
 financial_multiplier_basis INT UNSIGNED NOT NULL DEFAULT 10000,
 responsibility_basis INT UNSIGNED NOT NULL DEFAULT 10000, final_impact_hundredths INT UNSIGNED NOT NULL,
 confirmation_status VARCHAR(40) NOT NULL DEFAULT 'pending', confirmed_by INT NULL,
 confirmed_at DATETIME NULL, reviewer_note TEXT NULL, applied_at DATETIME NULL,
 reversed TINYINT(1) NOT NULL DEFAULT 0, reversal_event_id BIGINT UNSIGNED NULL,
 reversal_reason TEXT NULL, audit_id BIGINT UNSIGNED NULL, manual TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_epi_score_event_uuid(event_uuid),
 UNIQUE KEY uq_epi_score_event_evidence_rule(employee_id,evidence_uuid,rule_id,event_kind),
 KEY idx_epi_score_event_period(employee_id,period_start,period_end,confirmation_status,reversed),
 KEY idx_epi_root_incident(root_incident_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_scoring_monthly_scores(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, employee_name VARCHAR(160) NOT NULL,
 role_key VARCHAR(60) NULL, department_key VARCHAR(80) NULL, score_year SMALLINT NOT NULL,
 score_month TINYINT NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL,
 opening_hundredths INT NOT NULL DEFAULT 10000, positive_hundredths INT NOT NULL DEFAULT 0,
 deduction_hundredths INT NOT NULL DEFAULT 0, final_hundredths INT NOT NULL DEFAULT 10000,
 performance_level VARCHAR(40) NOT NULL DEFAULT 'Gold', evidence_count INT NOT NULL DEFAULT 0,
 confirmed_deduction_count INT NOT NULL DEFAULT 0, positive_evidence_count INT NOT NULL DEFAULT 0,
 pending_review_count INT NOT NULL DEFAULT 0, data_completeness_hundredths INT NOT NULL DEFAULT 0,
 confidence_label VARCHAR(40) NOT NULL DEFAULT 'Insufficient Data', calculation_version VARCHAR(80) NOT NULL,
 scorecard_id BIGINT UNSIGNED NULL, scorecard_version INT NULL, calculation_timestamp DATETIME NOT NULL,
 calculated_by INT NULL, score_status VARCHAR(40) NOT NULL DEFAULT 'live', locked TINYINT(1) NOT NULL DEFAULT 0,
 locked_by INT NULL, locked_at DATETIME NULL, lock_note TEXT NULL, recalculated TINYINT(1) NOT NULL DEFAULT 0,
 recalculation_reason TEXT NULL, previous_final_hundredths INT NULL, audit_reference VARCHAR(190) NOT NULL,
 owner_comments TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_epi_scoring_month(employee_id,score_year,score_month), KEY idx_epi_scoring_period(period_start,period_end,locked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_employee_monthly_category_scores(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, monthly_score_id BIGINT UNSIGNED NOT NULL,
 category_key VARCHAR(120) NOT NULL, category_name VARCHAR(190) NOT NULL,
 weight_hundredths INT UNSIGNED NOT NULL, opening_hundredths INT NOT NULL DEFAULT 10000,
 positive_hundredths INT NOT NULL DEFAULT 0, deduction_hundredths INT NOT NULL DEFAULT 0,
 final_hundredths INT NOT NULL, weighted_contribution_hundredths INT NOT NULL,
 event_count INT NOT NULL DEFAULT 0, explanation_json LONGTEXT NULL,
 UNIQUE KEY uq_epi_month_category(monthly_score_id,category_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_employee_score_audits(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, audit_uuid CHAR(36) NOT NULL, employee_id INT NOT NULL,
 period_start DATE NOT NULL, period_end DATE NOT NULL, trigger_name VARCHAR(80) NOT NULL,
 triggered_by INT NULL, input_evidence_json LONGTEXT NOT NULL, rule_versions_json LONGTEXT NOT NULL,
 scorecard_json LONGTEXT NOT NULL, category_calculations_json LONGTEXT NOT NULL,
 previous_hundredths INT NULL, new_hundredths INT NOT NULL, cache_state_json LONGTEXT NULL,
 calculation_version VARCHAR(80) NOT NULL, calculation_status VARCHAR(40) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_epi_score_audit_uuid(audit_uuid),
 KEY idx_epi_score_audit(employee_id,period_start,period_end,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_employee_month_locks(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, score_year SMALLINT NOT NULL,
 score_month TINYINT NOT NULL, monthly_score_id BIGINT UNSIGNED NOT NULL, locked_by INT NOT NULL,
 locked_at DATETIME NOT NULL, lock_note TEXT NOT NULL, override_incomplete TINYINT(1) NOT NULL DEFAULT 0,
 unlocked_by INT NULL, unlocked_at DATETIME NULL, unlock_reason TEXT NULL,
 UNIQUE KEY uq_epi_month_lock(employee_id,score_year,score_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_performance_manual_adjustments(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, adjustment_uuid CHAR(36) NOT NULL, employee_id INT NOT NULL,
 period_start DATE NOT NULL, period_end DATE NOT NULL, category_key VARCHAR(120) NOT NULL,
 adjustment_kind VARCHAR(20) NOT NULL, value_hundredths INT UNSIGNED NOT NULL, reason TEXT NOT NULL,
 evidence_reference VARCHAR(190) NULL, created_by INT NOT NULL, confirmed_by INT NOT NULL,
 score_event_id BIGINT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_epi_manual_adjustment(adjustment_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_performance_award_eligibility(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, monthly_score_id BIGINT UNSIGNED NOT NULL,
 score_level VARCHAR(40) NOT NULL, eligible TINYINT(1) NOT NULL DEFAULT 1,
 disqualifying_rule_id BIGINT UNSIGNED NULL, evidence_uuid CHAR(36) NULL,
 owner_confirmed_by INT NULL, owner_confirmed_at DATETIME NULL, explanation TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_epi_award_month(monthly_score_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO epi_scorecards(scorecard_key,scorecard_name,role_key,effective_from,status,version,approved_by,created_by) VALUES
('front_desk_default','Front Desk Scorecard','front_desk_admin','2026-01-01','active',1,1,1),
('packer_default','Packer Scorecard','packer','2026-01-01','active',1,1,1),
('general_default','General Employee Scorecard',NULL,'2026-01-01','active',1,1,1);

INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'attendance','Attendance and Reliability',1000 FROM epi_scorecards WHERE scorecard_key IN('front_desk_default','packer_default');
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'orders','Orders and Front Desk Operations',2500 FROM epi_scorecards WHERE scorecard_key='front_desk_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'customer_process','Customer and Process Compliance',1000 FROM epi_scorecards WHERE scorecard_key='front_desk_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'website_updates','Website Inventory Updates',1000 FROM epi_scorecards WHERE scorecard_key='front_desk_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'tasks','Task Performance',1500 FROM epi_scorecards WHERE scorecard_key='front_desk_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'courier','Courier Sending',1000 FROM epi_scorecards WHERE scorecard_key='front_desk_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'bookkeeping','Bookkeeping and Cash Control',1500 FROM epi_scorecards WHERE scorecard_key='front_desk_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'quality','Error and Quality',500 FROM epi_scorecards WHERE scorecard_key='front_desk_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'order_packing','Order Packing Performance',2000 FROM epi_scorecards WHERE scorecard_key='packer_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'packing_list','Packing List Productivity',2000 FROM epi_scorecards WHERE scorecard_key='packer_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'packing_accuracy','Packing Accuracy and Quantity Compliance',2000 FROM epi_scorecards WHERE scorecard_key='packer_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'priority_process','Priority and Process Compliance',1000 FROM epi_scorecards WHERE scorecard_key='packer_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'tasks','Task Performance',1000 FROM epi_scorecards WHERE scorecard_key='packer_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'courier_upload','Courier Upload Performance',500 FROM epi_scorecards WHERE scorecard_key='packer_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'quality','Error and Quality',500 FROM epi_scorecards WHERE scorecard_key='packer_default';
INSERT IGNORE INTO epi_scorecard_categories(scorecard_id,category_key,category_name,weight_hundredths) SELECT id,'general_reliability','General Reliability',10000 FROM epi_scorecards WHERE scorecard_key='general_default';
