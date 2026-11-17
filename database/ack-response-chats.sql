-- Additive: chat turns never enter the acknowledgement recipient/evidence ledger.
CREATE TABLE IF NOT EXISTS portal_ack_response_chats (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_id INT NOT NULL, employee_id INT NOT NULL, create_key CHAR(36) CHARACTER SET ascii NOT NULL,
 last_id BIGINT UNSIGNED NOT NULL DEFAULT 0, owner_seen BIGINT UNSIGNED NOT NULL DEFAULT 0,
 employee_seen BIGINT UNSIGNED NOT NULL DEFAULT 0, deferred_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 completed_at DATETIME NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY owner_retry(owner_id,create_key), KEY employee_inbox(employee_id,last_id), KEY owner_inbox(owner_id,last_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS portal_ack_response_chat_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, chat_id BIGINT UNSIGNED NOT NULL,
 sender_id INT NOT NULL, request_key CHAR(36) CHARACTER SET ascii NOT NULL,
 body TEXT NOT NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY message_retry(chat_id,sender_id,request_key), KEY thread_order(chat_id,id),
 FOREIGN KEY(chat_id) REFERENCES portal_ack_response_chats(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
