<?php
require_once __DIR__ . '/config.php';
requireAdmin();

$task_id  = (int)($_POST['task_id']  ?? 0);
$redirect = $_POST['redirect'] ?? 'employees.php';

if ($task_id) {
    $task = db()->prepare("SELECT * FROM onboarding_tasks WHERE id=?");
    $task->execute([$task_id]);
    $task = $task->fetch();
    if ($task) {
        $completed    = $task['completed'] ? 0 : 1;
        $completed_at = $completed ? date('Y-m-d H:i:s') : null;
        db()->prepare("UPDATE onboarding_tasks SET completed=?, completed_at=? WHERE id=?")
            ->execute([$completed, $completed_at, $task_id]);
    }
}

header('Location: ' . $redirect);
exit;
