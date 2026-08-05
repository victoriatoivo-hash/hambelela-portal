<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;use Throwable;

/** Fail-safe adapter from saved cashbook audit rows into append-only EPI records. */
final class BookkeepingActivityBridge
{
    private static $available;
    public static function record(PDO $pdo,int $auditId):void
    {
        try {
            if(self::$available===null){Performance::configure($pdo);$s=$pdo->prepare("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='bookkeeping_module_enabled'");$s->execute();$v=$s->fetchColumn();self::$available=Performance::enabled()&&($v===false||in_array(strtolower((string)$v),['1','true','yes','on'],true));}
            if(!self::$available||$auditId<=0)return;
            $s=$pdo->prepare('SELECT * FROM hambelela_cashbook_log WHERE id=?');$s->execute([$auditId]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)return;
            $entry=null;if((int)($r['entry_id']??0)>0){$q=$pdo->prepare('SELECT * FROM ops_cash_book_entries WHERE id=?');$q->execute([(int)$r['entry_id']]);$entry=$q->fetch(PDO::FETCH_ASSOC)?:null;}
            $actor=self::actor($pdo,(int)$r['user_id'],(string)$r['user_name']);$at=Support::timestamp($r['created_at']);$ref=$entry?'BK-'.$entry['id']:'BK-AUDIT-'.$auditId;
            $meta=['audit_id'=>$auditId,'entry_id'=>$r['entry_id'],'field'=>$r['field'],'description'=>$r['description'],'business_date'=>$entry?substr((string)$entry['transaction_date'],0,10):$at->format('Y-m-d'),'cash_in_cents'=>$entry?self::cents($entry['cash_in']):null,'cash_out_cents'=>$entry?self::cents($entry['cash_out']):null,'related_order_id'=>$entry['related_order_id']??null,'related_order_number'=>$entry['related_order_number']??null,'session_reference'=>$r['session_reference'],'device_reference'=>$r['device_reference'],'review_status'=>'pending_review','candidate_only'=>false];
            $key=Support::dedupe(['bookkeeping-audit',$auditId]);$action='bookkeeping_'.$r['action'];
            Performance::recordActivity(['module'=>'Bookkeeping','reference_number'=>$ref,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],'department'=>'Bookkeeping','activity_type'=>$action,'description'=>(string)($r['description']?:$action),'timestamp'=>$at,'manual'=>true,'activity_source'=>'hambelela_cashbook_log','deduplication_key'=>$key,'metadata'=>$meta]);
            Performance::recordEvidence(['module'=>'Bookkeeping','reference_number'=>$ref,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],'department'=>'Bookkeeping','action'=>$action,'action_description'=>(string)($r['description']?:$action),'previous_value'=>$r['old_value'],'new_value'=>$r['new_value'],'status_before'=>$r['field']==='status'?$r['old_value']:null,'status_after'=>$r['field']==='status'?$r['new_value']:null,'timestamp'=>$at,'manual'=>true,'activity_source'=>'hambelela_cashbook_log','financial_impact'=>$entry?((self::cents($entry['cash_in'])-self::cents($entry['cash_out']))/100):null,'deduplication_key'=>Support::dedupe(['bookkeeping-evidence',$auditId]),'metadata'=>$meta]);
            Performance::recordOwnership(['module'=>'Bookkeeping','reference_number'=>$ref,'original_owner_id'=>$entry['recorded_by']??$actor['id'],'current_owner_id'=>$entry['edited_by']??$actor['id'],'completed_by_id'=>$r['action']==='reconciled'?$actor['id']:null,'completed_by_name'=>$r['action']==='reconciled'?$actor['name']:null,'change_reason'=>$action,'changed_by'=>$actor['id'],'effective_at'=>$at]);
            self::candidate($r,$entry,$actor,$at,$ref,$meta);
        } catch(Throwable $e){self::log($e,$auditId);}
    }
    private static function candidate(array$r,?array$entry,array$actor,$at,string$ref,array$meta):void
    {if(!in_array((string)$r['action'],['deleted','permanently_deleted','edited','moved','reconciled'],true))return;$kind='review_candidate_'.$r['action'];$meta['candidate_only']=true;Performance::recordEvidence(['module'=>'Bookkeeping','reference_number'=>$ref,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],'department'=>'Bookkeeping','action'=>$kind,'action_description'=>'Owner review required: '.str_replace('_',' ',$kind),'previous_value'=>$r['old_value'],'new_value'=>$r['new_value'],'timestamp'=>$at,'manual'=>false,'activity_source'=>'bookkeeping_epi_candidate_engine','financial_impact'=>$entry?((self::cents($entry['cash_in'])-self::cents($entry['cash_out']))/100):null,'deduplication_key'=>Support::dedupe(['bookkeeping-candidate',$r['id'],$kind]),'metadata'=>$meta]);}
    private static function cents($v):int{return(int)round(((float)$v)*100);}
    private static function actor(PDO$p,int$id,string$name):array{return['id'=>$id?:null,'name'=>$name!==''?$name:($id?'Employee '.$id:'System')];}
    private static function log(Throwable$e,int$id):void{$dir=defined('BASE_PATH')?BASE_PATH.'/storage/logs':sys_get_temp_dir();if(!is_dir($dir))@mkdir($dir,0775,true);@file_put_contents($dir.'/epi-bookkeeping.log','['.date('c').'] audit '.$id.': '.$e->getMessage().PHP_EOL,FILE_APPEND);}
}
