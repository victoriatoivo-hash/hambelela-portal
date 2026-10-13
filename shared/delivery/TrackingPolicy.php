<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Shared GPS rules. UTC at the transport/storage boundary, Windhoek work windows. */
final class TrackingPolicy
{
    public static function owner(array $actor): void
    {
        if (($actor['kind']??'')!=='employee'||empty($actor['active'])||(int)($actor['id']??0)<1||($actor['role']??'')!=='owner_admin')
            throw new \DomainException('Owner access required.');
    }
    public static function settings(array $input): array
    {
        $limits=['moving_seconds'=>[10,60,15],'stationary_seconds'=>[30,300,60],
            'stationary_minutes'=>[5,120,10],'late_minutes'=>[1,120,15],
            'offline_seconds'=>[120,1800,300],'retention_days'=>[1,90,30],
            'route_interval_seconds'=>[120,1800,180],'route_daily_limit'=>[0,300,100],
            'deviation_metres'=>[200,5000,500]];
        $out=[];
        foreach($limits as $key=>$range){$value=$input[$key]??$range[2];
            if(filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<$range[0]||(int)$value>$range[1])throw new \DomainException('Invalid '.$key.'.');
            $out[$key]=(int)$value;}
        foreach(['work_start','work_end'] as $key){$value=(string)($input[$key]??($key==='work_start'?'08:00':'17:00'));
            if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$value))throw new \DomainException('Invalid working time.');$out[$key]=$value;}
        if($out['work_start']===$out['work_end'])throw new \DomainException('Work hours must not cover a whole day.');
        $days=$input['work_days']??[1,2,3,4,5];
        if(!is_array($days)||!$days||count($days)>7)throw new \DomainException('Choose working days.');
        foreach($days as $day)if(filter_var($day,FILTER_VALIDATE_INT)===false||(int)$day<1||(int)$day>7)throw new \DomainException('Invalid working day.');
        $out['work_days']=array_values(array_unique(array_map('intval',$days)));sort($out['work_days']);
        return $out;
    }
    public static function window(int $epoch,array $s): ?array
    {
        $local=(new \DateTimeImmutable('@'.$epoch))->setTimezone(new \DateTimeZone('Africa/Windhoek'));
        // Check today's and yesterday's start to support a shift spanning midnight.
        foreach([$local,$local->modify('-1 day')] as $day){
            if(!in_array((int)$day->format('N'),$s['work_days'],true))continue;
            $start=new \DateTimeImmutable($day->format('Y-m-d').' '.$s['work_start'],new \DateTimeZone('Africa/Windhoek'));
            $end=new \DateTimeImmutable($day->format('Y-m-d').' '.$s['work_end'],new \DateTimeZone('Africa/Windhoek'));
            if($end<=$start)$end=$end->modify('+1 day');
            if($epoch>=$start->getTimestamp()&&$epoch<$end->getTimestamp())return ['start'=>$start->getTimestamp(),'end'=>$end->getTimestamp()];
        }
        return null;
    }
    public static function point(array $p,int $now): array
    {
        foreach(['latitude'=>[-90,90],'longitude'=>[-180,180],'accuracy'=>[0,5000]] as $key=>$bounds){
            $n=$p[$key]??null;if(!is_numeric($n)||!is_finite((float)$n)||(float)$n<$bounds[0]||(float)$n>$bounds[1])throw new \DomainException('Invalid GPS '.$key.'.');$p[$key]=(float)$n;}
        $t=$p['recorded_at']??null;
        if(filter_var($t,FILTER_VALIDATE_INT)===false||(int)$t>$now+30||(int)$t<$now-86400)throw new \DomainException('GPS timestamp is outside the accepted window.');
        $id=(string)($p['event_id']??'');if(!preg_match('/^[a-f0-9-]{36}$/D',$id))throw new \DomainException('GPS event ID required.');
        return ['event_id'=>$id,'recorded_at'=>(int)$t,'latitude'=>$p['latitude'],'longitude'=>$p['longitude'],'accuracy'=>$p['accuracy']];
    }
    public static function status(int $now,?int $contact,?int $position,bool $valid,array $s,bool $active): string
    {
        if(!$active)return 'Inactive';
        if(self::window($now,$s)===null)return 'Off duty';
        if($contact===null||$now-$contact>$s['offline_seconds'])return 'Offline';
        if(!$valid||$position===null)return 'Location unavailable';
        if($position>$now+30||$now-$position>max(45,$s['stationary_seconds']+30))return 'Delayed';
        return 'Live';
    }
    /** Only used for movement/accuracy checks, never labelled as road distance. */
    public static function metres(float $a,float $b,float $c,float $d): float
    {
        $p=deg2rad($c-$a);$q=deg2rad($d-$b);$h=sin($p/2)**2+cos(deg2rad($a))*cos(deg2rad($c))*sin($q/2)**2;
        return 6371000*2*atan2(sqrt(max(0,min(1,$h))),sqrt(max(0,1-$h)));
    }
}
