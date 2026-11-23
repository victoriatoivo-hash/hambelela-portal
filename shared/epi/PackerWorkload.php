<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use Throwable;

/** Read-only workload context. Never grades employees or changes published scores. */
final class PackerWorkload
{
    public static function contribution(array $rows,int $employee,string $from,string $to,array $items):array
    {
        $out=['packed'=>0,'items_known_orders'=>0,'item_lines'=>0,'item_units'=>0.0,'responsibilities'=>0,'fulfilled'=>0,'fulfilled_by_colleague'=>0,'open'=>0,'overdue'=>0];
        foreach($rows as$r){
            if(in_array($r['result'],['Excluded','Waiting for Paid'],true))continue;
            $done=(int)($r['packed_by']??0)===$employee && $r['packed_at']>=$from && $r['packed_at']<=$to;
            if($done){$out['packed']++;if(isset($items[$r['id']])){$i=$items[$r['id']];$out['items_known_orders']++;$out['item_lines']+=(int)$i['lines'];$out['item_units']+=(float)$i['units'];}}
            // Same responsibility cohort for numerator and denominator, not all output divided by assignments.
            $due=$r['due_at']??null;$open=empty($r['packed_at'])&&in_array($r['result'],['Pending','Overdue'],true);
            if((int)($r['responsible_id']??0)!==$employee || (!$open && !($due!==null&&$due>=$from&&$due<=$to)))continue;
            $out['responsibilities']++;
            if(!empty($r['packed_at'])){$out['fulfilled']++;if((int)$r['packed_by']!==$employee)$out['fulfilled_by_colleague']++;}
            elseif($open){$out['open']++;if($r['result']==='Overdue')$out['overdue']++;}
        }
        $out['item_units']=round($out['item_units'],3);
        return $out;
    }

    /** Union overlapping intervals before subtraction, including overlapping leave and coverage. */
    public static function intervalSeconds(PackerOrdersPreview $clock,array $intervals,string $from,string $to):int
    {
        $clipped=[];
        foreach($intervals as$r){$a=max($from,$r[0]);$b=min($to,$r[1]??$to);if($a<$b)$clipped[]=[$a,$b];}
        usort($clipped,static fn($a,$b)=>strcmp($a[0],$b[0]));$merged=[];
        foreach($clipped as$r){$last=count($merged)-1;if($last>=0 && $r[0]<=$merged[$last][1])$merged[$last][1]=max($merged[$last][1],$r[1]);else$merged[]=$r;}
        $seconds=0;foreach($merged as[$a,$b])$seconds+=$clock->seconds($a,$b);return $seconds;
    }

    public static function capacity(PDO $db,?PDO $hr,PackerOrdersPreview $clock,int $employee,string $from,string $to):array
    {
        $gross=$clock->seconds($from,$to);$leave=[];$cover=[];$hrOk=false;$coverOk=false;
        try{
            $q=$db->prepare("SELECT effective_from,effective_to FROM epi_v2_duty_periods WHERE employee_id=? AND duty_key='front_desk' AND superseded_at IS NULL AND effective_from<? AND (effective_to IS NULL OR effective_to>?) AND (source='approved_front_roster' OR accepted_at IS NOT NULL)");
            $q->execute([$employee,$to,$from]);foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r)$cover[]=[$r['effective_from'],$r['effective_to']??$to];$coverOk=true;
        }catch(Throwable $e){/* Missing source remains unknown, not zero. */}
        try{
            if($hr){
                $q=$db->prepare('SELECT hr_employee_id,linked_at FROM employee_user_links WHERE portal_user_id=? AND active=1');$q->execute([$employee]);$links=$q->fetchAll(PDO::FETCH_ASSOC);
                if(count($links)===1 && !empty($links[0]['linked_at']) && $links[0]['linked_at']<=$from){
                    $link=$links[0];$q=$db->prepare('SELECT COUNT(*) FROM employee_user_links WHERE hr_employee_id=? AND active=1');$q->execute([$link['hr_employee_id']]);
                    if((int)$q->fetchColumn()===1){
                        $q=$hr->prepare('SELECT id FROM employees WHERE id=?');$q->execute([$link['hr_employee_id']]);
                        if($q->fetchColumn()){
                            $q=$hr->prepare("SELECT start_date,end_date,days,approved_at,approved_by FROM leave_requests WHERE employee_id=? AND status='approved' AND start_date<=? AND end_date>=?");$q->execute([$link['hr_employee_id'],substr($to,0,10),substr($from,0,10)]);$hrOk=true;
                            foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r){
                                if(empty($r['approved_at'])||empty($r['approved_by'])||$r['approved_at']>$to){$hrOk=false;continue;}
                                $a=PackerOrdersPreview::time($r['start_date'].' 00:00:00');$b=PackerOrdersPreview::time($r['end_date'].' 00:00:00')->modify('+1 day');
                                if($b<=$a){$hrOk=false;continue;}
                                // HR records whole working days (Monday–Saturday). A partial-day
                                // record without exact times cannot safely be subtracted as a full day.
                                $days=0;for($d=$a;$d<$b;$d=$d->modify('+1 day'))if((int)$d->format('N')!==7)$days++;
                                if(!is_numeric($r['days'])||abs((float)$r['days']-$days)>0.001){$hrOk=false;continue;}
                                $leave[]=[$a->format('Y-m-d H:i:s'),$b->format('Y-m-d H:i:s')];
                            }
                        }
                    }
                }
            }
        }catch(Throwable $e){$hrOk=false;}
        $leaveSeconds=self::intervalSeconds($clock,$leave,$from,$to);$coverSeconds=self::intervalSeconds($clock,$cover,$from,$to);
        return ['scheduled_hours'=>round($gross/3600,1),'leave_hours'=>$hrOk?round($leaveSeconds/3600,1):null,'front_cover_hours'=>$coverOk?round($coverSeconds/3600,1):null,
            'remaining_scheduled_hours'=>$hrOk&&$coverOk?round(max(0,$gross-self::intervalSeconds($clock,array_merge($leave,$cover),$from,$to))/3600,1):null,
            'status'=>$hrOk&&$coverOk?'recorded_adjustments':'needs_verification',
            'note'=>'Calendar hours through the reporting cutoff, less recorded approved leave and Front Desk/lunch cover. Overlaps deducted once. Not attendance, active packing hours or a productivity score; other duties and employment dates need review.'];
    }
}
