ALTER TABLE ops_packing_tasks
  ADD COLUMN monday_item_id VARCHAR(80) NULL AFTER consignment_id,
  ADD COLUMN monday_synced_at DATETIME NULL AFTER monday_item_id;

CREATE INDEX idx_ops_packing_tasks_monday_item_id
  ON ops_packing_tasks (monday_item_id);
