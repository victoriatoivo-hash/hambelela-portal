ALTER TABLE ops_packing_tasks
  ADD COLUMN received_weight VARCHAR(80) NULL AFTER item_name,
  ADD COLUMN packing_website_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER website_uploaded,
  ADD COLUMN date_started DATETIME NULL AFTER date_loaded,
  ADD COLUMN invoice_file_path VARCHAR(255) NULL AFTER notes,
  ADD COLUMN label_file_path VARCHAR(255) NULL AFTER invoice_file_path;

ALTER TABLE ops_packing_tasks
  MODIFY packing_status ENUM(
    'not_started',
    'packing',
    'done',
    'packed_label_needed',
    'label_created',
    'website',
    'correction_needed',
    'done_needs_label'
  ) NOT NULL DEFAULT 'not_started';
