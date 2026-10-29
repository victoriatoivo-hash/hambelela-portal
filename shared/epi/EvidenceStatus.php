<?php
declare(strict_types=1);
namespace Hambelela\EPI;

/** Presentation contract only: never changes eligibility, evidence or scores. */
final class EvidenceStatus
{
    public static function deadline(array $d,string $now):array
    {
        $state=$d['state']??'';$breach=!empty($d['breach_incident_uuid']);
        if($state==='excused')return ['key'=>'excluded','label'=>'Approved exclusion','reason'=>'Original obligation and exception approval audit are retained.'];
        if($state==='cancelled')return ['key'=>'cancelled','label'=>'Cancelled','reason'=>'Cancellation is not proof of success or an owner-approved exclusion. Original facts and scoring eligibility remain separately auditable.'];
        if(!empty($d['fulfilled_at']))return ['key'=>'completed','label'=>$breach?'Completed after breach':'Completed','reason'=>$breach?'Current Risk cleared; historical breach remains.':'Dated fulfilment recorded; scoring eligibility is checked separately.'];
        if($state==='needs_attribution')return ['key'=>'not_measurable','label'=>'Responsibility review required','reason'=>'Do not infer who was responsible at the deadline.'];
        if($state==='breached'||$breach)return ['key'=>'breached','label'=>'Overdue / breached','reason'=>'Historical incident retained; employee score requires proven ownership and eligibility.'];
        if(($d['due_at']??'9999')<$now)return ['key'=>'awaiting_watchdog','label'=>'Overdue — processing pending','reason'=>'Deadline passed; no employee deduction is inferred before watchdog attribution.'];
        return ['key'=>'pending','label'=>'Pending','reason'=>'Deadline has not elapsed; not a failed outcome.'];
    }
}
