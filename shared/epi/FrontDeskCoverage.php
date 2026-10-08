<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Explicit commands only; read/status does not initialise or repair data. */
final class FrontDeskCoverage
{
    private $db;
    private $hr;
    public function __construct(PDO $db, ?PDO $hr) { $this->db=$db; $this->hr=$hr; }

    public function enabled(): bool {
        $r=V2Store::one($this->db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_front_coverage_enabled'");
        return $r && $r['setting_value']==='1';
    }

    public function status(int $actor, $now=null): array {
        if (!$this->enabled()) return ['enabled'=>false];
        $time=Support::timestamp($now); $day=$time->format('Y-m-d');
        $primary=(int)(V2Store::policy($this->db)['front_desk_employee_id']??0);
        $person=$this->person($actor);
        if (!$primary || !in_array($person['role_key'],['owner_admin','front_desk_admin','marketing_sales'],true)) return ['enabled'=>false];
        $plan=V2Store::one($this->db,'SELECT * FROM epi_v2_front_plans WHERE work_date=? AND primary_employee_id=?',[$day,$primary]);
        $q=$this->db->query("SELECT e.id,e.full_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key='marketing_sales' ORDER BY e.full_name");
        $candidates=$q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor();
        $candidates=array_values(array_filter($candidates,function(array $candidate)use($time):bool{
            return HrAbsenceEvidence::inspect($this->db,$this->hr,(int)$candidate['id'],$time->format('Y-m-d H:i:s'))['state']==='no_approved_absence';
        }));
        $absence=HrAbsenceEvidence::inspect($this->db,$this->hr,$primary,$time->format('Y-m-d H:i:s'));
        $weekday=(int)$time->format('N')<=5;
        $prompt=$weekday && $actor===$primary && !$plan && $time->format('H:i')>='11:00' && $time->format('H:i')<'17:00';
        $alert=null;
        if ($absence['state']==='needs_review') $alert='HR availability cannot be verified. Review coverage before assigning responsibility.';
        elseif ($absence['state']==='approved_absence' && (!$plan || $plan['state']!=='active')) $alert='Approved HR absence: accepted Front Desk coverage is required.';
        elseif ($plan && $plan['state']==='active' && $time->format('Y-m-d H:i:s') >= $plan['planned_end']) $alert='Coverage end reached. Confirm the return handover; responsibility has not automatically reverted.';
        elseif ($plan && in_array($plan['state'],['requested','declined','accepted'],true) && $time->format('Y-m-d H:i:s') >= $plan['planned_start']) $alert='Planned coverage has not started. Confirm the actual handover.';
        elseif ($plan && $plan['state']==='exception') $alert='Front Desk exception recorded; owner attention required.';
        $outgoing=$plan && $plan['state']==='active'?(int)$plan['coverage_employee_id']:$primary;
        return ['enabled'=>true,'actor'=>$actor,'primary'=>$primary,'role'=>$person['role_key'],'plan'=>$plan,
            'handover'=>FrontHandoverChecklist::read($this->db,$outgoing,$time),
            'candidates'=>$candidates,'prompt'=>$prompt,'alert'=>$alert,'hr_state'=>$absence['state'],
            'hr_reason'=>$absence['reason'],'duty'=>(new OwnershipPeriodEngine($this->db))->dutyAt('front_desk',$time)];
    }

    public function command(int $actor, string $action, array $input, $now=null): void {
        if (!$this->enabled()) throw new RuntimeException('Coverage workflow is not activated.');
        $time=Support::timestamp($now); $at=$time->format('Y-m-d H:i:s'); $day=$time->format('Y-m-d');
        $primary=(int)(V2Store::policy($this->db)['front_desk_employee_id']??0);
        $person=$this->person($actor);
        if (!$primary || !in_array($person['role_key'],['owner_admin','front_desk_admin','marketing_sales'],true)) throw new RuntimeException('Not permitted.');
        V2Store::transaction($this->db,function()use($actor,$action,$input,$time,$at,$day,$primary,$person){
            V2Store::lock($this->db,'front-plan|'.$day);
            $plan=V2Store::one($this->db,'SELECT * FROM epi_v2_front_plans WHERE work_date=? AND primary_employee_id=? FOR UPDATE',[$day,$primary]);
            $handoverNotes=FrontHandoverChecklist::notes($this->db,$day);
            if($action==='plan' && isset($input['handover_notes']))$handoverNotes=FrontHandoverChecklist::validateNotes($input['handover_notes']);
            $handover=null;
            if(in_array($action,['accept','start','resume','absence_cover'],true)) {
                $outgoing=$action==='resume'?(int)($plan['coverage_employee_id']??0):$primary;
                $handover=FrontHandoverChecklist::confirm($this->db,$outgoing,$input,$time);
            }
            if (in_array($action,['plan','no_lunch','exception','absence_cover'],true)) {
                if ($plan && !in_array($plan['state'],['requested','declined','no_lunch','exception','accepted'],true)) throw new RuntimeException('Active or completed coverage cannot be replaced.');
                if ($plan && $action==='absence_cover') throw new RuntimeException('A daily plan already exists; owner review is required.');
                $absenceCover=$action==='absence_cover';
                if (!$absenceCover && ($actor!==$primary || (int)$time->format('N')>5)) throw new RuntimeException('Only Front Desk can record their weekday lunch plan.');
                if ($absenceCover && $person['role_key']!=='marketing_sales') throw new RuntimeException('Coverage must be accepted by the covering employee.');
                $reason=trim((string)($input['reason']??''));
                if (strlen($reason)>500) throw new RuntimeException('Keep the reason under 500 characters.');
                if (in_array($action,['no_lunch','exception'],true)) {
                    if ($reason==='') throw new RuntimeException('Please record a reason.');
                    if($plan) $this->db->prepare('UPDATE epi_v2_front_plans SET state=?,reason=?,accepted_at=NULL,coverage_employee_id=NULL,planned_start=NULL,planned_end=NULL WHERE id=?')->execute([$action,$reason,$plan['id']]);
                    else $this->db->prepare('INSERT INTO epi_v2_front_plans(work_date,primary_employee_id,state,reason) VALUES(?,?,?,?)')->execute([$day,$primary,$action,$reason]);
                } else {
                    $cover=$absenceCover?$actor:(int)($input['coverage_employee_id']??0);
                    if ($this->person($cover)['role_key']!=='marketing_sales' || $cover===$primary) throw new RuntimeException('Select an active Marketing & Sales coverage employee.');
                    if ($absenceCover) {
                        $hr=HrAbsenceEvidence::inspect($this->db,$this->hr,$primary,$at);
                        if ($hr['state']!=='approved_absence') throw new RuntimeException('Approved HR absence could not be verified. Owner review is required.');
                        $window=(new BusinessTimeEngine($this->db))->windowForDate($time);
                        if (!$window || $time<$window[0] || $time>=$window[1]) throw new RuntimeException('Coverage must begin during working hours.');
                        $from=$at;$to=$window[1]->format('Y-m-d H:i:s');
                        $this->available($cover,$at);
                        $this->replaceDuty($cover,$actor,$at,$to,'Accepted HR absence coverage',$primary);
                    } else {
                        $start=(string)($input['start']??'');$end=(string)($input['end']??'');
                        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end)) throw new RuntimeException('Choose valid lunch times.');
                        $from=$day.' '.$start.':00';$to=$day.' '.$end.':00';
                        if ($from<$at || $start<'08:00' || $end>'17:00' || $to<=$from) throw new RuntimeException('Choose a future lunch interval within working hours.');
                        $this->available($cover,$from);
                    }
                    if($plan) $this->db->prepare("UPDATE epi_v2_front_plans SET coverage_employee_id=?,planned_start=?,planned_end=?,state='requested',reason=?,accepted_at=NULL WHERE id=?")->execute([$cover,$from,$to,$reason,$plan['id']]);
                    else $this->db->prepare('INSERT INTO epi_v2_front_plans(work_date,primary_employee_id,coverage_employee_id,planned_start,planned_end,state,reason,accepted_at,actual_start) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$day,$primary,$cover,$from,$to,$absenceCover?'active':'requested',$absenceCover?'HR approved absence':$reason,$absenceCover?$at:null,$absenceCover?$at:null]);
                }
            } else {
                if (!$plan) throw new RuntimeException('No plan exists today.');
                $cover=(int)$plan['coverage_employee_id'];
                if (in_array($action,['accept','decline'],true)) {
                    if ($actor!==$cover || $plan['state']!=='requested') throw new RuntimeException('Only the requested employee can respond.');
                    $reason=trim((string)($input['reason']??''));
                    if ($action==='decline' && ($reason==='' || strlen($reason)>500)) throw new RuntimeException('Please provide a short reason.');
                    if ($action==='accept') $this->available($cover,$plan['planned_start']);
                    $this->db->prepare('UPDATE epi_v2_front_plans SET state=?,accepted_at=?,reason=? WHERE id=?')->execute([$action==='accept'?'accepted':'declined',$action==='accept'?$at:null,$action==='decline'?$reason:$plan['reason'],$plan['id']]);
                    if($action==='accept')V2OperationalBridge::record($this->db,'front_plan','handover_accepted',(int)$plan['id'],['employee_id'=>$actor,'occurred_at'=>$at]);
                } elseif ($action==='start') {
                    if ($actor!==$primary || $plan['state']!=='accepted') throw new RuntimeException('Coverage must be accepted before lunch starts.');
                    if ($at<$plan['planned_start'] || $at>=$plan['planned_end']) throw new RuntimeException('Start within the planned interval; otherwise owner review is required.');
                    $this->available($cover,$at);
                    $current=(new OwnershipPeriodEngine($this->db))->dutyAt('front_desk',$at);
                    if (!$current || (int)$current['employee_id']!==$primary) throw new RuntimeException('Confirm your current Front Desk duty before handing it over.');
                    $window=(new BusinessTimeEngine($this->db))->windowForDate($time);
                    if (!$window || $time>=$window[1]) throw new RuntimeException('Outside working hours.');
                    V2OperationalBridge::record($this->db,'front_plan','handover_started',(int)$plan['id'],['employee_id'=>$actor,'occurred_at'=>$at]);
                    $this->replaceDuty($cover,$actor,$at,$window[1]->format('Y-m-d H:i:s'),'Lunch handover accepted at '.$plan['accepted_at'],$primary);
                    $this->db->prepare("UPDATE epi_v2_front_plans SET state='active',actual_start=? WHERE id=?")->execute([$at,$plan['id']]);
                } elseif ($action==='resume') {
                    if ($actor!==$primary || $plan['state']!=='active') throw new RuntimeException('Only the primary Front Desk employee can confirm their return.');
                    $this->available($primary,$at);
                    $current=(new OwnershipPeriodEngine($this->db))->dutyAt('front_desk',$at);
                    if (!$current || (int)$current['employee_id']!==$cover) throw new RuntimeException('Coverage changed; owner review is required.');
                    $window=(new BusinessTimeEngine($this->db))->windowForDate($time);
                    if (!$window || $time>=$window[1]) throw new RuntimeException('Outside working hours.');
                    $this->replaceDuty($primary,$actor,$at,$window[1]->format('Y-m-d H:i:s'),'Confirmed return from lunch',$cover);
                    $this->db->prepare("UPDATE epi_v2_front_plans SET state='completed',actual_end=? WHERE id=?")->execute([$at,$plan['id']]);
                } else throw new RuntimeException('Unknown action.');
            }
            if($plan && in_array($action,['plan','no_lunch','exception'],true))
                V2OperationalBridge::record($this->db,'front_plan','handover_replanned',(int)$plan['id'],['employee_id'=>$actor,'occurred_at'=>$at,'reason'=>$reason]);
            $after=V2Store::one($this->db,'SELECT * FROM epi_v2_front_plans WHERE work_date=? AND primary_employee_id=?',[$day,$primary]);
            $after['handover_notes']=$handoverNotes;
            if($handover!==null)$after['reviewed_work']=$handover;
            V2Store::audit($this->db,'front-plan|'.$day,$actor,$action,$plan,$after);
        });
    }

    private function person(int $id): array {
        $r=V2Store::one($this->db,"SELECT e.id,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active'",[$id]);
        if (!$r) throw new RuntimeException('Active employee identity required.');
        return $r;
    }
    private function available(int $id,string $at): void {
        $this->person($id);
        $hr=HrAbsenceEvidence::inspect($this->db,$this->hr,$id,$at);
        if ($hr['state']!=='no_approved_absence' || V2Store::absence($this->db,$id,$at)) throw new RuntimeException('Availability cannot be confirmed from HR; owner review required.');
    }

    /** Scheduled command. The sender must honour the supplied deduplication key. */
    public function reminders(callable $send, $now=null): array {
        if (!$this->enabled()) return ['sent'=>0,'failed'=>0];
        $time=Support::timestamp($now);$day=$time->format('Y-m-d');$at=$time->format('Y-m-d H:i:s');
        $dayNumber=(int)$time->format('N');$clock=$time->format('H:i');
        if($dayNumber===7 || $clock<($dayNumber===6?'09:00':'08:00') || $clock>=($dayNumber===6?'13:00':'17:00'))return ['sent'=>0,'failed'=>0];
        $primary=(int)(V2Store::policy($this->db)['front_desk_employee_id']??0);
        $status=$this->status($primary,$time);$plan=$status['plan'];$events=[];
        if($status['prompt']) $events[]=['plan_required',$primary,'Please record today’s lunch time and request accepted coverage.'];
        if(!$plan && $status['hr_state']==='approved_absence') foreach($status['candidates'] as $candidate) $events[]=['absence_coverage',(int)$candidate['id'],'Approved Front Desk absence: confirm coverage if you are available.'];
        if($plan && in_array($plan['state'],['requested','accepted'],true) && $at>=Support::timestamp($plan['planned_start'])->modify('-15 minutes')->format('Y-m-d H:i:s')) {
            $events[]=['start_reminder',(int)$plan['coverage_employee_id'],'Front Desk coverage is approaching. Review and confirm the handover.'];
            $events[]=['start_reminder',$primary,'Front Desk coverage is approaching. Start the handover at the actual lunch time.'];
        }
        if($status['alert'] || ($status['prompt'] && $time->format('H:i')>='12:00')) {
            $q=$this->db->query("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key='owner_admin'");
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $owner) $events[]=['owner_attention',(int)$owner['id'],$status['alert']??'The daily lunch plan is still missing.'];
            $q->closeCursor();
        }
        if($plan && $plan['state']==='active' && $at>=$plan['planned_end']) {
            $events[]=['return_due',$primary,'Confirm your return to Front Desk. Coverage has not automatically reverted.'];
            $events[]=['return_due',(int)$plan['coverage_employee_id'],'Coverage end reached. Confirm the return with Front Desk or contact the owner.'];
        }
        $result=['sent'=>0,'failed'=>0];
        foreach($events as [$kind,$recipient,$message]) {
            $key=hash('sha256',Support::json([$day,$kind,$recipient,$plan['state']??null,$plan['planned_start']??null,$plan['accepted_at']??null]));
            $outcome=V2Store::transaction($this->db,function()use($key,$send,$recipient,$message,$at){
                V2Store::lock($this->db,'front-reminder|'.$key);
                if(V2Store::one($this->db,'SELECT reminder_key FROM epi_v2_front_reminders WHERE reminder_key=?',[$key])) return 'skipped';
                $id=$send($recipient,$message,'front-coverage-'.$key);
                if(!$id) return 'failed';
                $this->db->prepare('INSERT INTO epi_v2_front_reminders VALUES(?,?,?,?)')->execute([$key,$recipient,$at,$id]);
                return 'sent';
            });
            if(isset($result[$outcome])) $result[$outcome]++;
        }
        return $result;
    }
    private function replaceDuty(int $employee,int $actor,string $at,string $to,string $reason,int $expectedOutgoing): void {
        // Called only in the locked command transaction after actual acceptance.
        V2Store::lock($this->db,'duty|front_desk');
        $q=$this->db->prepare("SELECT * FROM epi_v2_duty_periods WHERE duty_key='front_desk' AND superseded_at IS NULL AND effective_to>? FOR UPDATE");
        $q->execute([$at]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $outgoing=[];
        foreach($rows as $row) {
            if ((int)$row['employee_id']!==$expectedOutgoing) throw new RuntimeException('Duty changed during handover; refresh and review.');
            if ($row['effective_from']>=$at) throw new RuntimeException('A scheduled duty conflicts; owner review required.');
            $this->db->prepare('UPDATE epi_v2_duty_periods SET effective_to=? WHERE id=?')->execute([$at,$row['id']]);
            V2Store::audit($this->db,'duty|front_desk',$actor,$reason,$row,['effective_to'=>$at]);
            $outgoing[(int)$row['employee_id']]=true;
        }
        (new OwnershipPeriodEngine($this->db))->assignDuty(['duty_key'=>'front_desk','employee_id'=>$employee,'assigned_by'=>$actor,'accepted_by'=>$employee,'accepted_at'=>$at,'effective_from'=>$at,'effective_to'=>$to,'responsibility_level'=>'primary','source'=>'front_coverage_workflow','reason'=>$reason]);
        // Transfer explicit Front Desk role ownership across its supported modules, never Packing.
        foreach(array_keys($outgoing) as $oldEmployee) {
            $q=$this->db->prepare("SELECT module,object_reference FROM epi_v2_ownership_periods WHERE module IN('Orders','Courier','Bookkeeping','Inventory') AND employee_id=? AND role_key IN ('front_desk_admin','marketing_sales') AND effective_from<? AND (effective_to IS NULL OR effective_to>?)");
            $q->execute([$oldEmployee,$at,$at]);$objects=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            foreach($objects as $object) (new OwnershipPeriodEngine($this->db))->assign([
                'module'=>$object['module'],'object_reference'=>$object['object_reference'],'employee_id'=>$employee,
                'role_key'=>$this->person($employee)['role_key'],'assigned_by'=>$actor,'accepted_by'=>$employee,
                'accepted_at'=>$at,'effective_from'=>$at,'source'=>'front_coverage_workflow','transfer_reason'=>$reason]);
        }
    }
}
