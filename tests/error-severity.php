<?php
require __DIR__.'/../apps/operations/kpi-error-severity.php';
function ops_rows(...$args):array{return [['assigned_employee_id'=>6,'completed_at'=>'2026-08-01 10:00:00']];}
$row=['category'=>'task_false_completion','accuracy_verified_by'=>1,'accuracy_verified_at'=>'2026-09-05','affects_kpi_accuracy'=>1,'description'=>'Task #123: required work was not done.'];
if(!kpi_false_completion_verified($row,6)||kpi_false_completion_verified($row,7))throw new RuntimeException('Attribution failed');
unset($row['accuracy_verified_by']);if(kpi_false_completion_verified($row,6))throw new RuntimeException('Unverified incident accepted');
if(kpi_error_severity_weight(['verified_false_completion'=>true,'severity'=>'low'])!==6||kpi_error_severity_weight(['severity'=>'critical'])!==4)throw new RuntimeException('Weights failed');
echo "Error severity safeguards passed.\n";
