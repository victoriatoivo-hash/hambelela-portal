<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Explicit deployment commands only. Never called from a GET or constructor. */
final class ShadowActivation
{
    public static function inspect(PDO $db): array
    {
        $people=$db->query("SELECT e.id,e.full_name,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key IN ('owner_admin','front_desk_admin') ORDER BY e.id")->fetchAll(PDO::FETCH_ASSOC);
        $owners=array_values(array_filter($people,static function($r){return $r['role_key']==='owner_admin';}));
        $front=array_values(array_filter($people,static function($r){return $r['role_key']==='front_desk_admin';}));
        if(count($owners)!==1||count($front)!==1)throw new RuntimeException('Exactly one active owner and one Front Desk account must be verified; no identity guessing');
        $settings=$db->query("SELECT setting_key,setting_value FROM epi_employee_performance_settings WHERE setting_key IN ('weekday_open','weekday_close','saturday_open','saturday_close','task_response_minutes') ORDER BY setting_key")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach(['weekday','saturday']as$day){
            foreach(['open','close']as$end)if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',(string)($settings[$day.'_'.$end]??'')))throw new RuntimeException('Missing valid saved business hours');
            if($settings[$day.'_close']<=$settings[$day.'_open'])throw new RuntimeException('Invalid saved business window');
        }
        $saved=json_decode((string)($settings['task_response_minutes']??''),true);$priorities=[];
        foreach(['urgent','important','normal']as$key)if(isset($saved[$key])&&filter_var($saved[$key],FILTER_VALIDATE_INT)!==false&&(int)$saved[$key]>0)$priorities[$key]=(int)$saved[$key];
        if(!$priorities)throw new RuntimeException('No saved task deadlines; leave capture disabled');
        $calendar=$db->query('SELECT * FROM epi_employee_business_calendar ORDER BY business_date')->fetchAll(PDO::FETCH_ASSOC);
        return ['owner'=>$owners[0],'front_desk'=>$front[0],'policy'=>[
            'version'=>'shadow-existing-'.hash('sha256',Support::json([$settings,$calendar])),
            'calendar_version'=>hash('sha256',Support::json([$settings,$calendar])),
            'activation_scope'=>'approved_existing_rules','task_assignment_policy'=>'owner_directed',
            'task_start_by_priority'=>$priorities,'orders'=>[],
            'front_desk_employee_id'=>(int)$front[0]['id'],'duty_acceptance_required'=>true,
            'official_scoring_enabled'=>false,'watchdog_max_gap_minutes'=>20,
            'source_settings'=>$settings,'calendar_snapshot'=>$calendar,
            'limitations'=>['No order SLA configured','No implicit coverage','No historical backlog capture','HR absence integration not yet validated; every incident remains shadow-only'],
        ]];
    }

    public static function migrateAndActivate(PDO $db,string $sql,string $release): array
    {
        $info=self::inspect($db); // Validate identities and policy BEFORE DDL.
        $version=(string)$db->query('SELECT VERSION()')->fetchColumn();
        if(stripos($version,'MariaDB')===false||version_compare($version,'10.6','<'))throw new RuntimeException('Verified MariaDB 10.6+ required');
        $lock=$db->query("SELECT GET_LOCK('epi_v2_p0_migration',0)")->fetchColumn();
        if(!(int)$lock)throw new RuntimeException('Another setup is running');
        try{
            $s=$db->query("SELECT setting_key FROM epi_employee_performance_settings WHERE setting_key IN ('epi_v2_capture_enabled','epi_v2_watchdog_enabled') AND setting_value<>'0'");
            if($s->fetch())throw new RuntimeException('Already enabled; use health/watchdog, not migration replay');
            foreach(['epi_v2_operational_deadlines'=>'policy_snapshot_json','epi_v2_performance_incidents'=>'root_incident_id','epi_v2_duty_periods'=>'superseded_at','epi_v2_quality_revisions'=>'active_root']as$table=>$column){
                $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);$columns=$s->fetchAll(PDO::FETCH_COLUMN);
                if($columns&&!in_array($column,$columns,true))throw new RuntimeException('Older schema requires reviewed additive upgrade');
            }
            foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql)as$statement)if(trim($statement)!=='')$db->exec($statement);
            $required=['epi_v2_activation_no_update','epi_v2_activation_no_delete'];
            $triggers=$db->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
            if(array_diff($required,$triggers))throw new RuntimeException('Activation immutability triggers missing');
            V2Store::transaction($db,function()use($db,$info,$release){
                if(V2Store::activation($db))throw new RuntimeException('Activation already exists; cannot replace immutable policy');
                $owner=(int)$info['owner']['id'];$policy=$info['policy']+['approval_source'=>'Owner-approved shadow deployment','release'=>$release];
                V2Store::activate($db,Support::timestamp()->format('Y-m-d H:i:s'),$owner,$policy);
                $db->prepare("UPDATE epi_employee_performance_settings SET setting_value='1',updated_by=? WHERE setting_key IN ('epi_v2_capture_enabled','epi_v2_watchdog_enabled')")->execute([$owner]);
                V2Store::audit($db,'activation',$owner,'Owner approved existing rules, shadow only, no official score changes',null,$policy);
            });
            return ['activation'=>V2Store::activation($db),'front_desk'=>$info['front_desk'],'official_scores_changed'=>false];
        }finally{$db->query("SELECT RELEASE_LOCK('epi_v2_p0_migration')");}
    }
}
