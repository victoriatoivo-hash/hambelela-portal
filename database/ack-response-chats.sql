-- Additive: existing acknowledgments, assistance and anonymous reports are not migrated.
CREATE TABLE IF NOT EXISTS portal_ack_response_chats (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_id INT NOT NULL, employee_id INT NOT NULL,
 create_key CHAR(36) CHARACTER SET ascii NOT NULL,
 title VARCHAR(190) NOT NULL, state ENUM('employee','admin','later','completed') NOT NULL DEFAULT 'employee',
 revision INT UNSIGNED NOT NULL DEFAULT 1, updated_at DATETIME NOT NULL,
 UNIQUE KEY retry(owner_id,create_key), INDEX employee_queue(employee_id,state,updated_at),
 INDEX owner_queue(owner_id,state,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS portal_ack_response_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, thread_id BIGINT UNSIGNED NOT NULL,
 sender_id INT NOT NULL, request_key CHAR(36) CHARACTER SET ascii NOT NULL,
 body TEXT NOT NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY retry(thread_id,sender_id,request_key), INDEX messages(thread_id,id),
 FOREIGN KEY(thread_id) REFERENCES portal_ack_response_chats(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
