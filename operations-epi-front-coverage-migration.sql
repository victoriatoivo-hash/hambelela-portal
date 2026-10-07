-- Explicit deployment operation only. Never execute during a page read.
CREATE TABLE IF NOT EXISTS epi_v2_front_plans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 work_date DATE NOT NULL,
 primary_employee_id INT NOT NULL,
 coverage_employee_id INT NULL,
 planned_start DATETIME NULL,
 planned_end DATETIME NULL,
 state VARCHAR(32) NOT NULL,
 reason VARCHAR(500) NULL,
 accepted_at DATETIME NULL,
 actual_start DATETIME NULL,
 actual_end DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY front_plan_day (work_date,primary_employee_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS epi_v2_front_reminders (
 reminder_key CHAR(64) PRIMARY KEY,
 recipient_id INT NOT NULL,
 sent_at DATETIME NOT NULL,
 notification_id BIGINT NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description)
 VALUES('epi_v2_front_coverage_enabled','0','boolean','Enable validated Front Desk lunch planning and accepted coverage.');
