<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/EmployeeDriverSession.php';
require_once __DIR__.'/DeliveryHistory.php';

/** Self-only, read-only projection. No new employee registry or performance ledger. */
final class DriverProfileData
{
    private \PDO $db;
    public function __construct(\PDO $db) {$this->db=$db;}
    public static function initials(string $name):string
    {
        $parts=preg_split('/\s+/u',trim($name),-1,PREG_SPLIT_NO_EMPTY)?:[];
        return $parts?mb_strtoupper(mb_substr($parts[0],0,1).(count($parts)>1?mb_substr(end($parts),0,1):'')):'DR';
    }
    private function rows(string $sql,array $args=[]):array {$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(\PDO::FETCH_ASSOC);}
    public function read(int $employee,?\DateTimeImmutable $now=null):array
    {
        EmployeeDriverSession::actor($this->db,$employee);
        $p=$this->rows('SELECT full_name FROM ops_employees WHERE id=?',[$employee])[0];
        $week=DeliveryHistory::filters(['period'=>'week'],$now);
        $metrics=['deliveries'=>null,'completion_rate'=>null,'errands'=>null,'errands_needs_review'=>null,'needs_review'=>null,'from'=>$week['from'],'to'=>$week['to'],'timezone'=>'Africa/Windhoek'];
        try {$history=(new DeliveryHistory($this->db))->page($employee,['period'=>'week'],$now);$metrics['deliveries']=$history['totals']['completed'];$metrics['needs_review']=$history['totals']['needs_review'];}
        catch(\PDOException $e){error_log('Driver profile delivery totals unavailable.');}
        $metrics['rate_assigned']=null;$metrics['rate_completed']=null;
        try {$rate=(new DeliveryHistory($this->db))->weeklyCompletion($employee,$now);$metrics['completion_rate']=$rate['rate'];$metrics['rate_assigned']=$rate['assigned'];$metrics['rate_completed']=$rate['completed'];}
        catch(\PDOException $e){error_log('Driver profile completion rate unavailable.');}
        try {
            // Count a current, completed errand once, attributed to its actual completion actor.
            // Reopen/cancel clears eligibility; repeated completion signals do not multiply work.
            $metrics['errands']=(int)$this->rows("SELECT COUNT(DISTINCT j.id) n FROM delivery_errands j
                JOIN delivery_errand_details d ON d.errand_id=j.id
                JOIN delivery_errand_events e ON e.errand_id=j.id AND e.action='completed'
                WHERE e.actor_id=? AND j.collected_at=e.created_at AND d.state='open'
                  AND e.created_at>=? AND e.created_at<?
                  AND NOT EXISTS(SELECT 1 FROM delivery_errand_events later WHERE later.errand_id=j.id AND later.id>e.id AND later.action IN ('reopen','dispatch_cancelled'))",
                [$employee,$week['from_utc'],$week['to_utc']])[0]['n'];
            $metrics['errands_needs_review']=(int)$this->rows("SELECT COUNT(*) n FROM delivery_errands j
                LEFT JOIN delivery_errand_details d ON d.errand_id=j.id
                WHERE j.driver_id=? AND j.collected_at>=? AND j.collected_at<? AND COALESCE(d.state,'open')<>'cancelled'
                  AND NOT EXISTS(SELECT 1 FROM delivery_errand_events e WHERE e.errand_id=j.id AND e.action='completed' AND e.created_at=j.collected_at)",
                [$employee,$week['from_utc'],$week['to_utc']])[0]['n'];
        }catch(\PDOException $e){$metrics['errands']=null;$metrics['errands_needs_review']=null;error_log('Driver profile errand totals unavailable.');}
        $preferences=['state'=>'unavailable','language'=>'English','desktop_enabled'=>null,'sound_enabled'=>null];
        try {
            // Read the canonical store WITHOUT the global helper's on-read schema migration/default fallback.
            $saved=$this->rows('SELECT desktop_enabled,sound_enabled FROM notification_preferences WHERE employee_id=?',[$employee]);
            $preferences['state']=$saved?'read_only':'not_saved';
            if($saved){$preferences['desktop_enabled']=(bool)$saved[0]['desktop_enabled'];$preferences['sound_enabled']=(bool)$saved[0]['sound_enabled'];}
        }catch(\PDOException $e){/* Unsupported schema is not a saved preference. */}
        EmployeeDriverSession::actor($this->db,$employee);
        return ['driver'=>['display_name'=>$p['full_name'],'initials'=>self::initials($p['full_name']),'role'=>'Driver','duty_state'=>'unknown'],
            'metrics'=>$metrics,'vehicle'=>['state'=>'unavailable'],'schedule'=>['state'=>'unavailable','timezone'=>'Africa/Windhoek'],
            'preferences'=>$preferences,'editable_fields'=>[]];
    }
}
