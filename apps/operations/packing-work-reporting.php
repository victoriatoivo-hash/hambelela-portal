<?php
declare(strict_types=1);
require_once BASE_PATH.'/shared/epi/PackerOrdersPreview.php';
require_once BASE_PATH.'/shared/epi/PackerWorkload.php';
require_once BASE_PATH.'/shared/epi/PackingWorkSummary.php';
use Hambelela\EPI\PackerOrdersPreview;
use Hambelela\EPI\PackingWorkSummary;

/** One read-only historical source for profiles and business totals; loaded once per request. */
function kpi_packing_work_source(): array
{
    static $source;
    if ($source !== null) return $source;
    $closures=[];
    if(ops_table_exists('kpi_holidays'))foreach(ops_rows('SELECT holiday_date FROM kpi_holidays WHERE active=1')as$r)$closures[]=$r['holiday_date'];
    if(ops_table_exists('epi_employee_business_calendar'))foreach(ops_rows('SELECT business_date FROM epi_employee_business_calendar WHERE is_working_day=0')as$r)$closures[]=$r['business_date'];
    if(!ops_table_exists('ops_packing_tasks'))throw new RuntimeException('Packing records are unavailable.');
    $columns=['id','item_name','assigned_employee_id','packing_status','quantity_planned','quantity_packed','priority','date_loaded','created_at'];
    foreach(['date_started','date_completed','packing_website_confirmed','packing_website_completed_at','frontdesk_website_updated','frontdesk_website_updated_at','frontdesk_website_updated_by','notes']as$c)if(ops_column_exists('ops_packing_tasks',$c))$columns[]=$c;
    // Include carry-in batches and archived history. Never cap the first 500 items.
    $where=ops_column_exists('ops_packing_tasks','deleted_at')?' WHERE deleted_at IS NULL':'';
    $items=ops_rows('SELECT '.implode(',',$columns).' FROM ops_packing_tasks'.$where);
    $allIds=array_fill_keys(array_map(static fn($r)=>(int)$r['id'],$items),true);$events=[];$owners=[];$knownStatus=[];
    if(ops_table_exists('ops_activity_logs')){
        $q=db()->query("SELECT id,entity_id,employee_id,action,metadata,UNIX_TIMESTAMP(created_at) event_epoch FROM ops_activity_logs WHERE entity_type='packing_task' ORDER BY created_at,id");
        while($r=$q->fetch(PDO::FETCH_ASSOC)){
            $tid=(int)$r['entity_id'];if(!isset($allIds[$tid]))continue;$m=json_decode((string)$r['metadata'],true)?:[];
            $at=(new DateTimeImmutable('@'.(int)$r['event_epoch']))->setTimezone(new DateTimeZone('Africa/Windhoek'))->format('Y-m-d H:i:s');
            $field=(string)($m['field']??'');$before=$m['previous_value']??$m['previous_status']??null;$after=$m['new_value']??$m['value']??$m['status']??null;
            if(!$field&&isset($m['previous_status'],$m['status']))$field='packing_status';
            if($field&&$before!==null&&$after!==null){
                $events[$tid][]=['id'=>(int)$r['id'],'at'=>$at,'field'=>$field,'before'=>$before,'after'=>$after,'actor'=>(int)$r['employee_id']];
                if($field==='packing_status')$knownStatus[$tid][$at.'|'.PackingWorkSummary::status($after)]=true;
            }
        }$q->closeCursor();
    }
    if(ops_table_exists('kpi_status_events')){
        $q=db()->query("SELECT id,record_id,old_status,new_status,changed_by,changed_at FROM kpi_status_events WHERE module='packing' ORDER BY changed_at,id");
        while($r=$q->fetch(PDO::FETCH_ASSOC)){
            $tid=(int)$r['record_id'];if(!isset($allIds[$tid]))continue;
            $at=(new DateTimeImmutable($r['changed_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Windhoek'))->format('Y-m-d H:i:s');
            if(isset($knownStatus[$tid][$at.'|'.PackingWorkSummary::status($r['new_status'])]))continue;
            $events[$tid][]=['id'=>(int)$r['id'],'at'=>$at,'field'=>'packing_status','before'=>$r['old_status'],'after'=>$r['new_status'],'actor'=>(int)$r['changed_by']];
        }$q->closeCursor();
    }
    if(ops_table_exists('ops_packing_assignment_log'))foreach(ops_rows('SELECT id,packing_task_id,old_employee_id,new_employee_id,UNIX_TIMESTAMP(changed_at) event_epoch FROM ops_packing_assignment_log ORDER BY changed_at,id')as$r){
        $owners[(int)$r['packing_task_id']][]=['id'=>(int)$r['id'],'at'=>(new DateTimeImmutable('@'.(int)$r['event_epoch']))->setTimezone(new DateTimeZone('Africa/Windhoek'))->format('Y-m-d H:i:s'),'old'=>(int)$r['old_employee_id'],'new'=>(int)$r['new_employee_id']];
    }
    return $source=compact('closures','items','events','owners');
}

function kpi_packing_work_summary(int $id, string $from, string $to, ?array $source=null): array
{
    static $summaries=[];
    $key=$id.'|'.$from.'|'.$to;
    if($source===null&&isset($summaries[$key]))return $summaries[$key];
    $source=$source??kpi_packing_work_source();
    extract($source, EXTR_SKIP);
    $exceptions=[];
    if(ops_table_exists('epi_v2_exceptions'))$exceptions=ops_rows("SELECT employee_id,object_reference,effective_from,effective_to FROM epi_v2_exceptions WHERE employee_id=? AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND (module='Packing List' OR module IS NULL) AND (kind='absence' OR (kind='obligation_excusal' AND object_reference LIKE 'PACK-%'))",[$id]);
    $earliest=$from;foreach($items as$r)if(!empty($r['date_started']))$earliest=min($earliest,$r['date_started']);
    $hr=null;try{if(function_exists('ops_hr_db'))$hr=ops_hr_db();}catch(Throwable $ignored){}
    $absence=PackingWorkSummary::absences(db(),$hr,$id,$earliest,$to);
    $summary=PackingWorkSummary::build($items,$events,$owners,array_merge($exceptions,$absence['intervals']),$id,$from,$to,new PackerOrdersPreview($closures));
    $summary['absence_source_verified']=$absence['verified'];
    return $summaries[$key]=$summary;
}

/** Daily completed output uses the exact same historical quantities and owners as profiles. */
function kpi_packing_output_trend(array $employees,string $from,string $to): array
{
    $rows=[];$source=kpi_packing_work_source();
    foreach($employees as$employee){
        $summary=kpi_packing_work_summary((int)$employee['id'],$from,$to);
        foreach($summary['output_daily'] as$day=>$output)$rows[]=array_merge($output,['day'=>$day,'assigned_employee_id'=>(int)$employee['id'],'employee_name'=>$employee['full_name']]);
    }
    usort($rows,static fn($a,$b)=>[$a['day'],$a['employee_name']]<=>[$b['day'],$b['employee_name']]);
    return $rows;
}

