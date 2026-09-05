<?php
require __DIR__.'/../apps/operations/kpi-courier-waybills-performance.php';
function ops_rows(...$args): array { return $GLOBALS['fixture']; }
$base=['id'=>1,'batch_id'=>'test','sent_date'=>'2026-08-03','courier_names'=>'Test Courier','number_of_waybills'=>1,'file_path'=>'a.pdf','original_filename'=>'a.pdf','uploaded_at'=>'2026-08-03 16:00:00','uploaded_by'=>6,'sent_at'=>'2026-08-04 08:00:00','sent_by'=>2,'status'=>'sent','notes'=>'','waybill_reference'=>'test','order_id'=>null,'customer_name'=>'','due_by'=>null,'uploaded_by_name'=>'Packer','uploaded_role_key'=>'packer','sent_by_name'=>'Front','sent_role_key'=>'front'];
$fixture=[$base,array_replace($base,['id'=>2,'uploaded_at'=>'2026-08-03 18:00:00','sent_at'=>null])];
$result=kpi_courier_waybills_performance(['id'=>6,'role_key'=>'packer'],'2026-08-01','2026-08-31',[],[]);
if($result['counts']['packer_late']!==1 || $result['counts']['sent_waybills']!==0 || $result['counts']['pending']!==1)throw new RuntimeException('Late attachment/partial sending failed');
$fixture[1]['sent_at']='2026-08-04 10:00:00';
$result=kpi_courier_waybills_performance(['id'=>6,'role_key'=>'packer'],'2026-08-01','2026-08-31',[],[]);
if($result['rows'][0]['sent_at']!=='2026-08-04 10:00:00' || $result['counts']['front_late']!==1)throw new RuntimeException('Final sending time failed');
echo "Courier batch timing safeguards passed.\n";
