<?php
declare(strict_types=1);
namespace Hambelela\EPI;

use DateTimeImmutable;
use PDO;
use Throwable;

/** Fail-safe adapter from operational Error Log actions to immutable EPI quality evidence. */
final class QualityActivityBridge
{
    private static $available;

    public static function record(PDO $pdo,string $legacyAction,int $errorId,array $metadata=[]):void
    {
        if($errorId<=0)return;
        try{
            self::recordV2Incident($pdo,$legacyAction,$errorId,$metadata);
            if(self::$available===null){Performance::configure($pdo);self::$available=true;}
            if(Performance::mode()===FeatureFlags::MODE_DISABLED)return;
            $row=self::error($pdo,$errorId);if(!$row&&$legacyAction!=='error_permanently_deleted')return;
            $actor=self::actor($pdo,(int)($metadata['actor_employee_id']??0));
            $at=Support::timestamp($metadata['occurred_at']??null);
            $reference='ERROR-'.$errorId;$action=self::action($legacyAction,$metadata);
            $before=$metadata['previous_value']??$metadata['old_value']??null;
            $after=$metadata['new_value']??$metadata['status']??null;
            $correlation=(string)($metadata['correlation_id']??Support::dedupe(['quality-correlation',$errorId,$legacyAction,$at->format('Y-m-d H:i:s'),$actor['id']]));
            $isTest=Performance::mode()===FeatureFlags::MODE_TEST;
            if($isTest&&empty($metadata['test_data']))return;
            $baseMeta=array_merge($metadata,[
                'error_id'=>$errorId,'logged_by_employee_id'=>$row['logged_by']??null,
                'logged_for_employee_id'=>$row['employee_id']??null,
                'responsible_employee_id'=>$row['attributed_employee_id']??$row['responsible_employee_id']??null,
                'responsibility_type'=>self::responsibility((string)($row['attribution_type']??'')),
                'category'=>$row['category']??null,'severity'=>$row['severity']??null,
                'financial_impact'=>$row['financial_impact']??null,'correlation_id'=>$correlation,
                'test_data'=>$isTest,'excluded_from_scoring'=>true,
            ]);
            $dedupe=Support::dedupe(['quality',$errorId,$legacyAction,$actor['id'],$at->format('Y-m-d H:i:s'),$before,$after,$metadata['event_nonce']??'']);
            $recording=$isTest?'test':'automatic';
            $activity=Performance::recordActivity(['module'=>'Error Log','reference_number'=>$reference,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],'department'=>$actor['department'],'activity_type'=>$action,'description'=>self::description($action,$row),'activity_source'=>'error_log:'.$legacyAction,'timestamp'=>$at,'manual'=>false,'recording_mode'=>$recording,'deduplication_key'=>Support::dedupe(['quality-activity',$dedupe]),'metadata'=>$baseMeta]);
            // Reporter/editor activity is retained for audit, but is always excluded
            // from scoring. A separate evidence row is attached to the confirmed
            // responsible employee only.
            $evidence=Performance::recordEvidence(['module'=>'Error Log','reference_number'=>$reference,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],'department'=>$actor['department'],'action'=>$action,'action_description'=>self::description($action,$row),'previous_value'=>$before,'new_value'=>$after,'status_before'=>$metadata['previous_status']??null,'status_after'=>$metadata['new_status']??$metadata['status']??null,'priority'=>$row['severity']??null,'timestamp'=>$at,'manual'=>false,'recording_mode'=>$recording,'activity_source'=>'error_log:'.$legacyAction,'financial_impact'=>$row['financial_impact']??null,'deduplication_key'=>Support::dedupe(['quality-evidence',$dedupe]),'metadata'=>array_merge($baseMeta,['activity_uuid'=>$activity,'responsibility_confirmed'=>false,'excluded_from_scoring'=>true])]);
            self::recordResponsibleEvidence($pdo,$row,$at,$recording,$correlation,$legacyAction);
            self::profile($pdo,$row,$actor,$at);
            if($action==='error_status_changed')self::statusHistory($pdo,$row,$metadata,$actor,$at,$evidence,$correlation);
        }catch(Throwable $e){self::failure($pdo,$e,$legacyAction,$errorId);}
    }

    private static function error(PDO $pdo,int $id):array{$s=$pdo->prepare('SELECT * FROM ops_error_logs WHERE id=? LIMIT 1');$s->execute([$id]);return$s->fetch(PDO::FETCH_ASSOC)?:[];}
    private static function actor(PDO $pdo,int $id):array{$id=$id?:((function_exists('ops_current_employee_id'))?(int)ops_current_employee_id():0);$name=function_exists('current_user')?(string)(current_user()['name']??''):'';if($id>0){try{$s=$pdo->prepare("SELECT e.full_name,COALESCE(r.name,'Operations') department FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?");$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC)?:[];$name=(string)($r['full_name']??$name);$department=(string)($r['department']??'Operations');}catch(Throwable $e){$department='Operations';}}else{$department='System';}return['id'=>$id?:null,'name'=>$name?:($id?'Employee '.$id:'System'),'department'=>$department];}
    private static function action(string $a,array $m):string{if($a==='error_status_updated')return'error_status_changed';$map=['error_logged'=>'error_created','error_updated'=>'error_edited','error_deleted'=>'error_moved_to_trash','error_restored'=>'error_restored','error_attribution_corrected'=>'error_responsibility_changed','error_financial_impact_changed'=>'error_financial_impact_changed','error_instruction_added'=>'error_note_added','error_attachment_uploaded'=>'error_attachment_uploaded','error_owner_reviewed'=>'error_owner_reviewed'];return$map[$a]??$a;}
    private static function responsibility(string $type):string{$map=['employee'=>'employee_error','business'=>'business_error','delivery_driver'=>'external_dependency','system'=>'system_error','supplier'=>'supplier_error','courier'=>'courier_error','customer'=>'customer_error','shared'=>'shared_responsibility'];return$map[$type]??'unconfirmed';}
    private static function recordV2Incident(PDO$pdo,string$legacyAction,int$errorId,array$metadata):void
    {
        try{$flag=$pdo->prepare("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_capture_enabled' LIMIT 1");$flag->execute();if(!in_array(strtolower(trim((string)$flag->fetchColumn())),['1','true','yes','on','enabled'],true))return;
            $row=self::error($pdo,$errorId);if(!$row)return;$responsibility=self::responsibility((string)($row['attribution_type']??''));$responsible=(int)($row['attributed_employee_id']??$row['responsible_employee_id']??0);
            $confirmed=$responsibility==='employee_error'&&$responsible>0&&(int)($row['affects_kpi_accuracy']??0)===1&&!empty($row['accuracy_verified_by'])&&!empty($row['attribution_verified_by']);
            $eventKey=$confirmed?'confirmed_employee_error':'quality_attribution_pending';$occurred=Support::timestamp($row['occurred_at']??$row['created_at']??$row['logged_at']??null);$status=strtolower((string)($row['status']??'open'));$resolved=in_array($status,['resolved','complete','completed','closed'],true);
            $version=(string)($row['attribution_verified_at']??'').'|'.(string)($row['accuracy_verified_at']??'').'|'.$responsibility.'|'.$responsible;$uuid=Support::uuidFromHash('quality-incident|'.$errorId.'|'.$version);
            $exclusion=$confirmed?null:($responsibility==='unconfirmed'?'insufficient_attribution':$responsibility);$meta=['error_id'=>$errorId,'reporter_employee_id'=>$row['logged_by']??null,'actor_employee_id'=>$metadata['actor_employee_id']??null,'responsible_employee_id'=>$responsible?:null,'responsibility_type'=>$responsibility,'responsibility_confirmed'=>$confirmed,'excluded_from_scoring'=>!$confirmed,'exclusion_reason'=>$exclusion,'legacy_action'=>$legacyAction,'category'=>$row['category']??null,'severity'=>$row['severity']??null];
            $stmt=$pdo->prepare('INSERT IGNORE INTO epi_v2_performance_incidents(incident_uuid,deadline_uuid,event_key,module,object_reference,responsible_employee_at_breach,occurred_at,due_at,current_risk_state,historical_state,eligibility_state,exclusion_reason,metadata_json,created_at,resolved_at) VALUES(?,NULL,?,?,?,?,?,NULL,?,?,?,?,?,NOW(),?)');
            $stmt->execute([$uuid,$eventKey,'Error Log','ERROR-'.$errorId,$confirmed?$responsible:null,$occurred->format('Y-m-d H:i:s'),$resolved?'resolved':'open',$confirmed?'breach':'neutral',$confirmed?'pending_rule':'needs_review',$exclusion,Support::json($meta),$resolved?Support::timestamp($row['updated_at']??null)->format('Y-m-d H:i:s'):null]);
        }catch(Throwable$error){error_log('EPI V2 quality capture: '.$error->getMessage());}
    }
    private static function recordResponsibleEvidence(PDO $pdo,array $row,DateTimeImmutable $at,string $recording,string $correlation,string $legacyAction):void
    {
        $responsibility=self::responsibility((string)($row['attribution_type']??''));
        $responsibleId=(int)($row['attributed_employee_id']??$row['responsible_employee_id']??0);
        $confirmed=$responsibility==='employee_error'&&$responsibleId>0&&(int)($row['affects_kpi_accuracy']??0)===1&&!empty($row['accuracy_verified_by'])&&!empty($row['attribution_verified_by']);
        if(!$confirmed)return;
        $employee=self::actor($pdo,$responsibleId);
        $metadata=[
            'canonical_event_key'=>'confirmed_employee_error','root_incident_id'=>'error:'.(int)$row['id'],
            'error_id'=>(int)$row['id'],'reporter_employee_id'=>$row['logged_by']??null,
            'responsible_employee_id'=>$responsibleId,'responsibility_type'=>'employee_error','responsibility_confirmed'=>true,
            'attribution_verified_by'=>$row['attribution_verified_by'],'accuracy_verified_by'=>$row['accuracy_verified_by'],
            'category'=>$row['category']??null,'severity'=>$row['severity']??null,'correlation_id'=>$correlation,
            'excluded_from_scoring'=>false,'test_data'=>$recording==='test',
        ];
        Performance::recordEvidence([
            'module'=>'Error Log','reference_number'=>'ERROR-'.(int)$row['id'],'employee_id'=>$responsibleId,
            'employee_name'=>$employee['name'],'department'=>$employee['department'],'action'=>'confirmed_employee_error',
            'action_description'=>'Confirmed employee quality incident: '.(string)($row['error_title']??('Error '.$row['id'])),
            'status_after'=>$row['status']??null,'priority'=>$row['severity']??null,'timestamp'=>$at,'manual'=>false,
            'recording_mode'=>$recording,'activity_source'=>'error_log:confirmed_responsibility',
            'financial_impact'=>$row['financial_impact']??null,'verified'=>true,'verified_by'=>$row['accuracy_verified_by'],
            'deduplication_key'=>Support::dedupe(['quality-responsible',(int)$row['id'],$responsibleId,$row['attribution_verified_at']??'', $row['accuracy_verified_at']??'']),
            'metadata'=>$metadata,
        ]);
    }
    private static function description(string $action,array $row):string{return ucwords(str_replace('_',' ',$action)).': '.(string)($row['error_title']??('Error '.$row['id']));}
    private static function profile(PDO $pdo,array $r,array $actor,DateTimeImmutable $at):void{$s=$pdo->prepare("INSERT INTO epi_quality_error_profiles(error_id,logged_by_employee_id,logged_for_employee_id,original_responsible_employee_id,current_responsible_employee_id,responsibility_type,operational_severity,recording_mode,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE current_responsible_employee_id=VALUES(current_responsible_employee_id),responsibility_type=VALUES(responsibility_type),operational_severity=VALUES(operational_severity),updated_at=VALUES(updated_at)");$s->execute([(int)$r['id'],$r['logged_by']??null,$r['employee_id']??null,$r['original_attributed_employee_id']??$r['responsible_employee_id']??null,$r['attributed_employee_id']??$r['responsible_employee_id']??null,self::responsibility((string)($r['attribution_type']??'')),$r['severity']??null,'automatic',$r['logged_at']??$at->format('Y-m-d H:i:s'),$at->format('Y-m-d H:i:s')]);}
    private static function statusHistory(PDO $pdo,array $r,array $m,array $actor,DateTimeImmutable $at,?string $evidence,string $correlation):void{$before=(string)($m['previous_status']??$m['old_value']??'');$after=(string)($m['new_status']??$m['status']??$r['status']??'');if($after==='')return;$minutes=null;try{$q=$pdo->prepare('SELECT changed_at FROM epi_quality_status_history WHERE error_id=? ORDER BY changed_at DESC,id DESC LIMIT 1');$q->execute([(int)$r['id']]);$last=$q->fetchColumn();if($last)$minutes=Performance::businessMinutes((string)$last,$at);}catch(Throwable $e){}$event=Support::uuidFromHash(Support::dedupe(['quality-status',(int)$r['id'],$before,$after,$at->format('Y-m-d H:i:s'),$actor['id']??0,$m['event_nonce']??'']));$s=$pdo->prepare('INSERT IGNORE INTO epi_quality_status_history(event_uuid,error_id,previous_status,new_status,changed_by_employee_id,reason,changed_at,business_minutes_in_previous_status,evidence_uuid,correlation_id,recording_mode,metadata_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$event,(int)$r['id'],$before?:null,$after,$actor['id'],$m['reason']??null,$at->format('Y-m-d H:i:s'),$minutes,$evidence,$correlation,Performance::mode()===FeatureFlags::MODE_TEST?'test':'automatic',Support::json($m)]);}
    private static function failure(PDO $pdo,Throwable $e,string $action,int $id):void{error_log('EPI Quality bridge: '.$e->getMessage());try{$s=$pdo->prepare('INSERT INTO epi_performance_logs(level,component,message,context_json) VALUES(?,?,?,?)');$s->execute(['error','quality_bridge',$e->getMessage(),Support::json(['action'=>$action,'error_id'=>$id])]);}catch(Throwable $ignored){}}
}
