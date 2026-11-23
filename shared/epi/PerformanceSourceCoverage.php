<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;

/** Read-only source-window description. Connected is NOT verified complete. */
final class PerformanceSourceCoverage
{
    public static function resolve(PDO $db,array $policy,string $period,array $coverage):array
    {
        require_once __DIR__.'/OperationalMeasurementPolicy.php';
        require_once __DIR__.'/ProvisionalTimingPolicy.php';
        require_once __DIR__.'/BookkeepingControlBridge.php';
        $now=Support::timestamp()->format('Y-m-d H:i:s');
        $start=$period.' 00:00:00';$end=Support::timestamp($start)->modify('+1 month')->format('Y-m-d H:i:s');
        $asOf=min($now,$end);$activation=V2Store::activation($db);
        $q=$db->query("SELECT setting_key,setting_value FROM epi_employee_performance_settings WHERE setting_key IN ('epi_v2_capture_enabled','epi_v2_results_enabled','epi_v2_watchdog_enabled')");
        $flags=$q->fetchAll(PDO::FETCH_KEY_PAIR);$q->closeCursor();
        $q=$db->query("SELECT COUNT(*) FROM epi_v2_outbox WHERE state='pending'");$pending=(int)$q->fetchColumn();$q->closeCursor();
        $native=OperationalMeasurementPolicy::current($db,$asOf);
        $timing=ProvisionalTimingPolicy::current($db,$asOf);
        $cash=BookkeepingControlBridge::provisional($db,$asOf);
        $from=max($activation['enforcement_start_at']??'9999-01-01 00:00:00',$policy['provisional_capture']['from']??$start);
        foreach($coverage as $category=>&$metrics)foreach($metrics as $metric=>&$row){
            $required=$from;$connected=true;
            if(in_array($category.'|'.$metric,['packing_list|priority','packing_list|compliance','courier|compliance','inventory|accuracy'],true)){
                $connected=$native!==null;$required=max($required,$native['effective_from']??'9999-01-01 00:00:00');
            }
            if(in_array($category.'|'.$metric,['packing|start','packing|timeliness','inventory|timeliness','bookkeeping|timeliness','communication|response'],true)){
                $connected=$connected&&$timing!==null;$required=max($required,$timing['effective_from']??'9999-01-01 00:00:00');
            }
            if($category==='bookkeeping'&&$metric==='controls'){
                $connected=$cash!==null;$required=max($required,$cash['effective_from']??'9999-01-01 00:00:00');
            }
            $active=($flags['epi_v2_capture_enabled']??'0')==='1'&&($flags['epi_v2_results_enabled']??'0')==='1'&&($flags['epi_v2_watchdog_enabled']??'0')==='1';
            $window=self::describe($start,$end,$asOf,$required,$connected&&$active,$pending,(bool)($row['complete']??false));
            // A healthy outbox is necessary, never sufficient, for verified coverage.
            $row['complete']=($row['complete']??false)&&$window['full_period_verified'];
            $row['source_window']=$window;
        }unset($row,$metrics);
        return $coverage;
    }

    public static function describe(string $start,string $end,string $asOf,string $from,bool $connected,int $pending,bool $verified):array
    {
        $ready=$connected&&$pending===0;
        $state=!$connected?'source_unavailable':($pending>0?'capture_pending':($asOf<=$start||$from>=$asOf?'not_started':($from>$start?'partial_period':($verified?'verified_period':'unverified_period'))));
        return ['state'=>$state,'capture_from'=>$from,'observed_through'=>min($asOf,$end),
            'period_start'=>$start,'period_end'=>$end,'pipeline_ready'=>$ready,
            'pending_capture_events'=>$pending,'full_period_verified'=>$verified&&$ready&&$from<=$start,
            'reason'=>$state==='partial_period'?'Capture began after this reporting month started; earlier work is not reconstructed.':
                ($state==='capture_pending'?'Operational capture has pending events; absence of observations is not zero workload.':
                ($state==='not_started'?'The observation window has not started.':
                ($state==='source_unavailable'?'A required capture contract or runtime flag is unavailable.':
                ($state==='unverified_period'?'Capture is connected, but complete responsibility/workload coverage has not been independently verified.':'Source period has explicit verification.'))))];
    }
}
