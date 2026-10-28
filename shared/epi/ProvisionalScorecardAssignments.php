<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;
require_once __DIR__.'/ScorecardCatalog.php';
require_once __DIR__.'/PerformanceRefreshQueue.php';

/** Explicit owner opt-in, existing scorecard tables only. Never grants official approval. */
final class ProvisionalScorecardAssignments
{
    /** Marketing staff receive a function-scoped Front result only for verified
     * counter/duty ownership. This is not a general Marketing role scorecard. */
    public static function addFrontCoverage(PDO $db,int $owner,int $employee,string $reason):array
    {
        if($owner!==(int)(V2Store::activation($db)['approved_by']??0)||trim($reason)==='')throw new RuntimeException('Approving owner and reason required');
        return V2Store::transaction($db,function()use($db,$owner,$employee,$reason){
            V2Store::lock($db,'provisional-scorecard-activation');
            $person=V2Store::one($db,"SELECT e.id,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active'",[$employee]);
            if(($person['role_key']??'')!=='marketing_sales')throw new RuntimeException('Active Marketing & Sales coverage employee required');
            $setting=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_provisional_assignments'");
            if(!$setting)throw new RuntimeException('Existing provisional activation required');
            $config=json_decode($setting['setting_value'],true,512,JSON_THROW_ON_ERROR);$base=null;
            foreach($config['employees'] as $entry){
                if((int)$entry['employee_id']===$employee){
                    $existing=json_decode(V2Store::one($db,'SELECT policy_json FROM epi_v2_scorecard_documents WHERE version=?',[$entry['version']])['policy_json']??'{}',true);
                    if(($existing['scope']['kind']??'')==='front_desk_coverage')return ['status'=>'already_configured','employee_id'=>$employee,'version'=>$entry['version']];
                    throw new RuntimeException('Existing employee scorecard requires a separately reviewed transition');
                }
                $row=V2Store::one($db,'SELECT * FROM epi_v2_scorecard_documents WHERE version=?',[$entry['version']]);
                if($row&&$row['role_key']==='front_desk')$base=$row;
            }
            if(!$base||!hash_equals($base['policy_hash'],hash('sha256',$base['policy_json'])))throw new RuntimeException('Verified provisional Front scorecard required');
            $p=json_decode($base['policy_json'],true,512,JSON_THROW_ON_ERROR);
            if(($p['status']??'')!=='provisional')throw new RuntimeException('Coverage remains provisional only');
            $at=Support::timestamp()->format('Y-m-d H:i:s');$p['version']='front-coverage-'.(int)$employee.'-'.str_replace(['-',':',' '],'',$at);
            $p['scope']=['kind'=>'front_desk_coverage','employee_role'=>'marketing_sales','description'=>'Verified Front Desk duties/counter orders only; not an overall Marketing performance score'];
            $p['provisional_capture']=['enabled'=>true,'from'=>$at,'authorised_at'=>$at,'authorised_by'=>$owner];
            $p['financial_use_allowed']=false;$json=Support::json($p);
            $current=Support::timestamp()->format('Y-m-01');
            if(V2Store::one($db,'SELECT employee_id FROM epi_v2_scorecard_assignments WHERE employee_id=? AND period_start=?',[$employee,$current]))throw new RuntimeException('Current period already has an assigned scorecard; review before replacement');
            $db->prepare('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)')->execute([$p['version'],'front_desk',$json,hash('sha256',$json)]);
            $config['employees'][]=['employee_id'=>$employee,'role_key'=>'marketing_sales','version'=>$p['version']];
            $db->prepare("UPDATE epi_employee_performance_settings SET setting_value=? WHERE setting_key='epi_v2_provisional_assignments'")->execute([Support::json($config)]);
            V2Store::audit($db,'front-coverage-scorecard|'.$employee,$owner,$reason,null,['version'=>$p['version'],'scope'=>$p['scope'],'measurement_begins_at'=>$at]);
            self::ensureCurrent($db);require_once __DIR__.'/PerformanceRefreshRuntime.php';
            PerformanceRefreshRuntime::invalidate($db,'Front Desk coverage result enabled');
            return ['status'=>'configured','employee_id'=>$employee,'version'=>$p['version'],'measurement_begins_at'=>$at,'official_score'=>null];
        });
    }
    /** Explicit owner-approved provisional correction to the Front Desk Orders
     * allocation. Published result revisions and locked assignments are retained. */
    public static function reviseFrontOrders(PDO $db,int $owner,string $reason):array
    {
        if($owner!==(int)(V2Store::activation($db)['approved_by']??0)||trim($reason)==='')throw new RuntimeException('Approving owner and reason required');
        $at=Support::timestamp()->format('Y-m-d H:i:s');$period=substr($at,0,7).'-01';
        return V2Store::transaction($db,function()use($db,$owner,$reason,$at,$period){
            V2Store::lock($db,'provisional-scorecard-activation');
            $setting=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_provisional_assignments'");
            if(!$setting)throw new RuntimeException('Existing provisional activation required');
            $config=json_decode($setting['setting_value'],true,512,JSON_THROW_ON_ERROR);$changed=[];
            foreach($config['employees'] as &$entry){
                $old=V2Store::one($db,'SELECT * FROM epi_v2_scorecard_documents WHERE version=?',[$entry['version']]);
                if(!$old||$old['role_key']!=='front_desk')continue;
                if(!hash_equals($old['policy_hash'],hash('sha256',$old['policy_json'])))throw new RuntimeException('Policy integrity failure');
                $p=json_decode($old['policy_json'],true,512,JSON_THROW_ON_ERROR);
                if(($p['status']??'')!=='provisional'||!isset($p['categories']['orders']['metrics']['completion'],$p['categories']['orders']['metrics']['first_time_right']))throw new RuntimeException('Expected provisional Front Desk Orders measures required');
                if(array_keys($p['categories']['orders']['metrics'])===['completion','first_time_right']
                    &&$p['categories']['orders']['metrics']['completion']['weight_hundredths']===8500
                    &&$p['categories']['orders']['metrics']['first_time_right']['weight_hundredths']===1500)continue;
                $previous=$p['version'];$p['version']='front-orders-85-15-'.str_replace(['-',':',' '],'',$at).'-'.(int)$entry['employee_id'];
                $completion=$p['categories']['orders']['metrics']['completion'];$accuracy=$p['categories']['orders']['metrics']['first_time_right'];
                $completion['weight_hundredths']=8500;$accuracy['weight_hundredths']=1500;
                $p['categories']['orders']['metrics']=['completion'=>$completion,'first_time_right'=>$accuracy];
                $p['categories']['orders']['internal_weights_status']='owner_approved_provisional';
                $p['policy_revisions'][]=['from_version'=>$previous,'approved_by'=>$owner,'approved_at'=>$at,'reason'=>trim($reason),
                    'change'=>'Front Orders: 85% completion SLA, 15% first-time-right; overall category weight unchanged; no packer New-stage attribution'];
                $json=Support::json($p);$db->prepare('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)')->execute([$p['version'],'front_desk',$json,hash('sha256',$json)]);
                $q=$db->prepare('SELECT a.* FROM epi_v2_scorecard_assignments a LEFT JOIN epi_v2_employee_result_heads h ON h.employee_id=a.employee_id AND h.period_start=a.period_start WHERE a.employee_id=? AND a.scorecard_version=? AND a.period_start>=? AND h.locked_at IS NULL');
                $q->execute([$entry['employee_id'],$previous,$period]);$assignments=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
                foreach($assignments as $a){
                    $db->prepare('UPDATE epi_v2_scorecard_assignments SET scorecard_version=? WHERE employee_id=? AND period_start=? AND scorecard_version=?')->execute([$p['version'],$entry['employee_id'],$a['period_start'],$previous]);
                    PerformanceRefreshQueue::request($db,(int)$entry['employee_id'],$a['period_start'],'Owner-approved provisional Front Orders allocation');
                }
                $entry['version']=$p['version'];$changed[]=['employee_id'=>(int)$entry['employee_id'],'previous_version'=>$previous,'version'=>$p['version'],'unlocked_periods'=>array_column($assignments,'period_start')];
            }unset($entry);
            if($changed){$db->prepare("UPDATE epi_employee_performance_settings SET setting_value=? WHERE setting_key='epi_v2_provisional_assignments'")->execute([Support::json($config)]);V2Store::audit($db,'front-orders-provisional-policy',$owner,$reason,null,$changed);}
            return ['changed'=>$changed,'official_scoring'=>false,'financial_use_allowed'=>false];
        });
    }
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
