<?php
if(!defined('BASE_PATH'))exit;
require __DIR__.'/../order-details/Helpers.php';
$paths+=['alert'=>'<path d="m12 2 10 18H2L12 2Z"/><path d="M12 9v4M12 17h.01"/>','camera'=>'<rect x="2" y="6" width="20" height="15" rx="3"/><path d="m7 6 2-3h6l2 3"/><circle cx="12" cy="13" r="4"/>','check'=>'<path d="m4 12 5 5 11-11"/>'];
$icon=static fn($key)=>'<svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" aria-hidden="true">'.($paths[$key]??'').'</svg>';
$reasons=['not_answering'=>['Customer not answering','Called or knocked; no response'],'wrong_address'=>['Wrong or missing address','Location details are incomplete'],'unavailable'=>['Customer unavailable','Requested another delivery time'],'other'=>['Other issue','Damage, safety or other concern']];
