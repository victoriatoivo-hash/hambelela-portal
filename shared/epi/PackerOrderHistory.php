<?php
declare(strict_types=1);
namespace Hambelela\EPI;

/** Normalises existing history in memory only; does not backfill or rewrite any record. */
final class PackerOrderHistory
{
    public static function event(string $action,array $m,string $at,int $actor,string $source): array
    {
        $base=['at'=>$at,'actor'=>$actor,'source'=>$source];$events=[];
        $field=(string)($m['field']??'');
        $before=(string)($m['old_value']??$m['previous_status']??$m['old_status']??$m['changes']['status']['from']??'');
        $after=(string)($m['new_value']??$m['new_status']??$m['status']??$m['changes']['status']['to']??'');
        $statusAction=in_array($action,['status_changed','order_status_changed','bulk_status_updated','in_progress','new_order','new','order_received','order_created'],true);
        if($field==='status'||$statusAction){
            if($after===''&&in_array($action,['in_progress','new_order','new'],true))$after=$action;
            if(in_array($action,['order_received','order_created'],true)&&$after==='')$after='new';
            if(PackerOrdersPreview::status($after)==='new')$events[]=$base+['kind'=>'new'];
            if(PackerOrdersPreview::status($after)==='progress'&&($before===''||PackerOrdersPreview::status($before)==='new'))$events[]=$base+['kind'=>'packed'];
        }
        // Only the Paid checkbox audit counts, not the selected payment method or a completion status.
        if(in_array($field,['portal_paid_confirmation','portal_paid_confirmed','payment_status'],true)||$action==='portal_paid_confirmation_updated'){
            $yes=static fn($v)=>in_array(strtolower($v),['paid','1','yes'],true);
            if($yes($after)&&!$yes($before))$events[]=$base+['kind'=>'paid'];
            if($yes($before)&&in_array(strtolower($after),['unpaid','0','no'],true))$events[]=$base+['kind'=>'unpaid'];
        }
        return $events;
    }
    public static function merge(array $events,?string $loaded): array
    {
        // Deduplicate mirrored writes only, not whole history sources: older cycles may
        // exist exclusively in one log. Different actors always remain reviewable.
        $rank=['kpi_status_events'=>1,'ops_activity_logs'=>2,'kpi_activity_events'=>3,'ops_order_stage_events'=>4,'epi_v2_outbox'=>5];
        usort($events,static fn($a,$b)=>strcmp($a['at'],$b['at']));$result=[];
        foreach($events as$e){
            $source=explode(':',$e['source']??'')[0];$duplicate=false;
            foreach($result as$i=>$prior){
                $other=explode(':',$prior['source']??'')[0];
                if($other===$source||$e['kind']!==$prior['kind']||(int)($e['actor']??0)!==(int)($prior['actor']??0))continue;
                if(abs(self::seconds($e['at'])-self::seconds($prior['at']))>2)continue;
                if(($rank[$source]??10)<($rank[$other]??10))$result[$i]=$e;
                $duplicate=true;break;
            }
            if(!$duplicate)$result[]=$e;
        }
        // The displayed loaded timestamp is the approved start for ordinary orders.
        // A later explicit return to New is retained so reopened orders remain reviewable.
        if($loaded){try{$loaded=PackerOrdersPreview::time($loaded)->format('Y-m-d H:i:s');$result[]=['kind'=>'new','at'=>$loaded,'actor'=>0,'source'=>'ops_orders:displayed_loaded_time'];}catch(\Throwable $e){}}
        return $result;
    }
    private static function seconds(string $at): int {return PackerOrdersPreview::time($at)->getTimestamp();}
    public static function ownership(array $changes): array
    {
        usort($changes,static fn($a,$b)=>strcmp($a['at'],$b['at']));$periods=[];
        foreach($changes as$c){
            $last=count($periods)-1;
            if($last>=0&&$periods[$last]['employee_id']===$c['employee_id'])continue;
            if($last>=0)$periods[$last]['effective_to']=$c['at'];
            $periods[]=['employee_id'=>$c['employee_id'],'effective_from'=>$c['at'],'effective_to'=>null];
        }
        return $periods;
    }
}
