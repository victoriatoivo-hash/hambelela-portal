<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if (!hash_equals('e3d31d213dec4aadb76215a3fa7b343c', (string) ($_GET['token'] ?? ''))) { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/shared/database.php';
$pdo = db();
$columnExists = static function (string $column) use ($pdo): bool { $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ops_orders' AND COLUMN_NAME=?"); $s->execute([$column]); return (int)$s->fetchColumn()>0; };
if (!$columnExists('paid_updated_at')) $pdo->exec('ALTER TABLE ops_orders ADD COLUMN paid_updated_at DATETIME NULL AFTER payment_status');
if (!$columnExists('paid_updated_by_employee_id')) $pdo->exec('ALTER TABLE ops_orders ADD COLUMN paid_updated_by_employee_id INT NULL AFTER paid_updated_at');
$pdo->beginTransaction();
try {
    $task=$pdo->prepare("UPDATE ops_checklist_tasks SET assigned_employee_id=2, updated_at=CURRENT_TIMESTAMP WHERE id=67 AND assigned_employee_id=10 AND task_name='Update the website'");
    $task->execute();
    $notificationIds=$pdo->query("SELECT id FROM notifications WHERE related_type='checklist_task' AND related_id=67")->fetchAll(PDO::FETCH_COLUMN);
    $insert=$pdo->prepare('INSERT IGNORE INTO notification_recipients (notification_id,employee_id) VALUES (?,2)');
    $delete=$pdo->prepare('DELETE FROM notification_recipients WHERE notification_id=? AND employee_id=10');
    foreach ($notificationIds as $notificationId) { $insert->execute([(int)$notificationId]); $delete->execute([(int)$notificationId]); }
    $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
$verify=$pdo->query("SELECT id,task_name,assigned_employee_id FROM ops_checklist_tasks WHERE id=67")->fetch(PDO::FETCH_ASSOC);
$recipients=$pdo->query("SELECT n.id,nr.employee_id FROM notifications n JOIN notification_recipients nr ON nr.notification_id=n.id WHERE n.related_type='checklist_task' AND n.related_id=67 ORDER BY n.id,nr.employee_id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success'=>true,'task'=>$verify,'notification_recipients'=>$recipients,'paid_updated_at'=>$columnExists('paid_updated_at'),'paid_updated_by_employee_id'=>$columnExists('paid_updated_by_employee_id')], JSON_PRETTY_PRINT);
