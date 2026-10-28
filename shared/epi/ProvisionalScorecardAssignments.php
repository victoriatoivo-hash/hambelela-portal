<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;
require_once __DIR__.'/ScorecardCatalog.php';

/** Explicit owner opt-in, existing scorecard tables only. Never grants official approval. */
final class ProvisionalScorecardAssignments
{
    public static function activate(PDO $db,int $owner,array $employees):array
    {
        if($owner!==(int)(V2Store::activation($db)['approved_by']??0))throw new RuntimeException('Activation owner required');
        $at=Support::timestamp()->format('Y-m-d H:i:s');
        return V2Store::transaction($db,function()use($db,$owner,$employees,$at){
            V2Store::lock($db,'provisional-scorecard-activation');
            if(V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_provisional_assignments'"))
                throw new RuntimeException('Existing activation must be inspected, not overwritten');
            $config=['authorised_by'=>$owner,'authorised_at'=>$at,'employees'=>[]];
            $templates=[];foreach(ScorecardCatalog::templates() as $p)$templates[$p['role']]=$p;
            foreach($employees as $employee){
                $person=V2Store::one($db,"SELECT e.id,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active'",[(int)$employee]);
                $role=['front_desk_admin'=>'front_desk','packer'=>'packer','packer_production_staff'=>'packer'][$person['role_key']??'']??null;
                if(!$role)throw new RuntimeException('Explicit Front Desk or Packer identity required; no role substitution');
                $p=$templates[$role];$p['version']=$role.'-provisional-'.str_replace(['-',':',' '],'',$at);
                $p['status']='provisional';$p['provisional_capture']=['enabled'=>true,'from'=>$at,'authorised_at'=>$at,'authorised_by'=>$owner];
                $p['financial_use_allowed']=false;
                $json=Support::json($p);$hash=hash('sha256',$json);
                $old=V2Store::one($db,'SELECT policy_hash FROM epi_v2_scorecard_documents WHERE version=?',[$p['version']]);
                if($old && $old['policy_hash']!==$hash)throw new RuntimeException('Immutable policy conflict');
                if(!$old)$db->prepare('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)')->execute([$p['version'],$role,$json,$hash]);
                $config['employees'][]=['employee_id'=>(int)$employee,'role_key'=>$person['role_key'],'version'=>$p['version']];
            }
            foreach(['epi_v2_provisional_assignments'=>Support::json($config),'epi_v2_provisional_capture_enabled'=>'1','epi_v2_results_enabled'=>'1'] as $key=>$value)
                $db->prepare('INSERT INTO epi_employee_performance_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$value]);
            V2Store::audit($db,'performance-provisional-activation',$owner,'Owner authorised provisional/default weights and 5/20/100 samples; no official or financial use',null,$config);
            self::ensureCurrent($db);
            return $config;
        });
    }

    /** Scheduler writer only. Renew only the explicitly listed people while their role is unchanged. */
    public static function ensureCurrent(PDO $db):void
    {
        $setting=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_provisional_assignments'");
        if(!$setting)return;
        $config=json_decode($setting['setting_value'],true,512,JSON_THROW_ON_ERROR);
        if((int)($config['authorised_by']??0)!==(int)(V2Store::activation($db)['approved_by']??0))throw new RuntimeException('Provisional assignment authority changed');
        $now=Support::timestamp();
        foreach($config['employees'] as $entry){
            $person=V2Store::one($db,"SELECT r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active'",[$entry['employee_id']]);
            if(!$person || $person['role_key']!==$entry['role_key'])continue;
            foreach([$now->format('Y-m-01'),$now->modify('first day of next month')->format('Y-m-01')] as $period){
                if($period<substr($config['authorised_at'],0,7).'-01')continue;
                $db->prepare('INSERT IGNORE INTO epi_v2_scorecard_assignments(employee_id,period_start,scorecard_version,assigned_by) VALUES(?,?,?,?)')
                    ->execute([$entry['employee_id'],$period,$entry['version'],$config['authorised_by']]);
            }
        }
    }
}
