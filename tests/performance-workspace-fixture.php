<?php
// Disposable rendering fixture. Never deployed and contains no live employee records.
define('BASE_PATH',dirname(__DIR__));define('BASE_URL','');$tab='dashboard';
function kpi_performance_employee_predicate($e,$r){return '1=1';}
function ops_hr_db(){return null;}
function ops_rows($sql){return [
 ['id'=>1,'full_name'=>'Klaudia Averinus','role_key'=>'packer','role_name'=>'Packer/Production Staff'],
 ['id'=>2,'full_name'=>'Ndinelao Kalola','role_key'=>'packer','role_name'=>'Packer/Production Staff'],
 ['id'=>3,'full_name'=>'Hope Kahuika','role_key'=>'marketing_sales','role_name'=>'Marketing & Sales Assistant'],
 ['id'=>4,'full_name'=>'Secilia Shiweda','role_key'=>'front_desk','role_name'=>'Front Desk/Admin Employee']
];}
echo '<main data-kpi-tab="dashboard"><!-- No employee score is calculated by this presentation. -->';
include BASE_PATH.'/apps/operations/performance-workspace.php';echo '</main>';
