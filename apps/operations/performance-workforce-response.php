<?php
declare(strict_types=1);
// Included by the existing authenticated report endpoint, not a standalone page.
if (!defined('BASE_PATH') || !function_exists('current_role_key')) { http_response_code(404); exit; }
require_role('owner_admin');
require_once BASE_PATH.'/shared/epi/EmployeePerformanceService.php';
header('Cache-Control: private, no-store');
header('X-Performance-Calculation: epi-v2-rate-1');
try {
    $month=(string)($_GET['month']??date('Y-m'));
    if(!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D',$month))throw new InvalidArgumentException('Choose a valid reporting month.');
    $employees=ops_rows("SELECT e.id,e.full_name,r.name role_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key<>'owner_admin' ORDER BY r.role_key,e.full_name");
    $employeeId=(int)($_GET['employee_id']??0);$role=(string)($_GET['role']??'all');
    $selected=array_values(array_filter($employees,static function(array $e)use($employeeId,$role):bool{
        return (!$employeeId||(int)$e['id']===$employeeId)&&($role==='all'||$e['role_key']===$role);
    }));
    if((string)($_GET['action']??'')==='dashboard_summary'){
        // Same stored result as profile/export; no dashboard score formula or GET recalculation.
        require_once BASE_PATH.'/shared/epi/V2PerformanceQuery.php';
        require_once BASE_PATH.'/shared/epi/OwnershipPeriodEngine.php';
        $service=new \Hambelela\EPI\EmployeePerformanceService(db());
        $risk=new \Hambelela\EPI\V2PerformanceQuery(db());$summaries=[];
        foreach($selected as $person){
            $result=$service->read((int)$person['id'],$month.'-01');
            $summaries[]=['employee_id'=>(int)$person['id'],'status'=>$result['status'],
                'official_score_hundredths'=>$result['official_score_hundredths']??null,
                'provisional_score_hundredths'=>$result['provisional_score_hundredths']??null,
                'result_id'=>$result['result_id']??null,'scorecard_version'=>$result['scorecard_version']??null,
                'last_calculated_at'=>$result['last_calculated_at']??null,
                'data_may_be_stale'=>!empty($result['data_may_be_stale']),
                'personal_risk_count'=>count($risk->personalRisk((int)$person['id'])),
                'risk_scope'=>'Tracked attributable obligations only; incomplete source coverage is not zero risk'];
        }
        kpi_send_json(['ok'=>true,'period_start'=>$month.'-01','employees'=>$summaries,'financial_use_allowed'=>false]);
    }
    $result=(new \Hambelela\EPI\EmployeePerformanceService(db()))->workforce($month.'-01',$selected);
    if(in_array((string)($_GET['action']??''),['export_bundle','export_csv'],true)){
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="employee-performance-'.$month.'.csv"');
        $out=fopen('php://output','wb');
        fputcsv($out,\Hambelela\EPI\EmployeePerformanceService::exportHeaders());
        foreach($result['reports'] as $entry)foreach(\Hambelela\EPI\EmployeePerformanceService::exportRows($entry['performance']) as $row){
            foreach($row as &$cell)if(is_string($cell)&&preg_match('/^[=+@\-\t\r]/',$cell))$cell="'".$cell;unset($cell);
            fputcsv($out,$row);
        }
        fclose($out);exit;
    }
    kpi_send_json(['ok'=>true,'employees'=>$employees,'workforce'=>$result]);
} catch(InvalidArgumentException $error) {
    kpi_send_json(['ok'=>false,'message'=>$error->getMessage()],422);
} catch(Throwable $error) {
    error_log('Employee performance workforce read failed: '.$error->getMessage());
    kpi_send_json(['ok'=>false,'message'=>'Performance results could not be loaded. No scores were changed.'],503);
}
