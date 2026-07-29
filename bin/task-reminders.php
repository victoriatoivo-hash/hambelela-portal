<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/shared/notifications.php';

date_default_timezone_set('Africa/Windhoek');
$created = notifications_schedule_task_reminders(null, false);
echo json_encode(['ok' => true, 'created' => $created, 'ran_at' => date(DATE_ATOM)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
