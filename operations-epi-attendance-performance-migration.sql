-- EPI Phase 8: additive attendance, portal activity and reliability structures.
INSERT INTO epi_employee_performance_settings (setting_key,setting_value,value_type,description) VALUES
('attendance_module_enabled','1','boolean','Enable the isolated Attendance EPI adapter when the master EPI flag is enabled.'),
('attendance_idle_threshold_minutes','15','integer','Idle threshold used only for estimated Portal Active Time.'),
('attendance_extended_idle_minutes','60','integer','Potential extended inactivity threshold requiring owner review.'),
('attendance_late_grace_minutes','10','integer','Configurable arrival grace after scheduled start.'),
('attendance_significantly_late_minutes','60','integer','Significant lateness threshold requiring owner review.'),
('attendance_recent_activity_minutes','15','integer','Current recently-active status threshold.'),
('attendance_lunch_start','12:00','time','Default configurable lunch start.'),
('attendance_lunch_end','13:00','time','Default configurable lunch end.'),
('attendance_poll_seconds','60','integer','Online staff refresh interval; polling is passive and never meaningful activity.')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO epi_employee_grace_periods (grace_key,module,label,minutes,uses_business_time) VALUES
('attendance_late_arrival','Attendance','Arrival grace',10,0),
('attendance_idle','Attendance','Portal idle threshold',15,0),
('attendance_extended_inactivity','Attendance','Extended inactivity review threshold',60,1)
ON DUPLICATE KEY UPDATE module=VALUES(module),label=VALUES(label);

CREATE TABLE IF NOT EXISTS epi_attendance_schedules (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id INT NULL, role_key VARCHAR(60) NULL,
 department_key VARCHAR(80) NULL, day_of_week TINYINT NOT NULL, scheduled_start TIME NULL,
 scheduled_end TIME NULL, break_start TIME NULL, break_end TIME NULL, expected_minutes INT NOT NULL DEFAULT 0,
 effective_from DATE NOT NULL, effective_to DATE NULL, schedule_type VARCHAR(40) NOT NULL DEFAULT 'standard',
 approved_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_epi_att_schedule_employee(employee_id,effective_from,effective_to,day_of_week),
 KEY idx_epi_att_schedule_role(role_key,department_key,day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_attendance_exceptions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id INT NULL, exception_type VARCHAR(80) NOT NULL,
 starts_at DATETIME NOT NULL, ends_at DATETIME NULL, reason TEXT NOT NULL, evidence_reference VARCHAR(190) NULL,
 affected_rules_json LONGTEXT NULL, added_by INT NOT NULL, approved_by INT NULL,
 review_status VARCHAR(60) NOT NULL DEFAULT 'pending_review', reviewed_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_epi_att_exception_employee(employee_id,starts_at,ends_at),
 KEY idx_epi_att_exception_review(review_status,starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_attendance_coverage (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id INT NOT NULL, covered_role VARCHAR(80) NOT NULL,
 starts_at DATETIME NOT NULL, ends_at DATETIME NULL, reason TEXT NOT NULL, approved_by INT NOT NULL,
 evidence_reference VARCHAR(190) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_epi_att_coverage_employee(employee_id,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_attendance_monthly_summaries (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id INT NOT NULL, summary_year SMALLINT NOT NULL,
 summary_month TINYINT NOT NULL, scheduled_days INT NOT NULL DEFAULT 0, present_days INT NOT NULL DEFAULT 0,
 leave_days DECIMAL(6,2) NOT NULL DEFAULT 0, on_time_days INT NOT NULL DEFAULT 0, late_days INT NOT NULL DEFAULT 0,
 unapproved_absences INT NOT NULL DEFAULT 0, partial_days INT NOT NULL DEFAULT 0,
 portal_active_minutes INT NOT NULL DEFAULT 0, review_status VARCHAR(60) NOT NULL DEFAULT 'pending_review',
 evidence_count INT NOT NULL DEFAULT 0, locked_at DATETIME NULL, locked_by INT NULL,
 calculation_version VARCHAR(40) NOT NULL DEFAULT 'phase8-v1', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id), UNIQUE KEY uq_epi_att_month(employee_id,summary_year,summary_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
