<?php
declare(strict_types=1);
// CLI-only retry worker. No schema changes, credentials, or customer output.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
if (!DELIVERY_ENABLED) { echo "Delivery disabled; no notifications dispatched.\n"; exit; }
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/delivery/NotificationOutbox.php';
try {
    $deliveryWorkerDb = db();
    // The lock prevents overlapping cron runs; a crashed process releases it.
    $deliveryWorkerLock = $deliveryWorkerDb->query("SELECT GET_LOCK('hambelela_delivery_outbox',0)")->fetchColumn();
    if ((int)$deliveryWorkerLock !== 1) { echo "Delivery worker already running.\n"; exit; }
    try {
        $deliverySent = \Hambelela\Delivery\NotificationOutbox::drain($deliveryWorkerDb,50);
        echo 'Delivery notifications dispatched: '.$deliverySent."\n";
    } finally {
        $deliveryWorkerDb->query("SELECT RELEASE_LOCK('hambelela_delivery_outbox')");
    }
} catch (Throwable $e) {
    error_log('Delivery notification worker unavailable: '.get_class($e));
    fwrite(STDERR,"Delivery notification worker unavailable; pending events retained.\n");
    exit(1);
}
