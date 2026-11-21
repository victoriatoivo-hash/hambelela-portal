-- Driver-declared custody only; no payment, sales or Cash Book posting.
CREATE TABLE delivery_driver_handovers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 driver_id INT NOT NULL,
 request_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 amount_minor BIGINT NOT NULL,
 expected_minor BIGINT NOT NULL,
 unallocated_minor BIGINT NOT NULL DEFAULT 0,
 note VARCHAR(2000) NOT NULL,
 sources_json JSON NOT NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(driver_id) REFERENCES ops_employees(id),
 UNIQUE KEY driver_request(driver_id,request_uuid),
 KEY handover_time(created_at,id),
 CHECK(amount_minor>0 AND amount_minor<=9999999999),
 CHECK(expected_minor>=0 AND unallocated_minor>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
