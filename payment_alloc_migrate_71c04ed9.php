<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if (!hash_equals('9f43a6c281d74e58925bb061c04b6d8e', (string) ($_GET['token'] ?? ''))) { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/shared/database.php';
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS order_payment_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    amount_cents BIGINT NOT NULL,
    transaction_reference VARCHAR(190) NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'portal',
    source_version VARCHAR(80) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_employee_id INT NULL,
    UNIQUE KEY uq_order_payment_method (order_id, payment_method),
    KEY idx_order_payment_updated (order_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS order_payment_allocation_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    previous_allocations_json LONGTEXT NULL,
    new_allocations_json LONGTEXT NOT NULL,
    changed_by_employee_id INT NULL,
    source VARCHAR(30) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payment_audit_order (order_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$check = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('order_payment_allocations','order_payment_allocation_audit') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
echo json_encode(['success' => count($check) === 2, 'database' => DB_NAME, 'tables' => $check], JSON_PRETTY_PRINT);
