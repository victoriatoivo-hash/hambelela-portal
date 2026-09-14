<?php
require_once __DIR__ . '/config.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $count = (int)db()->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
    echo json_encode(['success' => true, 'pending_count' => $count]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Pending leave requests could not be checked.']);
}
