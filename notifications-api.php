<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/shared/notifications.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
        $existingPreferences = notifications_preferences($employeeId);
        notifications_save_preferences($employeeId, [
            'desktop_enabled' => (int) ($_POST['desktop_enabled'] ?? 0) === 1,
            'sound_enabled' => (int) ($_POST['sound_enabled'] ?? 0) === 1,
            'sound_volume' => (int) ($_POST['sound_volume'] ?? 65),
            'sound_prompt_seen' => (int) ($_POST['sound_prompt_seen'] ?? 0) === 1,
            'muted_when_unavailable' => (int) ($_POST['muted_when_unavailable'] ?? 0) === 1,
            'modules' => isset($_POST['modules']) ? array_values(array_filter(array_map('strval', $_POST['modules']))) : $existingPreferences['modules'],
        ]);
        if (function_exists('ops_activity_log')) {
            ops_activity_log('notification_sound_' . (((int) ($_POST['sound_enabled'] ?? 0) === 1) ? 'enabled' : 'disabled'), 'employee', $employeeId, ['employee_id' => $employeeId, 'delivery_channel' => 'portal', 'timestamp' => gmdate(DATE_ATOM)]);
        }
    } elseif ($action === 'snooze') {
        if (!notifications_snooze_task((int) ($_POST['notification_id'] ?? 0), (string) ($_POST['duration'] ?? ''))) {
            http_response_code(422);
            throw new RuntimeException('This reminder could not be snoozed.');
        }
    } elseif ($action === 'claim_delivery') {
        echo json_encode(['ok' => true, 'claimed' => notifications_claim_task_delivery((int) ($_POST['notification_id'] ?? 0))]);
        exit;
    }

    $payload = notifications_for_current_user(12);
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Notifications are temporarily unavailable.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
