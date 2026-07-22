<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_login();

$taskId = (int) ($_GET['task_id'] ?? 0);
$employeeId = ops_current_employee_id();
$canViewAll = user_has_role('owner_admin');

if ($taskId <= 0 || (!$canViewAll && !$employeeId)) {
    http_response_code(404);
    exit('File not found.');
}

$sql = 'SELECT photo_path FROM ops_checklist_tasks WHERE id = ? AND deleted_at IS NULL';
$params = [$taskId];
if (!$canViewAll) {
    $sql .= ' AND assigned_employee_id = ? AND employee_visible = 1';
    $params[] = $employeeId;
}
$task = ops_row($sql . ' LIMIT 1', $params);
$storedPath = trim((string) ($task['photo_path'] ?? ''));

$proofRoot = realpath(BASE_PATH . '/uploads/checklist-proofs');
$filePath = $storedPath !== '' ? realpath(BASE_PATH . '/' . ltrim(str_replace('\\', '/', $storedPath), '/')) : false;
if ($proofRoot === false || $filePath === false || !is_file($filePath)
    || strpos(strtolower($filePath), strtolower($proofRoot . DIRECTORY_SEPARATOR)) !== 0) {
    http_response_code(404);
    exit('File not found.');
}

$mime = function_exists('mime_content_type') ? (string) mime_content_type($filePath) : 'application/octet-stream';
header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', basename($filePath)) . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
