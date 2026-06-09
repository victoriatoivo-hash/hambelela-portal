-- ============================================================
--  Hambelela Organic HR Portal — Database Setup
--  Run this once to create all tables
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ── Company Settings ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `settings` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key`  VARCHAR(100) NOT NULL UNIQUE,
  `setting_val`  TEXT,
  `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_val`) VALUES
  ('company_name',    'Hambelela Organic'),
  ('company_reg',     ''),
  ('company_address', ''),
  ('company_city',    'Windhoek'),
  ('company_country', 'Namibia'),
  ('company_phone',   ''),
  ('company_email',   'victoriatoivo@gmail.com'),
  ('company_vat',     ''),
  ('company_bank',    ''),
  ('letter_company_legal_name', 'Neaco Trading CC'),
  ('letter_company_trading_name', 'Hambelela Organic'),
  ('letter_company_reg', 'cc/2023/03878'),
  ('letter_physical_address', 'Office 3, floor one, Lazarette house, Erf 7173, corner of Juluis Nyerere street and John Muundjua street, Ausspannplatz, Windhoek, Namibia'),
  ('letter_email', 'info@hambelelaorganic.com'),
  ('letter_phone', '0856628598'),
  ('letter_website', 'www.hambelelaorganic.com'),
  ('letter_signatory_name', 'Ms. Victoria Toivo'),
  ('letter_default_responsibilities', 'packaging products, packing customer orders, preparing orders for courier, delivery or collection, handling inventory with care, maintaining dispatch records, and ensuring a clean and organised workspace'),
  ('payroll_month',   'March 2025'),
  ('payroll_run_day', '25'),
  ('ssf_rate',        '0.9'),
  ('ssf_min',         '4.50'),
  ('ssf_max',         '99.00'),
  ('paye_enabled',    '1'),
  ('leave_renewal',   '01 January'),
  ('shutdown_reserve_days', '10'),
  ('shutdown_start_md', '12-19'),
  ('shutdown_end_md', '01-04'),
  ('shutdown_allow_borrow', '1');

-- ── Users (login accounts) ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(120) NOT NULL,
  `email`        VARCHAR(180) NOT NULL UNIQUE,
  `password`     VARCHAR(255) NOT NULL,
  `role`         ENUM('admin','employee') NOT NULL DEFAULT 'employee',
  `employee_id`  INT UNSIGNED NULL,
  `active`       TINYINT(1) DEFAULT 1,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Employees ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `employees` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `emp_number`        VARCHAR(20) NOT NULL UNIQUE,
  `first_name`        VARCHAR(80) NOT NULL,
  `last_name`         VARCHAR(80) NOT NULL,
  `email`             VARCHAR(180),
  `phone`             VARCHAR(30),
  `id_number`         VARCHAR(30),
  `job_title`         VARCHAR(120),
  `department`        VARCHAR(80),
  `employment_type`   ENUM('full_time','part_time','contract','probation') DEFAULT 'full_time',
  `start_date`        DATE,
  `basic_salary`      DECIMAL(10,2) DEFAULT 0.00,
  `hourly_rate`       DECIMAL(8,2) DEFAULT 0.00,
  `bank_name`         VARCHAR(100),
  `bank_account`      VARCHAR(50),
  `bank_branch`       VARCHAR(80),
  `tax_number`        VARCHAR(30),
  `address`           TEXT,
  `emergency_name`    VARCHAR(120),
  `emergency_phone`   VARCHAR(30),
  `status`            ENUM('active','inactive','terminated') DEFAULT 'active',
  `avatar_initials`   VARCHAR(4),
  `avatar_color`      VARCHAR(12) DEFAULT '#40916C',
  `notes`             TEXT,
  `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Leave Balances ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `leave_balances` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`   INT UNSIGNED NOT NULL,
  `leave_type`    VARCHAR(50) NOT NULL,
  `balance_days`  DECIMAL(5,1) DEFAULT 0.0,
  `used_days`     DECIMAL(5,1) DEFAULT 0.0,
  `year`          YEAR NOT NULL DEFAULT (YEAR(CURDATE())),
  UNIQUE KEY `emp_type_year` (`employee_id`, `leave_type`, `year`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Leave Requests ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `leave_requests` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`     INT UNSIGNED NOT NULL,
  `leave_type`      VARCHAR(50) NOT NULL,
  `start_date`      DATE NOT NULL,
  `end_date`        DATE NOT NULL,
  `days`            DECIMAL(5,1) NOT NULL,
  `reason`          TEXT,
  `status`          ENUM('pending','approved','rejected') DEFAULT 'pending',
  `certificate`     VARCHAR(255) NULL,
  `reserve_warning` TINYINT(1) DEFAULT 0,
  `back_capture`    TINYINT(1) DEFAULT 0,
  `approved_by`     INT UNSIGNED NULL,
  `approved_at`     TIMESTAMP NULL,
  `reject_reason`   TEXT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Overtime ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `shutdown_leave_plans` (
  `employee_id` INT UNSIGNED NOT NULL,
  `year` INT NOT NULL,
  `handling` ENUM('unpaid','borrow','exception') DEFAULT NULL,
  `notes` TEXT NULL,
  `updated_by` INT UNSIGNED NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`employee_id`,`year`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `overtime` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`   INT UNSIGNED NOT NULL,
  `ot_date`       DATE NOT NULL,
  `start_time`    TIME NOT NULL,
  `end_time`      TIME NOT NULL,
  `hours`         DECIMAL(5,2) NOT NULL,
  `day_type`      ENUM('weekday','saturday','sunday','public_holiday') DEFAULT 'weekday',
  `rate`          DECIMAL(4,2) NOT NULL DEFAULT 1.50,
  `hourly_rate`   DECIMAL(8,2) NOT NULL,
  `amount`        DECIMAL(10,2) NOT NULL,
  `status`        ENUM('pending','approved','rejected') DEFAULT 'pending',
  `notes`         TEXT,
  `approved_by`   INT UNSIGNED NULL,
  `approved_at`   TIMESTAMP NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Payroll Runs ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `payroll_runs` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `period_label`  VARCHAR(30) NOT NULL,
  `period_month`  TINYINT NOT NULL,
  `period_year`   YEAR NOT NULL,
  `status`        ENUM('draft','finalised') DEFAULT 'draft',
  `generated_by`  INT UNSIGNED NULL,
  `generated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `month_year` (`period_month`, `period_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Payslips ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `payslips` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `run_id`          INT UNSIGNED NOT NULL,
  `employee_id`     INT UNSIGNED NOT NULL,
  `basic_salary`    DECIMAL(10,2) NOT NULL,
  `ot_pay`          DECIMAL(10,2) DEFAULT 0.00,
  `lwop_deduction`  DECIMAL(10,2) DEFAULT 0.00,
  `paye`            DECIMAL(10,2) DEFAULT 0.00,
  `ssf`             DECIMAL(10,2) DEFAULT 0.00,
  `other_deductions`DECIMAL(10,2) DEFAULT 0.00,
  `net_salary`      DECIMAL(10,2) NOT NULL,
  `pdf_path`        VARCHAR(255) NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`run_id`)        REFERENCES `payroll_runs`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`employee_id`)   REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Documents ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `documents` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`   INT UNSIGNED NULL,
  `doc_type`      VARCHAR(80) NOT NULL,
  `doc_name`      VARCHAR(255) NOT NULL,
  `file_path`     VARCHAR(255) NOT NULL,
  `file_size`     INT UNSIGNED,
  `uploaded_by`   INT UNSIGNED NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `employment_letters` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`     INT UNSIGNED NOT NULL,
  `letter_no`       VARCHAR(60) NOT NULL UNIQUE,
  `issued_date`     DATE NOT NULL,
  `title`           VARCHAR(200) NOT NULL DEFAULT 'Employment Confirmation Letter',
  `body_html`       MEDIUMTEXT NOT NULL,
  `responsibilities` TEXT NULL,
  `status`          ENUM('draft','published') NOT NULL DEFAULT 'draft',
  `download_count`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `download_limit`  TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `created_by`      INT UNSIGNED NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `published_at`    TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Onboarding Tasks ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `onboarding_tasks` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`   INT UNSIGNED NOT NULL,
  `task`          VARCHAR(255) NOT NULL,
  `completed`     TINYINT(1) DEFAULT 0,
  `completed_at`  TIMESTAMP NULL,
  `sort_order`    INT DEFAULT 0,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Notifications ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NULL,
  `title`       VARCHAR(200) NOT NULL,
  `message`     TEXT,
  `type`        ENUM('info','success','warning','error') DEFAULT 'info',
  `is_read`     TINYINT(1) DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Audit Log ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NULL,
  `action`      VARCHAR(100) NOT NULL,
  `description` TEXT,
  `ip_address`  VARCHAR(45),
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Default Admin User ───────────────────────────────────────
-- Password: Admin@Hambelela2025 (change after first login)
INSERT IGNORE INTO `users` (`name`, `email`, `password`, `role`) VALUES (
  'Victoria Oaingome',
  'victoria@hambelelaorganic.com',
  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uXTMlF7Im',
  'admin'
);
