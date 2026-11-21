<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Page routing only. The Order Board and errand APIs enforce their own permissions. */
final class ErrandLanding
{
    public static function enforce(array $actor): void
    {
        if (!in_array($actor['role'] ?? '', ['front_desk_admin', 'front_desk_admin_employee', 'marketing_sales'], true)) return;
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            http_response_code(405);
            exit;
        }
        header('Location: '.BASE_URL.'/apps/delivery/index.php?errands=1', true, 302);
        exit;
    }
}
