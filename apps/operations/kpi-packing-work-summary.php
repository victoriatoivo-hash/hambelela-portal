<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/apps/operations/packing-work-reporting.php';
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
    $summary=kpi_packing_work_summary($id,$from,$to);
    // Evidence is restricted to this employee's batches; no colleague's note text is returned.
    pws_reply(['ok'=>true,'employee'=>$employee['full_name'],'from'=>$from,'to'=>$to,'summary'=>$summary,
        'policy'=>['stalled_alerts'=>false,'fixed_deadlines'=>false,'score_changes'=>false,'priority'=>'Queue order only. Historical availability is not assumed; no automatic priority-skipping penalty.',
        'timing'=>'Working-time turnaround, not hands-on labour time. Mon–Fri 08:00–17:00, Sat 09:00–13:00; Sundays, holidays and recorded closures excluded. Only explicitly approved exclusion intervals are deducted; missing blocker/absence evidence is not guessed.',
        'quantities'=>'Finished batches use their recorded package breakdown, once per item, not cumulative edits. In-progress quantities remain visible separately. Quantity differences require review, not automatic blame.']] );
}catch(InvalidArgumentException $e){pws_reply(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('Packing work summary: '.$e->getMessage());pws_reply(['ok'=>false,'message'=>'Packing work could not be loaded. Please retry. No work records were changed.'],503);}
