<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use Throwable;

/** Converts existing immutable cashbook audit events into the shared EPI engines. */
final class BookkeepingActivityBridge
{
    public static function record(array $event): void
    {
        try {
            if (function_exists('db')) Performance::configure(\db());
            $entryId=(int)($event['entry_id']??0);$action=trim((string)($event['action']??'updated'));
            $reference=$entryId>0?'BK-'.$entryId:'BOOKKEEPING';$actor=(int)($event['actor_id']??0);$name=(string)($event['actor_name']??'System');
            $metadata=['entry_id'=>$entryId?:null,'field'=>$event['field']??null,'financial_value'=>self::money($event['new_value']??null),'immutable_source'=>'hambelela_cashbook_log'];
            $common=['module'=>'Bookkeeping','reference_number'=>$reference,'employee_id'=>$actor?:null,'employee_name'=>$name,'department'=>'Bookkeeping','timestamp'=>$event['timestamp']??null,'activity_source'=>'hambelela_cashbook_log','previous_value'=>$event['old_value']??null,'new_value'=>$event['new_value']??null,'metadata'=>$metadata,'deduplication_key'=>Support::dedupe(['bookkeeping',$entryId,$action,$event['field']??'', $event['timestamp']??'', $event['old_value']??'', $event['new_value']??''])];
            Performance::recordActivity($common+['activity_type'=>'bookkeeping_'.$action,'description'=>(string)($event['description']??ucwords(str_replace('_',' ',$action)))]);
            Performance::recordEvidence($common+['action'=>'bookkeeping_'.$action,'action_description'=>(string)($event['description']??ucwords(str_replace('_',' ',$action))),'recording_mode'=>'automatic']);
        } catch (Throwable $ignored) { /* Operational bookkeeping must never fail because EPI is unavailable. */ }
    }
    private static function money($value): ?string{$raw=trim((string)$value);return preg_match('/^-?\d+(?:\.\d+)?$/',$raw)?number_format((float)$raw,2,'.',''):null;}
}
