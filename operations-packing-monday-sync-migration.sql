DROP PROCEDURE IF EXISTS add_packing_column_if_missing;

DELIMITER //
CREATE PROCEDURE add_packing_column_if_missing(IN column_name VARCHAR(64), IN ddl_sql TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ops_packing_tasks'
          AND COLUMN_NAME = column_name
    ) THEN
        SET @sql = ddl_sql;
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

CALL add_packing_column_if_missing('invoice_number', 'ALTER TABLE ops_packing_tasks ADD COLUMN invoice_number VARCHAR(100) NULL AFTER item_name');
CALL add_packing_column_if_missing('invoice_date', 'ALTER TABLE ops_packing_tasks ADD COLUMN invoice_date DATE NULL AFTER invoice_number');
CALL add_packing_column_if_missing('supplier_name', 'ALTER TABLE ops_packing_tasks ADD COLUMN supplier_name VARCHAR(190) NULL AFTER invoice_date');
CALL add_packing_column_if_missing('monday_item_id', 'ALTER TABLE ops_packing_tasks ADD COLUMN monday_item_id VARCHAR(80) NULL AFTER notes');
CALL add_packing_column_if_missing('monday_sync_status', 'ALTER TABLE ops_packing_tasks ADD COLUMN monday_sync_status ENUM(''not_synced'', ''synced'', ''sync_failed'', ''updated'') NOT NULL DEFAULT ''not_synced'' AFTER monday_item_id');
CALL add_packing_column_if_missing('monday_sync_error', 'ALTER TABLE ops_packing_tasks ADD COLUMN monday_sync_error TEXT NULL AFTER monday_sync_status');
CALL add_packing_column_if_missing('monday_synced_at', 'ALTER TABLE ops_packing_tasks ADD COLUMN monday_synced_at DATETIME NULL AFTER monday_sync_error');

DROP PROCEDURE IF EXISTS add_packing_column_if_missing;
