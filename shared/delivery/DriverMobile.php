<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Presentation opt-in, never an authorization grant. Existing Driver guards still apply. */
final class DriverMobile
{
    // Verified in Owner Employee Accounts: Isarael Maluena, active Delivery Driver.
    private const EMPLOYEE_ID = 17;

    public static function enabled(array $user): bool
    {
        return (int)($user['id'] ?? 0) === self::EMPLOYEE_ID
            && ($user['role_key'] ?? '') === 'delivery_driver';
    }

    public static function links(): array
    {
        return [
            ['Dashboard', '/index.php', 'layout-dashboard', 'Your delivery workspace'],
            ['My Deliveries', '/apps/delivery/index.php', 'truck', 'Assigned deliveries and progress'],
            ['My Delivery Day', '/apps/delivery/index.php?view=day', 'chart-no-axes-combined', 'Daily totals and activity'],
            ['Notifications', '/notifications.php', 'bell', 'New deliveries and urgent updates'],
            ['My Account', '/profile.php', 'user-round', 'Your profile and account'],
        ];
    }
}
