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
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES ops_roles(id)
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
  invoice_number VARCHAR(100) NULL,
  invoice_date DATE NULL,
  supplier_name VARCHAR(190) NULL,
  received_weight VARCHAR(80) NULL,
  priority ENUM('top_critical', 'high', 'medium', 'low') NOT NULL DEFAULT 'medium',
  date_started DATETIME NULL,
  date_loaded DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  quantity_planned VARCHAR(190) NOT NULL,
  assigned_employee_id INT NULL,
  quantity_packed VARCHAR(190),
  date_completed DATETIME NULL,
  website_uploaded TINYINT(1) NOT NULL DEFAULT 0,
  packing_status ENUM('not_started', 'packing', 'website', 'done', 'done_needs_label') NOT NULL DEFAULT 'not_started',
  notes TEXT,
  invoice_file_path VARCHAR(255) NULL,
  label_file_path VARCHAR(255) NULL,
  monday_item_id VARCHAR(80) NULL,
  monday_sync_status ENUM('not_synced', 'synced', 'sync_failed', 'updated') NOT NULL DEFAULT 'not_synced',
  monday_sync_error TEXT NULL,
  monday_synced_at DATETIME NULL,
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

CREATE TABLE IF NOT EXISTS ops_status_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  module VARCHAR(80) NOT NULL,
  record_id INT NOT NULL,
  field_name VARCHAR(80) NOT NULL DEFAULT 'status',
  old_value VARCHAR(120) NULL,
  new_value VARCHAR(120) NULL,
  changed_by_employee_id INT NULL,
  assigned_employee_id INT NULL,
  metadata JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ops_status_record (module, record_id, field_name),
  INDEX idx_ops_status_changed_by (changed_by_employee_id, created_at),
  INDEX idx_ops_status_assigned (assigned_employee_id, created_at)
);

CREATE TABLE IF NOT EXISTS ops_kpi_employee_inputs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period_month CHAR(7) NOT NULL,
  employee_id INT NOT NULL,
  salary_override DECIMAL(12,2) NULL,
  attendance_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  reliability_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  communication_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  team_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  manual_score DECIMAL(5,2) NOT NULL DEFAULT 85,
  notes TEXT NULL,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ops_kpi_input (period_month, employee_id),
  INDEX idx_ops_kpi_input_employee (employee_id),
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ops_kpi_weights (
  role_group VARCHAR(40) NOT NULL,
  component_key VARCHAR(80) NOT NULL,
  component_label VARCHAR(160) NOT NULL,
  weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (role_group, component_key)
);

CREATE TABLE IF NOT EXISTS ops_kpi_score_snapshots (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  period_month CHAR(7) NOT NULL,
  employee_id INT NOT NULL,
  role_group VARCHAR(40) NOT NULL,
  overall_score DECIMAL(6,2) NOT NULL DEFAULT 0,
  component_scores JSON NULL,
  metrics_snapshot JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ops_kpi_snapshot_period (period_month, employee_id),
  FOREIGN KEY (employee_id) REFERENCES ops_employees(id) ON DELETE CASCADE
);

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

INSERT IGNORE INTO ops_report_settings (setting_key, setting_value) VALUES
('kpi_target_assignment_minutes', '45'),
('kpi_target_packing_minutes', '90'),
('kpi_target_order_completion_minutes', '240'),
('kpi_target_packing_task_minutes', '240'),
('kpi_target_bookkeeping_minutes', '90'),
('kpi_target_website_upload_minutes', '120'),
('kpi_error_penalty_points', '4'),
('kpi_monthly_bonus_percent', '5');

INSERT IGNORE INTO ops_kpi_weights (role_group, component_key, component_label, weight_percent) VALUES
('front', 'order_flow', 'Order / walk-in completion', 20),
('front', 'bookkeeping', 'Bookkeeping accuracy', 20),
('front', 'website_stock', 'Website stock upload', 15),
('front', 'tasks', 'Task completion', 15),
('front', 'errors', 'Error score', 15),
('front', 'communication', 'Communication / manual', 10),
('front', 'reliability', 'Reliability / attendance', 5),
('packer', 'order_speed', 'Order packing speed', 20),
('packer', 'packing_productivity', 'Packing list productivity', 25),
('packer', 'packing_accuracy', 'Packing accuracy', 20),
('packer', 'tasks', 'Task / cleaning compliance', 15),
('packer', 'errors', 'Error score', 15),
('packer', 'team', 'Team contribution / manual', 5);
