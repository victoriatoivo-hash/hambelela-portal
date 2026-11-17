CREATE TABLE IF NOT EXISTS portal_ack_owner_chat_deletions (
    owner_id INT NOT NULL,
    kind ENUM('assistance','chat') NOT NULL,
    thread_id BIGINT UNSIGNED NOT NULL,
    through_id BIGINT UNSIGNED NOT NULL,
    removed_at DATETIME NOT NULL,
    PRIMARY KEY(owner_id,kind,thread_id)
) ENGINE=InnoDB;
