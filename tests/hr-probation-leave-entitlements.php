<?php
require_once __DIR__ . '/../apps/hr-portal/includes/leave-balance-service.php';

function assertClose(float $actual, float $expected, string $message): void {
    if (abs($actual - $expected) > 0.001) {
        throw new RuntimeException($message . sprintf(' Expected %.1f, received %.1f.', $expected, $actual));
    }
}

if (!hrEmployeeIsOnProbation(['employment_type' => 'Probation'])) {
    throw new RuntimeException('Probation employment type was not detected.');
}
if (hrAnnualLeaveRequestAllowed(['employment_type' => 'probation'])) {
    throw new RuntimeException('Annual Leave must not be requestable during probation.');
}
if (!hrAnnualLeaveRequestAllowed(['employment_type' => 'permanent'])) {
    throw new RuntimeException('Annual Leave must unlock when probation ends.');
}

assertClose(
    hrProratedAnnualLeaveAccrued(24.0, '2026-09-01', 2026, 9, new DateTimeImmutable('2026-09-30')),
    2.0,
    'A September commencement should accrue only the September service period.'
);
assertClose(
    hrProratedAnnualLeaveAccrued(24.0, '2026-10-01', 2026, 9, new DateTimeImmutable('2026-09-30')),
    0.0,
    'Employment beginning after the requested period must not accrue leave.'
);
assertClose(
    hrProratedAnnualLeaveAccrued(24.0, '2025-01-01', 2026, 12, new DateTimeImmutable('2026-12-31')),
    24.0,
    'A full calendar year must remain capped at the configured annual entitlement.'
);

$entitlements = hrLeaveEntitlements(['employment_type' => 'probation']);
if ($entitlements['annual_leave']['available'] !== false
    || $entitlements['sick_leave']['available'] !== true
    || $entitlements['compassionate_leave']['available'] !== true) {
    throw new RuntimeException('Probation entitlement availability is incorrect.');
}

echo "HR probation leave entitlement calculation checks passed.\n";
