<?php
declare(strict_types=1);
namespace Hambelela\EPI;

/** Process evidence captured at completion, not reconstructed from later edits. */
final class TaskProcessEvidence
{
    public static function evaluate(array $record,array $metadata):array
    {
        foreach(['checklist_items','checked_items','completion_note_required','completion_evidence_required'] as $key)
            if(!array_key_exists($key,$record))return ['measured'=>false,'reason'=>'Task requirements snapshot is incomplete.'];
        $required=self::items($record['checklist_items']);$checked=self::items($record['checked_items']);
        if($required===null || $checked===null)return ['measured'=>false,'reason'=>'Checklist snapshot requires review.'];
        $missing=array_values(array_diff($required,$checked));
        $noteRequired=(int)$record['completion_note_required']===1;
        $proofRequired=(int)$record['completion_evidence_required']===1;
        if($proofRequired && !array_key_exists('completion_proof_ids',$metadata))
            return ['measured'=>false,'reason'=>'Required proof snapshot is unavailable.'];
        $note=trim(preg_replace('/\s+/u',' ',(string)($record['completion_note']??''))??'');
        $noteLength=function_exists('mb_strlen')?mb_strlen($note):strlen($note);
        // Matches the existing Task Management meaningful-note requirement.
        $noteValid=!$noteRequired || $noteLength>=26;
        $proofValid=!$proofRequired || count($metadata['completion_proof_ids'])>0;
        return ['measured'=>true,'outcome'=>!$missing&&$noteValid&&$proofValid?'success':'failure',
            'checklist_required'=>count($required),'checklist_missing'=>$missing,
            'note_required'=>$noteRequired,'note_valid'=>$noteValid,'proof_required'=>$proofRequired,
            'proof_valid'=>$proofValid,'proof_ids'=>$metadata['completion_proof_ids']??[],
            'actual'=>count($missing).' checklist items missing; required note '.($noteValid?'satisfied':'missing or insufficient').
                '; required proof '.($proofValid?'satisfied':'missing')];
    }
    private static function items($value):?array
    {
        if($value===null || $value==='')return [];
        $items=is_array($value)?$value:json_decode((string)$value,true);
        if(!is_array($items))return null;
        foreach($items as $item)if(!is_string($item)&&!is_int($item))return null;
        return array_values(array_unique(array_map('strval',$items)));
    }
}
