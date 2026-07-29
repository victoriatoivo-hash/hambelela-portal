CREATE TABLE IF NOT EXISTS ops_order_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(160) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    uploaded_by INT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    deleted_by INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ops_order_files_stored (stored_filename),
    KEY idx_ops_order_files_order (order_id, deleted_at),
    KEY idx_ops_order_files_uploader (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
