-- Owner instructions for unresolved Error Log records.
-- Additive only: existing errors, status, attribution and financial values are unchanged.
CREATE TABLE IF NOT EXISTS ops_error_instructions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    error_id INT NOT NULL,
    instruction_text TEXT NOT NULL,
    created_by_user_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    completed_by_user_id INT NULL,
    completion_note TEXT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    INDEX idx_error_instruction_history (error_id, created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ops_error_instruction_reads (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    instruction_id BIGINT NOT NULL,
    recipient_user_id INT NOT NULL,
    notification_id INT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_error_instruction_recipient (instruction_id, recipient_user_id),
    INDEX idx_error_instruction_unread (recipient_user_id, read_at, instruction_id),
    CONSTRAINT fk_error_instruction_read_instruction FOREIGN KEY (instruction_id)
        REFERENCES ops_error_instructions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback (only if the feature records are no longer required):
-- DROP TABLE ops_error_instruction_reads;
-- DROP TABLE ops_error_instructions;
