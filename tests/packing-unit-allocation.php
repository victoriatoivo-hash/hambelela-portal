<?php
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../apps/operations/packing-list-action.php');
foreach(['packing_unit_meta','packing_instructions_notes','packing_quantity_plan_stats','packing_received_stock_base','packing_workload_components','packing_invoice_allocation_validation'] as $name){
    $start=strpos($source,'function '.$name.'(');
    $end=strpos($source,"\n}",$start)+2;
    eval(substr($source,$start,$end-$start));
}
$cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
function expect_packing($condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
foreach($cases as [$plan,$received,$unit,$allocated,$packages,$valid]){
    $stats=packing_quantity_plan_stats($plan);
    $dimension=packing_unit_meta($unit)['dimension'];
    expect_packing(abs($stats['totals'][$dimension]-$allocated)<0.00001,$plan.' allocated');
    expect_packing((float)$stats['package_count']===(float)$packages,$plan.' packages');
    $result=packing_invoice_allocation_validation(['quantity_planned'=>$plan,'received_weight'=>$received,'unit'=>$unit]);
    expect_packing($result['valid']===$valid,$plan.' validity');
}
$row=['quantity_planned'=>'50(15)','received_weight'=>'1000','unit'=>'units','bulk_remainder'=>250];
expect_packing(packing_invoice_allocation_validation($row)['valid'],'accounted bulk');
$row['bulk_remainder']=251;
expect_packing(!packing_invoice_allocation_validation($row)['valid'],'excess bulk');
expect_packing(packing_workload_components('1000 units','50(20)','medium')['points']===22.5,'workload');
$notes=packing_instructions_notes("Historic invoice evidence\n[Packing instructions v1] {}",['general_instructions'=>'20 pouches','labelling_instructions'=>'Apply labels','repacking_instructions'=>'Keep dry']);
expect_packing(strpos($notes,'Historic invoice evidence')!==false,'existing notes preserved');
expect_packing(substr_count($notes,'[Packing instructions v1]')===1,'single notes entry');
expect_packing(strpos($notes,'Apply labels')!==false&&strpos($notes,'20 pouches')!==false,'optional notes persisted');
echo 'PASS: '.count($cases)." server cases, bulk remainder, workload and notes preservation\n";
