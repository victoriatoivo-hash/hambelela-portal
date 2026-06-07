ALTER TABLE ops_orders
  ADD COLUMN archived_at DATETIME NULL AFTER completed_at,
  ADD COLUMN archived_by INT NULL AFTER archived_at;

ALTER TABLE ops_packing_tasks
  ADD COLUMN archived_at DATETIME NULL AFTER updated_at,
  ADD COLUMN archived_by INT NULL AFTER archived_at;
