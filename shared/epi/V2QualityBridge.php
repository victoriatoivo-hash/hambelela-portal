<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;

/** Shadow-only projection of one quality root with preserved review revisions. */
final class V2QualityBridge
{
 public static function record(PDO $db,string $action,int $id,array $meta=[]):void {
    V2OperationalBridge::record($db,'error_log',$action,$id,$meta);
 }
 public static function capture(PDO $db,string $action,int $id,array $meta,array $record):void {
    $flag=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_capture_enabled'");
    if(!$flag||$flag['setting_value']!=='1'||!($activation=V2Store::activation($db)))return;
    V2Store::transaction($db,function()use($db,$action,$id,$meta,$activation,$record){
        $root='error:'.$id;V2Store::lock($db,$root);
        $r=$record;
        $employee=(int)(($r['attributed_employee_id']??0)?:($r['responsible_employee_id']??0));
        $occurred=Support::timestamp($r['occurred_at']??$r['created_at']??$r['logged_at']??null)->format('Y-m-d H:i:s');
        $historical=$occurred<$activation['enforcement_start_at'];
        $confirmed=($r['attribution_type']??'')==='employee'&&$employee>0&&(int)($r['affects_kpi_accuracy']??0)===1
          &&(int)($r['accuracy_verified_by']??0)===(int)$activation['approved_by']&&(int)($r['attribution_verified_by']??0)===(int)$activation['approved_by']
          &&!empty($r['accuracy_verified_at'])&&!empty($r['attribution_verified_at'])&&V2Store::one($db,'SELECT id FROM ops_employees WHERE id=?',[$employee]);
        $exclusion=EligibilityPolicy::exclusionReason(['module'=>'Orders'],$meta);
        $eligible=$confirmed&&!$historical&&$exclusion===null;
        // PDO 7.4 may return numeric IDs as strings; keep audit identity types stable.
        if(isset($r['logged_by']))$r['logged_by']=(int)$r['logged_by'];
        if(isset($r['attribution_verified_by']))$r['attribution_verified_by']=(int)$r['attribution_verified_by'];
        if(isset($meta['actor_employee_id']))$meta['actor_employee_id']=(int)$meta['actor_employee_id'];
        $snapshot=['root_incident_id'=>$root,'source_record'=>$r,'reporter_employee_id'=>$r['logged_by']??null,'actor_employee_id'=>$meta['actor_employee_id']??(function_exists('ops_current_employee_id')?ops_current_employee_id():null),'responsible_employee_id'=>$confirmed?$employee:null,'responsibility_confirmed'=>(bool)$confirmed,'reviewer_id'=>$r['attribution_verified_by']??null,'reviewed_at'=>$r['attribution_verified_at']??null,'historical_backfill'=>$historical,'excluded_from_scoring'=>true,'shadow_candidate_eligible'=>(bool)$eligible,'exclusion_reason'=>$exclusion??($historical?'historical_backfill':(!$confirmed?'insufficient_attribution':null)),'mode'=>'shadow'];
        $hash=hash('sha256',Support::json($snapshot));
        $current=V2Store::one($db,'SELECT * FROM epi_v2_quality_revisions WHERE root_incident_id=? AND superseded_at IS NULL',[$root]);
        if($current&&$current['revision_hash']===$hash)return;
        if($current)$db->prepare('UPDATE epi_v2_quality_revisions SET superseded_at=NOW(),eligible=0 WHERE id=?')->execute([$current['id']]);
        $db->prepare('INSERT INTO epi_v2_quality_revisions(root_incident_id,revision_hash,employee_id,eligible,snapshot_json) VALUES(?,?,?,?,?)')->execute([$root,$hash,$confirmed?$employee:null,(int)$eligible,Support::json($snapshot)]);
        $snapshot['revision_id']=(int)$db->lastInsertId();$uuid=Support::uuidFromHash($root);
        $resolved=in_array(strtolower($r['status']??''),['resolved','complete','completed','closed'],true);
        $db->prepare("INSERT INTO epi_v2_performance_incidents(incident_uuid,root_incident_id,event_key,module,object_reference,responsible_employee_at_breach,occurred_at,current_risk_state,historical_state,eligibility_state,exclusion_reason,metadata_json,resolved_at) VALUES(?,?,?,'Error Log',?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE event_key=VALUES(event_key),responsible_employee_at_breach=VALUES(responsible_employee_at_breach),current_risk_state=VALUES(current_risk_state),historical_state=VALUES(historical_state),eligibility_state=VALUES(eligibility_state),exclusion_reason=VALUES(exclusion_reason),metadata_json=VALUES(metadata_json),resolved_at=VALUES(resolved_at)")
          ->execute([$uuid,$root,$confirmed?'confirmed_employee_error':'quality_attribution_pending','ERROR-'.$id,$confirmed?$employee:null,$occurred,$resolved?'resolved':'open',$confirmed?'breach':'neutral',$eligible?'pending_rule':($historical?'historical_recovered':'needs_review'),$snapshot['exclusion_reason'],Support::json($snapshot),$resolved?Support::timestamp($r['updated_at']??null)->format('Y-m-d H:i:s'):null]);
    });
 }
}
