-- Phase 08: additive, review-only migration. Apply ONLY after review to a backed-up database.
-- All tables and existing notifications/notification_recipients must use InnoDB.
-- Employee-to-Owner assistance remains private and is NOT migrated or widened.
CREATE TABLE IF NOT EXISTS delivery_chat_threads (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 driver_id BIGINT UNSIGNED NOT NULL UNIQUE,
 audit_head CHAR(64) NOT NULL DEFAULT '',
 created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS delivery_chat_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 thread_id BIGINT UNSIGNED NOT NULL,
 sender_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(36) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 body TEXT NOT NULL,
 urgent TINYINT NOT NULL DEFAULT 0,
 context_type VARCHAR(16) NULL,
 context_id BIGINT UNSIGNED NULL,
 attachment_json TEXT NULL,
 created_at DATETIME(6) NOT NULL,
 handled_by BIGINT UNSIGNED NULL,
 handled_at DATETIME(6) NULL,
 UNIQUE KEY sender_request(sender_id,request_key),
 KEY thread_page(thread_id,id), KEY sender_rate(sender_id,created_at),
 CONSTRAINT delivery_chat_thread_fk FOREIGN KEY(thread_id) REFERENCES delivery_chat_threads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS delivery_chat_receipts (
 message_id BIGINT UNSIGNED NOT NULL,
 employee_id BIGINT UNSIGNED NOT NULL,
 delivered_at DATETIME(6) NULL,
 read_at DATETIME(6) NULL,
 PRIMARY KEY(message_id,employee_id),
 CONSTRAINT delivery_chat_receipt_fk FOREIGN KEY(message_id) REFERENCES delivery_chat_messages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS delivery_chat_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 thread_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(32) NOT NULL,
 data_json MEDIUMTEXT NOT NULL,
 created_at DATETIME(6) NOT NULL,
 previous_hash CHAR(64) NOT NULL,
 event_hash CHAR(64) NOT NULL,
 KEY thread_audit(thread_id,id),
 CONSTRAINT delivery_chat_audit_fk FOREIGN KEY(thread_id) REFERENCES delivery_chat_threads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
