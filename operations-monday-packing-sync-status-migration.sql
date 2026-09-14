ALTER TABLE ops_packing_tasks
  ADD COLUMN monday_sync_status ENUM('not_synced', 'synced', 'failed', 'updated') NOT NULL DEFAULT 'not_synced' AFTER monday_synced_at,
  ADD COLUMN monday_sync_error TEXT NULL AFTER monday_sync_status;
