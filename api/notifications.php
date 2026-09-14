<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/apps/operations/operations.php';
require_once BASE_PATH . '/shared/notifications.php';

require_login();

header('Content-Type: application/json');

try {
    $mode = (string) ($_GET['mode'] ?? 'summary');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'notification_claim') {
            echo json_encode(['ok' => true, 'claimed' => notifications_claim_task_delivery((int) ($_POST['notification_id'] ?? 0))]);
            exit;
        }
        if ($action === 'notification_snooze') {
            $updated = notifications_snooze_task((int) ($_POST['notification_id'] ?? 0), (string) ($_POST['duration'] ?? ''));
            if (!$updated) http_response_code(422);
            echo json_encode(['ok' => $updated]);
            exit;
        }
        if (in_array($action, ['notification_delivered', 'notification_viewed', 'notification_dismissed'], true)) {
            $notificationId = (int) ($_POST['notification_id'] ?? 0);
            if ($notificationId <= 0) { http_response_code(400); echo json_encode(['ok' => false]); exit; }
            $updated = notifications_mark_task_state($notificationId, substr($action, 13));
            if (!$updated) http_response_code(404);
            echo json_encode(['ok' => $updated, 'message' => $updated ? 'Notification updated.' : 'Notification not found.']);
            exit;
        }
        $alertId = (int) ($_POST['alert_id'] ?? 0);
        if ($action === 'urgent_remind' && $alertId > 0) {
            $minutes = (int) ($_POST['minutes'] ?? 0);
            $updated = notifications_remind_urgent_later($alertId, $minutes);
            if (!$updated) http_response_code(422);
            echo json_encode(['ok' => $updated, 'nextReminderMinutes' => $updated ? $minutes : null]);
            exit;
        }
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
    $summary['sidebar_counts'] = notifications_sidebar_counts_for_current_user();
    $summary['packing_list_unread_count'] = notifications_packing_assignment_unread_count();
    $summary['packing_list_unread_ids'] = notifications_packing_assignment_unread_ids();
    echo json_encode(['ok' => true] + $summary, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'unread_count' => 0, 'latest' => []]);
}
