<?php
declare(strict_types=1);
namespace Hambelela\EPI;

use PDO;

require_once __DIR__.'/EmployeePerformanceService.php';
require_once __DIR__.'/V2PerformanceQuery.php';
require_once __DIR__.'/OwnershipPeriodEngine.php';

/** Read-only adapter: the operational report never calculates an employee score. */
final class BusinessHealthPerformance
{
    public static function attach(PDO $db,array $payload):array
    {
        $from=(string)($payload['period']['from']??'');
        $to=(string)($payload['period']['to']??'');
        $month=substr($from,0,7);
        $singleMonth=preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D',$month) && $month===substr($to,0,7);
        $service=new EmployeePerformanceService($db);
        $query=new V2PerformanceQuery($db);
        foreach($payload['team']??[] as $i=>$person){
            $result=$singleMonth?$service->read((int)$person['id'],$month.'-01'):['status'=>'select_month'];
            $payload['team'][$i]=self::person($person,$result,$singleMonth?$month:null,
                count($query->personalRisk((int)$person['id'])));
        }
        // Legacy actor-speed/backlog rankings do not prove comparable personal performance.
        // Keep the existing cards, but never rank employees from those obsolete formulas.
        foreach(['overall','strengths','risks'] as $group){
            foreach($payload['recognition'][$group]??[] as $i=>$award){
                $payload['recognition'][$group][$i]=[
                    'key'=>$award['key']??'', 'title'=>$award['title']??'',
                    'status'=>'not_determined','confidence'=>'Not validated for comparison',
                    'message'=>'No employee ranking from provisional or incomplete results. Review the published monthly result and personal risk below.'
                ];
            }
        }
        $payload['employee_calculation']='epi-v2-rate-1';
        $payload['financial_use_allowed']=false;
        return $payload;
    }

    /** Pure presentation mapping, shared by cached and uncached report responses. */
    public static function person(array $person,array $result,?string $month,int $risk):array
    {
        $status=(string)($result['status']??'not_configured');
        $official=$result['official_score_hundredths']??null;
        $provisional=$result['provisional_score_hundredths']??null;
        $person['summary_score']=$official!==null?$official/100:($provisional!==null?$provisional/100:null);
        $person['summary_label']=$official!==null?'Official V2 monthly result':'Provisional V2 monthly result';
        if(in_array($status,['not_configured','pending_calculation','select_month'],true))$person['summary_label']='V2 monthly result';
        $person['summary_empty']=$status==='select_month'?'Select one month':(in_array($status,['not_configured','pending_calculation'],true)?'Not Calculated':'Not measured');
        $person['performance_month']=$month;
        $person['performance_status']=$status;
        $person['performance_result_id']=$result['result_id']??null;
        $person['scorecard_version']=$result['scorecard_version']??null;
        $person['summary_note']=($month?$month.' · Full monthly result, not a date-range score. ':'Choose a single month for the published employee result. ')
            .(!empty($result['data_may_be_stale'])?'Data may be stale; check automatic processing on the employee profile. ':'')
            .(!empty($result['function_scope']['description'])?$result['function_scope']['description'].'. ':'')
            .'Provisional results are not for pay or disciplinary decisions.';
        // Do not present the team queue, a completer's elapsed time, or a reporter's
        // incidents as this employee's responsibility. Category evidence is in profile.
        $person['metrics']=[['label'=>'My current risk · tracked obligations','value'=>$risk,
            'tooltip'=>'Personally attributable open obligations only. Incomplete tracking is not proof of zero risk.']];
        $observed=[];
        foreach($result['categories']??[] as $category){
            $score=$category['official_score_hundredths']??$category['provisional_score_hundredths']??null;
            $display=$score===null?'Not measured':number_format($score/100,1).'%';
            if($score!==null && $status!=='official')$display.=' · provisional';
            $person['metrics'][]=['label'=>$category['label'],'value'=>$display,
                'tooltip'=>'Same published category result as the employee profile. A provisional category is not an official employee score; missing required weight is not redistributed.'];
            foreach($category['metrics']??[] as $metric){
                if((int)($metric['eligible_volume']??0)<=0)continue;
                $observed[]=['label'=>$category['label'].' · '.$metric['label'],
                    'value'=>(int)$metric['numerator'].' / '.(int)$metric['eligible_volume']];
            }
        }
        if($observed)$person['metrics'][]=['label'=>'Observed work · provisional, not a score',
            'rows'=>$observed,'tooltip'=>'Each KPI has its own eligible denominator. Published observations do not prove complete monthly source coverage.'];
        return $person;
    }
}
