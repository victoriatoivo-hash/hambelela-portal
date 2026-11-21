-- Review before production. Additive audit only; no competing inbox or unread state.
-- No data backfill. Existing notification_recipients.read_at remains authoritative.
CREATE TABLE IF NOT EXISTS delivery_notification_access (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_id INT NOT NULL,
 notification_id INT NOT NULL,
 through_id INT NOT NULL DEFAULT 0,
 action VARCHAR(24) NOT NULL,
 event_key CHAR(64) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY notification_access_event (event_key),
 KEY notification_access_employee (employee_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Retain this audit table on application rollback. Do not erase historical reads.
