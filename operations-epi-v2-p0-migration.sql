-- Hambelela Employee Performance Intelligence V2 — P0 foundation.
-- Explicit deployment migration. It must never be executed by a page request.
-- Additive only: legacy evidence, scores and reports remain intact for parallel verification.

INSERT IGNORE INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description)
VALUES
('epi_canonical_system','v2_shadow','enum','EPI V2 is the target canonical system; legacy remains comparison-only during validation.'),
('epi_v2_capture_enabled','0','boolean','Capture V2 ownership and deadline records in shadow mode.'),
('epi_v2_watchdog_enabled','0','boolean','Explicit switch for the scheduled V2 deadline watchdog.'),
('epi_v2_calculation_version','EPI V2 P0 2.0','string','Current V2 foundation calculation contract.');

CREATE TABLE IF NOT EXISTS epi_v2_event_registry (
  event_key VARCHAR(100) NOT NULL PRIMARY KEY,
  module VARCHAR(60) NOT NULL,
  description VARCHAR(500) NOT NULL,
  responsibility_type VARCHAR(40) NOT NULL,
  polarity ENUM('positive','negative','neutral') NOT NULL,
  score_eligible TINYINT(1) NOT NULL DEFAULT 0,
  owner_review_required TINYINT(1) NOT NULL DEFAULT 0,
  severity_handling VARCHAR(40) NOT NULL DEFAULT 'none',
  sla_obligation_key VARCHAR(100) NULL,
  category_key VARCHAR(80) NOT NULL,
  applicable_roles_json LONGTEXT NULL,
  registry_version INT UNSIGNED NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_epi_v2_registry_module (module,active),
  KEY idx_epi_v2_registry_category (category_key,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO epi_v2_event_registry
(event_key,module,description,responsibility_type,polarity,score_eligible,owner_review_required,severity_handling,sla_obligation_key,category_key,applicable_roles_json)
VALUES
('order_created','Orders','Order entered the operational queue.','team_queue','neutral',0,0,'none',NULL,'orders_sla','["front_desk_admin"]'),
('order_acknowledgement_sla_breached','Orders','Order was not acknowledged before its configured deadline.','owner_at_due','negative',1,0,'capped','acknowledge_order','orders_sla','["front_desk_admin"]'),
('order_completion_sla_breached','Orders','Order was not completed before its configured deadline.','owner_at_due','negative',1,0,'capped','complete_order','orders_sla','["front_desk_admin"]'),
('order_new_sla_breached','Orders','Order remained New beyond its configured progression deadline.','owner_at_due','negative',1,0,'capped','move_order_out_of_new','orders_sla','["front_desk_admin"]'),
('order_completed_on_time','Orders','Order completed within the applicable SLA.','owner_at_fulfilment','positive',1,0,'none','complete_order','orders_sla','["front_desk_admin"]'),
('order_completed_late','Orders','Order completed after its deadline.','owner_at_fulfilment','neutral',0,0,'none','complete_order','orders_sla','["front_desk_admin"]'),
('order_completed_after_breach','Orders','Late order was subsequently completed; current risk clears but breach remains.','owner_at_fulfilment','neutral',0,0,'none','complete_order','orders_sla','["front_desk_admin"]'),
('order_reopened','Orders','Completed order was reopened.','responsible_employee','negative',1,1,'capped',NULL,'accuracy_quality','["front_desk_admin","packer"]'),
('payment_corrected','Orders','Recorded payment information required correction.','confirmed_responsible_employee','negative',1,1,'capped',NULL,'bookkeeping_cash','["front_desk_admin"]'),
('customer_followup_breached','Orders','Customer follow-up deadline expired unfulfilled.','owner_at_due','negative',1,0,'capped','customer_followup','customer_communication','["front_desk_admin","marketing_sales_assistant"]'),
('customer_followup_completed','Orders','Customer follow-up completed.','owner_at_fulfilment','positive',1,0,'none','customer_followup','customer_communication','["front_desk_admin","marketing_sales_assistant"]'),
('task_start_sla_breached','Tasks','Assigned task was not started by its start deadline.','owner_at_due','negative',1,0,'capped','start_task','tasks','[]'),
('task_completion_sla_breached','Tasks','Assigned task was not completed by its completion deadline.','owner_at_due','negative',1,0,'capped','complete_task','tasks','[]'),
('packing_quantity_error','Packing','Confirmed under-pack or over-pack incident.','confirmed_responsible_employee','negative',1,1,'capped',NULL,'accuracy_quality','["packer"]'),
('courier_send_sla_breached','Courier','Courier documents were not sent within the Front Desk SLA.','owner_at_due','negative',1,0,'capped','send_courier_documents','courier','["front_desk_admin"]'),
('website_stock_update_breached','Inventory','Website inventory update was not completed by deadline.','owner_at_due','negative',1,0,'capped','update_website_stock','inventory_website','["front_desk_admin","marketing_sales_assistant"]'),
('cash_entry_missing','Bookkeeping','Eligible cash transaction was not entered by deadline.','owner_at_due','negative',1,0,'capped','enter_cash_transaction','bookkeeping_cash','["front_desk_admin"]'),
('cash_variance','Bookkeeping','Reviewed cash reconciliation has a confirmed variance.','confirmed_responsible_employee','negative',1,1,'capped',NULL,'bookkeeping_cash','["front_desk_admin"]'),
('confirmed_employee_error','Error Log','Owner-confirmed quality incident attributed to the responsible employee.','confirmed_responsible_employee','negative',1,0,'capped',NULL,'accuracy_quality','[]'),
('quality_attribution_pending','Error Log','Quality incident lacks confirmed employee responsibility.','unconfirmed','neutral',0,1,'none',NULL,'accuracy_quality','[]'),
('incorrect_customer_information','Error Log','Confirmed incorrect customer information.','confirmed_responsible_employee','negative',1,1,'capped',NULL,'customer_communication','["front_desk_admin","marketing_sales_assistant"]'),
('incorrect_stock_information','Error Log','Confirmed incorrect stock information.','confirmed_responsible_employee','negative',1,1,'capped',NULL,'inventory_website','[]'),
('incorrect_payment_information','Error Log','Confirmed incorrect payment information.','confirmed_responsible_employee','negative',1,1,'capped',NULL,'bookkeeping_cash','["front_desk_admin"]'),
('internal_handover_missed','Tasks','Required handover was not initiated and accepted.','owner_at_due','negative',1,0,'capped','complete_handover','attendance_reliability','[]')
;

CREATE TABLE IF NOT EXISTS epi_v2_ownership_periods (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ownership_uuid CHAR(36) NOT NULL,
  module VARCHAR(60) NOT NULL,
  object_reference VARCHAR(190) NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  role_key VARCHAR(80) NULL,
  ownership_reason VARCHAR(100) NOT NULL,
  effective_from DATETIME NOT NULL,
  effective_to DATETIME NULL,
  assigned_by INT UNSIGNED NOT NULL,
  accepted_by INT UNSIGNED NULL,
  accepted_at DATETIME NULL,
  transfer_reason VARCHAR(255) NULL,
  source VARCHAR(100) NOT NULL,
  shift_id BIGINT UNSIGNED NULL,
  exception_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_epi_v2_ownership_uuid (ownership_uuid),
  KEY idx_epi_v2_owner_at (module,object_reference,effective_from,effective_to),
  KEY idx_epi_v2_owner_employee (employee_id,effective_from,effective_to)
  ,CONSTRAINT chk_epi_v2_owner_interval CHECK (effective_to IS NULL OR effective_to>effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_v2_duty_periods (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  duty_uuid CHAR(36) NOT NULL,
  duty_key VARCHAR(100) NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  responsibility_level ENUM('primary','coverage','secondary') NOT NULL,
  effective_from DATETIME NOT NULL,
  effective_to DATETIME NULL,
  assigned_by INT UNSIGNED NOT NULL,
  accepted_by INT UNSIGNED NULL,
  accepted_at DATETIME NULL,
  source VARCHAR(100) NOT NULL,
  shift_id BIGINT UNSIGNED NULL,
  exception_id BIGINT UNSIGNED NULL,
  superseded_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_epi_v2_duty_uuid (duty_uuid),
  KEY idx_epi_v2_duty_at (duty_key,effective_from,effective_to,responsibility_level),
  KEY idx_epi_v2_duty_employee (employee_id,effective_from,effective_to)
  ,CONSTRAINT chk_epi_v2_duty_interval CHECK (effective_to IS NULL OR effective_to>effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_v2_handovers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  handover_uuid CHAR(36) NOT NULL,
  duty_key VARCHAR(100) NOT NULL,
  outgoing_employee_id INT UNSIGNED NOT NULL,
  incoming_employee_id INT UNSIGNED NOT NULL,
  initiated_at DATETIME NOT NULL,
  initiated_by INT UNSIGNED NOT NULL,
  accepted_at DATETIME NULL,
  accepted_by INT UNSIGNED NULL,
  status ENUM('pending','accepted','cancelled','expired') NOT NULL DEFAULT 'pending',
  transfer_reason VARCHAR(255) NOT NULL,
  open_orders_json LONGTEXT NULL,
  waiting_customers_json LONGTEXT NULL,
  pending_payments_json LONGTEXT NULL,
  pending_courier_actions_json LONGTEXT NULL,
  unresolved_followups_json LONGTEXT NULL,
  customer_promises_json LONGTEXT NULL,
  source VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_epi_v2_handover_uuid (handover_uuid),
  KEY idx_epi_v2_handover_pending (incoming_employee_id,status,initiated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_v2_operational_deadlines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  deadline_uuid CHAR(36) NOT NULL,
  idempotency_key CHAR(64) NOT NULL,
  module VARCHAR(60) NOT NULL,
  object_reference VARCHAR(190) NOT NULL,
  obligation_key VARCHAR(100) NOT NULL,
  breach_event_key VARCHAR(100) NOT NULL,
  responsible_employee_snapshot INT UNSIGNED NULL,
  responsible_team VARCHAR(100) NULL,
  ownership_snapshot_json LONGTEXT NULL,
  source_event VARCHAR(100) NULL,
  starts_at DATETIME NOT NULL,
  due_at DATETIME NOT NULL,
  breach_eligible_at DATETIME NOT NULL,
  policy_snapshot_json LONGTEXT NOT NULL,
  historical_backfill TINYINT NOT NULL DEFAULT 1,
  breach_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  fulfilled_at DATETIME NULL,
  fulfilled_by INT UNSIGNED NULL,
  late_business_minutes DECIMAL(12,2) NULL,
  state ENUM('open','fulfilled','breached','needs_attribution','cancelled','excused') NOT NULL DEFAULT 'open',
  grace_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  exception_id BIGINT UNSIGNED NULL,
  breach_incident_uuid CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_epi_v2_deadline_uuid (deadline_uuid),
  UNIQUE KEY uq_epi_v2_deadline_idempotency (idempotency_key),
  KEY idx_epi_v2_deadline_watchdog (state,breach_eligible_at),
  KEY idx_epi_v2_deadline_team (responsible_team,state,due_at),
  KEY idx_epi_v2_deadline_object (module,object_reference,obligation_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_v2_performance_incidents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  incident_uuid CHAR(36) NOT NULL,
  root_incident_id VARCHAR(190) NOT NULL,
  deadline_uuid CHAR(36) NULL,
  event_key VARCHAR(100) NOT NULL,
  module VARCHAR(60) NOT NULL,
  object_reference VARCHAR(190) NOT NULL,
  responsible_employee_at_breach INT UNSIGNED NULL,
  occurred_at DATETIME NOT NULL,
  due_at DATETIME NULL,
  current_risk_state ENUM('open','resolved','excused') NOT NULL DEFAULT 'open',
  historical_state ENUM('breach','success','neutral') NOT NULL,
  eligibility_state VARCHAR(40) NOT NULL DEFAULT 'pending_rule',
  exclusion_reason VARCHAR(100) NULL,
  evidence_uuid CHAR(36) NULL,
  score_event_uuid CHAR(36) NULL,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  UNIQUE KEY uq_epi_v2_incident_uuid (incident_uuid),
  UNIQUE KEY uq_epi_v2_root (root_incident_id),
  UNIQUE KEY uq_epi_v2_incident_deadline (deadline_uuid),
  KEY idx_epi_v2_incident_employee (responsible_employee_at_breach,occurred_at),
  KEY idx_epi_v2_incident_event (event_key,occurred_at),
  KEY idx_epi_v2_incident_risk (current_risk_state,module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_v2_order_lifecycle_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL,
  object_reference VARCHAR(190) NOT NULL,
  stage_key VARCHAR(60) NOT NULL,
  employee_id INT UNSIGNED NULL,
  fulfilment_mode ENUM('walk_in','collection','windhoek_delivery','courier','showgrounds','other') NOT NULL,
  occurred_at DATETIME NOT NULL,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_epi_v2_order_lifecycle_uuid (event_uuid),
  KEY idx_epi_v2_order_lifecycle (object_reference,occurred_at),
  KEY idx_epi_v2_order_stage (stage_key,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No existing EPI evidence, score event, monthly score or legacy data is mutated.
CREATE TABLE IF NOT EXISTS epi_v2_activation (
 id TINYINT PRIMARY KEY CHECK(id=1), enforcement_start_at DATETIME NOT NULL,
 approved_by INT NOT NULL, approved_at DATETIME NOT NULL, policy_json LONGTEXT NOT NULL,
 mode VARCHAR(20) NOT NULL DEFAULT 'shadow' CHECK(mode='shadow')
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS epi_v2_scope_locks (scope_key CHAR(64) PRIMARY KEY) ENGINE=InnoDB;
CREATE TRIGGER IF NOT EXISTS epi_v2_activation_no_update BEFORE UPDATE ON epi_v2_activation FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='P0 activation is immutable';
CREATE TRIGGER IF NOT EXISTS epi_v2_activation_no_delete BEFORE DELETE ON epi_v2_activation FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='P0 activation is immutable';
CREATE TABLE IF NOT EXISTS epi_v2_ownership_audits (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, scope_key VARCHAR(255) NOT NULL,
 actor_id INT NOT NULL, reason VARCHAR(255) NOT NULL, before_json LONGTEXT, after_json LONGTEXT,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS epi_v2_object_duties (
 module VARCHAR(60) NOT NULL, object_reference VARCHAR(190) NOT NULL, duty_key VARCHAR(100) NOT NULL,
 PRIMARY KEY(module,object_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS epi_v2_exceptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id INT NULL, module VARCHAR(60) NULL,
 object_reference VARCHAR(190) NULL, effective_from DATETIME NOT NULL, effective_to DATETIME NOT NULL,
 approved_at DATETIME NOT NULL, approved_by INT NOT NULL, reason TEXT NOT NULL, source VARCHAR(190) NOT NULL,
 kind VARCHAR(40) NOT NULL, CHECK(effective_to>effective_from),
 KEY idx_epi_v2_exception(employee_id,effective_from,effective_to)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS epi_v2_quality_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, root_incident_id VARCHAR(190) NOT NULL,
 revision_hash CHAR(64) NOT NULL, employee_id INT NULL, eligible TINYINT NOT NULL,
 superseded_at DATETIME NULL, snapshot_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 active_root VARCHAR(190) GENERATED ALWAYS AS (CASE WHEN superseded_at IS NULL THEN root_incident_id ELSE NULL END) STORED,
 UNIQUE KEY uq_epi_v2_quality_current(active_root),
 KEY idx_epi_v2_quality_root(root_incident_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS epi_v2_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_key CHAR(64) NOT NULL UNIQUE,
 entity_type VARCHAR(60) NOT NULL, entity_id BIGINT NOT NULL, action VARCHAR(100) NOT NULL,
 payload_json LONGTEXT NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0,
 last_error TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_epi_v2_outbox_pending(state,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS epi_v2_watchdog_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, started_at DATETIME NOT NULL, finished_at DATETIME NULL,
 status VARCHAR(20) NOT NULL, rows_inspected INT NOT NULL DEFAULT 0, breaches_created INT NOT NULL DEFAULT 0,
 errors INT NOT NULL DEFAULT 0, runtime_ms INT NULL, details_json LONGTEXT NULL
) ENGINE=InnoDB;
