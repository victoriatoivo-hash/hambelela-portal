-- Explicit deployment migration; never run from a view or constructor.
CREATE TABLE IF NOT EXISTS epi_v2_front_rosters (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_id INT NOT NULL,
 effective_from DATE NOT NULL,
 effective_to DATE NOT NULL,
 approved_by INT NOT NULL,
 approved_at DATETIME NOT NULL,
 hours_json LONGTEXT NOT NULL,
 reason VARCHAR(500) NOT NULL
) ENGINE=InnoDB;
CREATE TRIGGER IF NOT EXISTS epi_front_roster_no_update BEFORE UPDATE ON epi_v2_front_rosters FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Roster approval history is immutable';
CREATE TRIGGER IF NOT EXISTS epi_front_roster_no_delete BEFORE DELETE ON epi_v2_front_rosters FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Roster approval history is immutable';
INSERT IGNORE INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description)
VALUES('epi_v2_front_roster_enabled','0','boolean','Activate explicitly approved Front Desk rosters and mandatory HR evidence checks.');
