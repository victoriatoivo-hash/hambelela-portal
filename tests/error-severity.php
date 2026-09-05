<?php
require __DIR__.'/../apps/operations/kpi-error-severity.php';
function ops_rows(...$args):array{return [['assigned_employee_id'=>6,'completed_at'=>'2026-08-01 10:00:00']];}
$row=['category'=>'task_false_completion','accuracy_verified_by'=>1,'accuracy_verified_at'=>'2026-09-05','affects_kpi_accuracy'=>1,'description'=>'Task #123: required work was not done.'];
if(!kpi_false_completion_verified($row,6)||kpi_false_completion_verified($row,7))throw new RuntimeException('Attribution failed');
unset($row['accuracy_verified_by'],$row['accuracy_verified_at']);if(!kpi_false_completion_verified($row,6))throw new RuntimeException('Owner approval should not be required');
$missing=$row;$missing['description']='No task reference';if(kpi_false_completion_verified($missing,6))throw new RuntimeException('Missing reference accepted');
$excluded=$row;$excluded['affects_kpi_accuracy']=0;if(kpi_false_completion_verified($excluded,6))throw new RuntimeException('Excluded incident accepted');
$ordinary=$row;$ordinary['category']='wrong_product';if(kpi_false_completion_verified($ordinary,6))throw new RuntimeException('Ordinary error reclassified');
if(kpi_error_severity_weight(['verified_false_completion'=>true,'severity'=>'low'])!==6||kpi_error_severity_weight(['severity'=>'critical'])!==4)throw new RuntimeException('Weights failed');
echo "Error severity safeguards passed.\n";
