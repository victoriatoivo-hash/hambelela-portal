<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(!user_has_role('owner_admin')){http_response_code(403);echo json_encode(['error'=>'Owner access required']);exit;}
try{echo json_encode((new \Hambelela\EPI\V2PerformanceQuery(db()))->watchdogHealth(),JSON_UNESCAPED_SLASHES);}
catch(Throwable $e){http_response_code(503);echo json_encode(['unhealthy'=>true,'status'=>'unavailable','message'=>'P0 health unavailable; check deployment and worker logs.']);}
