<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_login();

$attachmentId = (int) ($_GET['id'] ?? 0);
$mode = (string) ($_GET['mode'] ?? 'view');
if ($attachmentId <= 0 || !ops_table_exists('ops_checklist_attachments')) {
    http_response_code(404);
    exit('Attachment not found.');
}

$scope = ops_task_scope_for_current_user();
$employeeId = (int) ($scope['employee_id'] ?? ops_current_employee_id() ?? 0);
$canViewAll = ($scope['type'] ?? '') === 'all';
$sql = "SELECT a.*, t.assigned_employee_id
        FROM ops_checklist_attachments a
        JOIN ops_checklist_tasks t ON t.id = a.task_id
        WHERE a.id = ? AND a.removed_at IS NULL";
$params = [$attachmentId];
if (!$canViewAll) {
    $sql .= ' AND t.assigned_employee_id = ?';
    $params[] = $employeeId;
}
$sql .= ' LIMIT 1';
$rows = ops_rows($sql, $params);
if (!$rows) {
    http_response_code(404);
    exit('Attachment not found.');
}

$attachment = $rows[0];
$stored = basename((string) $attachment['stored_filename']);
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $stored)) {
    http_response_code(404);
    exit('Attachment not found.');
}
$path = BASE_PATH . '/uploads/checklist-attachments/' . $stored;
if (!is_file($path)) {
    http_response_code(404);
    exit('Attachment file is unavailable.');
}

$mime = (string) $attachment['mime_type'];
$inlineAllowed = strpos($mime, 'image/') === 0 || $mime === 'application/pdf';
$disposition = $mode === 'download' || !$inlineAllowed ? 'attachment' : 'inline';
$filename = str_replace(["\r", "\n", '"'], '', (string) $attachment['original_filename']);
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header("Content-Disposition: {$disposition}; filename*=UTF-8''" . rawurlencode($filename));
header('Cache-Control: private, max-age=300');
readfile($path);
exit;
