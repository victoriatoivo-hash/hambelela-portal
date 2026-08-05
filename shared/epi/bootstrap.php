<?php

declare(strict_types=1);

// Explicit opt-in only. This file is intentionally not loaded by global bootstrap/auth.
require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/FeatureFlags.php';
require_once __DIR__ . '/BusinessTimeEngine.php';
require_once __DIR__ . '/GracePeriodEngine.php';
require_once __DIR__ . '/EvidenceEngine.php';
require_once __DIR__ . '/ActivityEngine.php';
require_once __DIR__ . '/OwnershipEngine.php';
require_once __DIR__ . '/PerformanceEngine.php';
require_once __DIR__ . '/Performance.php';
require_once __DIR__ . '/OrdersActivityBridge.php';
require_once __DIR__ . '/OrdersPerformance.php';
require_once __DIR__ . '/PackingActivityBridge.php';
require_once __DIR__ . '/PackingPerformance.php';
require_once __DIR__ . '/TaskActivityBridge.php';
require_once __DIR__ . '/TaskPerformance.php';
require_once __DIR__ . '/CourierActivityBridge.php';
require_once __DIR__ . '/CourierPerformance.php';
require_once __DIR__ . '/BookkeepingActivityBridge.php';
require_once __DIR__ . '/BookkeepingPerformance.php';
require_once __DIR__ . '/AttendanceActivityBridge.php';
require_once __DIR__ . '/AttendancePerformance.php';
require_once __DIR__ . '/PerformanceScore.php';
