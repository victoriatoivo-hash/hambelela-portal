<?php
if(!defined('BASE_PATH'))exit;
require BASE_PATH.'/shared/delivery/order-details/Helpers.php';
$activeDriverTab='map';
$routePaths=[
 'filter'=>'<path d="M4 7h16M7 12h10M10 17h4"/>',
 'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
 'distance'=>'<path d="M6 3v18M18 3v18M12 4v3m0 3v4m0 3v3"/>',
 'locate'=>'<circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3"/>',
 'chevron'=>'<path d="m9 5 7 7-7 7"/>',
 'check'=>'<path d="m5 12 4 4L19 6"/>',
 'optimise'=>'<path d="M4 7h15m-4-4 4 4-4 4M20 17H5m4-4-4 4 4 4"/>'
];
$baseIcon=$icon;
$icon=static fn($key)=>isset($routePaths[$key])?'<svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" aria-hidden="true">'.$routePaths[$key].'</svg>':$baseIcon($key);
$reference=isset($reference)&&$reference&&$local;
