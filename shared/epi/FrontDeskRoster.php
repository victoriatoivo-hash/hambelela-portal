<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Owner-directed planned duty, not a fabricated employee login or acceptance. */
final class FrontDeskRoster
{
    /** Permanent job responsibility; begins at the next not-yet-started opening. */
    public static function approvePermanent(PDO $db,int $owner,$now=null): int {
        $at=Support::timestamp($now);
        $hours=OrdersSlaPolicy::approved()['hours'];
        $day=$at->setTime(0,0);
        for($i=0;$i<8;$i++,$day=$day->modify('+1 day')) {
            $window=$hours[$day->format('N')]??null;
            if(!$window)continue;
            $opening=Support::timestamp($day->format('Y-m-d').' '.$window[0].':00');
            if($opening<=$at)continue;
            return self::approve($db,(int)(V2Store::policy($db)['front_desk_employee_id']??0),$owner,
                $day->format('Y-m-d'),'9999-12-31','Permanent Front Desk job responsibility; coverage and approved absence remain explicit exceptions',$at);
        }
        throw new RuntimeException('No next scheduled opening found');
    }
    public static function enabled(PDO $db): bool {
        $r=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_front_roster_enabled'");
        return $r && $r['setting_value']==='1';
    }
    public static function approve(PDO $db,int $employee,int $owner,string $from,string $to,string $reason,$now=null): int {
        $at=Support::timestamp($now)->format('Y-m-d H:i:s');
        if($employee!==(int)(V2Store::policy($db)['front_desk_employee_id']??0))throw new RuntimeException('Roster must use the explicitly configured primary Front Desk employee');
        foreach([$from,$to] as $date) {
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||Support::timestamp($date)->format('Y-m-d')!==$date)throw new RuntimeException('Valid dates required');
        }
        if($to<$from || $from<substr($at,0,10) || trim($reason)==='' || strlen($reason)>500)throw new RuntimeException('Prospective roster dates and reason required');
        $authority=V2Store::one($db,"SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin'",[$owner]);
        $person=V2Store::one($db,"SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='front_desk_admin'",[$employee]);
        if(!$authority||!$person)throw new RuntimeException('Active owner approval and Front Desk employee required');
        return V2Store::transaction($db,function()use($db,$employee,$owner,$from,$to,$reason,$at){
            V2Store::lock($db,'front-roster');
            if(V2Store::one($db,'SELECT id FROM epi_v2_front_rosters WHERE effective_from<=? AND effective_to>=?',[$to,$from]))throw new RuntimeException('Overlapping roster requires reviewed correction');
            $db->prepare('INSERT INTO epi_v2_front_rosters(employee_id,effective_from,effective_to,approved_by,approved_at,hours_json,reason) VALUES(?,?,?,?,?,?,?)')->execute([$employee,$from,$to,$owner,$at,Support::json(OrdersSlaPolicy::approved()['hours']),$reason]);
            $id=(int)$db->lastInsertId();
            V2Store::audit($db,'front-roster|'.$id,$owner,'Approved scheduled duty; not employee acceptance',null,['employee_id'=>$employee,'from'=>$from,'to'=>$to,'approved_at'=>$at,'reason'=>$reason]);
            return $id;
        });
    }
    public static function materialise(PDO $db,?PDO $hr,$now=null): array {
        if(!self::enabled($db))return ['status'=>'disabled'];
        $time=Support::timestamp($now);$day=$time->format('Y-m-d');
        if((int)$time->format('N')===7)return ['status'=>'closed'];
        return V2Store::transaction($db,function()use($db,$hr,$time,$day){
            V2Store::lock($db,'duty|front_desk');
            $r=V2Store::one($db,'SELECT * FROM epi_v2_front_rosters WHERE effective_from<=? AND effective_to>=?',[$day,$day]);
            if(!$r)return ['status'=>'needs_review','reason'=>'missing_roster'];
            $hours=json_decode($r['hours_json'],true);$window=$hours[$time->format('N')]??null;
            if(!$window)return ['status'=>'closed'];
            $from=$day.' '.$window[0].':00';$to=$day.' '.$window[1].':00';
            if($time->format('Y-m-d H:i:s')<$from)return ['status'=>'before_open'];
            if($r['approved_at']>$from)return ['status'=>'needs_review','reason'=>'roster_not_approved_before_opening'];
            $active=V2Store::one($db,"SELECT id FROM ops_employees WHERE id=? AND status='active'",[$r['employee_id']]);
            if(!$active)return ['status'=>'needs_review','reason'=>'inactive_rostered_employee'];
            $absence=HrAbsenceEvidence::inspect($db,$hr,(int)$r['employee_id'],$from);
            if($absence['state']!=='no_approved_absence')return ['status'=>$absence['state']==='approved_absence'?'coverage_required':'needs_review','reason'=>$absence['reason']??'approved_absence'];
            // Never overwrite a real handover or re-create primary duty after lunch starts.
            if(V2Store::one($db,"SELECT id FROM epi_v2_duty_periods WHERE duty_key='front_desk' AND effective_from<? AND effective_to>?",[$to,$from]))return ['status'=>'existing'];
            $db->prepare("INSERT INTO epi_v2_duty_periods(duty_uuid,duty_key,employee_id,responsibility_level,effective_from,effective_to,assigned_by,accepted_by,accepted_at,source,shift_id) VALUES(?,'front_desk',?,'primary',?,?,?,NULL,NULL,'approved_front_roster',?)")->execute([Support::uuid(),$r['employee_id'],$from,$to,$r['approved_by'],$r['id']]);
            V2Store::audit($db,'duty|front_desk',(int)$r['approved_by'],'Scheduled opening duty without login or fictional acceptance',null,['roster_id'=>$r['id'],'employee_id'=>$r['employee_id'],'effective_from'=>$from,'effective_to'=>$to,'hr_evidence'=>$absence]);
            return ['status'=>'created','employee_id'=>(int)$r['employee_id'],'effective_from'=>$from,'effective_to'=>$to];
        });
    }
}
