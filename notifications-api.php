<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/shared/notifications.php';

require_login();

header('Content-Type: application/json');

try {
    $action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'list');

    if ($action === 'mark_read') {
        $ids = array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
        notifications_mark_read($ids);
    } elseif ($action === 'clear') {
        $ids = array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
        notifications_clear($ids);
    } elseif ($action === 'save_preferences') {
        $employeeId = notifications_current_employee_id();
        if (!$employeeId) {
            throw new RuntimeException('Could not identify the employee account.');
        }
        notifications_save_preferences($employeeId, [
            'desktop_enabled' => (int) ($_POST['desktop_enabled'] ?? 0) === 1,
            'sound_enabled' => (int) ($_POST['sound_enabled'] ?? 0) === 1,
            'muted_when_unavailable' => (int) ($_POST['muted_when_unavailable'] ?? 0) === 1,
            'modules' => array_values(array_filter(array_map('strval', $_POST['modules'] ?? []))),
        ]);
    } elseif ($action === 'permission_seen') {
        // Reserved for future auditing of browser permission prompts.
    }

    $payload = notifications_for_current_user(12);
    echo json_encode(['ok' => true] + $payload);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Notifications are temporarily unavailable.']);
}
