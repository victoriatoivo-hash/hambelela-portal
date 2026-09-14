-- EPI automatic-by-default scoring. Additive: no evidence, review, score or lock history is deleted.
INSERT INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description)
VALUES('epi_scoring_enabled','1','boolean','Evidence-first automatic scoring with review only for ambiguity.')
ON DUPLICATE KEY UPDATE setting_value='1',description=VALUES(description);

ALTER TABLE epi_performance_rule_versions ADD COLUMN automatic_application TINYINT(1) NOT NULL DEFAULT 1 AFTER grace_rule;
ALTER TABLE epi_performance_rule_versions ADD COLUMN minimum_confidence VARCHAR(20) NOT NULL DEFAULT 'high' AFTER automatic_application;
ALTER TABLE epi_performance_rule_versions ADD COLUMN owner_review_required TINYINT(1) NOT NULL DEFAULT 0 AFTER minimum_confidence;
ALTER TABLE epi_performance_rule_versions ADD COLUMN exclusion_conditions_json LONGTEXT NULL AFTER owner_review_required;
ALTER TABLE epi_performance_rule_versions ADD COLUMN grace_period_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER exclusion_conditions_json;
ALTER TABLE epi_performance_rule_versions ADD COLUMN root_incident_grouping VARCHAR(80) NOT NULL DEFAULT 'module_reference' AFTER grace_period_minutes;
ALTER TABLE epi_performance_score_events ADD COLUMN automatic_status VARCHAR(40) NOT NULL DEFAULT 'needs_review' AFTER confirmation_status;
ALTER TABLE epi_performance_score_events ADD COLUMN confidence_level VARCHAR(20) NOT NULL DEFAULT 'insufficient' AFTER automatic_status;
ALTER TABLE epi_performance_score_events ADD COLUMN exception_status VARCHAR(40) NULL AFTER confidence_level;
ALTER TABLE epi_performance_score_events ADD COLUMN expected_result TEXT NULL AFTER exception_status;
ALTER TABLE epi_performance_score_events ADD COLUMN actual_result TEXT NULL AFTER expected_result;
ALTER TABLE epi_performance_score_events ADD COLUMN exception_id BIGINT UNSIGNED NULL AFTER actual_result;
ALTER TABLE epi_performance_score_events ADD COLUMN supersedes_event_id BIGINT UNSIGNED NULL AFTER exception_id;

UPDATE epi_performance_rule_versions v
JOIN epi_performance_rules r ON r.id=v.rule_id
SET v.automatic_application=1,v.owner_review_required=0,v.owner_confirmation_required=0,v.minimum_confidence='high'
WHERE LOWER(CONCAT(r.rule_name,' ',r.event_type)) REGEXP 'completed|completion|in progress|late|overdue|deadline|checklist|required note|required evidence|quantity variance|website confirmation|waybill|bookkeeping|opening balance|closing balance|login';

UPDATE epi_performance_rule_versions v
JOIN epi_performance_rules r ON r.id=v.rule_id
SET v.automatic_application=0,v.owner_review_required=1,v.owner_confirmation_required=1,v.minimum_confidence='high'
WHERE LOWER(CONCAT(r.rule_name,' ',r.event_type)) REGEXP 'wrong product|missing product|customer complaint|shared responsibility|financial loss|repeat error|critical error|misconduct|supplier|courier fault|business error|system error|conflicting attribution';
