<?php
declare(strict_types=1);
require __DIR__.'/../apps/operations/business-health-coverage.php';
function bh_check($actual,$expected,string $message):void{if($actual!==$expected)throw new RuntimeException($message.': '.json_encode([$actual,$expected]));}
$result=kpi_business_health_coverage([
 ['key'=>'orders','score'=>0,'sample'=>5],
 ['key'=>'packing','score'=>null,'sample'=>100],
 ['key'=>'quality','score'=>null,'sample'=>4],
 ['key'=>'communications','score'=>null,'sample'=>0],
 ['key'=>'attendance','score'=>50,'sample'=>6],
]);
bh_check($result['measured_categories'],2,'Measured zero counts');
bh_check($result['complete'],false,'No silent missing-category redistribution');
bh_check(array_column($result['components'],'status'),['measured','output_only','insufficient_observations','no_observations','measured'],'Distinct explanations');
bh_check($result['components'][0]['score'],0,'Preserve genuine zero');
bh_check($result['components'][1]['minimum_sample'],null,'No invented packing threshold');
bh_check($result['components'][2]['minimum_sample'],5,'Existing minimum retained');
bh_check($result['components'][4]['scope'],'Current day only (legacy indicator)','Do not mislabel attendance period');
// Exercise the actual profile input expressions without bootstrap or database writes.
$source=file_get_contents(__DIR__.'/../apps/operations/kpi-employee-data.php');
preg_match('/\$frontOrdersRole=([^;]+);/',$source,$roleExpression);
foreach(['marketing_sales'=>true,'front_desk_admin'=>true,'packer'=>false] as $roleKey=>$expected){
 $frontDeskRole=strpos($roleKey,'front_desk')!==false;
 eval('$actual='.$roleExpression[1].';');bh_check($actual,$expected,'Role-specific Orders evidence');
}
preg_match('/\$orderScope=([^;]+);/',$source,$scopeExpression);
foreach([[null,[],0,0],[[],[],0,0],[['rows'=>[]],['completed'=>3],2,5]] as [$frontOrders,$frontCounts,$orderPending,$expected]){
 $orderAccuracyDenominator=900;eval('$actual='.$scopeExpression[1].';');bh_check($actual,$expected,'Never use unrelated workload or fabricated one-order denominator');
}
bh_check(strpos($source,'$frontOrders=$frontOrdersRole?kpi_front_orders_dashboard')!==false,true,'Actual shared profile reader is used');
$orders=file_get_contents(__DIR__.'/../apps/operations/kpi-front-orders.php');
bh_check(strpos($orders,"\$frontDeskEmployee=strpos((string)(\$employee['role_key']??''),'front_desk')!==false")!==false,true,'Marketing is not relabelled as primary Front Desk queue owner');
echo "PASS: category coverage, genuine zero, missing evidence, Marketing Orders inputs and unchanged queue-role gate\n";
