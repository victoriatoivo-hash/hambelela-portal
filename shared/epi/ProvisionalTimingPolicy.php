<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Append-only settings versions. Reads never migrate or activate anything. */
final class ProvisionalTimingPolicy
{
    public const FIELDS = [
        'packing_list_start_minutes'=>'Packing List — start',
        'packing_list_completion_minutes'=>'Packing List — complete',
        'website_update_minutes'=>'Website stock update',
        'cash_entry_minutes'=>'Cash entry',
        'communication_response_minutes'=>'Customer first response',
    ];
    public const APPROVED = [180,1080,480,30,30];
    private const PREFIX = 'epi_v2_timing_version_';

    public static function current(PDO $db, ?string $at=null):?array
    {
        $at=$at??Support::timestamp()->format('Y-m-d H:i:s');
        $s=$db->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key LIKE ?');
        $s->execute([self::PREFIX.'%']);$versions=$s->fetchAll(PDO::FETCH_COLUMN);$s->closeCursor();$selected=null;
        foreach($versions as $json){$v=json_decode($json,true);
            if(!is_array($v)||($v['kind']??'')!=='provisional_responsibility_timing_v1'||($v['effective_from']??'9999')>$at)continue;
            if(!$selected||$v['effective_from']>$selected['effective_from'])$selected=$v;
        }
        return $selected;
    }
    public static function policy(PDO $db,string $at):array
    {
        $base=V2Store::policy($db);$v=self::current($db,$at);
        return $v?array_replace($base,$v['minutes'],['_timing_version'=>$v]):$base;
    }
    public static function approve(PDO $db,array $minutes,int $actor,string $reason,?string $effective=null):array
    {
        if($actor!==(int)(V2Store::activation($db)['approved_by']??0)||trim($reason)==='')throw new RuntimeException('Authenticated approving owner and reason required.');
        if(array_diff(array_keys($minutes),array_keys(self::FIELDS))||count($minutes)!==5)throw new RuntimeException('Exactly the five timing settings are required.');
        foreach(self::FIELDS as $key=>$label){if(filter_var($minutes[$key],FILTER_VALIDATE_INT)===false||(int)$minutes[$key]<1||(int)$minutes[$key]>21600)throw new RuntimeException($label.' must be 1–21600 whole working minutes.');$minutes[$key]=(int)$minutes[$key];}
        $now=Support::timestamp()->format('Y-m-d H:i:s');$from=$effective?Support::timestamp($effective)->format('Y-m-d H:i:s'):$now;
        if($from<$now)throw new RuntimeException('Timing changes cannot be backdated.');
        return V2Store::transaction($db,function()use($db,$actor,$reason,$from,$now,$minutes){
            V2Store::lock($db,'provisional-timing-policy');
            $previous=self::current($db,$from);
            if($previous&&$previous['effective_from']===$from)throw new RuntimeException('A version already starts at this time.');
            $v=['kind'=>'provisional_responsibility_timing_v1','version'=>'timing-'.str_replace(['-',':',' '],'',$from).'-'.bin2hex(random_bytes(4)),
                'effective_from'=>$from,'approved_at'=>$now,'approved_by'=>$actor,'reason'=>trim($reason),'minutes'=>$minutes,
                'provisional'=>true,'financial_consequences'=>false,'disciplinary_use'=>false,
                'hours'=>['weekday_open'=>'08:00','weekday_close'=>'17:00','saturday_open'=>'09:00','saturday_close'=>'13:00'],
                'timezone'=>'Africa/Windhoek','clock'=>'accepted_responsibility_working_time'];
            $db->prepare('INSERT INTO epi_employee_performance_settings(setting_key,setting_value,value_type,description) VALUES(?,?,?,?)')
                ->execute([self::PREFIX.$v['version'],Support::json($v),'json','Provisional SLA version; do not edit in place.']);
            V2Store::audit($db,'provisional-timing-policy',$actor,$reason,$previous,$v);return $v;
        });
    }
    public static function deadlineOptions(array $policy,string $setting):array
    {
        if(empty($policy['_timing_version']))return [];
        return ['timing_policy'=>$policy['_timing_version'],'timing_setting'=>$setting,'responsibility_budget_minutes'=>(int)$policy[$setting]];
    }
    /** Approved weekly hours, independent of login and unrelated legacy settings. */
    public static function add(string $from,float $minutes):string
    {
        $cursor=Support::timestamp($from);$seconds=(int)round(max(0,$minutes)*60);
        while($seconds>0){$n=(int)$cursor->format('N');$day=$cursor->format('Y-m-d');
            $open=Support::timestamp($day.($n===6?' 09:00:00':' 08:00:00'));$close=Support::timestamp($day.($n===6?' 13:00:00':' 17:00:00'));
            if($n===7||$cursor>=$close){$cursor=$cursor->modify('+1 day')->setTime(0,0);continue;}
            $cursor=max($cursor,$open);$available=$close->getTimestamp()-$cursor->getTimestamp();
            if($seconds<=$available)return $cursor->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:s');
            $seconds-=$available;$cursor=$cursor->modify('+1 day')->setTime(0,0);
        }return $cursor->format('Y-m-d H:i:s');
    }
}
