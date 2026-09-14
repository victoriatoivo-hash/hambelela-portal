<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_role('owner_admin', 'front_desk_admin', 'front_desk_admin_employee');

function error_attachment_path_starts_with(string $path, string $prefix): bool
{
    return $prefix === '' || strncmp($path, $prefix, strlen($prefix)) === 0;
}

$errorId = max(0, (int) ($_GET['error_id'] ?? 0));
$requestedPath = trim((string) ($_GET['attachment'] ?? ''));
if ($errorId <= 0 || $requestedPath === '') { http_response_code(404); exit('Attachment not found.'); }

$employeeId = ops_current_employee_id();
$params = [$errorId];
$scope = '';
if (!user_has_role('owner_admin')) {
    $scope = " AND (employee_id = ? OR responsible_employee_id = ? OR attributed_employee_id = ? OR logged_by = ?
        OR people_involved = ? OR people_involved LIKE ? OR people_involved LIKE ? OR people_involved LIKE ?
        OR EXISTS (SELECT 1 FROM ops_error_instruction_reads ir JOIN ops_error_instructions oi ON oi.id=ir.instruction_id WHERE oi.error_id=ops_error_logs.id AND ir.recipient_user_id=?))";
    array_push($params, $employeeId, $employeeId, $employeeId, $employeeId, '['.$employeeId.']', '['.$employeeId.',%', '%,'.$employeeId.',%', '%,'.$employeeId.']', $employeeId);
}
$rows = ops_rows('SELECT attachment_paths FROM ops_error_logs WHERE id = ? AND deleted_at IS NULL' . $scope . ' LIMIT 1', $params);
if (!$rows) { http_response_code(404); exit('Attachment not found.'); }

$stored = json_decode((string) ($rows[0]['attachment_paths'] ?? ''), true);
$record = null;
foreach (is_array($stored) ? $stored : [] as $entry) {
    $path = is_array($entry) ? (string) ($entry['path'] ?? '') : (string) $entry;
    if ($path !== '' && hash_equals($path, $requestedPath)) {
        $record = is_array($entry) ? $entry : ['path' => $path, 'name' => basename($path)];
        break;
    }
}
if (!$record) { http_response_code(404); exit('Attachment not found.'); }

$uploadRoot = realpath(BASE_PATH . '/uploads/error-log');
$absolutePath = realpath(BASE_PATH . '/' . ltrim((string) $record['path'], '/'));
if (!$uploadRoot || !$absolutePath || !error_attachment_path_starts_with($absolutePath, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($absolutePath)) {
    http_response_code(404); exit('Attachment not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: 'application/octet-stream';
$downloadName = basename((string) ($record['name'] ?? basename($absolutePath)));
$disposition = error_attachment_path_starts_with($mime, 'image/') ? 'inline' : 'attachment';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolutePath));
header("Content-Disposition: {$disposition}; filename*=UTF-8''" . rawurlencode($downloadName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($absolutePath);
