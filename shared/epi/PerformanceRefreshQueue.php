<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use InvalidArgumentException;

require_once __DIR__.'/EmployeePerformanceService.php';

/** Writer/worker API only; never invoked by GET or by a service constructor.
 * Operational adapters enqueue old AND new owners on attribution correction.
 * The projector is supplied by the worker, never an HTTP payload.
 */
final class PerformanceRefreshQueue
{
    public static function request(PDO $db,int $employee,string $period,string $reason): void
    {
        if($employee<1 || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])-01$/D',$period) || trim($reason)==='' || strlen($reason)>160)
            throw new InvalidArgumentException('A valid employee, month and refresh reason are required.');
        $db->prepare('INSERT INTO epi_v2_performance_refresh_queue(employee_id,period_start,reason)
            VALUES(?,?,?) ON DUPLICATE KEY UPDATE requested_generation=requested_generation+1,
            reason=VALUES(reason),requested_at=NOW()')->execute([$employee,$period,$reason]);
    }

    public static function drain(PDO $db,callable $projector,int $limit=100): array
    {
        $limit=max(1,min(500,$limit));
        $rows=$db->query('SELECT employee_id,period_start FROM epi_v2_performance_refresh_queue
            WHERE requested_generation>processed_generation ORDER BY requested_at,employee_id LIMIT '.$limit)->fetchAll(PDO::FETCH_ASSOC);
        $result=['processed'=>0,'failed'=>0];
        foreach($rows as $row) {
            $employee=(int)$row['employee_id'];$period=$row['period_start'];
            try {
                $done=V2Store::transaction($db,function()use($db,$employee,$period,$projector){
                    // Same lock order as the result writer; duplicate workers serialize per employee/month.
                    V2Store::lock($db,'employee-performance:'.$employee.':'.$period);
                    $entry=V2Store::one($db,'SELECT * FROM epi_v2_performance_refresh_queue
                        WHERE employee_id=? AND period_start=? FOR UPDATE',[$employee,$period]);
                    if(!$entry || $entry['requested_generation']===$entry['processed_generation'])return false;
                    $generation=(int)$entry['requested_generation'];
                    $input=$projector($employee,$period);
                    if(!is_array($input) || !isset($input['opportunities'],$input['coverage']))
                        throw new \RuntimeException('Incomplete source projection.');
                    (new EmployeePerformanceService($db))->recalculate($employee,$period,$input['opportunities'],$input['coverage']);
                    $db->prepare('UPDATE epi_v2_performance_refresh_queue SET processed_generation=?,
                        processed_at=NOW(),attempts=attempts+1,last_error=NULL WHERE employee_id=? AND period_start=?')
                        ->execute([$generation,$employee,$period]);
                    return true;
                });
                if($done)$result['processed']++;
            } catch(\Throwable $error) {
                $result['failed']++;
                // Keep sensitive SQL/record values out of the portal-facing health record.
                $db->prepare('UPDATE epi_v2_performance_refresh_queue SET attempts=attempts+1,last_error=?
                    WHERE employee_id=? AND period_start=?')->execute(['Source projection or calculation failed; retry pending.',$employee,$period]);
                error_log('EPI performance refresh failed for employee '.$employee.', period '.$period.': '.$error->getMessage());
            }
        }
        return $result;
    }

    public static function health(PDO $db): array
    {
        return $db->query('SELECT COUNT(CASE WHEN requested_generation>processed_generation THEN 1 END) AS backlog,
            COUNT(CASE WHEN last_error IS NOT NULL THEN 1 END) AS failures,
            MAX(processed_at) AS last_recalculated_at,
            MIN(CASE WHEN requested_generation>processed_generation THEN requested_at END) AS oldest_pending_at
            FROM epi_v2_performance_refresh_queue')->fetch(PDO::FETCH_ASSOC);
    }
}
