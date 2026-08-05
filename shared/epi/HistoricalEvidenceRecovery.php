<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use PDO;
use Throwable;

/** Append-only recovery of attributable legacy events. Never updates operational rows. */
final class HistoricalEvidenceRecovery
{
    private $pdo;
    private $employees = [2 => 'Secilia Shiweda', 6 => 'Ndinelao Kalola', 7 => 'Klaudia Averinus'];
    private $inserted = 0;
    private $duplicates = 0;
    private $sources = [];

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function recover(string $from = '2026-07-01', string $to = '2026-07-31'): array
    {
        $run = Support::uuid();
        $this->pdo->prepare('INSERT INTO epi_historical_recovery_runs(run_uuid,period_start,period_end,target_employee_ids,started_at) VALUES(?,?,?,?,NOW())')
            ->execute([$run,$from,$to,implode(',',array_keys($this->employees))]);
        $this->pdo->beginTransaction();
        try {
            $this->recoverActivityLogs($from,$to);
            $this->recoverStatusEvents($from,$to);
            $this->recoverOrderStages($from,$to);
            $this->recoverSessions($from,$to);
            $this->recoverCashbook($from,$to);
            $this->recoverCourier($from,$to);
            $this->recoverPackingSnapshots($from,$to);
            $this->recoverQuality($from,$to);
            $this->auditSources($from,$to);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->issue($run,'recovery','run',null,'recovery_failed',$error->getMessage(),[]);
            throw $error;
        }
        $issues=(int)$this->pdo->query("SELECT COUNT(*) FROM epi_historical_recovery_issues WHERE run_uuid=".$this->pdo->quote($run))->fetchColumn();
        $this->pdo->prepare('UPDATE epi_historical_recovery_runs SET source_summary_json=?,recovered_count=?,duplicate_count=?,issue_count=?,completed_at=NOW() WHERE run_uuid=?')
            ->execute([Support::json($this->sources),$this->inserted,$this->duplicates,$issues,$run]);
        return ['run_uuid'=>$run,'period'=>[$from,$to],'employees'=>$this->employees,'inserted'=>$this->inserted,'duplicates'=>$this->duplicates,'issues'=>$issues,'sources'=>$this->sources];
    }

    private function recoverActivityLogs(string $from,string $to):void
    {
        $sql="SELECT id,employee_id,entity_type,entity_id,action,metadata,created_at FROM ops_activity_logs WHERE employee_id IN(2,6,7) AND created_at>=? AND created_at<? ORDER BY id";
        foreach($this->rows($sql,[$from.' 00:00:00',date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00']) as $r){
            $module=$this->module((string)$r['entity_type']);
            $meta=$this->json($r['metadata']??null);
            $this->record('ops_activity_logs',(string)$r['id'],$module,(int)$r['employee_id'],(string)($r['entity_type'].'-'.($r['entity_id']?:$r['id'])),(string)$r['action'],str_replace('_',' ',(string)$r['action']),(string)$r['created_at'],[
                'previous_value'=>$meta['previous_value']??$meta['old_value']??null,'new_value'=>$meta['new_value']??null,
                'status_before'=>$meta['status_before']??$meta['old_status']??null,'status_after'=>$meta['status_after']??$meta['new_status']??null,'legacy_metadata'=>$meta
            ]);
        }
    }

    private function recoverStatusEvents(string $from,string $to):void
    {
        $sql="SELECT id,module,record_id,old_status,new_status,changed_by,changed_at FROM kpi_status_events WHERE changed_by IN(2,6,7) AND changed_at>=? AND changed_at<? ORDER BY id";
        foreach($this->rows($sql,[$from.' 00:00:00',date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00']) as $r){
            $entity=(string)$r['module'];
            $this->record('kpi_status_events',(string)$r['id'],$this->module($entity),(int)$r['changed_by'],$entity.'-'.$r['record_id'],$entity.'_status_changed','Status changed from '.$r['old_status'].' to '.$r['new_status'],(string)$r['changed_at'],['status_before'=>$r['old_status'],'status_after'=>$r['new_status']]);
        }
    }

    private function recoverOrderStages(string $from,string $to):void
    {
        $sql="SELECT id,order_id,stage_key,employee_id,occurred_at,metadata FROM ops_order_stage_events WHERE employee_id IN(2,6,7) AND occurred_at>=? AND occurred_at<? ORDER BY id";
        foreach($this->rows($sql,[$from.' 00:00:00',date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00']) as $r)
            $this->record('ops_order_stage_events',(string)$r['id'],'Orders',(int)$r['employee_id'],'order-'.$r['order_id'],'order_'.$r['stage_key'],'Order stage '.$r['stage_key'],(string)$r['occurred_at'],['status_after'=>$r['stage_key'],'legacy_metadata'=>$this->json($r['metadata']??null)]);
    }

    private function recoverSessions(string $from,string $to):void
    {
        $sql="SELECT id,user_id,login_at,last_seen_at,logout_at FROM kpi_sessions WHERE user_id IN(2,6,7) AND login_at>=? AND login_at<? ORDER BY id";
        foreach($this->rows($sql,[$from.' 00:00:00',date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00']) as $r){
            $this->record('kpi_sessions',$r['id'].'-login','Portal Activity',(int)$r['user_id'],'session-'.$r['id'],'login','Portal login',(string)$r['login_at'],['status_before'=>'offline','status_after'=>'online']);
            if(!empty($r['logout_at']))$this->record('kpi_sessions',$r['id'].'-logout','Portal Activity',(int)$r['user_id'],'session-'.$r['id'],'logout','Portal logout',(string)$r['logout_at'],['status_before'=>'online','status_after'=>'offline']);
        }
    }

    private function recoverCashbook(string $from,string $to):void
    {
        $sql="SELECT id,user_id,entry_id,action,field,old_value,new_value,description,created_at FROM hambelela_cashbook_log WHERE user_id IN(2,6,7) AND created_at>=? AND created_at<? ORDER BY id";
        foreach($this->rows($sql,[$from.' 00:00:00',date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00']) as $r)
            $this->record('hambelela_cashbook_log',(string)$r['id'],'Bookkeeping',(int)$r['user_id'],'cashbook-'.$r['entry_id'],(string)$r['action'],(string)($r['description']?:'Bookkeeping '.$r['action']),(string)$r['created_at'],['previous_value'=>$r['old_value'],'new_value'=>$r['new_value'],'status_after'=>$r['action'],'legacy_metadata'=>['field'=>$r['field']]]);
    }

    private function recoverCourier(string $from,string $to):void
    {
        $sql="SELECT id,waybill_id,user_id,due_by,sent_at,minutes_late,logged_at FROM hambelela_waybill_sla_log WHERE user_id IN(2,6,7) AND logged_at>=? AND logged_at<? ORDER BY id";
        foreach($this->rows($sql,[$from.' 00:00:00',date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00']) as $r){$event=!empty($r['sent_at'])?'sent':'sla_recorded';
            $this->record('hambelela_waybill_sla_log',(string)$r['id'],'Courier',(int)$r['user_id'],'waybill-'.$r['waybill_id'],'courier_'.$event,'Courier '.$event,(string)($r['sent_at']?:$r['logged_at']),['status_after'=>$event,'legacy_metadata'=>['due_by'=>$r['due_by'],'minutes_late'=>$r['minutes_late']]]);}
    }

    private function recoverPackingSnapshots(string $from,string $to):void
    {
        $sql="SELECT id,assigned_employee_id,date_loaded,date_started,date_completed,priority,quantity_packed,packing_status,packing_website_confirmed,packing_website_completed_at,packing_website_completed_by,frontdesk_website_updated_at,frontdesk_website_updated_by FROM ops_packing_tasks WHERE assigned_employee_id IN(6,7) AND date_loaded>=? AND date_loaded<? ORDER BY id";
        foreach($this->rows($sql,[$from.' 00:00:00',date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00']) as $r){
            $id=(int)$r['assigned_employee_id'];$ref='packing-'.$r['id'];
            $this->record('ops_packing_tasks',$r['id'].'-assignment','Packing List',$id,$ref,'packing_assignment','Historical packing assignment',(string)$r['date_loaded'],['status_after'=>'assigned','priority'=>$r['priority'],'confidence'=>'moderate_snapshot']);
            if(!empty($r['date_started']))$this->record('ops_packing_tasks',$r['id'].'-started','Packing List',$id,$ref,'packing_started','Packing started',(string)$r['date_started'],['status_before'=>'assigned','status_after'=>'packing']);
            if(!empty($r['date_completed'])){
                $this->record('ops_packing_tasks',$r['id'].'-completed','Packing List',$id,$ref,'packing_completed','Packing completed',(string)$r['date_completed'],['status_before'=>'packing','status_after'=>'done']);
                if($r['quantity_packed']!==null)$this->record('ops_packing_tasks',$r['id'].'-quantity','Packing List',$id,$ref,'packing_quantity_packed','Quantity packed recorded',(string)$r['date_completed'],['new_value'=>$r['quantity_packed']]);
            }
            if(!empty($r['packing_website_confirmed'])&&!empty($r['packing_website_completed_at'])&&isset($this->employees[(int)$r['packing_website_completed_by']]))
                $this->record('ops_packing_tasks',$r['id'].'-website','Inventory',(int)$r['packing_website_completed_by'],$ref,'packing_website_confirmation','Website update confirmed',(string)$r['packing_website_completed_at'],['status_after'=>'complete']);
            if(!empty($r['frontdesk_website_updated_at'])&&isset($this->employees[(int)$r['frontdesk_website_updated_by']]))
                $this->record('ops_packing_tasks',$r['id'].'-frontdesk','Inventory',(int)$r['frontdesk_website_updated_by'],$ref,'website_update_completed','Front desk website update completed',(string)$r['frontdesk_website_updated_at'],['status_after'=>'complete']);
        }
    }

    private function recoverQuality(string $from,string $to):void
    {
        $sql="SELECT id,order_id,error_title,category,severity,status,attributed_employee_id,responsible_employee_id,attribution_type,logged_at,accuracy_verified_by,attribution_verified_by FROM ops_error_logs WHERE (attributed_employee_id IN(2,6,7) OR (attribution_type='employee' AND responsible_employee_id IN(2,6,7))) AND logged_at>=? AND logged_at<? ORDER BY id";
        foreach($this->rows($sql,[$from.' 00:00:00',date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00']) as $r){
            $id=(int)($r['attributed_employee_id']?:$r['responsible_employee_id']);$verified=!empty($r['accuracy_verified_by'])&&!empty($r['attribution_verified_by']);
            $this->record('ops_error_logs',(string)$r['id'],'Error Log',$id,'error-'.$r['id'],'quality_error_recorded',(string)($r['error_title'].' '.$r['category']),(string)$r['logged_at'],['status_after'=>$r['status'],'priority'=>$r['severity'],'verified'=>$verified,'verified_by'=>$r['attribution_verified_by']?:null]);
        }
    }

    private function record(string $table,string $sourceId,string $module,int $employeeId,string $reference,string $action,string $description,string $at,array $extra=[]):void
    {
        if(!isset($this->employees[$employeeId])||$at==='')return;
        $key=hash('sha256','step3c|'.$table.'|'.$sourceId.'|'.$employeeId.'|'.$action);
        $uuid=Support::uuidFromHash($key);$meta=['historical_backfill'=>true,'legacy_source'=>$table,'legacy_id'=>$sourceId,'original_timestamp'=>$at,'employee_attribution_source'=>$table,'backfilled_at'=>date('c'),'evidence_confidence'=>$extra['confidence']??'high']+($extra['legacy_metadata']??[]);
        $stmt=$this->pdo->prepare("INSERT IGNORE INTO epi_employee_evidence(evidence_uuid,deduplication_key,module,reference_number,employee_id,employee_name,department,action,action_description,previous_value,new_value,status_before,status_after,priority,occurred_at,business_date,recording_mode,activity_source,verified,verified_by,metadata_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$uuid,$key,$module,$reference,$employeeId,$this->employees[$employeeId],$employeeId===2?'Front Desk':'Packing',$action,$description,Support::json($extra['previous_value']??null),Support::json($extra['new_value']??null),$extra['status_before']??null,$extra['status_after']??null,$extra['priority']??null,$at,substr($at,0,10),'historical_backfill','legacy:'.$table,!empty($extra['verified'])?1:0,$extra['verified_by']??null,Support::json($meta)]);
        if($stmt->rowCount())$this->inserted++;else$this->duplicates++;
        $this->sources[$table]=($this->sources[$table]??0)+1;
    }

    private function auditSources(string $from,string $to):void
    {
        $req=$this->rows("SELECT source_key,module,action_pattern FROM epi_role_required_sources WHERE active=1",[]);
        foreach($this->employees as $employeeId=>$name)foreach($req as $source){
            $role=$employeeId===2?'front_desk_admin':'packer';
            $roleCheck=$this->pdo->prepare("SELECT COUNT(*) FROM epi_role_required_sources WHERE active=1 AND role_key=? AND source_key=?");$roleCheck->execute([$role,$source['source_key']]);$roleCount=(int)$roleCheck->fetchColumn();
            if(!$roleCount)continue;
            $sql="SELECT COUNT(*) c,MIN(occurred_at) first_at,MAX(occurred_at) last_at FROM epi_employee_evidence WHERE employee_id=? AND business_date BETWEEN ? AND ? AND LOWER(module)=LOWER(?)";$params=[$employeeId,$from,$to,$source['module']];
            if(trim((string)$source['action_pattern'])!==''){$sql.=" AND LOWER(CONCAT(COALESCE(action,''),' ',COALESCE(action_description,''))) REGEXP ?";$params[]=$source['action_pattern'];}
            $s=$this->pdo->prepare($sql);$s->execute($params);$c=$s->fetch(PDO::FETCH_ASSOC)?:[];$found=(int)($c['c']??0);
            $limitation=$found===0?'No attributable July records matched this required source.':null;
            if($source['source_key']==='attendance_sessions'&&($c['first_at']??'')>'2026-07-01 00:00:00')$limitation='Session history begins partway through July; earlier presence cannot be reconstructed.';
            $reliability=$found===0?'insufficient':($limitation?'moderate':'high');
            $this->pdo->prepare("INSERT INTO epi_historical_source_audits(period_start,period_end,employee_id,source_key,legacy_records_found,recovered_records,unresolved_records,source_first_at,source_last_at,source_reliability,limitation_note,audited_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE legacy_records_found=VALUES(legacy_records_found),recovered_records=VALUES(recovered_records),unresolved_records=VALUES(unresolved_records),source_first_at=VALUES(source_first_at),source_last_at=VALUES(source_last_at),source_reliability=VALUES(source_reliability),limitation_note=VALUES(limitation_note),audited_at=NOW()")
                ->execute([$from,$to,$employeeId,$source['source_key'],$found,$found,$limitation?1:0,$c['first_at']??null,$c['last_at']??null,$reliability,$limitation]);
        }
    }

    private function rows(string $sql,array $params):array{$s=$this->pdo->prepare($sql);$s->execute($params);return$s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    private function json($value):array{if(is_array($value))return$value;$j=json_decode((string)$value,true);return is_array($j)?$j:[];}
    private function module(string $source):string{$s=strtolower($source);if(strpos($s,'pack')!==false)return'Packing List';if(strpos($s,'task')!==false||strpos($s,'checklist')!==false)return'Tasks';if(strpos($s,'waybill')!==false||strpos($s,'courier')!==false)return'Courier';if(strpos($s,'cash')!==false||strpos($s,'book')!==false)return'Bookkeeping';if(strpos($s,'error')!==false)return'Error Log';if(strpos($s,'session')!==false||strpos($s,'login')!==false)return'Portal Activity';if(strpos($s,'notification')!==false)return'Notifications';if(strpos($s,'website')!==false||strpos($s,'inventory')!==false)return'Inventory';return'Orders';}
    private function issue(string $run,string $table,string $id,$employee,string $code,string $description,array $payload):void{$this->pdo->prepare('INSERT IGNORE INTO epi_historical_recovery_issues(run_uuid,source_table,source_record_id,employee_id,issue_code,issue_description,source_payload_json) VALUES(?,?,?,?,?,?,?)')->execute([$run,$table,$id,$employee,$code,$description,Support::json($payload)]);}
}
