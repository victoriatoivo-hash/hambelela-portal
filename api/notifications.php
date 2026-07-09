<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/shared/notifications.php';

require_login();

header('Content-Type: application/json');

try {
    $mode = (string) ($_GET['mode'] ?? 'summary');

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
