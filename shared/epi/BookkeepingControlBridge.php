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
    private const PREFIX='epi_v2_cash_control_version_';

    /** Explicit append-only owner policy; installing code never activates it. */
    public static function approveProvisional(PDO $db,array $controls,int $owner,string $reason,?string $effective=null):array
    {
        if($owner!==(int)(V2Store::activation($db)['approved_by']??0)||trim($reason)==='')throw new RuntimeException('Approving owner and reason required.');
        if(count($controls)!==2||array_diff(array_keys($controls),['opening_after_open_minutes','closing_before_close_minutes']))throw new RuntimeException('Exactly the opening and pre-close controls are required.');
        foreach($controls as &$minutes){if(filter_var($minutes,FILTER_VALIDATE_INT)===false||(int)$minutes<1||(int)$minutes>180)throw new RuntimeException('Cash control must be 1–180 whole minutes.');$minutes=(int)$minutes;}unset($minutes);
        foreach(['ops_cash_book_entries'=>['id','transaction_date','transaction_type','source','status','deleted_at','recorded_by','created_at','updated_at','cash_in','cash_out'],
            'hambelela_cashbook_recon'=>['id','recon_date','created_at','logged_by','system_balance','counted_total','variance']] as $table=>$required){
            $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$columns=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
            if(array_diff($required,$columns))throw new RuntimeException('Native cash-control evidence source is incomplete: '.$table);
        }
        $now=Support::timestamp()->format('Y-m-d H:i:s');$from=$effective?Support::timestamp($effective)->format('Y-m-d H:i:s'):$now;
        if($from<$now)throw new RuntimeException('Cash controls cannot be backdated.');
        return V2Store::transaction($db,function()use($db,$controls,$owner,$reason,$now,$from){
            V2Store::lock($db,'provisional-cash-controls');
            $previous=self::provisional($db,$from);
            if($previous&&$previous['controls']===$controls)return $previous;
            $v=['kind'=>'provisional_daily_cash_controls_v1','version'=>'cash-controls-'.str_replace(['-',':',' '],'',$from).'-'.bin2hex(random_bytes(4)),
                'effective_from'=>$from,'approved_at'=>$now,'approved_by'=>$owner,'reason'=>trim($reason),'controls'=>$controls,
                'provisional'=>true,'financial_use_allowed'=>false,'disciplinary_use'=>false,
                'hours'=>['weekday_open'=>'08:00','weekday_close'=>'17:00','saturday_open'=>'09:00','saturday_close'=>'13:00'],
                'closing_scope'=>'Reconciliation current through the pre-close deadline; later receipts remain separate cash-entry obligations, not an automatic variance or final cash freeze.'];
            $db->prepare('INSERT INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description) VALUES(?,?,?,?)')->execute([
                self::PREFIX.$v['version'],Support::json($v),'json','Provisional daily cash controls; immutable version, prospective only.']);
            V2Store::audit($db,'provisional-cash-controls',$owner,$reason,$previous,$v);return $v;
        });
    }

    /** Read-only policy lookup, shared with existing Performance Settings. */
    public static function provisional(PDO $db,?string $at=null):?array
    {
        $at=$at??Support::timestamp()->format('Y-m-d H:i:s');$owner=(int)(V2Store::activation($db)['approved_by']??0);
        $q=$db->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key LIKE ?');$q->execute([self::PREFIX.'%']);$versions=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();$selected=null;
        foreach($versions as $json){$v=json_decode($json,true);
            if(!is_array($v)||($v['kind']??'')!=='provisional_daily_cash_controls_v1'||(int)($v['approved_by']??0)!==$owner||($v['effective_from']??'9999')>$at)continue;
            if(!$selected||$v['effective_from']>$selected['effective_from'])$selected=$v;
        }
        return $selected;
    }

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
            if(isset($approved['provisional_policy'])){
                $h=$approved['provisional_policy']['hours'];$n=(int)$day->format('N');
                if($n===7)continue;
                $open=Support::timestamp($day->format('Y-m-d').' '.$h[$n===6?'saturday_open':'weekday_open']);
                $close=Support::timestamp($day->format('Y-m-d').' '.$h[$n===6?'saturday_close':'weekday_close']);
                if($open>$time)continue;
            }
            $ref='CASH-DAY-'.$day->format('Y-m-d');
            $db->prepare("INSERT IGNORE INTO epi_v2_object_duties VALUES('Bookkeeping',?,'front_desk')")->execute([$ref]);
            foreach($settings as $obligation=>[$key,$event]){
                if(!isset($config[$key]))continue;
                $due=$obligation==='record_opening_balance'?$open->modify('+'.$config[$key].' minutes'):$close->modify('-'.$config[$key].' minutes');
                if($due<=$open||$due>=$close)throw new RuntimeException('Cash-control deadline must fall inside the approved business shift.');
                $exists=V2Store::one($db,"SELECT id FROM epi_v2_operational_deadlines WHERE module='Bookkeeping' AND object_reference=? AND obligation_key=?",[$ref,$obligation]);
                if($exists)continue; // Keep the original deadline and policy snapshot.
                $options=[];
                if(isset($approved['provisional_policy'])){
                    $v=$approved['provisional_policy'];$hours=[];
                    foreach(range(1,6) as $n)$hours[(string)$n]=[$v['hours'][$n===6?'saturday_open':'weekday_open'],$v['hours'][$n===6?'saturday_close':'weekday_close']];
                    $options['stage_policy']=['version'=>$v['version'],'approved_by'=>$v['approved_by'],'rules'=>['hours'=>$hours]];
                }
                $engine->schedule(['module'=>'Bookkeeping','object_reference'=>$ref,'obligation_key'=>$obligation,
                    'breach_event_key'=>$event,'starts_at'=>$open,'due_at'=>$due,'cycle_id'=>'daily',
                    'source_event'=>'scheduled_cash_control','responsible_team'=>'front_desk',
                    'source_evidence'=>['business_date'=>$day->format('Y-m-d'),'configured_control'=>$key,'configured_minutes'=>$config[$key],
                        'control_policy_versions'=>$approved['versions'],'provisional_cash_policy'=>$approved['provisional_policy']??null]]+$options);
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
                $proof=V2Store::one($db,"SELECT id,recorded_by AS actor,created_at,transaction_date,cash_in,cash_out FROM ops_cash_book_entries
                    WHERE transaction_date>=? AND transaction_date<? AND status='active' AND deleted_at IS NULL
                    AND (transaction_type='opening_balance' OR source='opening_balance') AND created_at>=? AND created_at<=?
                    ORDER BY created_at,id LIMIT 1",[$date.' 00:00:00',Support::timestamp($date)->modify('+1 day')->format('Y-m-d H:i:s'),$d['starts_at'],$time->format('Y-m-d H:i:s')]);
            }elseif($d['obligation_key']==='complete_cash_reconciliation'){
                $snapshot=json_decode($d['policy_snapshot_json'],true)?:[];
                $provisional=$snapshot['source_evidence']['provisional_cash_policy']??null;
                if($provisional){
                    // Do not accept an early cash count while receipts can still
                    // arrive before the approved pre-close checkpoint.
                    if($time->format('Y-m-d H:i:s')<$d['due_at'])continue;
                    $watermark=V2Store::one($db,'SELECT MAX(GREATEST(created_at,COALESCE(CASE WHEN updated_at<=? THEN updated_at END,created_at))) changed_at FROM ops_cash_book_entries WHERE transaction_date>=? AND transaction_date<? AND created_at>=? AND created_at<=?',
                        [$d['due_at'],$date.' 00:00:00',Support::timestamp($date)->modify('+1 day')->format('Y-m-d H:i:s'),$d['starts_at'],$d['due_at']]);
                    $after=max($d['starts_at'],$watermark['changed_at']??$d['starts_at']);
                }else $after=$d['starts_at'];
                $proof=V2Store::one($db,'SELECT id,logged_by AS actor,created_at,recon_date,system_balance,counted_total,variance
                    FROM hambelela_cashbook_recon WHERE recon_date=? AND created_at>=? AND created_at<=? ORDER BY created_at,id LIMIT 1',
                    [$date,$after,$time->format('Y-m-d H:i:s')]);
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
        $provisional=self::provisional($db,$open);
        if($provisional){
            $flags=$db->query("SELECT setting_key,setting_value FROM epi_employee_performance_settings WHERE setting_key IN('epi_v2_provisional_capture_enabled','epi_v2_official_capture_enabled')")->fetchAll(PDO::FETCH_KEY_PAIR);
            if(($flags['epi_v2_provisional_capture_enabled']??'0')==='1'&&($flags['epi_v2_official_capture_enabled']??'0')==='0')
                return ['controls'=>$provisional['controls'],'versions'=>[$provisional['version']],'provisional_policy'=>$provisional];
        }
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
