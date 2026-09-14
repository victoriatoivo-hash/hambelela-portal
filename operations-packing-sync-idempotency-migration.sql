ALTER TABLE ops_packing_tasks
  MODIFY COLUMN monday_sync_status ENUM('not_synced','synced','failed','updated','duplicate_detected') NOT NULL DEFAULT 'not_synced';

