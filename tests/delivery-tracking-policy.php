<?php
declare(strict_types=1);
require __DIR__.'/../shared/delivery/TrackingPolicy.php';
use Hambelela\Delivery\TrackingPolicy as P;
function check($condition,$message){if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
function blocked(callable $f,$message){try{$f();}catch(DomainException $e){check(true,$message);return;}throw new RuntimeException($message);}
$s=P::settings([]);$now=(new DateTimeImmutable('2026-10-13 12:00:00',new DateTimeZone('Africa/Windhoek')))->getTimestamp();
check(P::window($now,$s)!==null,'Windhoek working hours accepted');
check(P::window($now+6*3600,$s)===null,'off-duty location excluded');
check(P::window((new DateTimeImmutable('2026-10-18 12:00:00+02:00'))->getTimestamp(),$s)===null,'non-working day excluded');
$night=P::settings(['work_start'=>'22:00','work_end'=>'06:00','work_days'=>[1]]);
check(P::window((new DateTimeImmutable('2026-10-13 01:00:00+02:00'))->getTimestamp(),$night)!==null,'overnight shift uses previous start day');
check(P::window((new DateTimeImmutable('2026-10-13 06:00:00+02:00'))->getTimestamp(),$night)===null,'shift ends at exact boundary');
check(P::status($now,$now,$now-10,true,$s,true)==='Live','recent accurate point is live');
check(P::status($now,$now,$now-200,true,$s,true)==='Delayed','queued old GPS is not shown live');
check(P::status($now,$now-400,$now-400,true,$s,true)==='Offline','missing connection is offline');
check(P::status($now,$now,null,false,$s,true)==='Location unavailable','heartbeat without location is not live');
check(P::status($now+6*3600,$now,$now,true,$s,true)==='Off duty','outside working hours is off duty');
$p=['event_id'=>'12345678-1234-4234-8234-123456789012','recorded_at'=>$now,'latitude'=>-22.56,'longitude'=>17.08,'accuracy'=>10];
check(P::point($p,$now)['recorded_at']===$now,'preserve actual observation timestamp');
blocked(fn()=>P::point(array_merge($p,['latitude'=>95]),$now),'invalid coordinates rejected');
blocked(fn()=>P::point(array_merge($p,['recorded_at'=>$now+3600]),$now),'future timestamps rejected');
blocked(fn()=>P::point(array_merge($p,['recorded_at'=>$now-90000]),$now),'expired queued data rejected');
blocked(fn()=>P::settings(['work_start'=>'08:00','work_end'=>'08:00']),'continuous 24-hour tracking rejected');
blocked(fn()=>P::owner(['kind'=>'employee','active'=>true,'id'=>4,'role'=>'delivery_driver']),'driver cannot manage tracking');
blocked(fn()=>P::owner(['kind'=>'partner','active'=>true,'id'=>4,'role'=>'owner_admin']),'partner cannot impersonate Owner');
P::owner(['kind'=>'employee','active'=>true,'id'=>1,'role'=>'owner_admin']);
check(abs(P::metres(-22.56,17.08,-22.56,17.08))<0.01,'stationary distance is zero');
