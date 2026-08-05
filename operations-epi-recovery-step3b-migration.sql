-- EPI Recovery Step 3B: honest score eligibility and source-based completeness.
-- Additive only. Existing score and audit rows remain intact.

INSERT INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description) VALUES
('epi_source_core_missing_limit','2','integer','Core sources missing at or above this count make a score insufficient.'),
('epi_source_high_confidence_core_basis','9000','integer','Minimum Core source coverage for High confidence.'),
('epi_source_moderate_confidence_core_basis','6500','integer','Minimum Core source coverage for Moderate confidence.'),
('epi_provisional_weight_redistribution','0','boolean','Owner-controlled; missing category weights are not redistributed by default.'),
('epi_step3b_calculation_version','EPI Step 3B Version 1.0','string','Source-completeness and evidence-eligibility calculation version.')
ON DUPLICATE KEY UPDATE description=VALUES(description);

CREATE TABLE IF NOT EXISTS epi_role_required_sources(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 role_key VARCHAR(60) NOT NULL,
 source_key VARCHAR(120) NOT NULL,
 source_name VARCHAR(190) NOT NULL,
 module VARCHAR(80) NOT NULL,
 category_key VARCHAR(120) NOT NULL,
 importance VARCHAR(20) NOT NULL,
 action_pattern VARCHAR(500) NULL,
 ownership_required TINYINT(1) NOT NULL DEFAULT 1,
 timestamp_required TINYINT(1) NOT NULL DEFAULT 1,
 status_history_required TINYINT(1) NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 configuration_json LONGTEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_epi_role_source(role_key,source_key),
 KEY idx_epi_role_source_lookup(role_key,importance,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_monthly_source_completeness(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_id INT NOT NULL,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 role_key VARCHAR(60) NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 source_key VARCHAR(120) NOT NULL,
 category_key VARCHAR(120) NOT NULL,
 importance VARCHAR(20) NOT NULL,
 records_expected INT NOT NULL DEFAULT 0,
 records_available INT NOT NULL DEFAULT 0,
 ownership_coverage_hundredths INT NOT NULL DEFAULT 0,
 timestamp_coverage_hundredths INT NOT NULL DEFAULT 0,
 status_history_coverage_hundredths INT NOT NULL DEFAULT 0,
 completeness_hundredths INT NOT NULL DEFAULT 0,
 source_status VARCHAR(30) NOT NULL,
 source_reliability VARCHAR(30) NOT NULL,
 reason_missing TEXT NULL,
 calculation_version VARCHAR(80) NOT NULL,
 calculated_at DATETIME NOT NULL,
 UNIQUE KEY uq_epi_month_source(employee_id,period_start,period_end,source_id),
 KEY idx_epi_month_source_status(employee_id,period_start,period_end,importance,source_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_evidence_eligibility_audits(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 evidence_uuid CHAR(36) NOT NULL,
 previous_state VARCHAR(40) NULL,
 new_state VARCHAR(40) NOT NULL,
 reason_code VARCHAR(80) NOT NULL,
 reason_text TEXT NULL,
 classified_by VARCHAR(80) NOT NULL DEFAULT 'rule_engine',
 calculation_version VARCHAR(80) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_epi_eligibility_evidence(evidence_uuid,created_at),
 KEY idx_epi_eligibility_state(new_state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_score_superseding_corrections(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 correction_uuid CHAR(36) NOT NULL,
 monthly_score_id BIGINT UNSIGNED NOT NULL,
 employee_id INT NOT NULL,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 previous_result_type VARCHAR(40) NULL,
 previous_score_hundredths INT NULL,
 corrected_result_type VARCHAR(40) NOT NULL,
 corrected_score_hundredths INT NULL,
 correction_reason TEXT NOT NULL,
 locked_record TINYINT(1) NOT NULL DEFAULT 0,
 created_by INT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_epi_score_correction_uuid(correction_uuid),
 UNIQUE KEY uq_epi_score_correction_month(monthly_score_id,corrected_result_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE epi_employee_evidence
 ADD COLUMN IF NOT EXISTS eligibility_state VARCHAR(40) NOT NULL DEFAULT 'unclassified',
 ADD COLUMN IF NOT EXISTS eligibility_reason VARCHAR(190) NULL,
 ADD COLUMN IF NOT EXISTS eligibility_version VARCHAR(80) NULL,
 ADD COLUMN IF NOT EXISTS eligibility_classified_at DATETIME NULL;

ALTER TABLE epi_scoring_monthly_scores
 ADD COLUMN IF NOT EXISTS result_type VARCHAR(40) NOT NULL DEFAULT 'legacy',
 ADD COLUMN IF NOT EXISTS official_score_hundredths INT NULL,
 ADD COLUMN IF NOT EXISTS official_performance_level VARCHAR(40) NULL,
 ADD COLUMN IF NOT EXISTS missing_critical_sources_json LONGTEXT NULL,
 ADD COLUMN IF NOT EXISTS missing_core_sources_json LONGTEXT NULL,
 ADD COLUMN IF NOT EXISTS source_coverage_json LONGTEXT NULL,
 ADD COLUMN IF NOT EXISTS superseded TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS superseded_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS superseded_reason TEXT NULL;

ALTER TABLE epi_employee_monthly_category_scores
 ADD COLUMN IF NOT EXISTS calculation_status VARCHAR(30) NOT NULL DEFAULT 'legacy',
 ADD COLUMN IF NOT EXISTS official_score_hundredths INT NULL,
 ADD COLUMN IF NOT EXISTS missing_sources_json LONGTEXT NULL,
 ADD COLUMN IF NOT EXISTS confidence_label VARCHAR(40) NULL;

INSERT IGNORE INTO epi_role_required_sources
(role_key,source_key,source_name,module,category_key,importance,action_pattern,status_history_required) VALUES
('front_desk_admin','attendance_sessions','Attendance and login/session history','Portal Activity','attendance','critical','login|logout|session',1),
('front_desk_admin','walkin_created','Walk-in order creation timestamps','Orders','orders','critical','walk.?in|order_created|new_order',1),
('front_desk_admin','walkin_completed','Walk-in completion attribution and timestamps','Orders','orders','critical','walk.?in.*complete|status.*complete|order_completed',1),
('front_desk_admin','order_closing','General Front Desk order-closing activity','Orders','orders','core','complete|closed|status_change',1),
('front_desk_admin','customer_communication','Customer communication and process activity','Orders','customer_process','core','customer|communication|contact|waiting',1),
('front_desk_admin','task_history','Task assignments, due dates and status history','Tasks','tasks','core','assign|due|complete|status|checklist',1),
('front_desk_admin','courier_sending','Waybill availability and sent timestamps','Courier','courier','core','available|upload|sent|send',1),
('front_desk_admin','bookkeeping_cash','Cash-order matching and balance evidence','Bookkeeping','bookkeeping','core','cash|match|opening|closing|reconcil',1),
('front_desk_admin','website_updates','Website inventory-update timestamps','Inventory','website_updates','supporting','website|inventory|update',0),
('front_desk_admin','quality_responsibility','Error and Quality responsibility records','Error Log','quality','supporting','error|quality|complaint|responsib',0),
('packer','attendance_sessions','Attendance and login/session history','Portal Activity','attendance','critical','login|logout|session',1),
('packer','order_new','Order New timestamps and attribution','Orders','order_packing','critical','new_order|order_created|status.*new',1),
('packer','order_in_progress','New to In Progress packing-stage history','Orders','order_packing','critical','in_progress|in progress|packing_started',1),
('packer','packing_assignment','Packing List assignment history','Packing List','packing_list','critical','assign|owner|responsible',1),
('packer','packing_status','Packing List status timestamps','Packing List','packing_list','core','status|started|done|complete',1),
('packer','packing_quantities','Required and packed quantities','Packing List','packing_accuracy','core','quantity|required|packed',0),
('packer','packing_priority','Packing priority evidence','Packing List','priority_process','supporting','priority',0),
('packer','website_confirmation','Packer website-confirmation evidence','Packing List','priority_process','core','website.*confirm|website.*update',0),
('packer','task_history','Task assignments, due dates and status history','Tasks','tasks','core','assign|due|complete|status|checklist',1),
('packer','courier_upload','Courier upload timestamps','Courier','courier_upload','core','upload',1),
('packer','quality_responsibility','Error and Quality responsibility records','Error Log','quality','supporting','error|quality|complaint|responsib',0);
