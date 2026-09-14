CREATE TABLE IF NOT EXISTS ops_report_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE ops_employees
  ADD COLUMN IF NOT EXISTS monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER status;

CREATE TABLE IF NOT EXISTS employee_user_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  portal_user_id INT NOT NULL,
  hr_employee_id INT NOT NULL,
  role VARCHAR(120) NULL,
  linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  linked_by INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_employee_user_link (portal_user_id),
  INDEX idx_hr_employee_link (hr_employee_id),
  FOREIGN KEY (portal_user_id) REFERENCES ops_employees(id) ON DELETE CASCADE,
  FOREIGN KEY (linked_by) REFERENCES ops_employees(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS kpi_status_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  module_key VARCHAR(80) NOT NULL,
  record_id BIGINT NOT NULL,
  old_status VARCHAR(120) NULL,
  new_status VARCHAR(120) NULL,
  changed_by_user_id INT NULL,
  linked_hr_employee_id INT NULL,
  assigned_employee_id INT NULL,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  business_time_elapsed DECIMAL(10,2) NULL,
  notes TEXT NULL,
  INDEX idx_kpi_status_module_record (module_key, record_id, timestamp),
  INDEX idx_kpi_status_user (changed_by_user_id, timestamp),
  INDEX idx_kpi_status_assigned (assigned_employee_id, timestamp),
  FOREIGN KEY (changed_by_user_id) REFERENCES ops_employees(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_employee_id) REFERENCES ops_employees(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS kpi_business_time_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  module_key VARCHAR(80) NOT NULL,
  record_id BIGINT NOT NULL,
  from_status VARCHAR(120) NULL,
  to_status VARCHAR(120) NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  real_minutes DECIMAL(10,2) NULL,
  business_minutes DECIMAL(10,2) NULL,
  employee_id INT NULL,
  hr_employee_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_business_time_module_record (module_key, record_id),
  INDEX idx_business_time_employee (employee_id, started_at)
);

CREATE TABLE IF NOT EXISTS kpi_employee_scores (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  period_month CHAR(7) NOT NULL,
  portal_user_id INT NOT NULL,
  hr_employee_id INT NULL,
  role_group VARCHAR(40) NOT NULL,
  total_score DECIMAL(5,2) NOT NULL DEFAULT 0,
  score_band VARCHAR(80) NULL,
  score_payload JSON NULL,
  calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_kpi_employee_score (period_month, portal_user_id),
  INDEX idx_kpi_employee_score_hr (hr_employee_id, period_month),
  FOREIGN KEY (portal_user_id) REFERENCES ops_employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS kpi_score_weights (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_group VARCHAR(40) NOT NULL,
  component_key VARCHAR(80) NOT NULL,
  component_label VARCHAR(160) NOT NULL,
  weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_kpi_score_weight (role_group, component_key)
);

CREATE TABLE IF NOT EXISTS kpi_module_metrics (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  period_month CHAR(7) NOT NULL,
  module_key VARCHAR(80) NOT NULL,
  portal_user_id INT NULL,
  hr_employee_id INT NULL,
  metric_key VARCHAR(120) NOT NULL,
  metric_value DECIMAL(12,2) NOT NULL DEFAULT 0,
  metric_payload JSON NULL,
  calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kpi_module_metric (period_month, module_key, metric_key),
  INDEX idx_kpi_module_employee (portal_user_id, period_month)
);

CREATE TABLE IF NOT EXISTS kpi_bonus_reviews (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  period_month CHAR(7) NOT NULL,
  portal_user_id INT NOT NULL,
  hr_employee_id INT NULL,
  salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  monthly_score DECIMAL(5,2) NOT NULL DEFAULT 0,
  quarterly_score DECIMAL(5,2) NOT NULL DEFAULT 0,
  bonus_eligibility VARCHAR(80) NULL,
  increment_recommendation VARCHAR(160) NULL,
  owner_decision VARCHAR(80) NULL,
  notes TEXT NULL,
  reviewed_by INT NULL,
  reviewed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_kpi_bonus_review (period_month, portal_user_id),
  FOREIGN KEY (portal_user_id) REFERENCES ops_employees(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES ops_employees(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS ops_kpi_employee_inputs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  period_month CHAR(7) NOT NULL,
  monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  attendance_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  reliability_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  compliance_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  team_contribution_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  communication_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  admin_accuracy_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  dispatch_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  operational_accuracy_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  notes TEXT,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_kpi_employee_period (employee_id, period_month),
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES ops_employees(id)
);

ALTER TABLE ops_kpi_employee_inputs
  ADD COLUMN IF NOT EXISTS communication_score DECIMAL(5,2) NOT NULL DEFAULT 85 AFTER team_contribution_score;

CREATE TABLE IF NOT EXISTS ops_kpi_status_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  module_key VARCHAR(80) NOT NULL,
  record_id BIGINT NOT NULL,
  old_status VARCHAR(120) NULL,
  new_status VARCHAR(120) NULL,
  changed_by INT NULL,
  assigned_employee_id INT NULL,
  metadata JSON NULL,
  occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kpi_history_module_record (module_key, record_id, occurred_at),
  INDEX idx_kpi_history_employee (assigned_employee_id, occurred_at),
  INDEX idx_kpi_history_changed_by (changed_by, occurred_at),
  FOREIGN KEY (changed_by) REFERENCES ops_employees(id),
  FOREIGN KEY (assigned_employee_id) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_kpi_role_weights (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_group VARCHAR(40) NOT NULL,
  component_key VARCHAR(80) NOT NULL,
  component_label VARCHAR(160) NOT NULL,
  weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_kpi_role_component (role_group, component_key)
);

CREATE TABLE IF NOT EXISTS ops_kpi_rewards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reward_name VARCHAR(160) NOT NULL,
  reward_value DECIMAL(12,2) NOT NULL DEFAULT 0,
  reward_type VARCHAR(80) NOT NULL DEFAULT 'recognition',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ops_order_stage_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  stage_key VARCHAR(80) NOT NULL,
  employee_id INT NULL,
  metadata JSON NULL,
  occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_stage_order (order_id, stage_key),
  INDEX idx_stage_employee (employee_id, occurred_at),
  FOREIGN KEY (order_id) REFERENCES ops_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id)
);

INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'orders', 'Order / walk-in completion', 20
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'front_desk' AND component_key = 'orders');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'bookkeeping', 'Bookkeeping accuracy', 20
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'front_desk' AND component_key = 'bookkeeping');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'website_stock', 'Website stock upload', 15
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'front_desk' AND component_key = 'website_stock');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'tasks', 'Task completion', 15
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'front_desk' AND component_key = 'tasks');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'errors', 'Error score', 15
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'front_desk' AND component_key = 'errors');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'communication', 'Communication / manual assessment', 10
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'front_desk' AND component_key = 'communication');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'reliability', 'Reliability / attendance', 5
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'front_desk' AND component_key = 'reliability');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'order_speed', 'Order packing speed', 20
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'packer' AND component_key = 'order_speed');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'packing_productivity', 'Packing list productivity', 25
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'packer' AND component_key = 'packing_productivity');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'packing_accuracy', 'Packing accuracy', 20
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'packer' AND component_key = 'packing_accuracy');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'tasks', 'Task / cleaning compliance', 15
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'packer' AND component_key = 'tasks');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'errors', 'Error score', 15
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'packer' AND component_key = 'errors');
INSERT INTO ops_kpi_role_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'team', 'Team contribution / manual', 5
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_role_weights WHERE role_group = 'packer' AND component_key = 'team');

INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'orders', 'Order / walk-in completion', 20
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'front_desk' AND component_key = 'orders');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'bookkeeping', 'Bookkeeping accuracy', 20
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'front_desk' AND component_key = 'bookkeeping');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'website_stock', 'Website stock upload', 15
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'front_desk' AND component_key = 'website_stock');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'tasks', 'Task completion', 15
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'front_desk' AND component_key = 'tasks');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'errors', 'Error score', 15
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'front_desk' AND component_key = 'errors');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'communication', 'Communication / manual assessment', 10
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'front_desk' AND component_key = 'communication');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'front_desk', 'reliability', 'Reliability / attendance', 5
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'front_desk' AND component_key = 'reliability');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'order_speed', 'Order packing speed', 20
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'packer' AND component_key = 'order_speed');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'packing_productivity', 'Packing list productivity', 25
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'packer' AND component_key = 'packing_productivity');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'packing_accuracy', 'Packing accuracy', 20
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'packer' AND component_key = 'packing_accuracy');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'tasks', 'Task / cleaning compliance', 15
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'packer' AND component_key = 'tasks');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'errors', 'Error score', 15
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'packer' AND component_key = 'errors');
INSERT INTO kpi_score_weights (role_group, component_key, component_label, weight_percent)
SELECT 'packer', 'team', 'Team contribution / manual', 5
WHERE NOT EXISTS (SELECT 1 FROM kpi_score_weights WHERE role_group = 'packer' AND component_key = 'team');

INSERT INTO ops_kpi_rewards (reward_name, reward_value, reward_type)
SELECT 'Driving lesson sponsorship', 800.00, 'development'
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_rewards WHERE reward_name = 'Driving lesson sponsorship');

INSERT INTO ops_kpi_rewards (reward_name, reward_value, reward_type)
SELECT 'Employee of the Month', 0.00, 'recognition'
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_rewards WHERE reward_name = 'Employee of the Month');

INSERT INTO ops_kpi_rewards (reward_name, reward_value, reward_type)
SELECT 'Gift voucher', 0.00, 'voucher'
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_rewards WHERE reward_name = 'Gift voucher');

INSERT INTO ops_kpi_rewards (reward_name, reward_value, reward_type)
SELECT 'Additional cash reward', 0.00, 'cash'
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_rewards WHERE reward_name = 'Additional cash reward');

INSERT INTO ops_kpi_rewards (reward_name, reward_value, reward_type)
SELECT 'Performance certificate', 0.00, 'recognition'
WHERE NOT EXISTS (SELECT 1 FROM ops_kpi_rewards WHERE reward_name = 'Performance certificate');
