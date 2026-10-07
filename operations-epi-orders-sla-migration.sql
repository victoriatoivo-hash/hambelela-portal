-- Explicit additive migration; activation is a separate reviewed operation.
CREATE TABLE IF NOT EXISTS epi_v2_orders_policy (
 version VARCHAR(80) PRIMARY KEY, effective_from DATETIME NOT NULL,
 approved_by INT NOT NULL, policy_json LONGTEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TRIGGER IF NOT EXISTS epi_orders_policy_immutable_update BEFORE UPDATE ON epi_v2_orders_policy FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Order policy versions are immutable';
CREATE TRIGGER IF NOT EXISTS epi_orders_policy_immutable_delete BEFORE DELETE ON epi_v2_orders_policy FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Order policy history is immutable';
CREATE TABLE IF NOT EXISTS epi_v2_orders_tracking (
 order_id BIGINT PRIMARY KEY, object_reference VARCHAR(190) NOT NULL,
 classification VARCHAR(40) NOT NULL, classification_source VARCHAR(100) NOT NULL,
 policy_version VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL,
 paid_at DATETIME NULL, packing_started_at DATETIME NULL,
 progressed_at DATETIME NULL, completed_at DATETIME NULL, removed_at DATETIME NULL,
 dispatch_at DATETIME NULL, rush_candidate TINYINT NOT NULL DEFAULT 0,
 source_snapshot_json LONGTEXT NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description)
VALUES('epi_v2_orders_sla_enabled','0','boolean','Enable approved prospective Orders stage deadlines in shadow mode.');
INSERT IGNORE INTO epi_v2_event_registry(event_key,module,description,responsibility_type,polarity,score_eligible,owner_review_required,sla_obligation_key,category_key,applicable_roles_json)
VALUES
('order_packing_sla_breached','Packing','Assigned order not packed within working-hour allowance','owner_at_due','negative',1,0,'pack_order','packing','["packer","packer_production_staff"]'),
('collection_expired','Orders','Unpaid collection reached customer collection allowance','external_dependency','neutral',0,0,'collection_expiry','orders_sla','[]'),
('courier_payment_wait_expired','Orders','Courier still waiting for payment','external_dependency','neutral',0,0,'courier_payment_wait','orders_sla','[]');
