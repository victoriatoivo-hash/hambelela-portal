-- Phase 6: additive Bookkeeping and Cash Control performance configuration/review data.
CREATE TABLE IF NOT EXISTS epi_bookkeeping_matches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  bookkeeping_entry_id INT NOT NULL,
  cash_amount_cents BIGINT NOT NULL,
  match_status VARCHAR(40) NOT NULL DEFAULT 'manually_confirmed',
  reviewed_by INT NOT NULL,
  reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  review_note VARCHAR(1000) NOT NULL,
  UNIQUE KEY uq_epi_bookkeeping_order_entry (order_id, bookkeeping_entry_id),
  KEY idx_epi_bookkeeping_match_order (order_id),
  KEY idx_epi_bookkeeping_match_entry (bookkeeping_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_bookkeeping_exceptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exception_type VARCHAR(60) NOT NULL,
  employee_id INT NULL,
  record_type VARCHAR(40) NOT NULL,
  record_id VARCHAR(80) NOT NULL,
  business_date DATE NOT NULL,
  reason VARCHAR(1500) NOT NULL,
  evidence_uuid CHAR(36) NULL,
  added_by INT NOT NULL,
  approved_by INT NULL,
  review_status VARCHAR(40) NOT NULL DEFAULT 'pending_review',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  KEY idx_epi_bk_exception_record (record_type,record_id),
  KEY idx_epi_bk_exception_employee_date (employee_id,business_date),
  KEY idx_epi_bk_exception_review (review_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_bookkeeping_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evidence_uuid CHAR(36) NOT NULL,
  reviewer_id INT NOT NULL,
  decision VARCHAR(50) NOT NULL,
  reason VARCHAR(1500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_epi_bk_review_evidence (evidence_uuid),
  KEY idx_epi_bk_review_reviewer (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description)
VALUES
 ('bookkeeping_cash_entry_rule','same_business_day','string','Configurable cash order to Bookkeeping timing rule'),
 ('bookkeeping_near_close_grace_minutes','60','integer','Near-closing grace window for cash entry'),
 ('bookkeeping_deposit_deadline','not_configured','string','Configurable deposit deadline; unmeasured until configured')
ON DUPLICATE KEY UPDATE description=VALUES(description);

INSERT INTO epi_employee_grace_periods(grace_key,module,label,minutes,uses_business_time) VALUES
 ('bookkeeping_cash_near_close','Bookkeeping','Cash received near closing',60,1),
 ('bookkeeping_deposit_delay','Bookkeeping','Approved deposit timing grace',0,1)
ON DUPLICATE KEY UPDATE label=VALUES(label);
