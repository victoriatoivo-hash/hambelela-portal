INSERT INTO cw_supplier_invoices
(id,supplier_name,invoice_number,invoice_date,uploaded_at,uploaded_by,uploaded_by_name,original_filename,stored_file,file_type,currency,exchange_rate,vat_treatment,subtotal,vat_amount,invoice_total,extraction_status,review_status,approval_status,approved_by,approved_by_name,approved_at,notes)
VALUES
(1,'Synthetic Draft Supplier','TEST-DRAFT-1','2026-01-10','2026-01-10 08:00:00',101,'Synthetic Owner','draft-1.pdf','uploads/cost-workbook/test/draft-1-random.pdf','application/pdf','NAD',1,'exclusive',100.00,15.00,115.00,'manual_review','needs_review','draft',NULL,NULL,NULL,'Synthetic fixture only'),
(2,'Synthetic Approved Supplier','TEST-APPROVED-2','2026-01-11','2026-01-11 08:00:00',101,'Synthetic Owner','approved-2.png','uploads/cost-workbook/test/approved-2-random.png','image/png','NAD',1,'inclusive',200.00,30.00,230.00,'manual_review','reviewed','approved',101,'Synthetic Owner','2026-01-12 09:30:00','Synthetic fixture only');

INSERT INTO cw_supplier_invoice_lines
(id,supplier_invoice_id,raw_description,product_description,quantity,purchase_unit,base_quantity,base_unit,unit_price,line_subtotal,vat_amount,line_total,discount,item_type,review_status)
VALUES
(1,1,'Synthetic raw material','Synthetic material A',2,'kg',2000,'g',50.00,90.00,13.50,103.50,10.00,'supplier_raw_material','valid'),
(2,2,'Synthetic packaged item','Synthetic material B',10,'unit',10,'unit',23.00,200.00,30.00,230.00,30.00,'supplier_raw_material','valid');

INSERT INTO cw_sync_batches
(id,sync_uuid,status,started_by,started_by_name,queued_at,started_at,heartbeat_at,updated_at,completed_at,total_products,processed_count,success_count,is_successful_snapshot)
VALUES (7,'00000000-0000-4000-8000-000000000007','completed',101,'Synthetic Owner','2026-01-09 08:00:00','2026-01-09 08:00:00','2026-01-09 08:05:00','2026-01-09 08:05:00','2026-01-09 08:05:00',1,1,1,1);

INSERT INTO cw_product_snapshots
(id,product_id,variation_id,product_name,variation_name,sku,category,product_type,regular_price_inc_vat,sale_price_inc_vat,active_price_inc_vat,stock_quantity,stock_status,manage_stock,website_status,permalink,sync_batch_id,synced_at)
VALUES (7,700,0,'Synthetic Snapshot Product','','SYN-700','Synthetic','simple',115.00,NULL,115.00,25,'instock',1,'publish','https://invalid.example/synthetic',7,'2026-01-09 08:05:00');

INSERT INTO cw_settings(setting_key,setting_value,updated_by_name) VALUES ('schema_version','2','system')
ON DUPLICATE KEY UPDATE setting_value='2',updated_by_name='system';
