<?php

declare(strict_types=1);

namespace Hambelela\EPI;

/** Central, fail-closed eligibility policy used by evidence and scoring. */
final class EligibilityPolicy
{
    private const EXCLUSION_FLAGS = [
        'excluded_from_scoring', 'system_error', 'business_error',
        'external_dependency', 'approved_leave', 'approved_exception',
        'system_outage', 'internet_outage', 'device_failure',
        'supplier_delay', 'courier_delay', 'customer_delay',
        'approved_role_coverage', 'duplicate', 'test_data', 'superseded',
    ];

    private const NON_EMPLOYEE_RESPONSIBILITY = [
        'business_error', 'system_error', 'supplier_error', 'courier_error',
        'customer_error', 'external_dependency', 'unconfirmed',
    ];

    public static function exclusionReason(array $row, array $metadata): ?string
    {
        if ((string) ($row['recording_mode'] ?? '') === 'test') return 'test_data';
        foreach (self::EXCLUSION_FLAGS as $flag) {
            if (self::flag($metadata[$flag] ?? false)) return $flag;
        }
        if (!empty($metadata['duplicate_of'])) return 'duplicate';
        if (self::flag($metadata['insufficient_attribution'] ?? false)) return 'insufficient_attribution';
        $responsibility = strtolower(trim((string) ($metadata['responsibility_type'] ?? '')));
        if (in_array($responsibility, self::NON_EMPLOYEE_RESPONSIBILITY, true)) return $responsibility;
        if ((string) ($row['module'] ?? '') === 'Error Log') {
            if ($responsibility !== 'employee_error' || !self::flag($metadata['responsibility_confirmed'] ?? false)
                || (int)($row['employee_id']??0)<=0 || (int)($row['employee_id']??0)!==(int)($metadata['responsible_employee_id']??0)) {
                return 'insufficient_attribution';
            }
        }
        return null;
    }
    private static function flag($value): bool { return in_array($value,[true,1,'1','true','yes','on'],true); }
}
