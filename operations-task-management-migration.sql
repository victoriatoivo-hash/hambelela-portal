CREATE TABLE IF NOT EXISTS ops_checklist_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  checklist_type VARCHAR(40) NOT NULL DEFAULT 'opening',
  task_name VARCHAR(190) NOT NULL,
  task_mode VARCHAR(20) NOT NULL DEFAULT 'one_off',
  priority VARCHAR(30) NOT NULL DEFAULT 'medium',
  assigned_employee_id INT NULL,
  date_assigned DATETIME NULL,
  deadline DATETIME NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'not_started',
  notes TEXT,
  instructions TEXT NULL,
  checklist_items TEXT NULL,
  checked_items TEXT NULL,
  completion_note TEXT NULL,
  photo_path VARCHAR(255),
  completed_at DATETIME NULL,
  date_completed DATETIME NULL,
  completed_by INT NULL,
  recurrence_key VARCHAR(120) NULL,
  recurring_rule VARCHAR(80) NULL,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE ops_checklist_tasks MODIFY status VARCHAR(40) NOT NULL DEFAULT 'not_started';
ALTER TABLE ops_checklist_tasks MODIFY checklist_type VARCHAR(40) NOT NULL DEFAULT 'opening';

ALTER TABLE ops_checklist_tasks ADD COLUMN priority VARCHAR(30) NOT NULL DEFAULT 'medium' AFTER task_name;
ALTER TABLE ops_checklist_tasks ADD COLUMN date_assigned DATETIME NULL AFTER assigned_employee_id;
ALTER TABLE ops_checklist_tasks ADD COLUMN scheduled_at DATETIME NULL AFTER date_assigned;
ALTER TABLE ops_checklist_tasks ADD COLUMN released_at DATETIME NULL AFTER scheduled_at;
CREATE INDEX idx_task_scheduled_release ON ops_checklist_tasks (scheduled_at, released_at, deleted_at);
ALTER TABLE ops_checklist_tasks ADD COLUMN instructions TEXT NULL AFTER notes;
ALTER TABLE ops_checklist_tasks ADD COLUMN checklist_items TEXT NULL AFTER instructions;
ALTER TABLE ops_checklist_tasks ADD COLUMN checked_items TEXT NULL AFTER checklist_items;
ALTER TABLE ops_checklist_tasks ADD COLUMN completion_note TEXT NULL AFTER checked_items;
ALTER TABLE ops_checklist_tasks ADD COLUMN date_completed DATETIME NULL AFTER completed_at;
ALTER TABLE ops_checklist_tasks ADD COLUMN completed_by INT NULL AFTER date_completed;
ALTER TABLE ops_checklist_tasks ADD COLUMN recurrence_key VARCHAR(120) NULL AFTER completed_by;
ALTER TABLE ops_checklist_tasks ADD COLUMN recurring_rule VARCHAR(80) NULL AFTER recurrence_key;
ALTER TABLE ops_checklist_tasks ADD COLUMN created_by INT NULL AFTER recurring_rule;
ALTER TABLE ops_checklist_tasks ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE ops_checklist_tasks ADD COLUMN first_displayed_at DATETIME NULL AFTER date_assigned;
ALTER TABLE ops_checklist_tasks ADD COLUMN first_opened_at DATETIME NULL AFTER first_displayed_at;
ALTER TABLE ops_checklist_tasks ADD COLUMN acknowledged_at DATETIME NULL AFTER first_opened_at;
ALTER TABLE ops_checklist_tasks ADD COLUMN started_at DATETIME NULL AFTER acknowledged_at;
ALTER TABLE ops_checklist_tasks ADD COLUMN completion_note_required TINYINT(1) NOT NULL DEFAULT 0 AFTER completion_note;
ALTER TABLE ops_checklist_tasks ADD COLUMN completion_evidence_required TINYINT(1) NOT NULL DEFAULT 0 AFTER completion_note_required;
ALTER TABLE ops_checklist_tasks ADD COLUMN performance_scored TINYINT(1) NOT NULL DEFAULT 1 AFTER completion_evidence_required;
ALTER TABLE ops_checklist_tasks ADD COLUMN blocked_reason TEXT NULL AFTER performance_scored;

UPDATE ops_checklist_tasks SET status = 'not_started' WHERE status IN ('pending', 'missed');
UPDATE ops_checklist_tasks SET status = 'done' WHERE status IN ('completed', 'approved');
