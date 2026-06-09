<?php
require_once __DIR__ . '/config.php';
requireAdmin(); // This uses your config.php auth system

if (!isset($_GET['file'])) {
    die('No file specified');
}

$filename = basename($_GET['file']); // Security: prevent directory traversal
$filepath = __DIR__ . '/uploads/certificates/' . $filename;

if (!file_exists($filepath)) {
    die('File not found: ' . htmlspecialchars($filename));
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