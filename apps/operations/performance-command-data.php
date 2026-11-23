<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once __DIR__.'/kpi-reporting.php';
require_login();
$owner=current_role_key()==='owner_admin';
if (!$owner && ((string)($_GET['section']??'')!=='drivers' || (int)($_GET['employee_id']??0)!==ops_current_employee_id() || strpos(current_role_key(),'driver')===false)) { http_response_code(403); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// Read-only projections. Never initialise another application's schema here.
function pc_metric(string $label,$value,string $note='',string $format='number'):array {
    return ['label'=>$label,'value'=>$value,'note'=>$note,'format'=>$format];
}
try {
    $period=kpi_resolve_reporting_period($_GET+['period'=>'this_month']);
    $from=$period['from']->format('Y-m-d'); $to=$period['to']->format('Y-m-d');
    $section=(string)($_GET['section']??''); $cards=[]; $rows=[]; $notes=[];
    if ($section==='marketing') {
        require_once BASE_PATH.'/shared/marketing.php';
        // Loading function definitions does not call marketing_require_access/schema_ready.
        $ctx=marketing_phase3_context(['period'=>'custom','date_from'=>$from,'date_to'=>$to]);
        $s=$ctx['summary']; $execution=$ctx['execution'];
        // A missing publishing target is not proof of on-time publication.
        $publishingDue=array_values(array_filter($ctx['content'],static function(array $item)use($from,$to):bool{
            $target=(string)($item['publish_at']??'');
            return $target!=='' && substr($target,0,10)>=$from && substr($target,0,10)<=$to && $target<=(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');
        }));
        $publishingOnTime=count(array_filter($publishingDue,static fn(array $item):bool=>!empty($item['published_at']) && $item['published_at']<=$item['publish_at']));
        $execution['on_time_rate']=$publishingDue?round(100*$publishingOnTime/count($publishingDue),1):null;
        $cards=[pc_metric('Content published',$s['content_published'],'Published timestamp in selected period'),pc_metric('Active campaigns',$s['campaigns_active'],'Campaign dates overlap selected period'),pc_metric('On-time publishing',$execution['on_time_rate'],'Existing Marketing calculation','percent'),pc_metric('First-pass approval',$execution['first_pass_rate'],'Recorded approval / correction evidence','percent'),pc_metric('Reported advertising spend',$s['spend'],'Marketing records; not a reconciled expense ledger','currency'),pc_metric('Campaign-attributed order value',$s['revenue_attributed'],'Recorded attribution; not profit or cash receipts','currency'),pc_metric('Reported reach',$s['reach'],'Latest valid channel snapshots'),pc_metric('Reported clicks',$s['clicks'],'Latest valid channel snapshots'),pc_metric('Return on ad spend',$s['roas'],'Attributed order value / recorded spend; not ROI','ratio'),pc_metric('Profit-based ROI',null,'No verified campaign costs / margin basis'),pc_metric('Website visitors',null,'No verified traffic integration in this report'),pc_metric('Conversion rate',null,'A comparable visitor/conversation denominator is required')];
        foreach($ctx['content'] as $item) {
            if(substr((string)$item['created_at'],0,10)>$to || (substr((string)$item['created_at'],0,10)<$from && substr((string)$item['due_at'],0,10)<$from && substr((string)$item['published_at'],0,10)<$from)) continue;
            $rows[]=['reference'=>'Content #'.$item['id'],'title'=>$item['title'],'status'=>$item['status'],'assignment_due'=>$item['due_at'],'publishing_target'=>$item['publish_at'],'published'=>$item['published_at'],'channel'=>$item['platform'],'href'=>BASE_URL.'/apps/marketing/execution.php?id='.(int)$item['id']];
        }
        foreach($ctx['campaigns'] as $item) {
            if(!empty($item['start_date'])&&$item['start_date']>$to || !empty($item['end_date'])&&$item['end_date']<$from)continue;
            $rows[]=['reference'=>'Campaign #'.$item['id'],'title'=>$item['name'],'status'=>$item['status'],'due'=>$item['end_date'],'completed'=>null,'channel'=>$item['channels_text'],'href'=>BASE_URL.'/apps/marketing/index.php?view=campaigns'];
        }
        $cards[2]['note']=$publishingOnTime.' on time / '.count($publishingDue).' explicit publishing targets due in period; unscheduled content is not eligible. Assignment deadlines are separate.';
        $contentRows=array_values(array_filter($rows,static fn(array $row):bool=>strpos($row['reference'],'Content #')===0));
        $cards[]=pc_metric('Marketing assignments',count($contentRows),'Selected-period created / due / published work in source');
        $cards[]=pc_metric('Assignments completed',count(array_filter($contentRows,static fn(array $row):bool=>in_array($row['status'],['completed','published'],true))),'Current completed or published state; not proof of publishing');
        $notes[]='Metrics retain the existing Marketing source and attribution rules. Publishing timing requires an explicit planned publish time; missing targets are excluded, never marked on time. Manual or imported snapshots are reported evidence, not independently verified sales causation. Missing integrations are not zero.';
        if(count($ctx['content'])>=500 || count($ctx['campaigns'])>=250 || count($ctx['metrics'])>=1000)$notes[]='Provisional coverage: the existing Marketing source reached its record limit. Narrow the period and review the source before interpreting these as complete totals.';
    } elseif ($section==='drivers') {
        $zone=new DateTimeZone('Africa/Windhoek');$utc=new DateTimeZone('UTC');
        $start=$period['from']->setTime(0,0)->setTimezone($utc)->format('Y-m-d H:i:s');$end=$period['to']->setTime(23,59,59)->setTimezone($utc)->format('Y-m-d H:i:s');
        $id=max(0,(int)($_GET['employee_id']??0));$params=[$start,$end];$scope=$id?' AND j.driver_employee_id=?':'';if($id)$params[]=$id;
        $jobs=ops_rows("SELECT j.id,j.public_reference,j.status,j.driver_employee_id,e.full_name employee,j.scheduled_date,j.created_at,j.started_at,j.delivered_at,j.completed_at FROM delivery_jobs j LEFT JOIN ops_employees e ON e.id=j.driver_employee_id WHERE j.created_at BETWEEN ? AND ?$scope ORDER BY j.created_at DESC,j.id DESC",$params);
        $done=0;$pending=0;$late=0;$failed=0;$minutes=[];
        foreach($jobs as $job) {
            $delivered=in_array($job['status'],['delivered','awaiting_reconciliation','completed'],true);if($delivered)$done++;elseif($job['status']!=='cancelled')$pending++;
            if($job['status']==='failed')$failed++;
            if(!$delivered&&!empty($job['scheduled_date'])&&!in_array($job['status'],['cancelled'],true)&&$job['scheduled_date']<(new DateTimeImmutable('today',$zone))->format('Y-m-d'))$late++;
            if($job['started_at']&&$job['delivered_at']&&$job['delivered_at']>=$job['started_at'])$minutes[]=(strtotime($job['delivered_at'])-strtotime($job['started_at']))/60;
            foreach(['created_at','started_at','delivered_at','completed_at'] as $field)if($job[$field])$job[$field]=(new DateTimeImmutable($job[$field],$utc))->setTimezone($zone)->format('Y-m-d H:i:s');
            $rows[]=['reference'=>$job['public_reference'],'employee'=>$job['employee']?:'Unassigned','status'=>$job['status'],'scheduled'=>$job['scheduled_date'],'started'=>$job['started_at'],'delivered'=>$job['delivered_at'],'reconciled'=>$job['completed_at'],'href'=>BASE_URL.($owner?'/apps/delivery/workspace.php?delivery=':'/apps/delivery/driver/index.php?delivery=').(int)$job['id']];
        }
        $cards=[pc_metric('Deliveries arranged',count($jobs),'Created in selected period; includes unassigned work'),pc_metric('Assigned',count(array_filter($jobs,static fn($j)=>!empty($j['driver_employee_id']))),'Recorded driver assignment, not proof of breach-time ownership'),pc_metric('Delivered',$done,'Includes awaiting reconciliation / completed'),pc_metric('Still outstanding',$pending,'Selected-period cohort, cancelled excluded'),pc_metric('Past scheduled date',$late,'Current operational risk, not an employee deduction'),pc_metric('Currently failed',$failed,'Current status, not total historical attempts'),pc_metric('Average delivery journey',$minutes?round(array_sum($minutes)/count($minutes),1):null,count($minutes).' valid start → delivered timestamp pairs','minutes'),pc_metric('Delivery completion',count($jobs)?round($done*100/count($jobs),1):null,'Delivered / all arranged records; operational rate only','percent')];
        $proof=null;
        if(is_file(BASE_PATH.'/shared/delivery/DeliveryHistory.php')) {
            try {
                require_once BASE_PATH.'/shared/delivery/DeliveryHistory.php';
                $driverIds=$id?[$id]:array_column(ops_rows("SELECT p.employee_id FROM delivery_driver_profiles p JOIN ops_employees e ON e.id=p.employee_id WHERE p.active=1 AND e.status='active'"),'employee_id');
                $proof=['completed'=>0,'issues_raised'=>0,'needs_review'=>0];
                foreach($driverIds as $driverId){$history=(new \Hambelela\Delivery\DeliveryHistory(db()))->page((int)$driverId,['period'=>'custom','from'=>$from,'to'=>$to]);foreach($proof as $key=>$value)$proof[$key]+=(int)($history['totals'][$key]??0);}
            } catch(Throwable $unavailableProof){$proof=null;error_log('Performance proof projection: '.$unavailableProof->getMessage());}
        }
        $cards[]=pc_metric('Proof-confirmed outcomes',$proof['completed']??null,'Existing Delivery proof validation; recorded in period, grouped per driver');
        $cards[]=pc_metric('Delivery issues raised',$proof['issues_raised']??null,'Original driver issue events recorded in period, not an automatic deduction');
        $cards[]=pc_metric('Proof / issue review needed',$proof['needs_review']??null,'Existing Delivery evidence rules');
        $notes[]='Driver output is separate from the Front Desk scorecard. No approved driver overall score is invented. Proof and issue cards use event dates; delivery-volume cards use the created-date cohort. Handovers remain in each original delivery record. Missing timestamps are not interpreted as on time.';
    } else { kpi_send_json(['ok'=>false,'message'=>'Unsupported performance source.'],422); }
    kpi_send_json(['ok'=>true,'period'=>['from'=>$from,'to'=>$to],'cards'=>$cards,'rows'=>$rows,'notes'=>$notes]);
} catch(Throwable $error) {
    error_log('Performance command source: '.$error->getMessage());
    kpi_send_json(['ok'=>false,'message'=>'This source is not measurable right now. Its records have not been changed; the source connection needs review.'],503);
}
