<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/NotificationOutbox.php';

/** Customer-confirmed times only. All storage timestamps are UTC; input/display is Windhoek. */
final class DeliveryDeadline
{
    public static function normalize(array $input):array
    {
        $mode=(string)($input['preference_mode']??'none');
        if(!in_array($mode,['none','before','window','appointment'],true))throw new \DomainException('Choose a valid delivery preference.');
        if($mode==='none')return ['mode'=>'none','start_at'=>null,'end_at'=>null];
        $date=(string)($input['preference_date']??'');
        $time=(string)($input['preference_time']??'');
        $parse=static function(string $t)use($date):string{
            $raw=$date.' '.$t;
            $d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i',$raw,new \DateTimeZone('Africa/Windhoek'));
            if(!$d||$d->format('Y-m-d H:i')!==$raw)throw new \DomainException('Choose a valid customer date and time.');
            return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        };
        $start=$parse($time);$end=$mode==='window'?$parse((string)($input['preference_end']??'')):$start;
        if($mode==='window'&&$end<=$start)throw new \DomainException('Window end must be after its start on the same date.');
        return ['mode'=>$mode,'start_at'=>$mode==='before'?null:$start,'end_at'=>$end];
    }
    public static function latest(\PDO $db,int $id):array
    {
        $s=$db->prepare("SELECT id,metadata_json,created_at,employee_id FROM delivery_events WHERE delivery_id=? AND event_type='deadline_updated' ORDER BY id DESC LIMIT 1");$s->execute([$id]);$e=$s->fetch(\PDO::FETCH_ASSOC);
        $p=$e?json_decode($e['metadata_json'],true):[];
        $d=$p['deadline']??['mode'=>'none','start_at'=>null,'end_at'=>null];
        return $d+['event_id'=>$e?(int)$e['id']:0,'changed_at'=>$e['created_at']??null,'changed_by'=>$e['employee_id']??null];
    }
    public static function label(array $d):string
    {
        if(($d['mode']??'none')==='none'||empty($d['end_at']))return 'No specific time';
        $format=static function($v,$f){return (new \DateTimeImmutable($v,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Windhoek'))->format($f);};
        $day=$format($d['end_at'],'d M Y');$end=$format($d['end_at'],'H:i');
        return $day.' · '.($d['mode']==='window'?$format($d['start_at'],'H:i').'–'.$end:($d['mode']==='before'?'Before ':'At ').$end);
    }
    public static function present(array $d,array $job,?int $now=null):array
    {
        $now=$now??time();$status='ETA unavailable';
        if(!empty($d['end_at'])){
            $end=strtotime($d['end_at'].' UTC');$completed=$job['delivered_at']??$job['completed_at']??null;
            if($completed)$status=strtotime($completed.' UTC')<=$end?'Completed On Time':'Completed Late';
            elseif(($job['status']??'')!=='cancelled'&&$now>$end)$status='Late';
        }
        return $d+['label'=>self::label($d),'status'=>$status,'eta_status'=>'ETA unavailable'];
    }
    public static function sortExpression():string
    {
        return "(SELECT JSON_UNQUOTE(JSON_EXTRACT(de.metadata_json,'$.deadline.end_at')) FROM delivery_events de WHERE de.delivery_id=j.id AND de.event_type='deadline_updated' ORDER BY de.id DESC LIMIT 1)";
    }
    /** Idempotent operational notices; no scoring, route changes or payment mutations. */
    public static function notifyLate(\PDO $db,int $id):void
    {
        if($db->inTransaction())throw new \LogicException('Deadline scan owns transaction.');
        $db->beginTransaction();
        try{
            $s=$db->prepare("SELECT * FROM delivery_jobs WHERE id=? FOR UPDATE");$s->execute([$id]);$j=$s->fetch(\PDO::FETCH_ASSOC);
            if($j&&in_array($j['status'],['ready','out_for_delivery','failed'],true)){
                $d=self::latest($db,$id);
                if(self::present($d,$j)['status']==='Late'){
                    $key='deadline-late:'.$id.':'.$d['event_id'];
                    NotificationOutbox::queue($db,$id,(int)$j['driver_employee_id'],$key.':driver:'.$j['driver_employee_id'],['kind'=>'deadline_late','deadline'=>self::label($d)]);
                    NotificationOutbox::notifyFront($db,$id,$key,'deadline_late');
                }
            }
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
