CREATE TABLE IF NOT EXISTS ops_checklist_task_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(120) NOT NULL,
    task_mode VARCHAR(20) NOT NULL DEFAULT 'manual',
    task_name VARCHAR(190) NOT NULL,
    instructions TEXT NOT NULL,
    assigned_employee_id INT NULL,
    priority VARCHAR(30) NOT NULL DEFAULT 'normal',
    urgent_alert_enabled TINYINT(1) NOT NULL DEFAULT 0,
    urgent_recipients_json TEXT NULL,
    recurring_rule VARCHAR(80) NULL,
    recurrence_time TIME NULL,
    recurrence_start_date DATE NULL,
    recurrence_end_date DATE NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_task_template_name (template_name),
    INDEX idx_task_template_employee (assigned_employee_id),
    INDEX idx_task_template_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ops_checklist_task_template_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    item_text VARCHAR(190) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_task_template_item (template_id, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ops_checklist_task_template_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_task_template_attachment (template_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE ops_checklist_tasks ADD COLUMN source_template_id INT NULL AFTER recurring_template_id;
