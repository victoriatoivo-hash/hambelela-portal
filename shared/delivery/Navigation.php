<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/ReleaseFlags.php';
require_once __DIR__.'/FrontSession.php';
final class Navigation
{
    public static function entry():?array
    {
        static $checked=false,$entry=null;
        if($checked)return $entry;$checked=true;
        if(!DELIVERY_ENABLED||!function_exists('current_user')||!function_exists('db'))return null;
        try{
            $actor=FrontSession::actor(\db(),(int)(\current_user()['id']??0));
            $route=DeliveryPolicy::can($actor,'delivery_view')?'index.php':'accounting.php';
            $entry=['name'=>'Delivery','desc'=>'local deliveries, Driver assignments and collection handovers','icon'=>'bike','href'=>BASE_URL.'/apps/delivery/'.$route,'active'=>true,'tone'=>'green'];
        }catch(\Throwable $e){return null;}
        return $entry;
    }
}
