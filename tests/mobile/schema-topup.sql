-- CI-only: columns/tables that production gained through lazily-run code paths outside these four pages.
ALTER TABLE ops_orders ADD COLUMN IF NOT EXISTS fulfilment_mode VARCHAR(40) NULL AFTER order_type;
ALTER TABLE ops_orders ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE ops_packing_tasks ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE ops_packing_tasks ADD COLUMN IF NOT EXISTS packing_row_key VARCHAR(64) NULL;
ALTER TABLE ops_packing_tasks ADD COLUMN IF NOT EXISTS workload_package_count INT NULL;
ALTER TABLE ops_packing_tasks ADD COLUMN IF NOT EXISTS workload_unit_count INT NULL;
ALTER TABLE ops_packing_tasks ADD COLUMN IF NOT EXISTS workload_volume_ml DECIMAL(12,2) NULL;
ALTER TABLE ops_packing_tasks ADD COLUMN IF NOT EXISTS workload_weight_grams DECIMAL(12,2) NULL;
ALTER TABLE ops_packing_tasks ADD COLUMN IF NOT EXISTS workload_points_override DECIMAL(10,2) NULL;
ALTER TABLE ops_packing_tasks ADD COLUMN IF NOT EXISTS workload_override_at DATETIME NULL;
ALTER TABLE ops_packing_tasks ADD COLUMN IF NOT EXISTS workload_override_reason VARCHAR(255) NULL;
ALTER TABLE ops_packing_tasks ADD COLUMN IF NOT EXISTS workload_parse_status VARCHAR(40) NULL;
ALTER TABLE ops_packing_tasks ADD COLUMN IF NOT EXISTS workload_breakdown_json TEXT NULL;
ALTER TABLE ops_checklist_tasks ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL;
ALTER TABLE ops_checklist_tasks ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
CREATE TABLE IF NOT EXISTS kpi_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  logout_at DATETIME NULL
);
CREATE TABLE IF NOT EXISTS ops_checklist_recurring_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(120) NULL UNIQUE,
  task_name VARCHAR(190) NOT NULL,
  checklist_type VARCHAR(40) NOT NULL DEFAULT 'opening',
  priority VARCHAR(30) NOT NULL DEFAULT 'normal',
  assigned_employee_id INT NULL,
  assignment_type VARCHAR(20) NOT NULL DEFAULT 'specific',
  floating_eligible_role VARCHAR(40) NULL,
  recurring_rule VARCHAR(80) NOT NULL,
  due_time TIME NOT NULL DEFAULT '09:00:00',
  instructions TEXT NULL,
  checklist_items TEXT NULL,
  completion_evidence_required TINYINT(1) NOT NULL DEFAULT 0,
  employee_visible TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
