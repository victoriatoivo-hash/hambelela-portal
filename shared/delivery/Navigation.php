<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/ReleaseFlags.php';
require_once __DIR__.'/FrontSession.php';
require_once __DIR__.'/EmployeeDriverSession.php';
final class Navigation
{
    public static function entry():?array
    {
        static $checked=false,$entry=null;
        if($checked)return $entry;$checked=true;
        if(!DELIVERY_ENABLED||!function_exists('current_user')||!function_exists('db'))return null;
        try{
            $driver=(\current_user()['role_key']??'')==='delivery_driver';
            $actor=$driver?EmployeeDriverSession::actor(\db(),(int)(\current_user()['id']??0)):FrontSession::actor(\db(),(int)(\current_user()['id']??0));
            $route=$driver||DeliveryPolicy::can($actor,'delivery_view')?'index.php':'accounting.php';
            if(($actor['role']??'')==='owner_admin')$route='driver-operations.php';
            $entry=['name'=>'Delivery','desc'=>'local deliveries, Driver assignments and collection handovers','icon'=>'bike','href'=>BASE_URL.'/apps/delivery/'.$route,'active'=>true,'tone'=>'green'];
        }catch(\Throwable $e){return null;}
        return $entry;
    }
}
