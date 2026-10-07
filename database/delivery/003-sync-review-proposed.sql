-- Additive draft. Do not apply live before schema review/approval.
CREATE TABLE delivery_order_sync_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id INT NOT NULL,
 snapshot_hash CHAR(64) NOT NULL,
 snapshot_json JSON NOT NULL,
 state ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
 first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_by_employee_id INT NULL,
 resolved_at DATETIME NULL,
 resolution_note VARCHAR(500) NULL,
 UNIQUE KEY uq_delivery_sync_snapshot(order_id,snapshot_hash),
 FOREIGN KEY(order_id) REFERENCES ops_orders(id),
 FOREIGN KEY(resolved_by_employee_id) REFERENCES ops_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
