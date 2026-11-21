<?php
declare(strict_types=1);
// Reuse the established isolated route fixtures; all extra business mutations roll back.
require __DIR__.'/test-live-route-phase4.php';
require BASE_PATH.'/shared/delivery/DriverRouteMaps.php';
use Hambelela\Delivery\DriverRouteMaps;
use Hambelela\Delivery\DriverLiveRouteData as R;
$actorId=3;$actorRole='delivery_driver';
$db->exec('CREATE TEMPORARY TABLE delivery_tracking_devices(employee_id BIGINT,active INT,settings_json TEXT)');
$db->exec('CREATE TEMPORARY TABLE delivery_tracking_routes(delivery_id BIGINT PRIMARY KEY,address_hash CHAR(64),destination_lat DOUBLE,destination_lon DOUBLE,geocoded_at BIGINT,calculated_at BIGINT,origin_at BIGINT,distance_metres INT,duration_seconds INT,expected_arrival BIGINT,polyline TEXT,error_code VARCHAR(80),route_lock_until BIGINT NOT NULL DEFAULT 0)');
$db->exec('CREATE TEMPORARY TABLE delivery_tracking_api_usage(usage_day DATE,api_name VARCHAR(20),requests INT,PRIMARY KEY(usage_day,api_name))');
$db->beginTransaction();
try{
 $db->exec("UPDATE delivery_jobs SET status='out_for_delivery',address='24 Synthetic Test Street, Windhoek',area='Windhoek' WHERE id=$id");
 $db->exec("UPDATE ops_orders SET payment_status='paid',payment_method='EFT',status='in_progress' WHERE id=$oid");
 $db->exec("DELETE FROM order_payment_allocations WHERE order_id=$oid");$db->exec("INSERT INTO order_payment_allocations(order_id,payment_method,amount_cents,source) VALUES($oid,'EFT',12000,'pos')");
 $event('parcel_collected',['driver_id'=>3]);
 $point=['latitude'=>-22.56,'longitude'=>17.08,'accuracy'=>20,'recorded_at'=>time(),'event_id'=>'11111111-aaaa-4aaa-8aaa-111111111111'];
 $rev=R::read($db,3)['revision_token'];$calls=[];$mode='ok';
 $transport=function($url,$body,$headers)use(&$calls,&$mode,$db,$id){
  $calls[]=['url'=>$url,'body'=>$body,'headers'=>$headers];
  if(strpos($url,'geocode')!==false)return ['status'=>'OK','results'=>[['geometry'=>['location'=>['lat'=>-22.57,'lng'=>17.09]]]]];
  if($mode==='reassign')$db->exec("UPDATE delivery_jobs SET driver_employee_id=4 WHERE id=$id");
  return ['routes'=>[['duration'=>'300s','distanceMeters'=>2400,'polyline'=>['encodedPolyline'=>'synthetic-only-not-real-route']]]];
 };
 $maps=new DriverRouteMaps($db,'synthetic-key-never-used',$transport);
 $reject=function($run,$label){try{$run();}catch(DomainException $e){verify(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);};
 verify(!(new DriverRouteMaps($db,'',$transport))->route(3,$id,$point,$rev)['available']&&count($calls)===0,'missing API configuration never calls Google');
 $db->prepare('INSERT INTO delivery_tracking_devices VALUES(3,1,?)')->execute([json_encode(['route_daily_limit'=>2,'route_interval_seconds'=>180])]);
 $reject(fn()=>$maps->route(4,$id,$point,$rev),'another driver cannot route assignment');
 $reject(fn()=>$maps->route(3,$id,$point,'old-revision'),'stale route revision blocked');
 $reject(fn()=>$maps->route(3,$id,array_replace($point,['recorded_at'=>time()-180]),$rev),'stale location blocked');
 $reject(fn()=>$maps->route(3,$id,array_replace($point,['accuracy'=>900]),$rev),'inaccurate location blocked');
 $result=$maps->route(3,$id,$point,$rev);verify($result['available']&&$result['driver_id']===3&&$result['assignment_id']===$id,'own assigned delivery has scoped estimate');
 verify($result['distance_metres']===2400&&$result['duration_seconds']===300,'provider estimate is not invented');
 verify(count($calls)===2&&$calls[1]['body']['computeAlternativeRoutes']===false,'one geocode and one route, no automatic optimisation');
 $cache=$db->query('SELECT * FROM delivery_tracking_routes')->fetch();verify($cache['polyline']===null&&$cache['distance_metres']===null&&$cache['duration_seconds']===null,'route results are not persisted');
 verify($db->query('SELECT COUNT(*) FROM delivery_tracking_api_usage')->fetchColumn()==2,'shared request accounting records both APIs');
 verify(!empty($maps->route(3,$id,$point,$rev)['throttled'])&&count($calls)===2,'rapid repeated request respects existing interval');
 $near=$maps->nearby(3,$point,$rev);$own=array_values(array_filter($near['stops'],fn($p)=>$p['assignment_id']===$id));
 verify(count($own)===1&&$own[0]['lat']===-22.57&&$near['driver_id']===3,'nearby map returns verified own cached destination');
 $reject(fn()=>$maps->nearby(3,$point,'old-revision'),'nearby rejects stale assignment revision');
 $reject(fn()=>$maps->nearby(4,$point,$rev),'nearby does not reveal another driver destinations');
 $reject(fn()=>$maps->nearby(3,array_replace($point,['accuracy'=>900]),$rev),'nearby rejects inaccurate position');
 verify(count($calls)===2,'nearby cached coordinates do not incur another route request');
 $db->exec('UPDATE delivery_tracking_routes SET calculated_at=0');$result=$maps->route(3,$id,$point,$rev);verify($result['available']&&count($calls)===3,'permitted destination cache avoids repeat geocode');
 $db->exec('UPDATE delivery_tracking_routes SET calculated_at=0');verify(!$maps->route(3,$id,$point,$rev)['available']&&count($calls)===3,'existing daily request quota enforced before provider');
 $db->exec('UPDATE delivery_tracking_api_usage SET requests=0');$db->exec('UPDATE delivery_tracking_routes SET calculated_at=0,route_lock_until=0');$mode='reassign';
 $db->exec('DELETE FROM delivery_tracking_devices');
 verify(!$maps->route(3,$id,$point,$rev)['available']&&count($calls)===4,'unpaired browser uses existing global limits and discards reassigned result');
 verify($db->inTransaction(),'routing does not commit caller business transaction');
}finally{$db->rollBack();}
verify(R::read($db,3)['revision_token']===$initial['revision_token'],'all business fixtures restored after route checks');
echo "TOTAL $checks combined route checks passed; no external Google requests\n";
