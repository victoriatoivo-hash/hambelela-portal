<?php
if(!defined('BASE_PATH'))exit;
require __DIR__.'/../order-details/Helpers.php';
$paths+=['check'=>'<path d="m6 12 4 4 8-8"/><circle cx="12" cy="12" r="10"/>',
    'camera'=>'<rect x="2" y="6" width="20" height="15" rx="3"/><path d="m7 6 2-3h6l2 3"/><circle cx="12" cy="13" r="4"/>',
    'signature'=>'<path d="m4 17 1-5L16 1l5 5-11 11-6 1ZM2 22h20M13 4l5 5"/>',
    'wallet'=>'<rect x="2" y="5" width="20" height="16" rx="3"/><path d="M3 5V3h15v2M22 11h-7v5h7"/>'];
$icon=static fn($key)=>'<svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" aria-hidden="true">'.($paths[$key]??'').'</svg>';
$canSubmit=$ctx&&!$ctx['reason']&&$ctx['arrival']&&in_array($ctx['policy']['evidence'],['one','both','signature'],true);
$back=$base.'/apps/delivery/index.php?delivery='.$assignmentId;
if($assignmentId===0)$back=$base.'/apps/delivery/index.php';
