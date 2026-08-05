<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/apps/operations/operations.php';
require_once BASE_PATH . '/shared/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function notifications_feed_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_login();
    $payload = notifications_for_current_user(100);
    $items = is_array($payload['notifications'] ?? null) ? $payload['notifications'] : [];
    $today = date('Y-m-d');
    $summary = [
        'unread' => (int) ($payload['unread_count'] ?? 0),
        'action_required' => 0,
        'today' => 0,
        'packing' => 0,
        'tasks' => 0,
        'errors' => 0,
    ];

    foreach ($items as $item) {
        $module = strtolower((string) ($item['module'] ?? ''));
        $priority = strtolower((string) ($item['priority'] ?? 'normal'));
        $createdDate = substr((string) ($item['created_at'] ?? ''), 0, 10);
        if (empty($item['read_at']) && in_array($priority, ['urgent', 'critical', 'important', 'high'], true)) {
            $summary['action_required']++;
        }
        if ($createdDate === $today) $summary['today']++;
        if (strpos($module, 'pack') !== false) $summary['packing']++;
        if (strpos($module, 'task') !== false) $summary['tasks']++;
        if (strpos($module, 'error') !== false) $summary['errors']++;
    }

    notifications_feed_response([
        'success' => true,
        'data' => ['summary' => $summary, 'notifications' => $items],
    ]);
} catch (Throwable $error) {
    error_log('Notifications feed failed: ' . $error->getMessage());
    notifications_feed_response([
        'success' => false,
        'message' => 'Unable to load notifications.',
    ], 500);
}
