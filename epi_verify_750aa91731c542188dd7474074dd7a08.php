<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (!hash_equals('045a9806ead64a6ba9e482abb90ffd9c', (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

require_once __DIR__ . '/shared/database.php';
require_once __DIR__ . '/shared/epi/bootstrap.php';

use Hambelela\EPI\FeatureFlags;
use Hambelela\EPI\Performance;

$flags = new FeatureFlags(db());
try {
    $flags->setEnabled(true);
    Performance::configure(db());
    $dedupe = hash('sha256', 'epi-foundation-live-self-test-v1');
    $evidence = [
        'module' => 'Portal Activity',
        'reference_number' => 'EPI-FOUNDATION-SELFTEST',
        'employee_name' => 'System Verification',
        'department' => 'System',
        'action' => 'foundation_verified',
        'action_description' => 'Live EPI Phase 1 architecture verification.',
        'timestamp' => '2026-08-05 09:00:00',
        'activity_source' => 'epi_live_self_test',
        'deduplication_key' => $dedupe,
        'verified' => true,
        'metadata' => ['test' => true],
    ];
    $first = Performance::recordEvidence($evidence);
    $duplicate = Performance::recordEvidence($evidence);
    $activity = Performance::recordActivity([
        'module' => 'Portal Activity',
        'reference_number' => 'EPI-FOUNDATION-SELFTEST',
        'employee_name' => 'System Verification',
        'activity_type' => 'foundation_self_test',
        'description' => 'Activity Engine live verification.',
        'timestamp' => '2026-08-05 09:00:00',
        'activity_source' => 'epi_live_self_test',
        'deduplication_key' => hash('sha256', 'epi-foundation-live-activity-v1'),
    ]);
    $ownership = Performance::recordOwnership([
        'module' => 'Portal Activity',
        'reference_number' => 'EPI-FOUNDATION-SELFTEST',
        'original_owner_name' => 'System Verification',
        'current_owner_name' => 'System Verification',
        'completed_by_name' => 'System Verification',
        'verified_by_name' => 'System Verification',
        'change_reason' => 'Phase 1 live verification',
        'effective_at' => '2026-08-05 09:00:00',
    ]);
    $weekday = Performance::businessMinutes('2026-08-03 08:00:00', '2026-08-03 17:00:00');
    $saturday = Performance::businessMinutes('2026-08-01 09:00:00', '2026-08-01 13:00:00');
    $sunday = Performance::businessMinutes('2026-08-02 08:00:00', '2026-08-02 17:00:00');
    $grace = Performance::gracePeriod('tasks');
    $evidenceCount = count(Performance::getEvidence(['reference_number' => 'EPI-FOUNDATION-SELFTEST']));
    $timelineCount = count(Performance::getTimeline(['reference_number' => 'EPI-FOUNDATION-SELFTEST']));
    $flags->setEnabled(false);
    echo json_encode([
        'ok' => $first !== null && $first === $duplicate && $activity !== null && $ownership !== null,
        'evidence_deduplicated' => $first === $duplicate && $evidenceCount === 1,
        'evidence_count' => $evidenceCount,
        'activity_count' => $timelineCount,
        'business_minutes' => ['weekday' => $weekday, 'saturday' => $saturday, 'sunday' => $sunday],
        'grace_key' => $grace['grace_key'] ?? null,
        'epi_enabled' => '0',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    try { $flags->setEnabled(false); } catch (Throwable $ignored) {}
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES);
}
