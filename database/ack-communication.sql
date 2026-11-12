-- Additive migration. Run after database/acknowledgments.sql, before publishing UI.
CREATE TABLE IF NOT EXISTS portal_ack_material (
 instruction_id BIGINT UNSIGNED PRIMARY KEY, question VARCHAR(1200) NOT NULL DEFAULT '',
 image_type VARCHAR(16) NULL, image_data MEDIUMBLOB NULL,
 FOREIGN KEY(instruction_id) REFERENCES portal_ack_instructions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS portal_ack_assistance (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, owner_id INT NOT NULL,
 sender_id INT NOT NULL, body TEXT NOT NULL, request_key CHAR(36) NOT NULL,
 created_at DATETIME NOT NULL, UNIQUE KEY sender_request(sender_id,request_key),
 INDEX conversation(owner_id,employee_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Deliberately no FK to a user, request log, session, notification or employee event.
CREATE TABLE IF NOT EXISTS portal_ack_anonymous (
 report_id CHAR(32) CHARACTER SET ascii PRIMARY KEY, lookup_hash CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
 owner_id INT NOT NULL, body TEXT NOT NULL, reply TEXT NULL, INDEX owner_reports(owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
