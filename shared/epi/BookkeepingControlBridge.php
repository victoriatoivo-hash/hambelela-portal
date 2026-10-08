<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;
require_once __DIR__.'/PerformanceRefreshRuntime.php';

/** Scheduled native cash controls. Never called by a page read.
 * Times come only from explicitly approved versioned scorecard configuration. No default
 * deadlines or employee assumptions are introduced by installing this adapter.
 */
final class BookkeepingControlBridge
{
    public static function reconcile(PDO $db,$now=null):array
    {
        if(!PerformanceRefreshRuntime::enabled($db))return ['status'=>'disabled','scheduled'=>0,'fulfilled'=>0];
        $activation=V2Store::activation($db);
        if(!$activation)return ['status'=>'not_configured','scheduled'=>0,'fulfilled'=>0];
        $settings=['record_opening_balance'=>['opening_after_open_minutes','cash_opening_sla_breached'],
            'complete_cash_reconciliation'=>['closing_before_close_minutes','cash_closing_sla_breached']];
        $time=Support::timestamp($now);$calendar=new BusinessTimeEngine($db);$engine=new DeadlineEngine($db);
        $latest=V2Store::one($db,"SELECT MAX(starts_at) last_start FROM epi_v2_operational_deadlines WHERE module='Bookkeeping'
            AND source_event='scheduled_cash_control'");
        $from=Support::timestamp($latest['last_start']??$activation['enforcement_start_at'])->setTime(0,0);
        $scheduled=0;$fulfilled=0;$days=0;$configured=false;$configurationCache=[];
        for($day=$from;$day<=$time&&$days<32;$day=$day->modify('+1 day')){
            $window=$calendar->windowForDate($day);if(!$window)continue;
            [$open,$close]=$window;
            if($open->format('Y-m-d H:i:s')<$activation['enforcement_start_at']||$open>$time)continue;
            $approved=self::configuration($db,$open->format('Y-m-d H:i:s'),$configurationCache);
            if(!$approved)continue;
            $config=$approved['controls'];$configured=true;$days++;
            $ref='CASH-DAY-'.$day->format('Y-m-d');
            $db->prepare("INSERT IGNORE INTO epi_v2_object_duties VALUES('Bookkeeping',?,'front_desk')")->execute([$ref]);
            foreach($settings as $obligation=>[$key,$event]){
                if(!isset($config[$key]))continue;
                $due=$obligation==='record_opening_balance'?$open->modify('+'.$config[$key].' minutes'):$close->modify('-'.$config[$key].' minutes');
                if($due<=$open||$due>=$close)throw new RuntimeException('Cash-control deadline must fall inside the approved business shift.');
                $exists=V2Store::one($db,"SELECT id FROM epi_v2_operational_deadlines WHERE module='Bookkeeping' AND object_reference=? AND obligation_key=?",[$ref,$obligation]);
                if($exists)continue; // Keep the original deadline and policy snapshot.
                $engine->schedule(['module'=>'Bookkeeping','object_reference'=>$ref,'obligation_key'=>$obligation,
                    'breach_event_key'=>$event,'starts_at'=>$open,'due_at'=>$due,'cycle_id'=>'daily',
                    'source_event'=>'scheduled_cash_control','responsible_team'=>'front_desk',
                    'source_evidence'=>['business_date'=>$day->format('Y-m-d'),'configured_control'=>$key,'configured_minutes'=>$config[$key],
                        'control_policy_versions'=>$approved['versions']]]);
                $scheduled++;
            }
        }
        // Native proofs are reconciled BEFORE the watchdog evaluates omissions.
        // Re-running is safe, and late fulfilment retains the historical breach.
        $q=$db->prepare("SELECT * FROM epi_v2_operational_deadlines WHERE module='Bookkeeping' AND source_event='scheduled_cash_control'
            AND state IN('open','breached','needs_attribution') AND starts_at<=? ORDER BY starts_at,id");
        $q->execute([$time->format('Y-m-d H:i:s')]);$deadlines=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        foreach($deadlines as $d){
            $date=substr($d['starts_at'],0,10);$proof=null;
            if($d['obligation_key']==='record_opening_balance'){
                $proof=V2Store::one($db,"SELECT id,recorded_by AS actor,created_at,entry_date,cash_in,cash_out FROM ops_cash_book_entries
                    WHERE entry_date=? AND status='active' AND deleted_at IS NULL
                    AND (transaction_type='opening_balance' OR source='opening_balance') AND created_at>=? AND created_at<=?
                    ORDER BY created_at,id LIMIT 1",[$date,$d['starts_at'],$time->format('Y-m-d H:i:s')]);
            }elseif($d['obligation_key']==='complete_cash_reconciliation'){
                $proof=V2Store::one($db,'SELECT id,logged_by AS actor,created_at,recon_date,system_balance,counted_total,variance
                    FROM hambelela_cashbook_recon WHERE recon_date=? AND created_at>=? AND created_at<=? ORDER BY created_at,id LIMIT 1',
                    [$date,$d['starts_at'],$time->format('Y-m-d H:i:s')]);
            }
            if(!$proof||(int)$proof['actor']<1)continue;
            V2Store::transaction($db,function()use($db,$engine,$d,$proof,&$fulfilled):void{
                if($engine->fulfil($d['deadline_uuid'],(int)$proof['actor'],$proof['created_at'])){
                    V2Store::audit($db,'cash-control|'.$d['deadline_uuid'],(int)$proof['actor'],
                        'Native cash-control proof; any variance requires separate responsibility review',null,$proof);
                    $fulfilled++;
                }
            });
        }
        return ['status'=>$configured||$deadlines?'success':'not_configured','scheduled'=>$scheduled,'fulfilled'=>$fulfilled];
    }

    private static function configuration(PDO $db,string $open,array &$cache):?array
    {
        $period=substr($open,0,7).'-01';
        if(!array_key_exists($period,$cache)){
            $q=$db->prepare("SELECT s.version,s.policy_json,s.policy_hash,a.validation_approved_at FROM epi_v2_scorecard_assignments a
                JOIN epi_v2_scorecard_documents s ON s.version=a.scorecard_version
                WHERE a.period_start=? AND a.validation_approved_by=? AND s.role_key='front_desk'");
            $q->execute([$period,(int)(V2Store::activation($db)['approved_by']??0)]);
            $cache[$period]=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        }
        $rows=$cache[$period];$result=null;
        foreach($rows as $row){
            if(empty($row['validation_approved_at'])||$row['validation_approved_at']>$open)continue;
            if(!hash_equals($row['policy_hash'],hash('sha256',$row['policy_json'])))throw new RuntimeException('Cash-control policy integrity failure.');
            $policy=json_decode($row['policy_json'],true)?:[];
            if(($policy['status']??'')!=='approved'||empty($policy['operational_controls']['cash']))continue;
            $controls=$policy['operational_controls']['cash'];
            if(!is_array($controls))throw new RuntimeException('Invalid approved cash-control configuration.');
            foreach($controls as $key=>$minutes){
                if(!in_array($key,['opening_after_open_minutes','closing_before_close_minutes'],true)
                    ||!is_int($minutes)||$minutes<1||$minutes>480)throw new RuntimeException('Invalid configured cash-control deadline.');
            }
            ksort($controls);
            if($result&&$result['controls']!==$controls)throw new RuntimeException('Conflicting approved cash-control times require owner review.');
            if(!$result)$result=['controls'=>$controls,'versions'=>[]];
            $result['versions'][]=$row['version'];
        }
        if($result)$result['versions']=array_values(array_unique($result['versions']));
        return $result;
    }
}
