CREATE TABLE IF NOT EXISTS ops_packing_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    packing_item_id INT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(190) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    uploaded_by INT NULL,
    uploaded_by_name VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    deleted_by INT NULL,
    UNIQUE KEY uniq_packing_stored_filename (stored_filename),
    INDEX idx_packing_attachment_item (packing_item_id, deleted_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
