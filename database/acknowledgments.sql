CREATE TABLE IF NOT EXISTS portal_ack_instructions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 root_id BIGINT UNSIGNED NULL,
 version INT NOT NULL DEFAULT 1,
 request_key CHAR(36) NOT NULL UNIQUE,
 title VARCHAR(190) NOT NULL,
 message_html MEDIUMTEXT NOT NULL,
 sender_id INT NOT NULL,
 deadline DATETIME NULL,
 required TINYINT(1) NOT NULL DEFAULT 1,
 notify_immediately TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 UNIQUE KEY root_version(root_id,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS portal_ack_recipients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 instruction_id BIGINT UNSIGNED NOT NULL,
 employee_id INT NOT NULL,
 employee_name VARCHAR(190) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'pending',
 acknowledged_at DATETIME NULL,
 session_reference CHAR(64) NULL,
 UNIQUE KEY instruction_employee(instruction_id,employee_id),
 INDEX employee_status(employee_id,status),
 FOREIGN KEY(instruction_id) REFERENCES portal_ack_instructions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS portal_ack_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 recipient_id BIGINT UNSIGNED NOT NULL,
 actor_id INT NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 message TEXT NOT NULL,
 request_key CHAR(36) NOT NULL UNIQUE,
 created_at DATETIME NOT NULL,
 notification_delivered_at DATETIME NULL,
 evidence_recorded_at DATETIME NULL,
 INDEX recipient_event(recipient_id,id),
 FOREIGN KEY(recipient_id) REFERENCES portal_ack_recipients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
