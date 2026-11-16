-- Run explicitly against the acknowledgments DB before deploying the register.
ALTER TABLE portal_ack_assistance ADD COLUMN IF NOT EXISTS topic_id BIGINT UNSIGNED NULL,
 ADD INDEX IF NOT EXISTS assistance_topic(owner_id,employee_id,topic_id,id);
-- Preserve existing topic boundaries; never move a message already linked explicitly.
UPDATE portal_ack_assistance a JOIN (
 SELECT id,COALESCE(MAX(CASE WHEN request_key LIKE 't:%' THEN id END) OVER
 (PARTITION BY owner_id,employee_id ORDER BY id ROWS UNBOUNDED PRECEDING),
 MIN(id) OVER(PARTITION BY owner_id,employee_id)) root FROM portal_ack_assistance
) history ON history.id=a.id SET a.topic_id=history.root WHERE a.topic_id IS NULL;
CREATE TABLE IF NOT EXISTS portal_ack_assistance_seen (
 owner_id INT NOT NULL, topic_id BIGINT UNSIGNED NOT NULL, seen BIGINT UNSIGNED NOT NULL DEFAULT 0,
 alerted BIGINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY(owner_id,topic_id)
) ENGINE=InnoDB;
-- Anonymous rows contain no reporter account, session, private code, IP, or device metadata.
CREATE TABLE IF NOT EXISTS portal_ack_report_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 report_id CHAR(32) CHARACTER SET ascii NOT NULL,
 side ENUM('reporter','owner') NOT NULL, body TEXT NOT NULL,
 message_key VARCHAR(48) CHARACTER SET ascii NOT NULL,
 submitted_on DATE NULL,
 owner_read TINYINT(1) NOT NULL DEFAULT 0, alerted TINYINT(1) NOT NULL DEFAULT 0,
 UNIQUE KEY report_retry(report_id,message_key), INDEX thread_order(report_id,id),
 FOREIGN KEY(report_id) REFERENCES portal_ack_anonymous(report_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS portal_ack_register_seen (
 owner_id INT NOT NULL, recipient_id BIGINT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NOT NULL, alerted_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(owner_id,recipient_id)
) ENGINE=InnoDB;
-- Copy only the text that actually survives. Historical submission times are unknown.
INSERT IGNORE INTO portal_ack_report_messages(report_id,side,body,message_key,owner_read,alerted)
 SELECT report_id,'reporter',body,'opening',1,1 FROM portal_ack_anonymous;
INSERT IGNORE INTO portal_ack_report_messages(report_id,side,body,message_key,owner_read,alerted)
 SELECT report_id,'owner',reply,'legacy-reply',1,1 FROM portal_ack_anonymous WHERE reply IS NOT NULL;
