<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';

final class FrontSession
{
    /** Re-read account status and role; never trust caller/browser role fields. */
    public static function actor(\PDO $db,int $employeeId):array
    {
        $s=$db->prepare("SELECT e.id,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active'");$s->execute([$employeeId]);$row=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$row)throw new \DomainException('Active employee required.');
        $actor=['kind'=>'employee','id'=>(int)$row['id'],'role'=>$row['role_key'],'active'=>true];
        // Only Delivery-specific explicit grants; no name matching or browser roles.
        $s=$db->prepare('SELECT p.permission_key FROM delivery_employee_grants g JOIN ops_permissions p ON p.id=g.permission_id WHERE g.employee_id=?');$s->execute([$employeeId]);$actor['grants']=$s->fetchAll(\PDO::FETCH_COLUMN);
        if(!DeliveryPolicy::can($actor,'delivery_view')&&!DeliveryPolicy::can($actor,'delivery_arrange')&&!DeliveryPolicy::can($actor,'delivery_accounting_view')&&!DeliveryPolicy::can($actor,'delivery_rates_manage'))throw new \DomainException('Delivery access denied.');
        return $actor;
    }
    public static function csrf(string $token):void
    {
        if(empty($_SESSION['delivery_front_csrf'])||!hash_equals($_SESSION['delivery_front_csrf'],$token))throw new \DomainException('Session verification failed.');
    }
}
