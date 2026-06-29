<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

$errors   = [];
$messages = [];

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $messages[] = 'Connected to database: ' . DB_NAME;
} catch (PDOException $e) {
    $errors[] = 'Connection failed: ' . $e->getMessage();
    goto output;
}

// Run each table creation individually — guaranteed order
$tables_sql = [

'settings' => "CREATE TABLE IF NOT EXISTS `settings` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key`  VARCHAR(100) NOT NULL UNIQUE,
  `setting_val`  TEXT,
  `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'users' => "CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(120) NOT NULL,
  `email`        VARCHAR(180) NOT NULL UNIQUE,
  `password`     VARCHAR(255) NOT NULL,
  `role`         ENUM('admin','employee') NOT NULL DEFAULT 'employee',
  `employee_id`  INT UNSIGNED NULL,
  `active`       TINYINT(1) DEFAULT 1,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'employees' => "CREATE TABLE IF NOT EXISTS `employees` (
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
  `social_security_number` VARCHAR(50),
  `medical_aid_fund`     VARCHAR(120),
  `medical_aid_active`   TINYINT(1) NOT NULL DEFAULT 0,
  `medical_aid_total`    DECIMAL(10,2) NOT NULL DEFAULT 275.00,
  `medical_aid_company`  DECIMAL(10,2) NOT NULL DEFAULT 110.00,
  `medical_aid_employee` DECIMAL(10,2) NOT NULL DEFAULT 165.00,
  `address`           TEXT,
  `emergency_name`    VARCHAR(120),
  `emergency_phone`   VARCHAR(30),
  `status`            ENUM('active','inactive','terminated') DEFAULT 'active',
  `avatar_color`      VARCHAR(12) DEFAULT '#40916C',
  `notes`             TEXT,
  `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'leave_balances' => "CREATE TABLE IF NOT EXISTS `leave_balances` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`   INT UNSIGNED NOT NULL,
  `leave_type`    VARCHAR(50) NOT NULL,
  `balance_days`  DECIMAL(5,1) DEFAULT 0.0,
  `used_days`     DECIMAL(5,1) DEFAULT 0.0,
  `year`          INT NOT NULL,
  UNIQUE KEY `emp_type_year` (`employee_id`, `leave_type`, `year`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'leave_requests' => "CREATE TABLE IF NOT EXISTS `leave_requests` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'shutdown_leave_plans' => "CREATE TABLE IF NOT EXISTS `shutdown_leave_plans` (
  `employee_id` INT UNSIGNED NOT NULL,
  `year` INT NOT NULL,
  `handling` ENUM('unpaid','borrow','exception') DEFAULT NULL,
  `notes` TEXT NULL,
  `updated_by` INT UNSIGNED NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`employee_id`,`year`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'overtime' => "CREATE TABLE IF NOT EXISTS `overtime` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'payroll_runs' => "CREATE TABLE IF NOT EXISTS `payroll_runs` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `period_label`  VARCHAR(30) NOT NULL,
  `period_month`  TINYINT NOT NULL,
  `period_year`   INT NOT NULL,
  `status`        ENUM('draft','finalised') DEFAULT 'draft',
  `generated_by`  INT UNSIGNED NULL,
  `generated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `month_year` (`period_month`, `period_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'payslips' => "CREATE TABLE IF NOT EXISTS `payslips` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `run_id`           INT UNSIGNED NOT NULL,
  `employee_id`      INT UNSIGNED NOT NULL,
  `basic_salary`     DECIMAL(10,2) NOT NULL,
  `ot_pay`           DECIMAL(10,2) DEFAULT 0.00,
  `lwop_deduction`   DECIMAL(10,2) DEFAULT 0.00,
  `paye`             DECIMAL(10,2) DEFAULT 0.00,
  `ssf`              DECIMAL(10,2) DEFAULT 0.00,
  `medical_aid_fund`     VARCHAR(120),
  `medical_aid_total`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `medical_aid_company`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `medical_aid_employee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `other_deductions` DECIMAL(10,2) DEFAULT 0.00,
  `net_salary`       DECIMAL(10,2) NOT NULL,
  `pdf_path`         VARCHAR(255) NULL,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`run_id`)      REFERENCES `payroll_runs`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'medical_aid_payments' => "CREATE TABLE IF NOT EXISTS `medical_aid_payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `period_month` TINYINT NOT NULL,
  `period_year` INT NOT NULL,
  `active_employee_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_payable` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `company_contribution` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `employee_contribution` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `paid_status` TINYINT(1) NOT NULL DEFAULT 0,
  `paid_date` DATETIME NULL,
  `paid_by` INT UNSIGNED NULL,
  `notes_reference` TEXT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `month_year` (`period_month`, `period_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'documents' => "CREATE TABLE IF NOT EXISTS `documents` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`   INT UNSIGNED NULL,
  `doc_type`      VARCHAR(80) NOT NULL,
  `doc_name`      VARCHAR(255) NOT NULL,
  `file_path`     VARCHAR(255) NOT NULL,
  `file_size`     INT UNSIGNED,
  `uploaded_by`   INT UNSIGNED NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'employment_letters' => "CREATE TABLE IF NOT EXISTS `employment_letters` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'onboarding_tasks' => "CREATE TABLE IF NOT EXISTS `onboarding_tasks` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`   INT UNSIGNED NOT NULL,
  `task`          VARCHAR(255) NOT NULL,
  `completed`     TINYINT(1) DEFAULT 0,
  `completed_at`  TIMESTAMP NULL,
  `sort_order`    INT DEFAULT 0,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'notifications' => "CREATE TABLE IF NOT EXISTS `notifications` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NULL,
  `title`       VARCHAR(200) NOT NULL,
  `message`     TEXT,
  `type`        ENUM('info','success','warning','error') DEFAULT 'info',
  `is_read`     TINYINT(1) DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'audit_log' => "CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NULL,
  `action`      VARCHAR(100) NOT NULL,
  `description` TEXT,
  `ip_address`  VARCHAR(45),
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

foreach ($tables_sql as $name => $sql) {
    try {
        $pdo->exec($sql);
        $messages[] = 'Created table: ' . $name;
    } catch (PDOException $e) {
        $errors[] = 'Failed on table ' . $name . ': ' . $e->getMessage();
    }
}

// Insert default settings
$defaults = [
    ['company_name','Hambelela Organic'],
    ['company_reg',''],
    ['company_address',''],
    ['company_city','Windhoek'],
    ['company_country','Namibia'],
    ['company_phone',''],
    ['company_email','victoriatoivo@gmail.com'],
    ['company_vat',''],
    ['company_bank',''],
    ['letter_company_legal_name','Neaco Trading CC'],
    ['letter_company_trading_name','Hambelela Organic'],
    ['letter_company_reg','cc/2023/03878'],
    ['letter_physical_address','Office 3, floor one, Lazarette house, Erf 7173, corner of Julius Nyerere Street and John Muundjua Street, Ausspannplatz, Windhoek, Namibia'],
    ['letter_email','info@hambelelaorganic.com'],
    ['letter_phone','0856628598'],
    ['letter_website','www.hambelelaorganic.com'],
    ['letter_signatory_name','Ms. Victoria Toivo'],
    ['letter_default_responsibilities','packaging products, packing customer orders, preparing orders for courier, delivery or collection, handling inventory with care, maintaining dispatch records, and ensuring a clean and organised workspace'],
    ['payroll_month','March 2025'],
    ['payroll_run_day','25'],
    ['ssf_rate','0.9'],
    ['ssf_min','4.50'],
    ['ssf_max','99.00'],
    ['paye_enabled','1'],
    ['leave_renewal','01 January'],
    ['shutdown_reserve_days','10'],
    ['shutdown_start_md','12-19'],
    ['shutdown_end_md','01-04'],
    ['shutdown_allow_borrow','1'],
];
$stmt = $pdo->prepare("INSERT IGNORE INTO `settings` (`setting_key`,`setting_val`) VALUES (?,?)");
foreach ($defaults as $row) $stmt->execute($row);
$messages[] = 'Default settings inserted';

// Insert default admin — password: Admin@Hambelela2025
$pw = password_hash('Admin@Hambelela2025', PASSWORD_BCRYPT);
$stmt = $pdo->prepare("INSERT IGNORE INTO `users` (`name`,`email`,`password`,`role`) VALUES (?,?,?,?)");
$stmt->execute(['Victoria Oaingome','victoria@hambelelaorganic.com',$pw,'admin']);
$messages[] = 'Admin account ready';

// Create upload folders
foreach (['/uploads','/uploads/documents','/uploads/certificates','/uploads/payslips'] as $d) {
    if (!is_dir(__DIR__.$d)) mkdir(__DIR__.$d, 0755, true);
}
$messages[] = 'Upload folders ready';

output:
$success = empty($errors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hambelela HR — Installer</title>
<style>
  body{font-family:Arial,sans-serif;background:#f0f4f1;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
  .box{background:#fff;border-radius:12px;padding:40px;max-width:580px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,.08)}
  h2{color:#1a2e22;margin-bottom:20px}
  .msg{font-size:13px;padding:7px 12px;border-radius:6px;margin-bottom:5px;background:#d8f3dc;color:#1a2e22}
  .err{background:#fee2e2;color:#7f1d1d}
  .done{margin-top:20px;padding:16px;background:#d8f3dc;border-radius:8px;color:#1a2e22;line-height:1.8}
  .fail{margin-top:20px;padding:16px;background:#fee2e2;border-radius:8px;color:#7f1d1d}
  .btn{display:inline-block;margin-top:20px;padding:12px 28px;background:#2d6a4f;color:#fff;border-radius:8px;text-decoration:none;font-weight:bold}
  code{background:#eee;padding:2px 6px;border-radius:4px;font-size:12px}
</style>
</head>
<body>
<div class="box">
  <h2>Hambelela Organic — HR Setup</h2>
  <?php foreach($messages as $m): ?><div class="msg"><?=htmlspecialchars($m)?></div><?php endforeach ?>
  <?php foreach($errors as $e): ?><div class="msg err"><?=htmlspecialchars($e)?></div><?php endforeach ?>
  <?php if($success): ?>
  <div class="done">
    <strong>Installation complete!</strong><br><br>
    Login details:<br>
    Email: <code>victoria@hambelelaorganic.com</code><br>
    Password: <code>Admin@Hambelela2025</code><br><br>
    <strong>Delete <code>install.php</code> and <code>install.sql</code> after logging in.</strong>
  </div>
  <a href="index.php" class="btn">Go to HR Portal</a>
  <?php else: ?>
  <div class="fail"><strong>Installation failed.</strong> Check errors above.</div>
  <?php endif ?>
</div>
</body>
</html>
