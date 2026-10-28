<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Prospective owner-authorised source contract, not a second calculator. */
final class OperationalMeasurementPolicy
{
    private const KEY='epi_v2_operational_measurements';
    public static function current(PDO $db,?string $at=null):?array
    {
        $r=V2Store::one($db,'SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?',[self::KEY]);
        $p=json_decode($r['setting_value']??'null',true);
        return $p&&$p['effective_from']<=($at??Support::timestamp()->format('Y-m-d H:i:s'))?$p:null;
    }
    public static function activate(PDO $db,int $owner,string $reason,?string $at=null):array
    {
        if($owner!==(int)(V2Store::activation($db)['approved_by']??0)||trim($reason)==='')throw new RuntimeException('Approving owner and reason required.');
        return V2Store::transaction($db,function()use($db,$owner,$reason,$at){
            V2Store::lock($db,'operational-measurement-activation');
            $old=V2Store::one($db,'SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?',[self::KEY]);
            if($old)return json_decode($old['setting_value'],true,512,JSON_THROW_ON_ERROR);
            $from=Support::timestamp($at)->format('Y-m-d H:i:s');
            $p=['version'=>'native-measures-'.str_replace(['-',':',' '],'',$from),'effective_from'=>$from,'approved_by'=>$owner,'reason'=>trim($reason),
                'provisional'=>true,'financial_use_allowed'=>false,'disciplinary_use_allowed'=>false,
                'courier_allocation'=>'From this boundary paid-courier packing SLA/readiness is allocated once to Courier readiness; other order packing remains Orders readiness. Existing category/internal weights are unchanged.',
                'priority_rank'=>['top_critical'=>0,'high'=>1,'medium'=>2,'low'=>3],
                'packing_checks'=>['quantity_recorded','packer_website_confirmation','label_ready'],
                'dispatch_checks'=>['document','courier','linked_order','parcel_count','customer_reference']];
            $db->prepare('INSERT INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description) VALUES(?,?,?,?)')->execute([self::KEY,Support::json($p),'json','Prospective native performance measurement contract; immutable after activation.']);
            $setting=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_provisional_assignments'");
            if($setting){
                $assignments=json_decode($setting['setting_value'],true,512,JSON_THROW_ON_ERROR);$versions=[];
                foreach($assignments['employees'] as &$entry){
                    $old=V2Store::one($db,"SELECT * FROM epi_v2_scorecard_documents WHERE version=? AND role_key='packer'",[$entry['version']]);
                    if(!$old)continue;
                    if(!hash_equals($old['policy_hash'],hash('sha256',$old['policy_json'])))throw new RuntimeException('Packer scorecard integrity failure.');
                    $document=json_decode($old['policy_json'],true,512,JSON_THROW_ON_ERROR);
                    if(($document['status']??'')!=='provisional')throw new RuntimeException('Native-source cutover may only revise provisional scorecards.');
                    if(!isset($versions[$old['version']])){
                        $document['version']='packer-native-'.substr(hash('sha256',$old['version'].'|'.$from),0,20);
                        $document['operational_measurements']=$p;
                        $document['categories']['orders']['metrics']['progression']['label']='Non-courier order packing SLA; earlier captured work retains its original allocation';
                        $document['categories']['courier']['metrics']['timeliness']['label']='Paid-courier packing/readiness within the approved SLA and eligible dispatch cutoff';
                        $json=Support::json($document);
                        $db->prepare('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)')->execute([$document['version'],'packer',$json,hash('sha256',$json)]);
                        $versions[$old['version']]=$document['version'];
                    }
                    $new=$versions[$old['version']];
                    $q=$db->prepare('SELECT a.period_start FROM epi_v2_scorecard_assignments a LEFT JOIN epi_v2_employee_result_heads h ON h.employee_id=a.employee_id AND h.period_start=a.period_start WHERE a.employee_id=? AND a.scorecard_version=? AND a.period_start>=? AND h.locked_at IS NULL');
                    $q->execute([$entry['employee_id'],$old['version'],substr($from,0,7).'-01']);$periods=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
                    foreach($periods as $period)$db->prepare('UPDATE epi_v2_scorecard_assignments SET scorecard_version=? WHERE employee_id=? AND period_start=?')->execute([$new,$entry['employee_id'],$period]);
                    $entry['version']=$new;
                }unset($entry);
                $db->prepare("UPDATE epi_employee_performance_settings SET setting_value=? WHERE setting_key='epi_v2_provisional_assignments'")->execute([Support::json($assignments)]);
            }
            V2Store::audit($db,'operational-measurements',$owner,$reason,null,$p);
            return $p;
        });
    }
}
