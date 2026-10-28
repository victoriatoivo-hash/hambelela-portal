<?php
// Exercise the real guard with synthetic rows only; no employee/order writes.
namespace Hambelela\EPI {
    final class V2Store {
        public static bool $enabled=true;
        public static string $historicalMode='courier';
        public static function one($db,$sql,$args=[]) {
            return str_contains($sql,'setting_value') ? ['setting_value'=>self::$enabled?'1':'0'] : ['classification'=>self::$historicalMode];
        }
    }
}
namespace {
    require __DIR__.'/../shared/epi/OrdersSlaPolicy.php';
    require __DIR__.'/../shared/epi/OrdersStageBridge.php';
    $source=file_get_contents(__DIR__.'/../apps/operations/operations.php');
    $start=strpos($source,'function ops_normalize_fulfilment_mode(');
    eval(substr($source,$start,strpos($source,'function ops_nav(',$start)-$start));
    $db=new class extends PDO {public function __construct(){}};
    $count=0;
    $check=function($mode,$paid,$status,$allowed,$oldMode='courier',$extra=[])use($db,&$count){
        \Hambelela\EPI\V2Store::$historicalMode=$oldMode;
        $order=array_merge(['id'=>38336,'order_type'=>'courier','fulfilment_mode'=>$mode,'portal_paid_confirmed'=>$paid],$extra);
        $before=$order;$result=true;
        try {\Hambelela\EPI\OrdersStageBridge::assertStatusAllowed($db,$order,$status);}
        catch(RuntimeException $e){$result=false;if(!str_contains($e->getMessage(),'courier order'))throw $e;}
        if($result!==$allowed||$order!==$before)throw new RuntimeException("Incorrect guard for $mode/$paid/$status");
        $count++;
    };
    foreach(['collection','delivery','walk_in','other','local_pickup','windhoek_delivery']as$mode)
        foreach([0,1]as$paid)foreach(['in_progress','completed']as$status)$check($mode,$paid,$status,true,'courier',['order_type'=>$mode]);
    foreach(['courier','Courier','nampost','pudo']as$mode)
        foreach(['in_progress','packed','verified','ready_for_courier','complete','completed']as$status){
            $check($mode,0,$status,false,'collection');$check($mode,1,$status,true,'collection');
        }
    $check('courier',0,'new_order',true);
    $check('courier',0,'cancelled',true);
    $check('courier',0,'in_progress',true,'courier',['pos_fulfilment_mode'=>'collection']);
    $check('collection',0,'in_progress',false,'collection',['pos_fulfilment_mode'=>'courier']);
    $check('',0,'in_progress',false,'collection');
    \Hambelela\EPI\V2Store::$enabled=false;
    $check('courier',0,'in_progress',true);
    echo "PASS: $count current-mode courier-only Paid checks, unpaid collection/delivery, stale historical modes, aliases, no record mutation and existing feature flag\n";
}
