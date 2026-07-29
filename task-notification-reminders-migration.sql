CREATE TABLE IF NOT EXISTS portal_schema_migrations (
    migration_key VARCHAR(190) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE notifications ADD COLUMN deadline_state VARCHAR(24) NULL AFTER priority;
ALTER TABLE notifications ADD COLUMN sound_key VARCHAR(24) NULL AFTER deadline_state;
ALTER TABLE notifications ADD COLUMN scheduled_at DATETIME NULL AFTER sound_key;
ALTER TABLE notifications ADD COLUMN deduplication_key VARCHAR(190) NULL AFTER scheduled_at;
ALTER TABLE notifications ADD UNIQUE KEY uniq_notification_deduplication (deduplication_key);

ALTER TABLE notification_recipients ADD COLUMN snoozed_until DATETIME NULL AFTER next_reminder_at;

ALTER TABLE notification_preferences ADD COLUMN sound_volume TINYINT UNSIGNED NOT NULL DEFAULT 65 AFTER sound_enabled;
ALTER TABLE notification_preferences ADD COLUMN sound_prompt_seen TINYINT(1) NOT NULL DEFAULT 0 AFTER sound_volume;
ALTER TABLE notification_preferences ALTER COLUMN sound_enabled SET DEFAULT 0;
