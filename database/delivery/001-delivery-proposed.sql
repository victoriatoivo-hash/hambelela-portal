-- DELIVERY ONLY / PROPOSAL / NOT APPLIED
-- Requires approval and live SHOW CREATE TABLE validation before execution.
-- All amounts are NAD integer cents; all timestamps UTC.
-- No existing tables altered; no employees, roles, grants, rates or partners seeded.
-- MySQL 8.0.16+ or compatible MariaDB with enforced CHECK constraints required.

CREATE TABLE delivery_partners (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL,
 code VARCHAR(30) NOT NULL UNIQUE,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_by_employee_id INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (created_by_employee_id) REFERENCES ops_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Partner identity is not a fake HR employee. Separate session namespace/login.
CREATE TABLE delivery_partner_users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 partner_id BIGINT UNSIGNED NOT NULL,
 display_name VARCHAR(190) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 0,
 failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
 locked_until DATETIME NULL,
 session_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (partner_id) REFERENCES delivery_partners(id),
 KEY idx_delivery_partner_user (partner_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_driver_profiles (
 employee_id INT PRIMARY KEY,
 active TINYINT(1) NOT NULL DEFAULT 0,
 verified_by_employee_id INT NOT NULL,
 verified_at DATETIME NOT NULL,
 FOREIGN KEY (employee_id) REFERENCES ops_employees(id),
 FOREIGN KEY (verified_by_employee_id) REFERENCES ops_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Explicit per-employee grants supplement existing role permissions.
CREATE TABLE delivery_employee_grants (
 employee_id INT NOT NULL,
 permission_id INT NOT NULL,
 granted_by_employee_id INT NOT NULL,
 granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (employee_id,permission_id),
 FOREIGN KEY (employee_id) REFERENCES ops_employees(id),
 FOREIGN KEY (permission_id) REFERENCES ops_permissions(id),
 FOREIGN KEY (granted_by_employee_id) REFERENCES ops_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_zones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 area VARCHAR(190) NOT NULL UNIQUE,
 aliases_json JSON NULL,
 fee_cents BIGINT NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 updated_by_employee_id INT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK (fee_cents >= 0),
 FOREIGN KEY (updated_by_employee_id) REFERENCES ops_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 public_reference VARCHAR(80) NOT NULL UNIQUE,
 source ENUM('hambelela','partner') NOT NULL,
 order_id INT NULL,
 partner_id BIGINT UNSIGNED NULL,
 partner_user_id BIGINT UNSIGNED NULL,
 partner_reference VARCHAR(190) NULL,
 customer_name VARCHAR(190) NOT NULL,
 customer_mobile VARCHAR(80) NOT NULL,
 address TEXT NOT NULL,
 area VARCHAR(190) NOT NULL,
 zone_id BIGINT UNSIGNED NULL,
 latitude DECIMAL(10,7) NULL,
 longitude DECIMAL(10,7) NULL,
 fee_cents BIGINT NOT NULL,
 fee_payer ENUM('customer','partner','already_paid') NOT NULL,
 fee_order_component_cents BIGINT NOT NULL DEFAULT 0,
 prepaid_reference VARCHAR(190) NULL,
 manual_rate_reason VARCHAR(500) NULL,
 order_due_snapshot_cents BIGINT NOT NULL DEFAULT 0,
 partner_cod_due_cents BIGINT NOT NULL DEFAULT 0,
 scheduled_date DATE NOT NULL,
 scheduled_window VARCHAR(80) NULL,
 urgent TINYINT(1) NOT NULL DEFAULT 0,
 status ENUM('ready','out_for_delivery','delivered','awaiting_reconciliation','completed','failed','cancelled') NOT NULL DEFAULT 'ready',
 driver_employee_id INT NULL,
 arranged_by_employee_id INT NULL,
 notes TEXT NULL,
 additional_delivery_reason VARCHAR(500) NULL,
 additional_delivery TINYINT(1) NOT NULL DEFAULT 0,
 active_primary_order_id INT GENERATED ALWAYS AS
   (CASE WHEN source='hambelela' AND additional_delivery=0 AND status NOT IN ('completed','cancelled') THEN order_id ELSE NULL END) STORED,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 started_at DATETIME NULL,
 delivered_at DATETIME NULL,
 completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_delivery_active_order (active_primary_order_id),
 KEY idx_delivery_driver_queue (driver_employee_id,scheduled_date,status,urgent,id),
 KEY idx_delivery_board (scheduled_date,status,id),
 KEY idx_delivery_partner_scope (partner_id,partner_user_id,status,id),
 FOREIGN KEY (order_id) REFERENCES ops_orders(id),
 FOREIGN KEY (partner_id) REFERENCES delivery_partners(id),
 FOREIGN KEY (partner_user_id) REFERENCES delivery_partner_users(id),
 FOREIGN KEY (zone_id) REFERENCES delivery_zones(id),
 FOREIGN KEY (driver_employee_id) REFERENCES delivery_driver_profiles(employee_id),
 FOREIGN KEY (arranged_by_employee_id) REFERENCES ops_employees(id),
 CHECK ((source='hambelela' AND order_id IS NOT NULL AND partner_id IS NULL AND partner_user_id IS NULL AND arranged_by_employee_id IS NOT NULL) OR (source='partner' AND order_id IS NULL AND partner_id IS NOT NULL AND partner_user_id IS NOT NULL)),
 CHECK (fee_cents >= 0 AND order_due_snapshot_cents >= 0 AND partner_cod_due_cents >= 0),
 CHECK (fee_order_component_cents >= 0 AND fee_order_component_cents <= fee_cents),
 CHECK (source='partner' OR partner_cod_due_cents=0),
 CHECK (source='hambelela' OR (order_due_snapshot_cents=0 AND fee_order_component_cents=0)),
 CHECK (fee_payer<>'partner' OR partner_id IS NOT NULL),
 CHECK (additional_delivery=0 OR (order_id IS NOT NULL AND CHAR_LENGTH(TRIM(additional_delivery_reason))>0)),
 CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
 CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Locks and request results: actor-scoped UUID + body hash, committed with action.
CREATE TABLE delivery_requests (
 actor_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action VARCHAR(60) NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 response_json JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (actor_key,request_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 delivery_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(60) NOT NULL,
 employee_id INT NULL,
 partner_user_id BIGINT UNSIGNED NULL,
 metadata_json JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (delivery_id) REFERENCES delivery_jobs(id),
 FOREIGN KEY (employee_id) REFERENCES ops_employees(id),
 FOREIGN KEY (partner_user_id) REFERENCES delivery_partner_users(id),
 CHECK ((employee_id IS NOT NULL AND partner_user_id IS NULL) OR (employee_id IS NULL AND partner_user_id IS NOT NULL)),
 KEY idx_delivery_timeline (delivery_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_receipts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 delivery_id BIGINT UNSIGNED NOT NULL,
 collection_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 payment_method VARCHAR(30) NOT NULL,
 transaction_reference VARCHAR(190) NULL,
 order_goods_cents BIGINT NOT NULL DEFAULT 0,
 delivery_fee_cents BIGINT NOT NULL DEFAULT 0,
 partner_cod_cents BIGINT NOT NULL DEFAULT 0,
 collected_by_employee_id INT NOT NULL,
 collected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 status ENUM('collected','reconciled','issue','reversed') NOT NULL DEFAULT 'collected',
 reconciled_by_employee_id INT NULL,
 reconciled_at DATETIME NULL,
 issue_reason VARCHAR(500) NULL,
 order_allocation_applied_at DATETIME NULL,
 reversal_reason VARCHAR(500) NULL,
 reversed_by_employee_id INT NULL,
 reversed_at DATETIME NULL,
 FOREIGN KEY (delivery_id) REFERENCES delivery_jobs(id),
 FOREIGN KEY (collected_by_employee_id) REFERENCES ops_employees(id),
 FOREIGN KEY (reconciled_by_employee_id) REFERENCES ops_employees(id),
 FOREIGN KEY (reversed_by_employee_id) REFERENCES ops_employees(id),
 CHECK (order_goods_cents>=0 AND delivery_fee_cents>=0 AND partner_cod_cents>=0 AND order_goods_cents+delivery_fee_cents+partner_cod_cents>0),
 KEY idx_delivery_receipts (delivery_id,status,id),
 KEY idx_delivery_handover (status,collected_by_employee_id,collected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_expenses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 expense_date DATE NOT NULL,
 category VARCHAR(60) NOT NULL,
 amount_cents BIGINT NOT NULL,
 paid TINYINT(1) NOT NULL DEFAULT 0,
 paid_at DATETIME NULL,
 supplier VARCHAR(190) NULL,
 description TEXT NOT NULL,
 created_by_employee_id INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reversed_at DATETIME NULL,
 reversed_by_employee_id INT NULL,
 reversal_reason VARCHAR(500) NULL,
 CHECK (amount_cents>0),
 FOREIGN KEY (created_by_employee_id) REFERENCES ops_employees(id),
 FOREIGN KEY (reversed_by_employee_id) REFERENCES ops_employees(id),
 KEY idx_delivery_expenses (expense_date,paid,reversed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only accounting movements. Separate balances; no automatic netting.
CREATE TABLE delivery_ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 delivery_id BIGINT UNSIGNED NULL,
 receipt_id BIGINT UNSIGNED NULL,
 expense_id BIGINT UNSIGNED NULL,
 partner_id BIGINT UNSIGNED NULL,
 account ENUM('fee_earned','fee_received','expense_paid','order_funds_received','partner_cod_held','partner_cod_remitted') NOT NULL,
 amount_cents BIGINT NOT NULL,
 reference VARCHAR(190) NULL,
 reason VARCHAR(500) NULL,
 reversal_of BIGINT UNSIGNED NULL UNIQUE,
 employee_id INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK (amount_cents<>0),
 FOREIGN KEY (delivery_id) REFERENCES delivery_jobs(id),
 FOREIGN KEY (receipt_id) REFERENCES delivery_receipts(id),
 FOREIGN KEY (expense_id) REFERENCES delivery_expenses(id),
 FOREIGN KEY (partner_id) REFERENCES delivery_partners(id),
 FOREIGN KEY (employee_id) REFERENCES ops_employees(id),
 FOREIGN KEY (reversal_of) REFERENCES delivery_ledger(id),
 KEY idx_delivery_ledger_date (account,created_at),
 KEY idx_delivery_partner_ledger (partner_id,account,id),
 KEY idx_delivery_job_ledger (delivery_id,account,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Worker uses existing notification service; never sends inside DB transaction.
CREATE TABLE delivery_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 delivery_id BIGINT UNSIGNED NOT NULL,
 recipient_employee_id INT NOT NULL,
 payload_json JSON NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 delivered_at DATETIME NULL,
 FOREIGN KEY (delivery_id) REFERENCES delivery_jobs(id),
 FOREIGN KEY (recipient_employee_id) REFERENCES ops_employees(id),
 KEY idx_delivery_outbox_pending (delivered_at,next_attempt_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
