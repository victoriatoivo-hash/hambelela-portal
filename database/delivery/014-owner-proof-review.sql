-- Phase A02. Apply explicitly during an approved release; never from a page load.
-- Rollback before use: DROP TABLE delivery_owner_proof_actions;
-- After use: retain/export audit rows; roll back application code only.
CREATE TABLE delivery_owner_proof_actions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 delivery_id BIGINT UNSIGNED NOT NULL,
 employee_id BIGINT UNSIGNED NOT NULL,
 request_id CHAR(36) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 evidence_version CHAR(64) NOT NULL,
 action_type VARCHAR(24) NOT NULL,
 reason VARCHAR(60) NULL,
 body TEXT NOT NULL,
 related_action_id BIGINT UNSIGNED NULL,
 attachment_json TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY owner_proof_request (employee_id,request_id),
 KEY owner_proof_delivery (delivery_id,id),
 KEY owner_proof_rate (employee_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
