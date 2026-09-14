CREATE TABLE IF NOT EXISTS bookkeeping_order_allocations (
  allocation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bookkeeping_entry_id INT NOT NULL,
  order_id INT NOT NULL,
  cash_amount_allocated_cents BIGINT NOT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  review_status ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  review_note TEXT NULL,
  UNIQUE KEY uq_bookkeeping_order_allocation (bookkeeping_entry_id, order_id),
  KEY idx_bookkeeping_allocation_order (order_id, review_status),
  CONSTRAINT fk_bookkeeping_allocation_entry FOREIGN KEY (bookkeeping_entry_id) REFERENCES ops_cash_book_entries(id),
  CONSTRAINT fk_bookkeeping_allocation_order FOREIGN KEY (order_id) REFERENCES ops_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookkeeping_order_allocation_audit (
  audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  allocation_id BIGINT UNSIGNED NULL,
  bookkeeping_entry_id INT NOT NULL,
  order_id INT NOT NULL,
  previous_value_json LONGTEXT NULL,
  new_value_json LONGTEXT NOT NULL,
  action VARCHAR(40) NOT NULL,
  actor_id INT NOT NULL,
  supporting_note TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bookkeeping_allocation_audit_order (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO kpi_settings (setting_key, setting_value)
VALUES ('bookkeeping_cash_deadline', '17:00'), ('bookkeeping_deposit_schedule', 'not_configured')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
