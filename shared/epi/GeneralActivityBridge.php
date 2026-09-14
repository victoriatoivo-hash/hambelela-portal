<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;use Throwable;

final class GeneralActivityBridge
{
    public static function record(PDO $pdo,string $action,string $entityType,int $entityId,array $metadata=[]):void
    {
        // Background polling, heartbeat, passive page_view, and static requests are deliberately absent.
        $moduleEntities=['order','packing_task','checklist_task','courier_waybill','courier_waybill_batch','error_log'];
        if(in_array($entityType,$moduleEntities,true))return;
        $meaningful=['created','updated','edited','uploaded','downloaded','note_added','status_changed','assigned','assignment_accepted','restored','moved_to_trash','deleted','permanently_deleted','owner_reviewed','acknowledged','payment_reversed','reference_corrected','ownership_corrected'];
        $normal=strtolower($action);$accepted=false;foreach($meaningful as$needle){if(strpos($normal,$needle)!==false){$accepted=true;break;}}if(!$accepted)return;
        try{Performance::configure($pdo);if(Performance::mode()===FeatureFlags::MODE_DISABLED)return;$isTest=Performance::mode()===FeatureFlags::MODE_TEST;if($isTest&&empty($metadata['test_data']))return;
            $employeeId=function_exists('ops_current_employee_id')?(int)ops_current_employee_id():0;$at=Support::timestamp($metadata['occurred_at']??null);$reference=strtoupper($entityType).'-'.$entityId;$dedupe=Support::dedupe(['general',$entityType,$entityId,$action,$employeeId,$at->format('Y-m-d H:i:s'),$metadata['event_nonce']??'']);$meta=array_merge($metadata,['test_data'=>$isTest,'excluded_from_scoring'=>true,'general_bridge'=>true]);
            Performance::recordActivity(['module'=>'Portal Activity','reference_number'=>$reference,'employee_id'=>$employeeId?:null,'activity_type'=>$action,'description'=>ucwords(str_replace('_',' ',$action)),'activity_source'=>'ops_activity_log:general','timestamp'=>$at,'recording_mode'=>$isTest?'test':'automatic','deduplication_key'=>Support::dedupe(['general-activity',$dedupe]),'metadata'=>$meta]);
            Performance::recordEvidence(['module'=>'Portal Activity','reference_number'=>$reference,'employee_id'=>$employeeId?:null,'action'=>$action,'action_description'=>ucwords(str_replace('_',' ',$action)),'previous_value'=>$metadata['previous_value']??null,'new_value'=>$metadata['new_value']??null,'timestamp'=>$at,'recording_mode'=>$isTest?'test':'automatic','activity_source'=>'ops_activity_log:general','deduplication_key'=>Support::dedupe(['general-evidence',$dedupe]),'metadata'=>$meta]);
        }catch(Throwable$error){error_log('EPI general activity bridge failed: '.$error->getMessage());}
    }
}
