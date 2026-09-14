-- Completed Task Correction workflow. Safe to run repeatedly.
ALTER TABLE ops_checklist_tasks ADD COLUMN IF NOT EXISTS active_correction_id INT NULL;
ALTER TABLE ops_checklist_tasks ADD COLUMN IF NOT EXISTS correction_round_count INT NOT NULL DEFAULT 0;
ALTER TABLE ops_checklist_tasks ADD COLUMN IF NOT EXISTS correction_required TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE ops_checklist_tasks ADD COLUMN IF NOT EXISTS correction_requested_at DATETIME NULL;
ALTER TABLE ops_checklist_tasks ADD COLUMN IF NOT EXISTS correction_due_at DATETIME NULL;
ALTER TABLE ops_checklist_tasks ADD COLUMN IF NOT EXISTS correction_require_new_proof TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE ops_checklist_attachments ADD COLUMN IF NOT EXISTS correction_cycle_id INT NULL;

CREATE TABLE IF NOT EXISTS ops_checklist_corrections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  correction_round INT NOT NULL,
  requested_by INT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  correction_message TEXT NOT NULL,
  correction_due_at DATETIME NOT NULL,
  require_new_proof TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'open',
  employee_started_at DATETIME NULL,
  employee_completed_at DATETIME NULL,
  employee_completion_note TEXT NULL,
  resolved_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancelled_by INT NULL,
  cancellation_reason TEXT NULL,
  completion_snapshot_json LONGTEXT NOT NULL,
  open_token VARCHAR(120) NULL,
  UNIQUE KEY uq_task_correction_round (task_id, correction_round),
  UNIQUE KEY uq_open_correction (open_token),
  KEY idx_task_correction (task_id, status, requested_at)
);
