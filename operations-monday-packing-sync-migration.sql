ALTER TABLE ops_packing_tasks
  ADD COLUMN monday_item_id VARCHAR(80) NULL AFTER consignment_id,
  ADD COLUMN monday_synced_at DATETIME NULL AFTER monday_item_id,
  ADD COLUMN monday_sync_status ENUM('not_synced', 'synced', 'failed', 'updated') NOT NULL DEFAULT 'not_synced' AFTER monday_synced_at,
  ADD COLUMN monday_sync_error TEXT NULL AFTER monday_sync_status;

CREATE INDEX idx_ops_packing_tasks_monday_item_id
  ON ops_packing_tasks (monday_item_id);
