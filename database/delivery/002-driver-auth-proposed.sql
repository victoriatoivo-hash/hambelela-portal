-- LOCAL DEVELOPMENT ONLY. Separate additive proposal; not approved/applied live.
-- Provisioning must verify the employee and mobile ownership before activation.
CREATE TABLE delivery_driver_credentials (
 employee_id INT PRIMARY KEY,
 mobile VARCHAR(20) NOT NULL UNIQUE,
 secret_hash VARCHAR(255) NOT NULL,
 active TINYINT NOT NULL DEFAULT 0,
 session_version INT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY (employee_id) REFERENCES delivery_driver_profiles(employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE delivery_login_limits (
 bucket CHAR(64) PRIMARY KEY,
 failures INT UNSIGNED NOT NULL DEFAULT 0,
 window_start BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
