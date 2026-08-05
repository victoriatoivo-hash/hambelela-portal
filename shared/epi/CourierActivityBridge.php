<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use DateTimeImmutable;use PDO;use Throwable;

/** Fail-safe adapter from saved Courier actions into immutable EPI evidence. */
final class CourierActivityBridge
{
    private static $available;
    public static function record(PDO $pdo,string $legacyAction,int $waybillId,array $input=[]): void
    {
        try{
            if(self::$available===null){Performance::configure($pdo);$s=$pdo->prepare("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='courier_module_enabled'");$s->execute();$v=$s->fetchColumn();self::$available=Performance::enabled()&&($v===false||in_array(strtolower((string)$v),['1','true','yes','on'],true));}
            if(!self::$available)return;
            $rows=self::rows($pdo,$waybillId,(string)($input['batch_id']??''));if(!$rows)return;
            foreach($rows as$row)self::recordRow($pdo,$legacyAction,$row,$input);
        }catch(Throwable$e){self::log($e,$legacyAction,$waybillId);}
    }
    private static function rows(PDO$pdo,int$id,string$batch):array
    {if($id>0){$s=$pdo->prepare('SELECT * FROM hambelela_waybills WHERE id=?');$s->execute([$id]);return$s->fetchAll(PDO::FETCH_ASSOC)?:[];}if($batch!==''){$s=$pdo->prepare('SELECT * FROM hambelela_waybills WHERE batch_id=? ORDER BY id');$s->execute([$batch]);return$s->fetchAll(PDO::FETCH_ASSOC)?:[];}return[];}
    private static function recordRow(PDO$pdo,string$legacy,array$row,array$input):void
    {
        $action=self::action($legacy);$actorId=$action==='courier_waybill_sent'?(int)($row['sent_by']??0):(int)($row['uploaded_by']??0);if(!$actorId)$actorId=(int)($input['employee_id']??0);
        $actor=self::actor($pdo,$actorId);$at=Support::timestamp($action==='courier_waybill_sent'?($row['sent_at']??null):($row['uploaded_at']??null));$ref='WAYBILL-'.(string)($row['batch_id']?:$row['id']);
        $uploaded=!empty($row['uploaded_at'])?Support::timestamp($row['uploaded_at']):null;$sent=!empty($row['sent_at'])?Support::timestamp($row['sent_at']):null;
        $duration=$uploaded&&$sent?Performance::businessMinutes($uploaded,$sent):null;
        $metadata=array_merge($input,['legacy_action'=>$legacy,'waybill_id'=>(int)$row['id'],'batch_id'=>$row['batch_id'],'business_date'=>$row['sent_date']??null,'business_date_source'=>!empty($row['sent_date'])?'explicit_courier_batch_date':'unavailable','courier_type'=>$row['courier_names']??null,'order_id'=>$row['order_id']??null,'waybill_reference'=>$row['waybill_reference']??null,'uploaded_by'=>(int)($row['uploaded_by']??0),'uploaded_at'=>$row['uploaded_at']??null,'sent_by'=>$row['sent_by']??null,'sent_at'=>$row['sent_at']??null,'upload_to_send_business_minutes'=>$duration,'timing_basis'=>'business_time','review_status'=>'pending_review']);
        $dedupe=Support::dedupe(['courier',$row['id'],$legacy,$actorId,$at->format('Y-m-d H:i:s')]);
        Performance::recordActivity(['module'=>'Courier','reference_number'=>$ref,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],'department'=>$actor['department'],'activity_type'=>$action,'description'=>self::description($action,$row),'timestamp'=>$at,'manual'=>true,'activity_source'=>'courier_activity_log:'.$legacy,'deduplication_key'=>$dedupe,'metadata'=>$metadata]);
        Performance::recordEvidence(['module'=>'Courier','reference_number'=>$ref,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],'department'=>$actor['department'],'action'=>$action,'action_description'=>self::description($action,$row),'previous_value'=>$input['previous_value']??null,'new_value'=>$input['new_value']??null,'status_before'=>$input['previous_value']??null,'status_after'=>$input['new_value']??($row['status']??null),'timestamp'=>$at,'working_minutes'=>$duration,'manual'=>true,'activity_source'=>'courier_activity_log:'.$legacy,'deduplication_key'=>Support::dedupe(['courier-evidence',$dedupe]),'metadata'=>$metadata]);
        self::candidate($action,$row,$actor,$at,$ref,$metadata);
        Performance::recordOwnership(['module'=>'Courier','reference_number'=>$ref,'original_owner_id'=>$row['uploaded_by']??null,'current_owner_id'=>$row['sent_by']?:($row['uploaded_by']??null),'completed_by_id'=>$action==='courier_waybill_sent'?$actor['id']:null,'completed_by_name'=>$action==='courier_waybill_sent'?$actor['name']:null,'change_reason'=>$action,'changed_by'=>$actor['id'],'effective_at'=>$at]);
    }
    private static function action(string$a):string{if($a==='courier_waybill_uploaded')return'courier_waybill_uploaded';if($a==='courier_waybill_sent')return'courier_waybill_sent';if(strpos($a,'download')!==false)return'courier_waybill_downloaded';if(strpos($a,'archive')!==false)return'courier_waybill_archived';if(strpos($a,'trash')!==false)return'courier_waybill_trashed';return$a;}
    private static function actor(PDO$pdo,int$id):array{if($id<=0)return['id'=>null,'name'=>'System','department'=>'Courier'];try{$s=$pdo->prepare("SELECT e.full_name,COALESCE(r.name,'Courier') department FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?");$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC)?:[];}catch(Throwable$e){$r=[];}return['id'=>$id,'name'=>(string)($r['full_name']??('Employee '.$id)),'department'=>(string)($r['department']??'Courier')];}
    private static function description(string$a,array$r):string{return ucwords(str_replace('_',' ',$a)).' for batch '.(string)($r['batch_id']?:$r['id']);}
    private static function candidate(string$action,array$row,array$actor,DateTimeImmutable$at,string$ref,array$metadata):void
    {
        if($action!=='courier_waybill_sent'||empty($row['due_by'])||empty($row['sent_at']))return;
        $due=Support::timestamp($row['due_by']);$late=$at>$due;$kind=$late?'deduction_candidate_late_courier_send':'bonus_candidate_on_time_courier_send';
        $metadata['candidate_only']=true;$metadata['review_status']='pending_review';$metadata['due_at']=$due->format('Y-m-d H:i:s');$metadata['delay_business_minutes']=$late?Performance::businessMinutes($due,$at):0;
        Performance::recordEvidence(['module'=>'Courier','reference_number'=>$ref,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],'department'=>$actor['department'],'action'=>$kind,'action_description'=>ucwords(str_replace('_',' ',$kind)),'timestamp'=>$at,'working_minutes'=>$metadata['delay_business_minutes'],'manual'=>false,'activity_source'=>'courier_epi_candidate_engine','deduplication_key'=>Support::dedupe(['courier-candidate',$row['id'],$kind,$at->format('Y-m-d H:i:s')]),'metadata'=>$metadata]);
    }
    private static function log(Throwable$e,string$a,int$id):void{$dir=defined('BASE_PATH')?BASE_PATH.'/storage/logs':sys_get_temp_dir();if(!is_dir($dir))@mkdir($dir,0775,true);@file_put_contents($dir.'/epi-courier.log','['.date('c').'] '.$a.' waybill '.$id.': '.$e->getMessage().PHP_EOL,FILE_APPEND);}
}
