<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if (!hash_equals('3dd37af7b4da4cb6bcfe9228f5fe8935', (string) ($_GET['token'] ?? ''))) { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/shared/database.php';
$pdo = db();
$query = static function (string $sql, array $params = []) use ($pdo): array {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); $stmt->closeCursor(); return $rows;
};
$employees = $query("SELECT e.id,e.full_name,e.email,e.status,r.role_key,r.name role_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.full_name LIKE 'Secilia%' OR e.email LIKE '%secilia%' ORDER BY e.id");
$employeeIds = array_map(static fn(array $row): int => (int) $row['id'], $employees);
$tasks = $query("SELECT id,task_name,assigned_employee_id,status,recurrence_key,recurring_rule,employee_visible,created_at,updated_at FROM ops_checklist_tasks WHERE id=67 OR task_name LIKE '%Secilia%' OR assigned_employee_id IN (" . ($employeeIds ? implode(',', array_fill(0, count($employeeIds), '?')) : '-1') . ") ORDER BY id DESC LIMIT 40", $employeeIds);
$notifications = $query("SELECT n.id,n.related_id,n.title,n.created_at,nr.employee_id,nr.delivered_at,nr.read_at,nr.cleared_at FROM notifications n JOIN notification_recipients nr ON nr.notification_id=n.id WHERE n.related_type='checklist_task' AND (n.related_id=67 OR nr.employee_id IN (" . ($employeeIds ? implode(',', array_fill(0, count($employeeIds), '?')) : '-1') . ") ) ORDER BY n.id DESC LIMIT 40", $employeeIds);
$tables = $query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ops_employees','ops_checklist_tasks','notifications','notification_recipients','task_assignees','employee_user_links') ORDER BY TABLE_NAME");
$columns = $query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ops_orders','ops_checklist_tasks','ops_employees') AND COLUMN_NAME IN ('assigned_employee_id','payment_status','paid_updated_at','paid_updated_by_employee_id','employee_id','full_name') ORDER BY TABLE_NAME,COLUMN_NAME");
echo json_encode(['database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(), 'employees' => $employees, 'tasks' => $tasks, 'notifications' => $notifications, 'tables' => $tables, 'columns' => $columns], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
