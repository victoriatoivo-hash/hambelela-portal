<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

header('Content-Type: application/json');

try {
    if (current_role_key() === 'guest') {
        http_response_code(401);
        throw new RuntimeException('Your session expired.');
    }
    $filters = [
        'period' => trim((string) ($_GET['period'] ?? 'previous_month')),
        'date_from' => trim((string) ($_GET['date_from'] ?? '')),
        'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        'employee_id' => trim((string) ($_GET['employee_id'] ?? '')),
        'action' => trim((string) ($_GET['action'] ?? '')),
    ];
    $currentEmployeeId = ops_current_employee_id();
    $canReviewAll = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
    if (!$canReviewAll) {
        $filters['employee_id'] = (string) $currentEmployeeId;
    }
    $service = new \Hambelela\EPI\OrdersPerformance(db());
    $kind = trim((string) ($_GET['kind'] ?? 'summary'));
    if ($kind === 'evidence') $data = $service->getEvidence($filters, 500);
    elseif ($kind === 'walk_ins') $data = $service->getWalkIns($filters, 500);
    elseif ($kind === 'outstanding') $data = $service->getOutstanding($filters, 500);
    elseif ($kind === 'employee' && (int) $filters['employee_id'] > 0) $data = $service->getEmployee((int) $filters['employee_id'], $filters);
    else $data = $service->getSummary($filters);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $error->getMessage()]);
}
