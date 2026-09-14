<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/owner-dashboard-data.php';

require_role('owner_admin', 'supervisor_manager');

header('Content-Type: application/json; charset=utf-8');

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-d');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : $from;
if ($to < $from) {
    $to = $from;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $action = (string) ($payload['action'] ?? $_POST['action'] ?? '');
        if ($action === 'resolve_owner_error') {
            owner_dashboard_bootstrap();
            $id = (int) ($payload['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid error id.');
            }
            $stmt = db()->prepare('UPDATE owner_error_log SET resolved = 1, resolved_at = NOW(), resolved_by = ? WHERE id = ?');
            $stmt->execute([ops_current_employee_id(), $id]);
            echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
            exit;
        }
        throw new RuntimeException('Unknown dashboard action.');
    }

    echo json_encode(['ok' => true, 'data' => owner_dashboard_build($from, $to)], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Dashboard data could not be loaded.'], JSON_UNESCAPED_SLASHES);
}
