<?php
declare(strict_types=1);
require __DIR__.'/delivery-production-core.php';
use Hambelela\Delivery\DeliveryDeadline;
$pref=['preference_mode'=>'window','preference_date'=>$body['date'],'preference_time'=>'14:00','preference_end'=>'16:00'];
$d=DeliveryDeadline::normalize($pref);
ok(substr($d['end_at'],11,5)==='14:00','Windhoek input stored as UTC');
foreach([['preference_mode'=>'bad'],array_merge($pref,['preference_end'=>'13:00']),array_merge($pref,['preference_date'=>'2026-02-30'])] as $bad){try{DeliveryDeadline::normalize($bad);throw new RuntimeException('Invalid deadline accepted');}catch(DomainException $e){ok(true,'invalid preference rejected');}}
$oid=random_int(100000000,900000000);$db->exec("INSERT INTO ops_orders(id,total_amount,notes) VALUES($oid,450,'Shipping address: Test delivery street')");
$add=array_merge($body,$pref,['order'=>$oid,'uuid'=>uid(),'urgent'=>false]);
$j=$core->command($front,$add);$jid=$j['id'];
ok($core->command($front,$add)===$j,'deadline assignment retry creates one job');
$v=$core->board($driver,['id'=>$jid])['jobs'][0];
ok($v['deadline']['mode']==='window'&&$v['deadline']['status']==='ETA unavailable','Driver receives confirmed window without invented ETA');
ok((int)$v['fee_cents']===6000&&(int)$v['urgent']===0,'deadline does not alter fee or urgent flag');
$edit=['action'=>'deadline','id'=>$jid,'uuid'=>uid(),'version'=>$v['version'],'preference_mode'=>'before','preference_date'=>$body['date'],'preference_time'=>'12:00'];
$core->command($front,$edit);ok($core->command($front,$edit)['id']===$jid,'deadline editing is idempotent');
try{$core->command($driver,array_merge($edit,['uuid'=>uid()]));throw new RuntimeException('Driver edited deadline');}catch(DomainException $e){ok(true,'Driver cannot edit customer deadline');}
try{$core->command($front,array_merge($edit,['uuid'=>uid()]));throw new RuntimeException('Stale edit');}catch(DomainException $e){ok(true,'stale deadline edit rejected');}
$events=$db->query("SELECT employee_id,metadata_json FROM delivery_events WHERE delivery_id=$jid AND event_type='deadline_updated' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
ok(count($events)===2&&(int)$events[1]['employee_id']===$id,'deadline history records author and both versions');
ok($core->board($driver,['state'=>'for_delivery'])['jobs'][0]['id']==$jid,'confirmed deadline gets queue priority');
$past=['mode'=>'before','start_at'=>null,'end_at'=>'2020-01-01 10:00:00'];
$db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(?,'deadline_updated',?,?)")->execute([$jid,$id,json_encode(['deadline'=>$past])]);
DeliveryDeadline::notifyLate($db,$jid);DeliveryDeadline::notifyLate($db,$jid);
$count=(int)$db->query("SELECT COUNT(*) FROM delivery_outbox WHERE delivery_id=$jid AND event_key LIKE 'deadline-late:%:driver:%'")->fetchColumn();
ok($count===1,'late alerts deduplicated across repeated scans');
ok(DeliveryDeadline::present($past,['status'=>'ready'])['status']==='Late','actual missed deadline marked late without GPS');
ok(DeliveryDeadline::present($past,['status'=>'completed','delivered_at'=>'2020-01-01 09:00:00'])['status']==='Completed On Time','completion uses actual timestamp');
ok(DeliveryDeadline::present($past,['status'=>'completed','delivered_at'=>'2020-01-01 11:00:00'])['status']==='Completed Late','late completion evidence preserved');
echo "Deadline tests passed on synthetic records only.\n";
