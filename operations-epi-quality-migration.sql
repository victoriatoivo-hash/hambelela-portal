-- EPI Recovery Step 2: additive Error, Quality and Financial Impact structures.
-- Existing ops_error_logs fields remain authoritative for the operational Error Log.
CREATE TABLE IF NOT EXISTS epi_quality_categories (
  id INT AUTO_INCREMENT PRIMARY KEY, category_key VARCHAR(80) NOT NULL,
  category_name VARCHAR(120) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
  display_order INT NOT NULL DEFAULT 0, requires_custom_description TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
  UNIQUE KEY uniq_epi_quality_category (category_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_quality_severities (
  id INT AUTO_INCREMENT PRIMARY KEY, severity_key VARCHAR(40) NOT NULL,
  severity_name VARCHAR(80) NOT NULL, display_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1, metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_epi_quality_severity (severity_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_quality_error_profiles (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, error_id INT NOT NULL,
  logged_by_employee_id INT NULL, logged_for_employee_id INT NULL,
  original_responsible_employee_id INT NULL, current_responsible_employee_id INT NULL,
  assigned_to_employee_id INT NULL, resolved_by_employee_id INT NULL,
  verified_by_employee_id INT NULL, reopened_by_employee_id INT NULL,
  owner_reviewed_by_employee_id INT NULL, department_responsible VARCHAR(120) NULL,
  responsibility_type VARCHAR(40) NOT NULL DEFAULT 'unconfirmed',
  operational_severity VARCHAR(40) NULL, customer_impact_level VARCHAR(40) NULL,
  financial_severity VARCHAR(40) NULL, safety_risk VARCHAR(40) NULL, reputational_risk VARCHAR(40) NULL,
  root_cause_key VARCHAR(80) NULL, root_cause_note TEXT NULL,
  repeat_status VARCHAR(40) NOT NULL DEFAULT 'first_occurrence',
  discovery_at DATETIME NULL, assigned_at DATETIME NULL, resolved_at DATETIME NULL, closed_at DATETIME NULL,
  recording_mode VARCHAR(20) NOT NULL DEFAULT 'automatic', metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
  UNIQUE KEY uniq_epi_quality_error_profile (error_id),
  INDEX idx_epi_quality_responsible (current_responsible_employee_id, responsibility_type),
  INDEX idx_epi_quality_status_dates (resolved_at, closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_quality_status_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, event_uuid CHAR(36) NOT NULL, error_id INT NOT NULL,
  previous_status VARCHAR(40) NULL, new_status VARCHAR(40) NOT NULL,
  changed_by_employee_id INT NULL, reason TEXT NULL, changed_at DATETIME NOT NULL,
  business_minutes_in_previous_status DECIMAL(12,2) NULL,
  evidence_uuid CHAR(36) NULL, correlation_id VARCHAR(100) NULL,
  recording_mode VARCHAR(20) NOT NULL DEFAULT 'automatic', metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_epi_quality_status_event (event_uuid),
  UNIQUE KEY uniq_epi_quality_status_dedupe (error_id, previous_status, new_status, changed_at, changed_by_employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_quality_owner_reviews (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, review_uuid CHAR(36) NOT NULL, error_id INT NOT NULL,
  reviewer_employee_id INT NOT NULL, decision VARCHAR(60) NOT NULL, reason TEXT NOT NULL,
  supporting_evidence TEXT NULL, responsibility_percentage DECIMAL(5,2) NULL,
  related_root_incident_id INT NULL, reviewed_at DATETIME NOT NULL,
  evidence_uuid CHAR(36) NULL, recording_mode VARCHAR(20) NOT NULL DEFAULT 'automatic',
  metadata_json JSON NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_epi_quality_review (review_uuid), INDEX idx_epi_quality_review_error (error_id, reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_quality_responsibility_allocations (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, allocation_uuid CHAR(36) NOT NULL, error_id INT NOT NULL,
  responsible_type VARCHAR(40) NOT NULL, responsible_employee_id INT NULL,
  responsibility_percentage DECIMAL(5,2) NOT NULL, reason TEXT NOT NULL,
  approved_by_employee_id INT NOT NULL, approved_at DATETIME NOT NULL,
  supersedes_allocation_uuid CHAR(36) NULL, evidence_uuid CHAR(36) NULL,
  recording_mode VARCHAR(20) NOT NULL DEFAULT 'automatic', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_epi_quality_allocation (allocation_uuid), INDEX idx_epi_quality_allocation_error (error_id, approved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_quality_financial_impacts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, impact_uuid CHAR(36) NOT NULL, error_id INT NOT NULL,
  direct_product_cost DECIMAL(14,2) NOT NULL DEFAULT 0, courier_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  refund_amount DECIMAL(14,2) NOT NULL DEFAULT 0, replacement_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  compensation_amount DECIMAL(14,2) NOT NULL DEFAULT 0, labour_rework_estimate DECIMAL(14,2) NOT NULL DEFAULT 0,
  recoverable_amount DECIMAL(14,2) NOT NULL DEFAULT 0, recovered_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_financial_impact DECIMAL(14,2) NOT NULL DEFAULT 0, valuation_status VARCHAR(20) NOT NULL DEFAULT 'estimated',
  currency CHAR(3) NOT NULL DEFAULT 'NAD', confirmed_by_employee_id INT NULL, confirmed_at DATETIME NULL,
  reason TEXT NULL, evidence_uuid CHAR(36) NULL, recording_mode VARCHAR(20) NOT NULL DEFAULT 'automatic',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_epi_quality_impact (impact_uuid), INDEX idx_epi_quality_impact_error (error_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_quality_corrective_actions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, action_uuid CHAR(36) NOT NULL, error_id INT NOT NULL,
  action_type VARCHAR(80) NOT NULL, action_description TEXT NULL, responsible_employee_id INT NULL,
  due_at DATETIME NULL, completed_at DATETIME NULL, status VARCHAR(30) NOT NULL DEFAULT 'open',
  related_task_id INT NULL, verified_by_employee_id INT NULL, evidence_reference TEXT NULL,
  created_by_employee_id INT NOT NULL, recording_mode VARCHAR(20) NOT NULL DEFAULT 'automatic',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
  UNIQUE KEY uniq_epi_quality_corrective (action_uuid), INDEX idx_epi_quality_corrective_error (error_id, status, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_quality_record_links (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, error_id INT NOT NULL, related_module VARCHAR(60) NOT NULL,
  related_record_id BIGINT NULL, related_reference VARCHAR(190) NULL,
  link_method VARCHAR(40) NOT NULL, review_status VARCHAR(30) NOT NULL DEFAULT 'pending_review',
  linked_by_employee_id INT NULL, linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  metadata_json JSON NULL, UNIQUE KEY uniq_epi_quality_link (error_id, related_module, related_record_id, related_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_quality_root_causes (
  id INT AUTO_INCREMENT PRIMARY KEY, cause_key VARCHAR(80) NOT NULL, cause_name VARCHAR(140) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, display_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_epi_quality_root_cause (cause_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_quality_repeat_reviews (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, review_uuid CHAR(36) NOT NULL, error_id INT NOT NULL,
  related_error_id INT NULL, repeat_status VARCHAR(40) NOT NULL, window_days INT NULL,
  reviewed_by_employee_id INT NOT NULL, reason TEXT NOT NULL, reviewed_at DATETIME NOT NULL,
  evidence_uuid CHAR(36) NULL, recording_mode VARCHAR(20) NOT NULL DEFAULT 'automatic',
  UNIQUE KEY uniq_epi_quality_repeat_review (review_uuid), INDEX idx_epi_quality_repeat_error (error_id, reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epi_quality_exceptions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, exception_uuid CHAR(36) NOT NULL, error_id INT NULL,
  exception_type VARCHAR(80) NOT NULL, description TEXT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'open',
  created_by_employee_id INT NULL, reviewed_by_employee_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL, metadata_json JSON NULL, UNIQUE KEY uniq_epi_quality_exception (exception_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO epi_quality_severities (severity_key,severity_name,display_order) VALUES
('low','Low',10),('medium','Medium',20),('high','High',30),('critical','Critical',40);
INSERT IGNORE INTO epi_quality_categories (category_key,category_name,display_order,requires_custom_description) VALUES
('wrong_product_packed','Wrong Product Packed',10,0),('missing_product','Missing Product',20,0),
('wrong_quantity','Wrong Quantity',30,0),('wrong_variation','Wrong Variation',40,0),('wrong_label','Wrong Label',50,0),
('damaged_product','Damaged Product',60,0),('leaking_product','Leaking Product',70,0),('product_quality_problem','Product Quality Problem',80,0),
('customer_complaint','Customer Complaint',90,0),('late_order','Late Order',100,0),('late_courier','Late Courier',110,0),
('incorrect_waybill','Incorrect Waybill',120,0),('payment_error','Payment Error',130,0),('bookkeeping_error','Bookkeeping Error',140,0),
('website_error','Website Error',150,0),('inventory_error','Inventory Error',160,0),('task_compliance_error','Task Compliance Error',170,0),
('process_not_followed','Process Not Followed',180,0),('communication_error','Communication Error',190,0),
('delivery_driver_error','Delivery Driver Error',200,0),('supplier_error','Supplier Error',210,0),
('business_error','Business Error',220,0),('system_error','System Error',230,0),('other','Other',999,1);

INSERT IGNORE INTO epi_quality_root_causes (cause_key,cause_name,display_order) VALUES
('checklist_not_followed','Checklist not followed',10),('order_not_read','Order not read properly',20),
('wrong_item_selected','Wrong item selected',30),('wrong_quantity_counted','Wrong quantity counted',40),
('label_not_checked','Label not checked',50),('rushed_work','Rushed work',60),('training_gap','Training gap',70),
('communication_failure','Communication failure',80),('system_issue','System issue',90),
('incorrect_information','Incorrect information',100),('inventory_mismatch','Inventory mismatch',110),
('courier_issue','Courier issue',120),('supplier_issue','Supplier issue',130),('process_unclear','Process unclear',140),
('owner_instruction','Owner instruction',150),('unknown','Unknown',999);
