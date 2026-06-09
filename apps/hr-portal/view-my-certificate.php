<?php
require_once __DIR__ . '/config.php';
requireLogin();
$user = currentUser();
$db = db();

if (!isset($_GET['file'])) {
    die('No file specified');
}

$filename = basename($_GET['file']); // Security: prevent directory traversal
$filepath = __DIR__ . '/uploads/certificates/' . $filename;

if (!file_exists($filepath)) {
    die('File not found');
}

// Security: Verify this certificate belongs to the logged-in employee
// Extract employee ID from filename (format: cert_EMPID_timestamp.ext)
preg_match('/^cert_(\d+)_/', $filename, $matches);

if (!$matches) {
    die('Invalid file format');
}

$fileEmployeeId = (int)$matches[1];
$loggedInEmployeeId = (int)($user['emp_id'] ?? 0);

// Allow access if:
// 1. User is admin (role === 'admin'), OR
// 2. User is the employee who uploaded it (emp_id matches)
if ($user['role'] !== 'admin' && $fileEmployeeId !== $loggedInEmployeeId) {
    die('Access denied: You can only view your own certificates');
}

// Determine MIME type
$fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png'
];

$mimeType = $mimeTypes[$fileExtension] ?? 'application/octet-stream';

// Set headers
header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, must-revalidate');

// Output file
readfile($filepath);
exit;