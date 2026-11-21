<?php
// Local layout reference only. Cannot submit to this route or create a real completion.
if(!defined('DRIVER_OFFLINE_LAB')||!DRIVER_OFFLINE_LAB)exit;
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
require_once BASE_PATH.'/shared/delivery/ProofContext.php';
$base='';$local=true;$assignmentId=0;$error='Layout example only. No delivery is selected and no records can be saved.';
$ctx=['reference'=>'POD · DESIGN EXAMPLE','name'=>'Sample recipient','job'=>[],'version'=>0,'arrival'=>'2026-11-20 09:00:00','payment_verified'=>true,'payment_label'=>'EFT paid · example','due'=>null,'reason'=>'No assigned delivery in this layout example.','receipt'=>null,'policy'=>\Hambelela\Delivery\ProofPolicy::configured()];
require BASE_PATH.'/shared/delivery/proof/Helpers.php';require BASE_PATH.'/shared/delivery/proof/View.php';
