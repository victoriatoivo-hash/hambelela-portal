<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/EmployeeDriverSession.php';

/** One authenticated foreground-opening request per Namibia day; not attendance. */
final class DriverAppActivity
{
    public static function record(\PDO $db,int $driver,?\DateTimeImmutable $now=null):array
    {
        EmployeeDriverSession::actor($db,$driver);
        $now=($now??new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Africa/Windhoek'));
        $day=$now->format('Y-m-d');$hash=hash('sha256','driver-app-open|'.$driver.'|'.$day);
        $key=substr($hash,0,8).'-'.substr($hash,8,4).'-4'.substr($hash,13,3).'-a'.substr($hash,17,3).'-'.substr($hash,20,12);
        $data=['day'=>$day,'first_opened_at'=>$now->format('c'),'label'=>'App activity · '.$now->format('H:i'),'meaning'=>'First recorded app opening today; not duty, attendance or payroll.'];
        // Existing durable idempotency journal: namespaced, one row/day, no new schema.
        $q=$db->prepare("INSERT IGNORE INTO delivery_requests(actor_key,request_uuid,action,request_hash,response_json,created_at) VALUES(?,?,'driver_app_open',?,?,?)");
        $q->execute(['driver-app:'.$driver,$key,$hash,json_encode($data,JSON_THROW_ON_ERROR),$now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        $q=$db->prepare("SELECT response_json FROM delivery_requests WHERE actor_key=? AND request_uuid=? AND action='driver_app_open'");$q->execute(['driver-app:'.$driver,$key]);
        return json_decode((string)$q->fetchColumn(),true,16,JSON_THROW_ON_ERROR);
    }
}
