<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Read-only, owner-approved 120-minute policy. Never publishes scores or changes work. */
final class PackerOrdersPreview
{
    public const VERSION = 'packer-orders-history-summary-test-v2';
    private array $closures;
    private array $holidays = [];
    private array $yearSeconds = [];
    public function __construct(array $closures = []) { $this->closures = array_fill_keys($closures, true); }
    public static function time(string $s): DateTimeImmutable {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?$/D', $s)) throw new RuntimeException('Missing or invalid evidence timestamp');
        $d = new DateTimeImmutable($s, new DateTimeZone('Africa/Windhoek'));
        if ($d->format('Y-m-d H:i:s') !== str_replace('T',' ',substr($s,0,19))) throw new RuntimeException('Invalid evidence date');
        return $d->setTimezone(new DateTimeZone('Africa/Windhoek'));
    }
    public static function status(string $s): string {
        $s = strtolower(preg_replace('/[\s_-]+/', '', $s));
        return ['neworder'=>'new','inprogress'=>'progress','completed'=>'complete'][$s] ?? $s;
    }
    private function holidays(int $year): array {
        if (isset($this->holidays[$year])) return $this->holidays[$year];
        // Public Holidays Act 26/1990; Sunday holidays also close Monday.
        // https://www.lac.org.na/laws/annoSTAT/Public%20Holidays%20Act%2026%20of%201990.pdf
        // 28 May: https://www.gov.na/documents/146489/243786/Genocide+Remembrance+Day+2025.pdf/943da8fe-f23f-ccf3-0765-522d4797a547
        $dates=[];
        foreach (['01-01','03-21','05-01','05-04','05-25','08-26','12-10','12-25','12-26'] as $day) $dates[]="$year-$day";
        if ($year>=2025) $dates[]="$year-05-28";
        // Gregorian computus; independent of host timezone and PHP calendar extension.
        $a=$year%19;$b=intdiv($year,100);$c=$year%100;$d=intdiv($b,4);$e=$b%4;$f=intdiv($b+8,25);$g=intdiv($b-$f+1,3);
        $h=(19*$a+$b-$d-$g+15)%30;$i=intdiv($c,4);$k=$c%4;$l=(32+2*$e+2*$i-$h-$k)%7;$m=intdiv($a+11*$h+22*$l,451);
        $easter=self::time(sprintf('%04d-%02d-%02d 00:00:00',$year,intdiv($h+$l-7*$m+114,31),($h+$l-7*$m+114)%31+1));
        foreach ([-2,1,39] as $offset) $dates[]=$easter->modify(($offset>=0?'+':'').$offset.' days')->format('Y-m-d');
        foreach ($dates as $day) { $d=self::time($day.' 00:00:00'); if ($d->format('N')==='7') $dates[]=$d->modify('+1 day')->format('Y-m-d'); }
        return $this->holidays[$year]=array_fill_keys($dates,true);
    }
    public function window(DateTimeImmutable $day): ?array {
        $day=$day->setTimezone(new DateTimeZone('Africa/Windhoek'));
        $key=$day->format('Y-m-d');$n=(int)$day->format('N');
        if ($n===7 || isset($this->closures[$key]) || isset($this->holidays((int)$day->format('Y'))[$key])) return null;
        return [$day->setTime($n===6?9:8,0),$day->setTime($n===6?13:17,0)];
    }
    public function due(string $start): string {
        $cursor=self::time($start);$remaining=7200;
        for ($days=0;$days<3700;$days++) {
            $w=$this->window($cursor);
            if ($w) {
                if ($cursor<$w[0]) $cursor=$w[0];
                $available=max(0,$w[1]->getTimestamp()-$cursor->getTimestamp());
                if ($remaining<=$available) return $cursor->modify('+'.$remaining.' seconds')->format('Y-m-d H:i:s');
                $remaining-=$available;
            }
            $cursor=$cursor->modify('+1 day')->setTime(0,0);
        }
        throw new RuntimeException('Working calendar has no available deadline');
    }
    public function seconds(string $start,string $end): int {
        $a=self::time($start);$b=self::time($end);if($b<=$a)return 0;
        $first=(int)$a->format('Y');$last=(int)$b->format('Y');
        $sum=$this->yearPosition($b)-$this->yearPosition($a);
        for($year=$first;$year<$last;$year++){$this->calendarYear($year);$sum+=$this->yearSeconds[$year]['total'];}
        return $sum;
    }
    // Reuse calendar days across orders and previous-period comparisons.
    private function calendarYear(int $year): void {
        if(isset($this->yearSeconds[$year]))return;
        $prefix=[];$total=0;$day=self::time("$year-01-01 00:00:00");
        while((int)$day->format('Y')===$year){
            $prefix[$day->format('m-d')]=$total;
            if($w=$this->window($day))$total+=$w[1]->getTimestamp()-$w[0]->getTimestamp();
            $day=$day->modify('+1 day');
        }
        $this->yearSeconds[$year]=['days'=>$prefix,'total'=>$total];
    }
    private function yearPosition(DateTimeImmutable $at): int {
        $year=(int)$at->format('Y');$this->calendarYear($year);
        $seconds=$this->yearSeconds[$year]['days'][$at->format('m-d')];
        if($w=$this->window($at))$seconds+=max(0,min($at->getTimestamp(),$w[1]->getTimestamp())-$w[0]->getTimestamp());
        return $seconds;
    }
    /** Events are normalized Namibia timestamps with explicit New/Paid/transition evidence. */
    public function evaluate(array $order,array $events,array $ownership,array $exceptions,string $asOf): array {
        $r=$order+['eligible_at'=>null,'due_at'=>null,'packed_at'=>null,'packed_by'=>null,'responsible_id'=>null,'working_minutes'=>null,'overdue_minutes'=>null,'result'=>'Not Measured','reason'=>'New-entry timestamp not recorded','score_outcome'=>null,'evidence'=>[]];
        $mode=strtolower(trim((string)$order['mode']));$r['mode']=$mode;
        if (!in_array($mode,['collection','delivery','courier'],true) || ($mode==='collection' && preg_match('/walk[\s_-]*in[\s_-]*customer/i',(string)($order['contact']??'')))) {
            return array_replace($r,['result'=>'Excluded','reason'=>'Walk-in collection or mode outside packer Orders scope']);
        }
        if (in_array(self::status((string)$order['status']),['cancelled','canceled','refunded','failed','trash'],true)) return array_replace($r,['result'=>'Excluded','reason'=>'Cancelled, refunded or removed order']);
        usort($events,static fn($a,$b)=>strcmp($a['at'],$b['at']));
        $new=null;$paid=null;$packed=null;$transitions=[];$reopened=false;$futurePacked=false;$paymentReversed=false;
        foreach ($events as $event) {
            if ($event['at']>$asOf) { if($event['kind']==='packed')$futurePacked=true; continue; }
            if ($event['kind']==='new') { if ($packed) $reopened=true; $new=$new??$event['at']; }
            if ($event['kind']==='paid' && $paid===null) $paid=$event['at'];
            if ($event['kind']==='unpaid' && $paid!==null) $paymentReversed=true;
            if ($event['kind']==='packed') {
                $key=$event['at'].'|'.($event['actor']??0);$transitions[$key]=true;
                if (!$packed) $packed=$event;
            }
            $r['evidence'][]=$event['source']??'Recorded event';
        }
        if ($packed) { $r['packed_at']=$packed['at'];$r['packed_by']=(int)($packed['actor']??0)?:null; }
        if ($reopened || count($transitions)>1) return array_replace($r,['reason'=>'Multiple packing cycles or conflicting transitions require review; not counted twice']);
        if (!$new) return $r;
        if ($mode==='courier' && $paymentReversed) return array_replace($r,['reason'=>'Paid flag was reversed; eligibility history requires review']);
        if ($mode==='courier' && !$paid) {
            $waiting=empty($order['paid'])&&!$packed&&self::status((string)$order['status'])==='new';
            return array_replace($r,['result'=>$waiting?'Waiting for Paid':'Not Measured','reason'=>$waiting?'Courier clock has not started':'Original Paid timestamp missing']);
        }
        $start=$mode==='courier'?max($new,$paid):$new;$r['eligible_at']=$start;$r['due_at']=$this->due($start);
        if ($packed && $packed['at']<$start) return array_replace($r,['reason'=>'Packing precedes eligibility; review source history']);
        if (!$packed && !$futurePacked && self::status((string)$order['status'])!=='new') return array_replace($r,['reason'=>'New → In Progress transition missing; Complete is not packing evidence']);
        $end=$packed['at']??$asOf;
        $seconds=$this->seconds($start,$end);$r['working_minutes']=round($seconds/60,2);
        $late=$seconds>7200;
        $r['result']=$packed?($late?'Late':'On Time'):($late?'Overdue':'Pending');
        $r['overdue_minutes']=$late?round($this->seconds($r['due_at'],$end)/60,2):0;
        // Responsibility at deadline for late work, at actual completion for on-time work.
        // Never turn the eventual packing actor into the earlier responsible employee.
        $responsibilityAt=$late?$r['due_at']:($packed['at']??min($asOf,$r['due_at']));
        $owners=array_values(array_filter($ownership,static fn($o)=>$o['effective_from']<=$responsibilityAt && (empty($o['effective_to'])||$o['effective_to']>$responsibilityAt)));
        if (count($owners)===1) $r['responsible_id']=(int)$owners[0]['employee_id'];
        $r['reason']=$r['responsible_id']?'120 working minutes; verified responsibility':'Responsibility not proven — team review, no individual penalty';
        foreach ($exceptions as $x) {
            if ($x['effective_from']<=$end && $x['effective_to']>$start && (empty($x['employee_id'])||(int)$x['employee_id']===$r['responsible_id'])) {
                $r['result']='Excluded';$r['reason']='Approved blocker/absence: '.$x['reason'];return $r;
            }
        }
        if ($r['responsible_id'] && in_array($r['result'],['On Time','Late','Overdue'],true)) $r['score_outcome']=$r['result']==='On Time'?'success':'failure';
        return $r;
    }
    public static function summary(array $rows,int $employee,string $from,string $to): array {
        $s=['assigned'=>0,'waiting_paid'=>0,'packed'=>0,'on_time'=>0,'late'=>0,'overdue'=>0,'pending'=>0,'not_measured'=>0,'responsibility_review'=>0,'timing_measured'=>0,'timing_missing'=>0,'timing_reasons'=>[],'on_time_percent'=>null,'excluded'=>0,'assessed'=>0,'successes'=>0,'score'=>null,'average'=>null,'fastest'=>null,'slowest'=>null,'modes'=>[]];$durations=[];
        foreach ($rows as $r) {
            $mine=(int)($r['responsible_id']??0)===$employee;
            $done=(int)($r['packed_by']??0)===$employee && $r['packed_at']>=$from && $r['packed_at']<=$to;
            if (!$mine&&!$done && (int)($r['assigned_id']??0)!==$employee) continue;
            if ($mine && !in_array($r['result'],['Excluded','Waiting for Paid','Not Measured'],true)) $s['assigned']++;
            if ($r['result']==='Waiting for Paid') $s['waiting_paid']++;
            if (!isset($s['modes'][$r['mode']])) $s['modes'][$r['mode']]=['packed'=>0,'on_time'=>0,'late'=>0,'overdue'=>0,'minutes'=>[]];
            $m=&$s['modes'][$r['mode']];
            if ($done && !in_array($r['result'],['Excluded','Waiting for Paid'],true)) {
                $s['packed']++;$m['packed']++;
                if ($r['working_minutes']!==null && in_array($r['result'],['On Time','Late'],true)) { $durations[]=$r['working_minutes'];$m['minutes'][]=$r['working_minutes']; }
                if ($r['result']==='On Time') {$s['on_time']++;$m['on_time']++;}
                if ($r['result']==='Late') {$s['late']++;$m['late']++;}
            }
            if ($mine && $r['result']==='Overdue') {$s['overdue']++;$m['overdue']++;}
            if ($mine && $r['result']==='Pending') $s['pending']++;
            if ($r['result']==='Not Measured') {$s['not_measured']++;$s['timing_reasons'][$r['reason']]=($s['timing_reasons'][$r['reason']]??0)+1;}
            if (!$r['responsible_id'] && !in_array($r['result'],['Excluded','Waiting for Paid'],true)) $s['responsibility_review']++;
            if ($r['result']==='Excluded') $s['excluded']++;
            if ($mine && $r['due_at']>=$from && $r['due_at']<=$to && $r['score_outcome']!==null) { $s['assessed']++;if($r['score_outcome']==='success')$s['successes']++; }
            unset($m);
        }
        foreach ($s['modes'] as &$m) {$m['average']=$m['minutes']?round(array_sum($m['minutes'])/count($m['minutes']),1):null;unset($m['minutes']);}unset($m);
        if ($durations) {$s['average']=round(array_sum($durations)/count($durations),1);$s['fastest']=min($durations);$s['slowest']=max($durations);}
        $s['timing_measured']=count($durations);$s['timing_missing']=$s['packed']-count($durations);
        $s['on_time_percent']=$s['timing_measured']?round(100*$s['on_time']/$s['timing_measured'],1):null;
        $s['score']=$s['assessed']?round(100*$s['successes']/$s['assessed'],1):null;
        return $s;
    }
}
