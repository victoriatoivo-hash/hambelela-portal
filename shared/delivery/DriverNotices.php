<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';
final class DriverNotices
{
    private static function guard(array $actor):void
    {
        if(($actor['role']??'')!=='delivery_driver'||!DeliveryPolicy::can($actor,'delivery_view_assigned'))throw new \DomainException('Restricted Driver session required.');
    }
    public static function list(\PDO $db,array $actor):array
    {
        self::guard($actor);
        $s=$db->prepare("SELECT n.id,n.title,n.message,n.priority,n.created_at,r.read_at FROM notification_recipients r JOIN notifications n ON n.id=r.notification_id JOIN delivery_jobs j ON j.id=n.related_id WHERE r.employee_id=? AND j.driver_employee_id=? AND n.module='delivery' AND n.related_type='delivery_job' AND r.cleared_at IS NULL ORDER BY n.id DESC LIMIT 20");
        $s->execute([$actor['id'],$actor['id']]);return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    public static function read(\PDO $db,array $actor,int $id):void
    {
        self::guard($actor);
        if($id<1)throw new \DomainException('Notification required.');
        $s=$db->prepare("UPDATE notification_recipients r JOIN notifications n ON n.id=r.notification_id JOIN delivery_jobs j ON j.id=n.related_id SET r.read_at=COALESCE(r.read_at,UTC_TIMESTAMP()) WHERE n.id=? AND r.employee_id=? AND j.driver_employee_id=? AND n.module='delivery' AND n.related_type='delivery_job'");
        $s->execute([$id,$actor['id'],$actor['id']]);
    }
}
