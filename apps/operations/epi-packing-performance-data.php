<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
header('Content-Type: application/json; charset=utf-8');
if (current_role_key()==='guest'){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Authentication required.']);exit;}
if (!user_has_role('owner_admin','supervisor_manager')){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Owner or supervisor access required.']);exit;}
try{
    $service=new \Hambelela\EPI\PackingPerformance(db());
    $filters=['period'=>(string)($_GET['period']??'previous_month'),'date_from'=>(string)($_GET['date_from']??''),'date_to'=>(string)($_GET['date_to']??''),'employee_id'=>(string)($_GET['employee_id']??''),'priority'=>(string)($_GET['priority']??''),'packing_status'=>(string)($_GET['packing_status']??''),'product_state'=>(string)($_GET['product_state']??''),'workload_class'=>(string)($_GET['workload_class']??'')];
    $kind=(string)($_GET['kind']??'employee_summary');
    $map=['employee_summary'=>'getEmployeeSummary','order_summary'=>'getOrderSummary','packing_list_summary'=>'getPackingListSummary','turnaround'=>'getTurnaroundMetrics','priority'=>'getPriorityCompliance','quantity'=>'getQuantityAccuracy','workload'=>'getWorkloadProfile','website'=>'getWebsiteUpdateCompliance','current'=>'getCurrentStatus','evidence'=>'getEvidence'];
    if(!isset($map[$kind]))throw new RuntimeException('Unknown data kind.');
    $payload=$kind==='evidence'?$service->getEvidence($filters,(int)($_GET['limit']??250)):$service->{$map[$kind]}($filters);
    echo json_encode(['ok'=>true,'kind'=>$kind,'data'=>$payload],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Unable to load EPI Packing verification data.']);}

