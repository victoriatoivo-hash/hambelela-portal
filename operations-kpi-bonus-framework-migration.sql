CREATE TABLE IF NOT EXISTS ops_report_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
