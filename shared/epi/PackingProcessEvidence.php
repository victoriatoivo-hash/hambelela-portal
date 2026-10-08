<?php
declare(strict_types=1);
namespace Hambelela\EPI;

/** Quantity evidence, not an automatic employee-fault decision.
 * Package sizes must match, not merely total mass/volume. Unknown text fails closed.
 */
final class PackingProcessEvidence
{
    public static function evaluate(array $record):array
    {
        $planned=self::packages((string)($record['quantity_planned']??''));
        $packed=self::packages((string)($record['quantity_packed']??''));
        if($planned===null||$packed===null)return ['measured'=>false,'requires_review'=>true,
            'reason'=>'packing_quantity_evidence_missing','actual'=>'Complete, comparable planned and packed quantities are required.'];
        $exact=$planned===$packed;
        return ['measured'=>$exact,'requires_review'=>!$exact,'planned'=>$planned,'packed'=>$packed,
            'reason'=>$exact?null:'packing_quantity_variance_requires_review',
            'actual'=>$exact?'Packed package sizes and quantities match the plan.':
                'Planned and packed packages differ. Responsibility and any approved quantity change require review.'];
    }

    private static function packages(string $text):?array
    {
        if(trim($text)==='')return null;
        $pattern='/\b(\d+(?:\.\d+)?)\s*(kg|kgs|kilograms?|g|grams?|ml|millilitres?|milliliters?|l|lt|litres?|liters?|pcs?|pieces?|units?)\b\s*(?:\(\s*(\d+)\s*\)|[x*]\s*(\d+))?/i';
        preg_match_all($pattern,$text,$matches,PREG_SET_ORDER);
        $rest=preg_replace($pattern,'',$text);
        if(!$matches||preg_replace('/[\s,;+]+/','',(string)$rest)!=='')return null;
        $packages=[];
        foreach($matches as $m){
            $size=(float)$m[1];$unit=strtolower($m[2]);
            $count=(int)(($m[3]??'')!==''?$m[3]:(($m[4]??'')!==''?$m[4]:1));
            if(!is_finite($size)||$size<=0||$count<=0)return null;
            if(in_array($unit,['kg','kgs','kilogram','kilograms'],true)){$size*=1000;$dimension='g';}
            elseif(in_array($unit,['g','gram','grams'],true))$dimension='g';
            elseif(in_array($unit,['l','lt','litre','litres','liter','liters'],true)){$size*=1000;$dimension='ml';}
            elseif(in_array($unit,['ml','millilitre','millilitres','milliliter','milliliters'],true))$dimension='ml';
            else $dimension='pieces';
            if(!is_finite($size))return null;
            $key=$dimension.':'.rtrim(rtrim(sprintf('%.6F',$size),'0'),'.');
            $packages[$key]=($packages[$key]??0)+$count;
        }
        ksort($packages);return $packages;
    }
}
