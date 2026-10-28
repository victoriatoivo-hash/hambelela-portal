<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
require_once __DIR__.'/ProvisionalTimingPolicy.php';

/** Reconstructs elapsed allowance from accepted ownership, not current assignee/login. */
final class ResponsibilityClock
{
    public static function inspect(PDO $db,?PDO $hr,array $deadline,string $until):array
    {
        $snap=json_decode($deadline['policy_snapshot_json'],true)?:[];
        $budget=(float)$snap['responsibility_budget_minutes'];$from=$deadline['starts_at'];
        $points=[$from,$until];$employees=[];
        $s=$db->prepare('SELECT * FROM epi_v2_ownership_periods WHERE module=? AND object_reference=? AND effective_from<? AND (effective_to IS NULL OR effective_to>?)');
        $s->execute([$deadline['module'],$deadline['object_reference'],$until,$from]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();
        $duty=V2Store::one($db,'SELECT duty_key FROM epi_v2_object_duties WHERE module=? AND object_reference=?',[$deadline['module'],$deadline['object_reference']]);
        if($duty){$s=$db->prepare('SELECT * FROM epi_v2_duty_periods WHERE duty_key=? AND superseded_at IS NULL AND effective_from<? AND (effective_to IS NULL OR effective_to>?)');
            $s->execute([$duty['duty_key'],$until,$from]);$rows=array_merge($rows,$s->fetchAll(PDO::FETCH_ASSOC));$s->closeCursor();}
        foreach($rows as $r){$employees[(int)$r['employee_id']]=true;foreach(['effective_from','effective_to','accepted_at'] as $k)if(!empty($r[$k]))$points[]=$r[$k];}
        if($employees){$ids=array_keys($employees);$s=$db->prepare('SELECT effective_from,effective_to,approved_at FROM epi_v2_exceptions WHERE employee_id IN('.implode(',',array_fill(0,count($ids),'?')).') AND kind=\'absence\' AND effective_from<? AND effective_to>?');
            $s->execute(array_merge($ids,[$until,$from]));foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)foreach($r as $p)$points[]=$p;$s->closeCursor();}
        for($day=Support::timestamp($from)->setTime(0,0);$day<=Support::timestamp($until);$day=$day->modify('+1 day')){
            $date=$day->format('Y-m-d');$n=(int)$day->format('N');$points[]=$date.' 00:00:00';
            if($n!==7){$points[]=$date.($n===6?' 09:00:00':' 08:00:00');$points[]=$date.($n===6?' 13:00:00':' 17:00:00');}
        }
        $points=array_values(array_unique(array_filter($points,fn($p)=>$p>=$from&&$p<=$until)));sort($points);
        $engine=new OwnershipPeriodEngine($db);$elapsed=0.0;$segments=[];$paused=[];$hrCache=[];$due=null;$breachOwner=null;
        for($i=0;$i<count($points)-1;$i++){$a=$points[$i];$b=$points[$i+1];
            $minutes=DeadlineEngine::minutes($a,$b,['hours'=>$snap['timing_policy']['hours'],'calendar'=>[]]);if($minutes<=0)continue;
            $owner=$engine->ownerAt($deadline['module'],$deadline['object_reference'],$a);
            $reason=$owner?null:'no_accepted_responsibility';
            if($owner){$cacheKey=$owner['employee_id'].'|'.substr($a,0,10);
                if(!isset($hrCache[$cacheKey]))$hrCache[$cacheKey]=HrAbsenceEvidence::inspect($db,$hr,(int)$owner['employee_id'],$a);
                $availability=$hrCache[$cacheKey];
                if($availability['state']!=='no_approved_absence')$reason=$availability['state']==='approved_absence'?'approved_absence':'availability_unverified';
            }
            if($reason){$paused[]=['from'=>$a,'to'=>$b,'reason'=>$reason,'employee_id'=>$owner['employee_id']??null];continue;}
            $used=min($minutes,max(0,$budget-$elapsed));
            $segments[]=['from'=>$a,'to'=>$b,'employee_id'=>(int)$owner['employee_id'],'ownership_uuid'=>$owner['ownership_uuid'],'minutes'=>$used];
            $elapsed+=$used;
            if($elapsed>=$budget){$due=Support::timestamp($a)->modify('+'.(int)round($used*60).' seconds')->format('Y-m-d H:i:s');$breachOwner=$owner;break;}
        }
        return ['budget_minutes'=>$budget,'elapsed_minutes'=>round($elapsed,4),'remaining_minutes'=>max(0,$budget-$elapsed),
            'due_at'=>$due,'owner_at_expiry'=>$breachOwner,'segments'=>$segments,'paused'=>$paused,
            'evaluated_through'=>$until,'provisional'=>true];
    }
}
