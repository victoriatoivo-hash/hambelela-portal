<?php
// Authenticated, synthetic presentation fixture only. Never included by a production route.
if(!defined('DRIVER_OFFLINE_LAB')||DRIVER_OFFLINE_LAB!==true){http_response_code(404);exit;}
$local=true;$reference=true;$base='';$driverId=3;$error=null;
$stops=[];
foreach(['Pioneers Park','Klein Windhoek'] as $i=>$area)$stops[]=['assignment_id'=>0,'area'=>$area,'customer_display_name'=>'Sample customer','order_reference'=>'DESIGN-'.($i+1),'status_label'=>'Sample only','address_display'=>null];
if(isset($_GET['stress']))for($i=3;$i<=25;$i++)$stops[]=['assignment_id'=>0,'area'=>'Synthetic long area description for layout verification '.$i,'customer_display_name'=>str_repeat('Sample long customer name ',3),'order_reference'=>'DESIGN-'.$i,'status_label'=>'Sample only','address_display'=>null];
$data=['date'=>(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d'),'remaining_stops'=>count($stops),'stops'=>$stops,'needs_review'=>[]];
require BASE_PATH.'/shared/delivery/live-route/Helpers.php';
ob_start();require BASE_PATH.'/shared/delivery/live-route/View.php';$html=ob_get_clean();
// Keep dummy reference rows non-navigating; no sample assignment IDs reach a real controller.
$html=preg_replace('/<a class="ho-driver-route__stop" href="[^"]+" data-route-stop>/', '<div class="ho-driver-route__stop" data-route-stop>', $html);
$html=str_replace('</span><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></a>', '</span><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></div>', $html);
echo $html;
