-- Reconciliation metadata only. Original payments and accounting ledgers remain unchanged.
CREATE TABLE IF NOT EXISTS accounts_payment_evidence (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source_key CHAR(64) NOT NULL UNIQUE,
 source_type VARCHAR(32) NOT NULL,
 source_reference VARCHAR(190) NOT NULL,
 transaction_at DATETIME NOT NULL,
 amount_cents BIGINT NOT NULL,
 method_code VARCHAR(30) NOT NULL,
 settlement_state VARCHAR(32) NOT NULL,
 metadata_json LONGTEXT NOT NULL,
 imported_by INT NOT NULL,
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX evidence_date(transaction_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS accounts_payment_matches (
 allocation_id BIGINT UNSIGNED PRIMARY KEY,
 evidence_key VARCHAR(100) NOT NULL UNIQUE,
 allocation_fingerprint CHAR(64) NOT NULL,
 evidence_fingerprint CHAR(64) NOT NULL,
 status VARCHAR(20) NOT NULL,
 note TEXT NULL,
 matched_by INT NOT NULL,
 confirmed_by INT NULL,
 confirmed_at DATETIME NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS accounts_payment_review_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id INT NOT NULL,
 allocation_id BIGINT UNSIGNED NULL,
 action VARCHAR(40) NOT NULL,
 employee_id INT NOT NULL,
 evidence_key VARCHAR(100) NULL,
 details_json LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX audit_order(order_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
