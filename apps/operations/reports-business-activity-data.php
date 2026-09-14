<?php

declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once __DIR__.'/kpi-reporting.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');

try{
    if((string)($_GET['action']??'')==='timeline'){
        $timelineModule=trim((string)($_GET['module']??''));
        $recordId=max(0,(int)($_GET['record_id']??0));
        if($timelineModule===''||!$recordId)throw new RuntimeException('Invalid timeline record.');
        $timelineEvents=kpi_unified_events('2000-01-01 00:00:00','2099-12-31 23:59:59',null,$timelineModule,null,$recordId);
        $evidence=[];
        foreach($timelineEvents as$timelineEvent)$evidence[]=[
            'old_status'=>$timelineEvent['previous_status'],
            'new_status'=>$timelineEvent['new_status'],
            'changed_at'=>$timelineEvent['occurred_at'],
            'changed_by_name'=>$timelineEvent['actor_name']?:'System',
            'action_key'=>$timelineEvent['action'],
            'related_reference'=>$timelineEvent['related_reference'],
            'source_log'=>$timelineEvent['source_log'],
            'source_event_id'=>$timelineEvent['source_event_id'],
            'evidence_quality'=>$timelineEvent['evidence_quality'],
        ];
        foreach($evidence as$evidenceIndex=>$timelineEvent){
            $next=$evidence[$evidenceIndex+1]['changed_at']??null;
            $evidence[$evidenceIndex]['duration_minutes']=$next?max(0,(int)((strtotime((string)$next)-strtotime((string)$timelineEvent['changed_at']))/60)):null;
        }
        kpi_send_json(['ok'=>true,'module'=>$timelineModule,'record_id'=>$recordId,'events'=>$evidence,'empty_message'=>'No matching Activity Log or authoritative record event was found.']);
    }
    $zone=new DateTimeZone('Africa/Windhoek');$resolved=kpi_resolve_reporting_period($_GET);$from=$resolved['from'];$to=$resolved['to'];
    $fromSql=$from->format('Y-m-d 00:00:00');$toSql=$to->format('Y-m-d 23:59:59');
    $page=max(1,(int)($_GET['page']??1));$perPage=max(20,min(100,(int)($_GET['per_page']??50)));
    $module=trim((string)($_GET['module']??''));$actorFilter=trim((string)($_GET['actor']??''));$sourceFilter=trim((string)($_GET['source']??''));$resultFilter=trim((string)($_GET['result']??''));$assignmentFilter=trim((string)($_GET['assignment']??''));
    $ownerIds=[];foreach(ops_rows("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE r.role_key='owner_admin'")as$row)$ownerIds[]=(int)$row['id'];
    $events=kpi_unified_events($fromSql,$toSql,null,$module!==''?$module:null);$normalized=[];$assignmentRecords=[];$assignmentRuns=[];
    $autoPattern='/auto(matic|matically)?[_ -]?assign|walk_in_auto_assigned|packed_by_auto_assigned/i';
    foreach($events as$event){$meta=$event['metadata']??[];$action=(string)$event['action'];$isAutomatic=preg_match($autoPattern,$action)===1||stripos((string)($meta['assignment_method']??''),'automatic')!==false||(!$event['actor_user_id']&&preg_match('/system|background|sync|import/i',$action));
        $actorType=$isAutomatic?'system':(in_array((int)($event['actor_user_id']??0),$ownerIds,true)?'owner':($event['actor_user_id']?'employee':'unknown'));
        $result=preg_match('/fail|error/i',$action)?'failed':(preg_match('/skip|duplicate|no_eligible|unavailable/i',$action)?'skipped':'successful');
        $assignment=preg_match($autoPattern,$action)?'automatically_assigned':(preg_match('/reassign/i',$action)?'reassigned':(preg_match('/assign/i',$action)?'manually_assigned':'not_assignment'));
        $kpiEffect=$assignment==='automatically_assigned'?'informational_only':(preg_match('/start|complete|sent|resolved|confirmed/i',$action)?'employee_evidence':'no_kpi_effect');
        if($actorFilter!==''&&$actorType!==$actorFilter)continue;if($sourceFilter!==''&&$sourceFilter!==$actorType)continue;if($resultFilter!==''&&$result!==$resultFilter)continue;if($assignmentFilter!==''&&$assignment!==$assignmentFilter)continue;
        if($assignment==='automatically_assigned'){$recordKey=$event['section'].'|'.$event['record_type'].'|'.$event['record_id'];$assignmentRecords[$recordKey]=true;$correlation=(string)($meta['correlation_id']??$meta['batch_id']??substr((string)$event['occurred_at'],0,16));$assignmentRuns[$event['section'].'|'.$correlation]=true;}
        $normalized[]=['id'=>$event['event_id'],'occurred_at'=>$event['occurred_at'],'actor_type'=>$actorType,'actor_employee_id'=>$event['actor_user_id'],'actor'=>$actorType==='system'?'System':($event['actor_name']??'Unknown historical actor'),'module'=>$event['section'],'action'=>$action,'record_type'=>$event['record_type'],'record_id'=>$event['record_id'],'previous_value'=>$event['previous_status'],'new_value'=>$event['new_status'],'source'=>$event['source_log'],'result'=>$result,'assignment'=>$assignment,'kpi_effect'=>$kpiEffect,'evidence_quality'=>$event['evidence_quality'],'related_reference'=>$event['related_reference']];
    }
    $counts=['human'=>0,'automatic'=>0,'owner'=>0,'failed'=>0,'review'=>0,'completed'=>0];$moduleCounts=[];
    foreach($normalized as$row){if($row['actor_type']==='system')$counts['automatic']++;elseif($row['actor_type']==='owner')$counts['owner']++;if($row['result']==='failed')$counts['failed']++;if($row['evidence_quality']!=='exact_logged_event')$counts['review']++;if($row['kpi_effect']==='employee_evidence')$counts['completed']++;$moduleCounts[$row['module']]=($moduleCounts[$row['module']]??0)+1;}
    $human=count(array_filter($normalized,static function(array$row):bool{return$row['actor_type']==='employee';}));
    arsort($moduleCounts);$breakdown=[];foreach($moduleCounts as$key=>$value)$breakdown[]=['module'=>$key,'activities'=>$value];
    $total=count($normalized);$offset=($page-1)*$perPage;$rows=array_slice(array_reverse($normalized),$offset,$perPage);
    $cards=[['label'=>'Total recorded activities','value'=>$total],['label'=>'Human actions','value'=>$human],['label'=>'Automatic system actions','value'=>$counts['automatic']],['label'=>'Owner actions','value'=>$counts['owner']],['label'=>'Automatic assignment runs','value'=>count($assignmentRuns)],['label'=>'Unique records automatically assigned','value'=>count($assignmentRecords)],['label'=>'Employee start or completion actions','value'=>$counts['completed']],['label'=>'Failed actions','value'=>$counts['failed'],'status'=>$counts['failed']?'warning':'ok'],['label'=>'Activities requiring review','value'=>$counts['review'],'status'=>$counts['review']?'warning':'ok']];
    $funnel=[['status'=>'Recorded events','events'=>$total],['status'=>'Automatic assignments','events'=>count($assignmentRecords)],['status'=>'Employee work evidence','events'=>$counts['completed']]];
    kpi_send_json(['ok'=>true,'section'=>'business-activity','period'=>['from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'show_adoption_banner'=>false],'cards'=>$cards,'funnel'=>$funnel,'breakdown'=>$breakdown,'overdue'=>[],'rows'=>$rows,'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>(int)ceil($total/$perPage)],'methodology'=>'Automatic assignment is business activity only. Employee credit begins with authenticated start or completion evidence.']);
}catch(Throwable$error){error_log(date(DATE_ATOM).' business activity timeline: '.$error->getMessage().PHP_EOL,3,BASE_PATH.'/logs/kpi_errors.log');kpi_send_json(['ok'=>false,'message'=>'The business activity timeline is temporarily unavailable.'],500);}
