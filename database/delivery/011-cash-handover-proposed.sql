-- SECTION 11 PROPOSAL ONLY. No production migration without review/approval.
-- Reuses delivery_receipts (physical collection), delivery_requests (replay),
-- delivery_events (custody adapter), notifications and the separate reconciliation.
-- NAD integer cents, UTC timestamps. No cascading financial-history deletion.
CREATE TABLE delivery_cash_handovers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 driver_id INT NOT NULL,
 state ENUM('awaiting_front_desk_receipt','disputed','rejected','confirmed','reversed') NOT NULL,
 expected_minor BIGINT NOT NULL,
 declared_minor BIGINT NOT NULL,
 note VARCHAR(2000) NOT NULL,
 correction_of BIGINT UNSIGNED NULL,
 counted_minor BIGINT NULL,
 receiver_id INT NULL,
 received_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(driver_id) REFERENCES ops_employees(id),
 FOREIGN KEY(receiver_id) REFERENCES ops_employees(id),
 FOREIGN KEY(correction_of) REFERENCES delivery_cash_handovers(id),
 CHECK(expected_minor>0 AND declared_minor>0 AND declared_minor<=9999999999),
 CHECK(counted_minor IS NULL OR (counted_minor>=0 AND counted_minor<=9999999999)),
 KEY driver_state(driver_id,state,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE delivery_cash_allocations (
 handover_id BIGINT UNSIGNED NOT NULL,
 receipt_id BIGINT UNSIGNED NOT NULL,
 allocated_minor BIGINT NOT NULL,
 PRIMARY KEY(handover_id,receipt_id),
 FOREIGN KEY(handover_id) REFERENCES delivery_cash_handovers(id),
 FOREIGN KEY(receipt_id) REFERENCES delivery_receipts(id),
 CHECK(allocated_minor>0),
 KEY receipt_allocation(receipt_id,handover_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE delivery_cash_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 handover_id BIGINT UNSIGNED NOT NULL,
 actor_id INT NOT NULL,
 action VARCHAR(40) NOT NULL,
 payload_json JSON NOT NULL,
 previous_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 event_hash CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(handover_id) REFERENCES delivery_cash_handovers(id),
 FOREIGN KEY(actor_id) REFERENCES ops_employees(id),
 KEY handover_audit(handover_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
