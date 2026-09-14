<?php
if (php_sapi_name() !== 'cli') {
    $expectedKey = (string)getenv('HR_ACCRUAL_KEY');
    $key = (string)($_SERVER['HTTP_X_HR_ACCRUAL_KEY'] ?? '');
    if ($expectedKey === '' || $key === '' || !hash_equals($expectedKey, $key)) {
        http_response_code(403);
        die('Forbidden');
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/leave-balance-service.php';

date_default_timezone_set('Africa/Windhoek');
$db = db();

if (hrLeaveRecoveryLocked($db)) {
    if (php_sapi_name() !== 'cli') {
        http_response_code(423);
    }
    die("Leave accrual is temporarily locked while HR records are being verified.\n");
}

try {
    $result = hrSyncAnnualLeaveAccrual($db, (int)date('Y'), (int)date('n'), 'scheduled_accrual');
} catch (Throwable $e) {
    error_log('Scheduled leave accrual failed: ' . $e->getMessage());
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
    }
    die("Leave accrual failed.\n");
}

if (php_sapi_name() === 'cli') {
    echo sprintf(
        "Done: %d employees. Accrued=%.1f days. New periods=%d.\n",
        $result['employee_count'],
        $result['accrued_days'],
        $result['periods_applied']
    );
    foreach ($result['employees'] as $employee) {
        echo sprintf(
            "  %s: accrued=%.1f, used=%.1f, available=%.1f\n",
            $employee['employee_name'],
            $employee['accrued_days'],
            $employee['used_days'],
            $employee['available_days']
        );
    }
    exit;
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'year' => $result['year'],
    'month' => $result['month'],
    'accrued_days' => $result['accrued_days'],
    'employee_count' => $result['employee_count'],
    'periods_applied' => $result['periods_applied'],
]);
