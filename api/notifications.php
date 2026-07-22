<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/shared/notifications.php';

require_login();

header('Content-Type: application/json');

try {
    $mode = (string) ($_GET['mode'] ?? 'summary');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        $alertId = (int) ($_POST['alert_id'] ?? 0);
        if (!in_array($action, ['urgent_delivered', 'urgent_viewed', 'urgent_dismissed'], true) || $alertId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Invalid urgent alert action.']);
            exit;
        }
        $state = substr($action, 7);
        echo json_encode(['ok' => notifications_mark_urgent_state($alertId, $state)]);
        exit;
    }

    if ($mode === 'urgent') {
        $preferences = notifications_preferences();
        echo json_encode(['ok' => true, 'alerts' => notifications_urgent_tasks_for_current_user(), 'sound_enabled' => (int) ($preferences['sound_enabled'] ?? 1)], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($mode !== 'summary') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unsupported notification mode.']);
        exit;
    }

    $summary = notifications_summary_for_current_user(5);
    echo json_encode(['ok' => true] + $summary, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'unread_count' => 0, 'latest' => []]);
}
