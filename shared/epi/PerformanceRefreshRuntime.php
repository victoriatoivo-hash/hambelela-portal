<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
require_once __DIR__.'/PerformanceRefreshQueue.php';
require_once __DIR__.'/OperationalPerformanceProjector.php';

/** Explicit opt-in until all adapters and owner validation pass. No schema repairs. */
final class PerformanceRefreshRuntime
{
    public static function enabled(PDO $db):bool
    {
        $row=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_results_enabled'");
        return ($row['setting_value']??'0')==='1';
    }
    public static function invalidate(PDO $db,string $reason):void
    {
        if(!self::enabled($db))return;
        // Include prior owners/locked months: corrected attribution must refresh both sides.
        $rows=$db->query('SELECT employee_id,period_start FROM epi_v2_scorecard_assignments')->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row)PerformanceRefreshQueue::request($db,(int)$row['employee_id'],$row['period_start'],substr($reason,0,160));
    }
    public static function run(PDO $db):array
    {
        if(!self::enabled($db))return ['status'=>'disabled','processed'=>0,'failed'=>0];
        // Periodic reconciliation catches deadline expiry, coverage changes and missed mutation hooks.
        self::invalidate($db,'scheduled operational reconciliation');
        $projector=new OperationalPerformanceProjector($db);
        $result=PerformanceRefreshQueue::drain($db,[$projector,'employee']);
        return $result+['status'=>$result['failed']?'failed':'success'];
    }
}
