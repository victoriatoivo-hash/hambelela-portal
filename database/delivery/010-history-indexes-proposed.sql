-- Review actual hosting definitions before applying; no production DDL authorized.
-- Add only if an equivalent index is absent. No event/data migration or backfill.
CREATE INDEX idx_driver_history ON delivery_events(employee_id,event_type,created_at,id);
