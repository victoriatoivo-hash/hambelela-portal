-- EPI Phase 6: additive Bookkeeping and Cash Control configuration.
INSERT INTO epi_employee_performance_settings (setting_key,setting_value,value_type,description) VALUES
('bookkeeping_module_enabled','1','boolean','Enable the isolated Bookkeeping EPI adapter when the master EPI flag is enabled.'),
('bookkeeping_cash_entry_grace_minutes','0','integer','Business-time grace after the normal same-business-day cash-entry deadline.'),
('bookkeeping_cash_entry_deadline','17:00','time','Default cash-entry deadline; owner configurable.'),
('bookkeeping_deposit_deadline','not_configured','string','Owner-configured deposit deadline. Unconfigured deposits remain insufficient historical data.'),
('bookkeeping_deposit_schedule','not_configured','string','Owner-configured deposit schedule.'),
('bookkeeping_walk_in_identifiers','["walk-in","walk in"]','json','Configurable walk-in identifiers reused from Orders.'),
('bookkeeping_matching_precedence','["direct_order_id","exact_order_number","confirmed_allocation","amount_date_customer","owner_manual_match"]','json','Safe order-to-ledger matching precedence.'),
('bookkeeping_review_states','["pending_review","confirmed_employee_error","confirmed_system_error","confirmed_pos_sync_error","confirmed_owner_instruction","external_dependency","excused","corrected","dismissed"]','json','Append-only owner review outcomes.'),
('bookkeeping_variance_tolerance_cents','0','integer','Exact fixed-decimal variance tolerance in cents.')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO epi_employee_grace_periods (grace_key,module,label,minutes,uses_business_time) VALUES
('bookkeeping_cash_entry','Bookkeeping','Cash order entry grace',0,1),
('bookkeeping_deposit_logging','Bookkeeping','Deposit logging grace',0,1),
('bookkeeping_reconciliation','Bookkeeping','Daily reconciliation grace',0,1)
ON DUPLICATE KEY UPDATE module=VALUES(module),label=VALUES(label);

CREATE TABLE IF NOT EXISTS epi_bookkeeping_match_reviews (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, order_id INT NULL, order_number VARCHAR(80) NULL,
 bookkeeping_entry_id INT NULL, match_method VARCHAR(80) NOT NULL, match_confidence VARCHAR(30) NOT NULL,
 match_status VARCHAR(60) NOT NULL DEFAULT 'pending_review', expected_cash_cents BIGINT NOT NULL DEFAULT 0,
 actual_cash_cents BIGINT NOT NULL DEFAULT 0, reviewed_by INT NULL, review_reason TEXT NULL,
 reviewed_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_epi_bk_match_order(order_id,match_status),
 KEY idx_epi_bk_match_entry(bookkeeping_entry_id,match_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epi_bookkeeping_exceptions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, exception_type VARCHAR(80) NOT NULL,
 employee_id INT NULL, related_record_type VARCHAR(60) NOT NULL, related_record_id INT NULL,
 business_date DATE NOT NULL, reason TEXT NOT NULL, evidence_reference VARCHAR(190) NULL,
 added_by INT NOT NULL, approved_by INT NULL, review_decision VARCHAR(60) NOT NULL DEFAULT 'pending_review',
 reviewed_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_epi_bk_exception_record(related_record_type,related_record_id),
 KEY idx_epi_bk_exception_date(business_date,review_decision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
