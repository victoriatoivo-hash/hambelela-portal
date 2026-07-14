CREATE TABLE IF NOT EXISTS ops_roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ops_employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) UNIQUE,
  phone VARCHAR(60),
  password_hash VARCHAR(255),
  failed_login_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_failed_login_at DATETIME NULL,
  requires_code_reset TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  packing_assignable TINYINT(1) NOT NULL DEFAULT 0,
  packing_auto_assignable TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES ops_roles(id)
);

CREATE TABLE IF NOT EXISTS ops_login_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NULL,
  identity_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(80) NOT NULL,
  successful TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ops_login_attempts_ip_time (ip_address, attempted_at),
  INDEX idx_ops_login_attempts_employee_time (employee_id, attempted_at)
);

CREATE TABLE IF NOT EXISTS ops_security_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(80) NOT NULL,
  employee_id INT NULL,
  ip_address VARCHAR(80) NULL,
  user_agent VARCHAR(255) NULL,
  metadata_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ops_security_events_type_time (event_type, created_at)
);

CREATE TABLE IF NOT EXISTS ops_security_migrations (
  migration_key VARCHAR(120) PRIMARY KEY,
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ops_employee_availability (
  employee_id INT PRIMARY KEY,
  availability_status ENUM('available', 'on_lunch', 'offline') NOT NULL DEFAULT 'available',
  unavailable_until DATETIME NULL,
  note VARCHAR(255),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ops_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  permission_key VARCHAR(90) NOT NULL UNIQUE,
  description VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS ops_role_permissions (
  role_id INT NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES ops_roles(id),
  FOREIGN KEY (permission_id) REFERENCES ops_permissions(id)
);

CREATE TABLE IF NOT EXISTS ops_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  woo_order_id BIGINT UNSIGNED NULL,
  order_number VARCHAR(80) NOT NULL UNIQUE,
  customer_name VARCHAR(190) NOT NULL,
  customer_contact VARCHAR(80),
  payment_method VARCHAR(80),
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  product_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  shipping_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  shipping_tax_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  refund_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_status ENUM('unpaid', 'partial', 'paid', 'refunded') NOT NULL DEFAULT 'unpaid',
  order_type ENUM('collection', 'courier', 'delivery') NOT NULL DEFAULT 'collection',
  priority ENUM('normal', 'urgent', 'same_day') NOT NULL DEFAULT 'normal',
  complexity TINYINT UNSIGNED NOT NULL DEFAULT 1,
  assigned_packer_id INT NULL,
  assigned_at DATETIME NULL,
  assigned_verifier_id INT NULL,
  status ENUM('new_order', 'assigned', 'in_progress', 'packed', 'verified', 'ready_for_collection', 'ready_for_courier', 'ready_for_delivery', 'completed', 'error_logged', 'correction_required') NOT NULL DEFAULT 'new_order',
  notes TEXT,
  workload_score DECIMAL(10,2) NOT NULL DEFAULT 0,
  packing_started_at DATETIME NULL,
  packed_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ops_woo_order (woo_order_id),
  FOREIGN KEY (assigned_packer_id) REFERENCES ops_employees(id),
  FOREIGN KEY (assigned_verifier_id) REFERENCES ops_employees(id),
  FOREIGN KEY (created_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  woo_order_line_id BIGINT UNSIGNED NULL,
  woo_product_id BIGINT UNSIGNED NULL,
  woo_variation_id BIGINT UNSIGNED NULL,
  product_id INT NULL,
  product_name VARCHAR(190) NOT NULL,
  sku VARCHAR(80),
  barcode VARCHAR(120),
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  packed_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  status ENUM('pending', 'packed', 'verified', 'correction_required') NOT NULL DEFAULT 'pending',
  packed_by INT NULL,
  packed_at DATETIME NULL,
  UNIQUE KEY uniq_ops_order_woo_line (order_id, woo_order_line_id),
  FOREIGN KEY (order_id) REFERENCES ops_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (packed_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_barcode_scans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NULL,
  order_item_id INT NULL,
  employee_id INT NULL,
  expected_barcode VARCHAR(120),
  scanned_barcode VARCHAR(120) NOT NULL,
  result ENUM('matched', 'mismatch', 'unknown') NOT NULL,
  message VARCHAR(255),
  scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES ops_orders(id),
  FOREIGN KEY (order_item_id) REFERENCES ops_order_items(id),
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_checklist_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  checklist_type ENUM('opening', 'midday', 'closing', 'cleaning', 'saturday', 'stock_refill') NOT NULL,
  task_name VARCHAR(190) NOT NULL,
  default_deadline TIME NULL,
  requires_approval TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS ops_checklist_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_id INT NULL,
  checklist_type ENUM('opening', 'midday', 'closing', 'cleaning', 'saturday', 'stock_refill') NOT NULL,
  task_name VARCHAR(190) NOT NULL,
  assigned_employee_id INT NULL,
  deadline DATETIME NULL,
  status ENUM('pending', 'in_progress', 'completed', 'missed', 'approved') NOT NULL DEFAULT 'pending',
  notes TEXT,
  photo_path VARCHAR(255),
  completed_at DATETIME NULL,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (template_id) REFERENCES ops_checklist_templates(id),
  FOREIGN KEY (assigned_employee_id) REFERENCES ops_employees(id),
  FOREIGN KEY (approved_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_error_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NULL,
  order_id INT NULL,
  category ENUM('wrong_product_packed', 'wrong_quantity_packed', 'incorrect_pouch_used', 'product_not_labelled_correctly', 'stock_not_updated', 'dirty_workstation', 'checklist_not_completed', 'order_delayed', 'courier_issue', 'customer_complaint', 'petty_cash_discrepancy', 'incorrect_formulation', 'damaged_stock', 'poor_communication') NOT NULL,
  severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'low',
  description TEXT NOT NULL,
  customer_impact TEXT,
  financial_impact DECIMAL(12,2) NOT NULL DEFAULT 0,
  resolution TEXT,
  repeat_issue TINYINT(1) NOT NULL DEFAULT 0,
  logged_by INT NULL,
  logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id),
  FOREIGN KEY (order_id) REFERENCES ops_orders(id),
  FOREIGN KEY (logged_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_consignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_name VARCHAR(190) NOT NULL,
  supplier_name VARCHAR(190),
  total_weight_kg DECIMAL(12,3) NOT NULL DEFAULT 0,
  expected_breakdown TEXT,
  date_received DATE NOT NULL,
  notes TEXT,
  status ENUM('planned', 'assigned', 'in_progress', 'completed', 'discrepancy') NOT NULL DEFAULT 'planned',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ops_consignment_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  consignment_id INT NOT NULL,
  assigned_employee_id INT NULL,
  packaging_size VARCHAR(60) NOT NULL,
  estimated_quantity INT NOT NULL DEFAULT 0,
  assigned_quantity INT NOT NULL DEFAULT 0,
  actual_packed_quantity INT NOT NULL DEFAULT 0,
  shortfall INT NOT NULL DEFAULT 0,
  excess_quantity INT NOT NULL DEFAULT 0,
  damaged_quantity INT NOT NULL DEFAULT 0,
  workload_points DECIMAL(10,2) NOT NULL DEFAULT 0,
  difference_reason VARCHAR(255),
  notes TEXT,
  status ENUM('assigned', 'in_progress', 'completed', 'discrepancy') NOT NULL DEFAULT 'assigned',
  completed_at DATETIME NULL,
  FOREIGN KEY (consignment_id) REFERENCES ops_consignments(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_employee_id) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_packing_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  consignment_id INT NULL,
  item_name VARCHAR(190) NOT NULL,
  priority ENUM('top_critical', 'high', 'medium', 'low') NOT NULL DEFAULT 'medium',
  date_loaded DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  quantity_planned VARCHAR(190) NOT NULL,
  assigned_employee_id INT NULL,
  quantity_packed VARCHAR(190),
  date_completed DATETIME NULL,
  website_uploaded TINYINT(1) NOT NULL DEFAULT 0,
  packing_status VARCHAR(80) NOT NULL DEFAULT 'not_started',
  notes TEXT,
  workload_points DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (consignment_id) REFERENCES ops_consignments(id),
  FOREIGN KEY (assigned_employee_id) REFERENCES ops_employees(id),
  FOREIGN KEY (created_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_inventory_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_name VARCHAR(190) NOT NULL,
  sku VARCHAR(80) UNIQUE,
  barcode VARCHAR(120) UNIQUE,
  available_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  reserved_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  packed_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  low_stock_threshold DECIMAL(12,3) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ops_inventory_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  inventory_item_id INT NOT NULL,
  source_type ENUM('customer_order', 'stock_packing', 'manual_adjustment', 'discrepancy', 'return_correction') NOT NULL,
  source_id INT NULL,
  movement_type ENUM('in', 'out', 'reserve', 'release', 'adjust') NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  reason VARCHAR(255),
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (inventory_item_id) REFERENCES ops_inventory_items(id),
  FOREIGN KEY (created_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_supplier_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_name VARCHAR(190),
  items_needed TEXT NOT NULL,
  estimated_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  reason VARCHAR(255),
  quote_path VARCHAR(255),
  approval_status ENUM('draft', 'requested', 'approved', 'rejected', 'paid', 'delivered') NOT NULL DEFAULT 'draft',
  proof_of_payment_path VARCHAR(255),
  receipt_path VARCHAR(255),
  delivery_confirmed_at DATETIME NULL,
  requested_by INT NULL,
  approved_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (requested_by) REFERENCES ops_employees(id),
  FOREIGN KEY (approved_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_petty_cash_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entry_type ENUM('cash_received', 'cash_paid_out', 'reconciliation') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  proof_path VARCHAR(255),
  running_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  variance DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  context_type ENUM('task', 'order', 'error', 'announcement', 'stock_issue', 'checklist_note') NOT NULL,
  context_id INT NULL,
  title VARCHAR(190) NOT NULL,
  message TEXT NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_activity_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80),
  entity_id INT NULL,
  metadata JSON,
  ip_address VARCHAR(64),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id)
);

CREATE TABLE IF NOT EXISTS ops_board_presence (
  employee_id INT PRIMARY KEY,
  page VARCHAR(120) NOT NULL DEFAULT 'orders_board',
  last_seen_at DATETIME NOT NULL,
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id) ON DELETE CASCADE
);

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

INSERT IGNORE INTO ops_roles (role_key, name, description) VALUES
('owner_admin', 'Owner/Admin', 'Full access to operations, reports, financial tracking and settings'),
('front_desk_admin', 'Front Desk/Admin Employee', 'Customer orders, delivery coordination, supplier requests, petty cash and errors'),
('packer', 'Packer/Production Staff', 'Assigned packing tasks, barcode screens, checklists and own performance'),
('supervisor_manager', 'Supervisor/Manager', 'Future operations supervision and approval role');

INSERT IGNORE INTO ops_permissions (permission_key, description) VALUES
('orders.manage', 'Create and manage customer orders'),
('orders.pack', 'Pack assigned orders'),
('orders.verify', 'Verify packed orders'),
('checklists.manage', 'Create and assign checklist tasks'),
('checklists.complete', 'Complete own checklist tasks'),
('errors.log', 'Log operational errors'),
('reports.view', 'View KPI and operational reports'),
('inventory.manage', 'Manage inventory and adjustments'),
('cash.manage', 'Manage petty cash and supplier requests'),
('settings.manage', 'Manage roles, users and system settings');

INSERT IGNORE INTO ops_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM ops_roles r CROSS JOIN ops_permissions p
WHERE r.role_key = 'owner_admin';

INSERT IGNORE INTO ops_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM ops_roles r CROSS JOIN ops_permissions p
WHERE r.role_key = 'front_desk_admin'
  AND p.permission_key IN ('orders.manage', 'checklists.complete', 'errors.log', 'cash.manage');

INSERT IGNORE INTO ops_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM ops_roles r CROSS JOIN ops_permissions p
WHERE r.role_key = 'packer'
  AND p.permission_key IN ('orders.pack', 'checklists.complete');

INSERT IGNORE INTO ops_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM ops_roles r CROSS JOIN ops_permissions p
WHERE r.role_key = 'supervisor_manager'
  AND p.permission_key IN ('orders.manage', 'orders.verify', 'checklists.manage', 'checklists.complete', 'errors.log', 'reports.view', 'inventory.manage');
