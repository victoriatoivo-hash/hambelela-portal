<?php
declare(strict_types=1);
require __DIR__.'/../apps/operations/kpi-command-policy.php';
require __DIR__.'/../apps/operations/kpi-front-packing-list.php';
require __DIR__.'/../apps/operations/kpi-packer-orders.php';
function ops_table_exists(string $name):bool{return true;}
function ops_rows(string $sql,array $params=[]):array{return $GLOBALS['syntheticPackingEvents'];}
$settings=['target_fulfilment_hours'=>2,'frontdesk_website_target_minutes'=>300,'command02_timing_effective_from'=>'2026-11-23 20:00:00','command02_previous_packing_hours'=>6,'command02_previous_website_minutes'=>480];
$check=static function($actual,$expected):void{if($actual!==$expected)throw new RuntimeException(var_export($actual,true).' != '.var_export($expected,true));};
$check(kpi_command_target_minutes($settings,'packing','2026-11-23 19:59:59'),360.0);
$check(kpi_command_target_minutes($settings,'packing','2026-11-23 20:00:00'),120.0);
$check(kpi_command_target_minutes($settings,'packing',null),360.0);
$check(kpi_command_target_minutes($settings,'website','2026-11-22 10:00:00'),480.0);
$check(kpi_command_target_minutes($settings,'website','2026-11-24 08:00:00'),300.0);
$zone=new DateTimeZone('Africa/Windhoek');
$check(kpi_front_packing_deadline(new DateTimeImmutable('2026-11-28 10:00:00',$zone),[],$settings)->format('Y-m-d H:i'),'2026-11-30 10:00');
$check(kpi_front_packing_deadline(new DateTimeImmutable('2026-11-29 12:00:00',$zone),[],$settings)->format('Y-m-d H:i'),'2026-11-30 13:00');
$event=static fn(string $id,string $started):array=>['reference_number'=>$id,'occurred_at'=>'2026-11-25 10:00:00','working_minutes'=>180,'metadata_json'=>json_encode(['created_at'=>$started]),'item_quantity'=>1,'fulfilment_mode'=>'collection'];
$GLOBALS['syntheticPackingEvents']=[$event('synthetic-old','2026-11-22 09:00:00')];
$old=kpi_packer_epi_orders_evidence(1,'2026-11-01','2026-11-30',$settings,[]);
$check($old['target_minutes'],360.0);$check($old['counts']['on_time'],1);
$GLOBALS['syntheticPackingEvents'][]=$event('synthetic-new','2026-11-24 09:00:00');
$mixed=kpi_packer_epi_orders_evidence(1,'2026-11-01','2026-11-30',$settings,[]);
$check((float)$mixed['target_minutes'],240.0);$check($mixed['counts']['on_time'],1);$check($mixed['counts']['late'],1);
$check($mixed['rows'][0]['target_minutes'],360.0);$check($mixed['rows'][1]['target_minutes'],120.0);
echo "PASS: prospective allowances, historical windows, unknown dates, Saturday carry-over and Sunday pause\n";
