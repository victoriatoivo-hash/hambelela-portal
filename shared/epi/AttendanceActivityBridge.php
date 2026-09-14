<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use Throwable;

final class AttendanceActivityBridge
{
    public static function recordLogin(array $user, string $source, string $sessionReference = ''): void
    {
        self::record('login', $user, $source, $sessionReference);
    }

    public static function recordLogout(array $user, string $source, string $sessionReference = ''): void
    {
        self::record('logout', $user, $source, $sessionReference);
    }

    private static function record(string $type, array $user, string $source, string $sessionReference): void
    {
        try {
            if (!function_exists('db')) return;
            Performance::configure(db());
            $employeeId = (int)($user['id'] ?? 0);
            if ($employeeId <= 0) return;
            $reference = 'SESSION-' . substr(hash('sha256', $sessionReference !== '' ? $sessionReference : session_id()), 0, 16);
            $when = date('Y-m-d H:i:s');
            $common = [
                'module' => 'Portal Activity', 'reference_number' => $reference,
                'employee_id' => $employeeId, 'employee_name' => (string)($user['name'] ?? ''),
                'department' => (string)($user['role_key'] ?? ''), 'timestamp' => $when,
                'activity_source' => 'login_handler:' . preg_replace('/[^a-z0-9_-]/i', '', $source),
                'recording_mode' => 'automatic',
            ];
            Performance::recordActivity($common + [
                'activity_type' => $type,
                'description' => $type === 'login' ? 'Employee signed in to the portal.' : 'Employee signed out of the portal.',
            ]);
            Performance::recordEvidence($common + [
                'module' => 'Attendance', 'action' => 'portal_' . $type,
                'action_description' => $type === 'login' ? 'Authenticated portal session started.' : 'Authenticated portal session ended.',
                'previous_value' => null, 'new_value' => $type,
            ]);
        } catch (Throwable $error) {
            self::log($type, $error);
        }
    }

    private static function log(string $type, Throwable $error): void
    {
        $line = date(DATE_ATOM) . ' ' . $type . ': ' . $error->getMessage() . PHP_EOL;
        error_log('EPI attendance bridge failed: ' . $error->getMessage());
        @error_log($line, 3, dirname(__DIR__, 2) . '/logs/epi-attendance.log');
    }
}
