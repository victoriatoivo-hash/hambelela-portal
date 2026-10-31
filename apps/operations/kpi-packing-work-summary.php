<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/epi/PackerOrdersPreview.php';
require_once BASE_PATH.'/shared/epi/PackerWorkload.php';
require_once BASE_PATH.'/shared/epi/PackingWorkSummary.php';
use Hambelela\EPI\PackerOrdersPreview;
use Hambelela\EPI\PackingWorkSummary;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function pws_reply(array $body,int $code=200):void {http_response_code($code);echo json_encode($body,JSON_INVALID_UTF8_SUBSTITUTE);exit;}
try{
    require_login();
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')pws_reply(['ok'=>false,'message'=>'Read-only report'],405);
    $id=(int)($_GET['id']??ops_current_employee_id());
    if(current_role_key()!=='owner_admin'&&$id!==ops_current_employee_id())pws_reply(['ok'=>false,'message'=>'You may view only your own performance'],403);
    $employee=ops_rows('SELECT e.id,e.full_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?',[$id])[0]??null;
    if(!$employee||strpos($employee['role_key'],'packer')===false)pws_reply(['ok'=>false,'message'=>'Packer profile required'],404);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $now=new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek'));
    $from=(string)($_GET['from']??$now->format('Y-m-01'));$to=(string)($_GET['to']??$now->format('Y-m-d'));
    foreach([$from,$to]as$day){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$day);if(!$d||$d->format('Y-m-d')!==$day)throw new InvalidArgumentException('Choose valid dates.');}
    if($from>$to||(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366)throw new InvalidArgumentException('Choose a period of no more than one year.');
    $from.=' 00:00:00';$to=min($to.' 23:59:59',$now->format('Y-m-d H:i:s'));
    if($from>$to)throw new InvalidArgumentException('Choose a period that has already started.');
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
    $exceptions=[];
    if(ops_table_exists('epi_v2_exceptions'))$exceptions=ops_rows("SELECT employee_id,object_reference,effective_from,effective_to FROM epi_v2_exceptions WHERE employee_id=? AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND (module='Packing List' OR module IS NULL) AND (kind='absence' OR (kind='obligation_excusal' AND object_reference LIKE 'PACK-%'))",[$id]);
    $earliest=$from;foreach($items as$r)if(!empty($r['date_started']))$earliest=min($earliest,$r['date_started']);
    $hr=null;try{if(function_exists('ops_hr_db'))$hr=ops_hr_db();}catch(Throwable $ignored){}
    $absence=PackingWorkSummary::absences(db(),$hr,$id,$earliest,$to);
    $summary=PackingWorkSummary::build($items,$events,$owners,array_merge($exceptions,$absence['intervals']),$id,$from,$to,new PackerOrdersPreview($closures));
    $summary['absence_source_verified']=$absence['verified'];
    // Evidence is restricted to this employee's batches; no colleague's note text is returned.
    pws_reply(['ok'=>true,'employee'=>$employee['full_name'],'from'=>$from,'to'=>$to,'summary'=>$summary,
        'policy'=>['stalled_alerts'=>false,'fixed_deadlines'=>false,'score_changes'=>false,'priority'=>'Queue order only. Historical availability is not assumed; no automatic priority-skipping penalty.',
        'timing'=>'Working-time turnaround, not hands-on labour time. Mon–Fri 08:00–17:00, Sat 09:00–13:00; Sundays, holidays and recorded closures excluded. Only explicitly approved exclusion intervals are deducted; missing blocker/absence evidence is not guessed.',
        'quantities'=>'Finished batches use their recorded package breakdown, once per item, not cumulative edits. In-progress quantities remain visible separately. Quantity differences require review, not automatic blame.']] );
}catch(InvalidArgumentException $e){pws_reply(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('Packing work summary: '.$e->getMessage());pws_reply(['ok'=>false,'message'=>'Packing work could not be loaded. Please retry. No work records were changed.'],503);}
