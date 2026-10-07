<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;

/** SELECT-only activation diagnostics. Passing these checks is not activation authority. */
final class StageReadiness
{
    public static function inspect(PDO $db,?PDO $hr,$now=null): array {
        $at=Support::timestamp($now);$blockers=[];$schema=[];
        $required=[
            'epi_v2_orders_policy'=>['version','effective_from','approved_by','policy_json'],
            'epi_v2_orders_tracking'=>['order_id','classification','policy_version','created_at','paid_at','progressed_at','removed_at','source_snapshot_json'],
            'epi_v2_front_rosters'=>['employee_id','effective_from','effective_to','approved_by','approved_at','hours_json'],
            'epi_v2_front_plans'=>['work_date','primary_employee_id','coverage_employee_id','accepted_at','actual_start','actual_end'],
            'epi_v2_front_reminders'=>['reminder_key','recipient_id','sent_at','notification_id'],
            'notifications'=>['id','title','message','module','related_type','priority','deduplication_key','action_link','created_by'],
            'notification_recipients'=>['notification_id','employee_id'],
            'employee_user_links'=>['id','portal_user_id','hr_employee_id','linked_at','active'],
        ];
        foreach($required as $table=>$columns) {
            $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $s->execute([$table]);$actual=$s->fetchAll(PDO::FETCH_COLUMN);$s->closeCursor();
            $schema[$table]=array_values(array_diff($columns,$actual));
            if($schema[$table])$blockers[]='schema:'.$table;
        }
        $activation=V2Store::activation($db);
        if(!$activation||($activation['mode']??'')!=='shadow')$blockers[]='shadow_activation_required';
        $primary=(int)(V2Store::policy($db)['front_desk_employee_id']??0);
        $people=$db->query("SELECT e.id,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key IN('front_desk_admin','marketing_sales') ORDER BY e.id")->fetchAll(PDO::FETCH_ASSOC);
        $identities=[];$validPrimary=false;$coverCount=0;
        foreach($people as $person) {
            $id=(int)$person['id'];
            if($id===$primary&&$person['role_key']==='front_desk_admin')$validPrimary=true;
            $evidence=HrAbsenceEvidence::inspect($db,$hr,$id,$at->format('Y-m-d H:i:s'));
            if($person['role_key']==='marketing_sales'&&$evidence['state']!=='needs_review')$coverCount++;
            $identities[]=['employee_id'=>$id,'role'=>$person['role_key'],'hr_state'=>$evidence['state'],'reason'=>$evidence['reason']??null];
            if($id===$primary&&$evidence['state']==='needs_review')$blockers[]='hr_identity_or_evidence:'.$id;
        }
        if(!$validPrimary)$blockers[]='configured_primary_not_active_front_desk';
        if(!$coverCount)$blockers[]='no_active_marketing_coverage_candidate';
        $runs=$db->query('SELECT started_at,finished_at,status FROM epi_v2_watchdog_runs ORDER BY id DESC LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
        $schedule=self::scheduleCheck($runs,$at);
        if(!$schedule['ready'])$blockers[]='scheduler:'.$schedule['reason'];
        $pending=(int)$db->query("SELECT COUNT(*) FROM epi_v2_outbox WHERE state='pending'")->fetchColumn();
        if($pending)$blockers[]='unprocessed_operational_evidence';
        $flags=$db->query("SELECT setting_key,setting_value FROM epi_employee_performance_settings WHERE setting_key IN('epi_v2_capture_enabled','epi_v2_watchdog_enabled','epi_v2_orders_sla_enabled','epi_v2_front_roster_enabled','epi_v2_front_coverage_enabled')")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach(['epi_v2_capture_enabled','epi_v2_watchdog_enabled'] as $flag)if(($flags[$flag]??'0')!=='1')$blockers[]='disabled:'.$flag;
        return ['checked_at'=>$at->format('Y-m-d H:i:s'),'read_only'=>true,
            'technical_prerequisites_ready'=>!$blockers,'blockers'=>array_values(array_unique($blockers)),
            'missing_columns'=>$schema,'identities'=>$identities,'scheduler'=>$schedule,'pending_evidence'=>$pending,'flags'=>$flags,
            'activation_authorized'=>false,'official_scoring_ready'=>false,
            'remaining_release_checks'=>['live file hashes','independent failure alert','authenticated two-person handover verification','explicit migrations and prospective policy/primary-duty approval']];
    }
    public static function scheduleCheck(array $runs,$now=null): array {
        $now=Support::timestamp($now);
        if(count($runs)<3)return ['ready'=>false,'reason'=>'three_successful_runs_required'];
        $previous=null;
        foreach(array_slice($runs,0,3) as $run) {
            if(($run['status']??'')!=='success'||empty($run['finished_at']))return ['ready'=>false,'reason'=>'unsuccessful_run'];
            $start=Support::timestamp($run['started_at']);$finish=Support::timestamp($run['finished_at']);
            if($finish<$start||$finish>$now)return ['ready'=>false,'reason'=>'invalid_run_time'];
            if($previous===null && $now->getTimestamp()-$finish->getTimestamp()>600)return ['ready'=>false,'reason'=>'stale'];
            if($previous!==null) {
                $gap=$previous->getTimestamp()-$start->getTimestamp();
                if($gap<240||$gap>600)return ['ready'=>false,'reason'=>'cadence_not_verified'];
            }
            $previous=$start;
        }
        return ['ready'=>true,'reason'=>null,'runs'=>array_slice($runs,0,3),
            'note'=>'Cadence observed; scheduler provenance and external alert delivery require separate verification.'];
    }
}
