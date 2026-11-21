<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Explicit errand-management roles, independent of broad delivery grants. */
final class ErrandManagementAccess
{
    public static function allowed(array $actor): bool
    {
        return !empty($actor['active']) && (int)($actor['id'] ?? 0) > 0
            && ($actor['kind'] ?? '') === 'employee'
            && in_array($actor['role'] ?? '', [
                'owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'marketing_sales',
            ], true);
    }
}
