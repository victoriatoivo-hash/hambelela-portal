ALTER TABLE ops_packing_tasks
  ADD COLUMN monday_board_id VARCHAR(80) NULL AFTER monday_item_id,
  ADD COLUMN packing_row_key VARCHAR(64) NULL AFTER monday_sync_error,
  ADD COLUMN duplicate_removed_at DATETIME NULL AFTER packing_row_key,
  ADD COLUMN duplicate_of_id INT NULL AFTER duplicate_removed_at;

ALTER TABLE ops_packing_tasks
  MODIFY COLUMN monday_sync_status ENUM('not_synced', 'synced', 'failed', 'updated', 'duplicate_detected') NOT NULL DEFAULT 'not_synced';

CREATE INDEX idx_ops_packing_tasks_monday_board_id
  ON ops_packing_tasks (monday_board_id);

CREATE INDEX idx_ops_packing_tasks_row_key
  ON ops_packing_tasks (packing_row_key);
