-- Separate Packing Website Complete from Front Desk Website Updated.
-- Existing website_uploaded values are intentionally not copied: their audit
-- provenance is ambiguous and must not become false Front Desk KPI history.
ALTER TABLE ops_packing_tasks
    ADD COLUMN packing_website_completed_at DATETIME NULL,
    ADD COLUMN packing_website_completed_by INT NULL,
    ADD COLUMN frontdesk_website_updated TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN frontdesk_website_updated_at DATETIME NULL,
    ADD COLUMN frontdesk_website_updated_by INT NULL;
