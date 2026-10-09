<?php
declare(strict_types=1);
namespace Hambelela\Accounts;

/** Pure matching policy. Never creates payments, receipts or ledger entries. */
final class PaymentEvidence
{
    public static function fingerprint(array $source): string
    {
        foreach(['source_type','source_id','amount_cents','payment_method','version'] as $key){
            if(!array_key_exists($key,$source))throw new \InvalidArgumentException('Incomplete evidence snapshot: '.$key);
        }
        $canonical=[];foreach(['source_type','source_id','amount_cents','payment_method','version'] as $key)$canonical[$key]=(string)$source[$key];
        return hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR));
    }
    public static function match(array $allocation,array $evidence): array
    {
        $reasons=[];
        if((int)($allocation['amount_cents']??0)<=0)return ['eligible'=>false,'reasons'=>['Payment allocation must be positive.']];
        if(!empty($evidence['reversed'])||!empty($evidence['deleted']))return ['eligible'=>false,'reasons'=>['Evidence is reversed or deleted.']];
        if((int)$allocation['amount_cents']!==(int)($evidence['amount_cents']??-1))return ['eligible'=>false,'reasons'=>['Amounts differ; split evidence requires explicit allocation.']];
        $reasons[]='Amount matches';
        if(empty($allocation['payment_method'])||$allocation['payment_method']!==($evidence['payment_method']??null))return ['eligible'=>false,'reasons'=>['Payment methods differ or are unverified.']];
        $linked=(int)($evidence['order_id']??0)>0&&(int)$evidence['order_id']===(int)$allocation['order_id'];
        $ref=trim((string)($allocation['transaction_reference']??''));
        $referenceMatches=$ref!==''&&hash_equals($ref,trim((string)($evidence['transaction_reference']??'')));
        if(!$linked&&!$referenceMatches)return ['eligible'=>false,'reasons'=>['Amount alone is insufficient: a verified Order link or payment reference is required.']];
        $reasons[]=$linked?'Existing Order link matches':'Exact payment reference matches';
        if(empty($evidence['receipt_verified']))return ['eligible'=>false,'reasons'=>array_merge($reasons,['Independent receipt or handover has not been verified.'])];
        if(($evidence['settlement_state']??'')==='terminal_approved')return ['eligible'=>false,'reasons'=>array_merge($reasons,['Terminal approval is not a settled bank receipt.'])];
        return ['eligible'=>true,'reasons'=>$reasons];
    }
    public static function orderStatus(int $total,array $components): string
    {
        if($total<0)return 'Discrepancy';
        $recorded=0;$verified=0;$allConfirmed=true;$matched=true;
        foreach($components as $component){
            $amount=(int)$component['amount_cents'];
            if($amount<0||!empty($component['discrepancy']))return 'Discrepancy';
            $recorded+=$amount;
            if(!empty($component['evidence_current'])&&!empty($component['confirmed']))$verified+=$amount;else $allConfirmed=false;
            if(empty($component['evidence_current'])||empty($component['matched']))$matched=false;
        }
        if($recorded>$total)return 'Discrepancy';
        if($recorded<$total)return 'Partially Paid';
        if($total===0||!$components)return 'Awaiting Evidence';
        if($allConfirmed&&$verified===$total)return 'Confirmed';
        return $matched?'Matched':'Awaiting Evidence';
    }
    public static function csvCell(string $value): string
    {
        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u',$value)?"'".$value:$value;
    }
}
