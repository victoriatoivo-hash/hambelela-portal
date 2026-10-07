<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use DateTimeImmutable;
use RuntimeException;

/** Owner-approved rules, copied into immutable database policy versions at activation. */
final class OrdersSlaPolicy
{
    public static function approved(): array {
        return ['version'=>'orders-stages-v1','hours'=>['1'=>['08:00','17:00'],'2'=>['08:00','17:00'],'3'=>['08:00','17:00'],'4'=>['08:00','17:00'],'5'=>['08:00','17:00'],'6'=>['09:00','13:00']],
            'walk_in_minutes'=>300,'packing_minutes'=>['collection'=>180,'delivery'=>180,'courier'=>240],
            'front_minutes'=>['collection'=>720,'delivery'=>1440,'courier'=>720],
            'collection_expiry_hours'=>72,'courier_payment_wait_minutes'=>1440,
            'dispatch_cutoff'=>'13:30','dispatch_days'=>[1,2,3,4,5]];
    }
    public static function workingDue($start,int $minutes,array $policy): DateTimeImmutable {
        if($minutes<=0 || empty($policy['hours'])) throw new RuntimeException('Positive allowance and working calendar required');
        $cursor=Support::timestamp($start);$remaining=$minutes*60;
        for($days=0;$days<3660;$days++) {
            $window=$policy['hours'][(string)$cursor->format('N')]??null;
            if($window) {
                $open=Support::timestamp($cursor->format('Y-m-d').' '.$window[0]);$close=Support::timestamp($cursor->format('Y-m-d').' '.$window[1]);
                if($cursor<$open)$cursor=$open;
                if($cursor<$close) {
                    $seconds=$close->getTimestamp()-$cursor->getTimestamp();
                    if($remaining<=$seconds)return $cursor->modify('+'.$remaining.' seconds');
                    $remaining-=$seconds;
                }
            }
            $cursor=$cursor->modify('+1 day')->setTime(0,0);
        }
        throw new RuntimeException('Working calendar could not produce a deadline');
    }
    public static function collectionExpiry($start,int $hours): DateTimeImmutable {
        if($hours<=0)throw new RuntimeException('Positive expiry required');
        $cursor=Support::timestamp($start);$remaining=$hours*3600;
        while($remaining>0) {
            $next=$cursor->modify('+1 day')->setTime(0,0);
            if((int)$cursor->format('N')!==7) {
                $available=$next->getTimestamp()-$cursor->getTimestamp();
                if($remaining<=$available)return $cursor->modify('+'.$remaining.' seconds');
                $remaining-=$available;
            }
            $cursor=$next;
        }
        return $cursor;
    }
    public static function dispatch($paidAt,$packingDue,array $policy): array {
        $paid=Support::timestamp($paidAt);$due=Support::timestamp($packingDue);$day=$due->setTime(0,0);
        for($i=0;$i<14;$i++,$day=$day->modify('+1 day')) {
            $cutoff=Support::timestamp($day->format('Y-m-d').' '.$policy['dispatch_cutoff']);
            if(in_array((int)$day->format('N'),$policy['dispatch_days'],true)&&$cutoff>=$due) {
                $today=Support::timestamp($paid->format('Y-m-d').' '.$policy['dispatch_cutoff']);
                return ['dispatch_at'=>$cutoff->format('Y-m-d H:i:s'),'rush_candidate'=>in_array((int)$paid->format('N'),$policy['dispatch_days'],true)&&$paid<$today&&$due>$today];
            }
        }
        throw new RuntimeException('No dispatch day configured');
    }
    public static function classify(array $order): array {
        $raw=strtolower(trim((string)(($order['fulfilment_mode']??'')?:($order['order_type']??''))));
        $aliases=['walk-in'=>'walk_in','walk in'=>'walk_in','windhoek_delivery'=>'delivery','windhoek delivery'=>'delivery','yango'=>'delivery'];
        $mode=$aliases[$raw]??$raw;
        // One-time legacy migration only. Persist this decision; do not reclassify on later contact edits.
        if($mode==='collection' && preg_match('/^walk[ -]?in(?: customer)?$/i',trim((string)($order['customer_contact']??''))))return ['walk_in','legacy_contact_marker_at_creation'];
        return [in_array($mode,['walk_in','collection','delivery','courier'],true)?$mode:'unknown','structured_mode_at_creation'];
    }
}
