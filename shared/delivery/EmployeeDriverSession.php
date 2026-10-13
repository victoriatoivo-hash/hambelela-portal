<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Standard employee identity; the retired mobile-secret cookie grants no access. */
final class EmployeeDriverSession
{
    public static function allowedPath(string $path):bool
    {
        $path=strtolower(str_replace('\\','/',parse_url($path,PHP_URL_PATH)?:''));
        $base=defined('BASE_URL')?rtrim((string)BASE_URL,'/'):'';
        if($base!==''&&strpos($path,$base.'/')===0)$path=substr($path,strlen($base));
        return in_array($path,[
            '/apps/delivery/driver/index.php','/apps/delivery/driver/api.php','/apps/delivery/driver/logout.php',
            '/index.php','/apps/delivery/index.php','/profile.php','/change-access-code.php','/apps/hr-portal/portal-login.php',
            '/notifications.php','/api/notifications-feed.php','/notifications-api.php','/api/notifications.php',
            '/apps/operations/portal-presence.php',
        ],true);
    }
    public static function actor(\PDO $db,int $employeeId):array
    {
        $s=$db->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.id=? AND e.status='active' AND r.role_key='delivery_driver' AND p.active=1");
        $s->execute([$employeeId]);
        if(!$s->fetchColumn())throw new \DomainException('Active Driver employee required.');
        return ['kind'=>'employee','id'=>$employeeId,'role'=>'delivery_driver','active'=>true,'driver_verified'=>true];
    }
    public static function csrf(string $token):void
    {
        if(empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$token))throw new \DomainException('Session verification failed.');
    }
}
