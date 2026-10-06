<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** All lifecycle paths serialize the same obligation and use the same breach evaluator. */
final class DeadlineEngine
{
 private $pdo;
 private $ownership;
 public function __construct(PDO $pdo){$this->pdo=$pdo;$this->ownership=new OwnershipPeriodEngine($pdo);}
 public function schedule(array $input):string {
    $module=Support::requireModule((string)($input['module']??''));$ref=trim((string)($input['object_reference']??''));$obligation=(string)($input['obligation_key']??'');
    $event=(new CanonicalEventRegistry($this->pdo))->event((string)($input['breach_event_key']??''));
    if($ref===''||$event['module']!==$module||$event['sla_obligation_key']!==$obligation||empty($input['starts_at'])||empty($input['due_at']))throw new RuntimeException('Explicit matching obligation, start and due are required');
    $start=Support::timestamp($input['starts_at']);$due=Support::timestamp($input['due_at']);if($due<=$start)throw new RuntimeException('Due must follow start');
    $activation=V2Store::activation($this->pdo);$policy=V2Store::policy($this->pdo);
    $historical=!$activation||$start->format('Y-m-d H:i:s')<$activation['enforcement_start_at']||!empty($input['historical_backfill']);
    $calendar=$this->pdo->query('SELECT * FROM epi_employee_business_calendar ORDER BY business_date')->fetchAll(PDO::FETCH_ASSOC);
    $settings=$this->pdo->query("SELECT setting_key,setting_value FROM epi_employee_performance_settings WHERE setting_key IN('weekday_open','weekday_close','saturday_open','saturday_close')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $snapshot=['event'=>$event,'activation'=>$activation,'sla_version'=>$policy['version']??'unconfigured','calendar_version'=>$policy['calendar_version']??'unconfigured','calendar'=>$calendar,'hours'=>$settings,'policy'=>$policy,'original_created_at'=>$input['original_created_at']??$start->format('Y-m-d H:i:s'),'historical_record'=>!empty($input['historical_record']),'historical_backfill'=>$historical,'enforcement_started_at'=>$activation['enforcement_start_at']??null];
    $key=Support::dedupe([$module,$ref,$obligation,$input['cycle_id']??$start->format('Y-m-d H:i:s')]);$uuid=Support::uuidFromHash($key);
    $grace=max(0,(int)($input['grace_minutes']??0));$eligible=$due->modify('+'.$grace.' minutes')->format('Y-m-d H:i:s');
    return V2Store::transaction($this->pdo,function()use($input,$module,$ref,$obligation,$event,$start,$due,$snapshot,$historical,$key,$uuid,$grace,$eligible){
        V2Store::lock($this->pdo,'deadline|'.$key);
        $existing=V2Store::one($this->pdo,'SELECT * FROM epi_v2_operational_deadlines WHERE idempotency_key=?',[$key]);
        if($existing){if($existing['due_at']!==$due->format('Y-m-d H:i:s'))throw new RuntimeException('Deadline replay conflicts with original due time');return $existing['deadline_uuid'];}
        $this->pdo->prepare('INSERT INTO epi_v2_operational_deadlines(deadline_uuid,idempotency_key,module,object_reference,obligation_key,breach_event_key,responsible_employee_snapshot,responsible_team,ownership_snapshot_json,source_event,starts_at,due_at,breach_eligible_at,policy_snapshot_json,historical_backfill,grace_minutes,exception_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$uuid,$key,$module,$ref,$obligation,$event['event_key'],$input['responsible_employee_id']??null,$input['responsible_team']??null,Support::json($input['ownership_snapshot']??null),$input['source_event']??null,$start->format('Y-m-d H:i:s'),$due->format('Y-m-d H:i:s'),$eligible,Support::json($snapshot),(int)$historical,$grace,$input['exception_id']??null]);
        return $uuid;
    });
 }
 public function fulfil(string $uuid,int $employee,$at=null):bool {
    V2Store::employee($this->pdo,$employee);$time=Support::timestamp($at)->format('Y-m-d H:i:s');
    return V2Store::transaction($this->pdo,function()use($uuid,$employee,$time){
        $d=V2Store::one($this->pdo,'SELECT * FROM epi_v2_operational_deadlines WHERE deadline_uuid=? FOR UPDATE',[$uuid]);
        if(!$d||$d['fulfilled_at']||in_array($d['state'],['cancelled','excused'],true))return false;
        if($time<$d['starts_at'])throw new RuntimeException('Cannot fulfil a future obligation');
        $d['fulfilled_at']=$time;$d['fulfilled_by']=$employee;
        $this->evaluateLocked($d,$time);
        $late=self::minutes($d['due_at'],$time,json_decode($d['policy_snapshot_json'],true)?:[]);
        $this->pdo->prepare("UPDATE epi_v2_operational_deadlines SET fulfilled_at=?,fulfilled_by=?,late_business_minutes=?,state='fulfilled' WHERE id=?")->execute([$time,$employee,$late,$d['id']]);
        $this->pdo->prepare("UPDATE epi_v2_performance_incidents SET current_risk_state='resolved',resolved_at=? WHERE deadline_uuid=? AND current_risk_state='open'")->execute([$time,$uuid]);
        return true;
    });
 }
 public function fulfilObject(string $module,string $ref,string $obligation,int $employee,$at=null):int {
    $time=Support::timestamp($at)->format('Y-m-d H:i:s');$s=$this->pdo->prepare("SELECT deadline_uuid FROM epi_v2_operational_deadlines WHERE module=? AND object_reference=? AND obligation_key=? AND starts_at<=? AND state IN('open','breached','needs_attribution') ORDER BY starts_at");
    $s->execute([$module,$ref,$obligation,$time]);$n=0;foreach($s->fetchAll(PDO::FETCH_COLUMN)as$id)if($this->fulfil($id,$employee,$time))$n++;return $n;
 }
 public function cancelObject(string $module,string $ref,int $actor,$at):void {
    $time=Support::timestamp($at)->format('Y-m-d H:i:s');
    V2Store::transaction($this->pdo,function()use($module,$ref,$actor,$time){
        $s=$this->pdo->prepare("SELECT * FROM epi_v2_operational_deadlines WHERE module=? AND object_reference=? AND starts_at<=? AND state IN('open','breached','needs_attribution') FOR UPDATE");$s->execute([$module,$ref,$time]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC)as$d){$this->evaluateLocked($d,$time);$this->pdo->prepare("UPDATE epi_v2_operational_deadlines SET state='cancelled',cancelled_at=? WHERE id=?")->execute([$time,$d['id']]);$this->pdo->prepare("UPDATE epi_v2_performance_incidents SET current_risk_state='resolved',resolved_at=? WHERE deadline_uuid=?")->execute([$time,$d['deadline_uuid']]);V2Store::audit($this->pdo,$module.'|'.$ref,$actor,'cancel obligation',$d,['cancelled_at'=>$time]);}
    });
 }
 public function processDue($now=null,int $limit=500,bool $isolateFailures=false):array {
    $started=microtime(true);
    $time=Support::timestamp($now)->format('Y-m-d H:i:s');$limit=max(1,min(5000,$limit));
    $s=$this->pdo->prepare("SELECT deadline_uuid FROM epi_v2_operational_deadlines WHERE breach_incident_uuid IS NULL AND state IN('open','fulfilled') AND breach_eligible_at<? AND (fulfilled_at IS NULL OR fulfilled_at>breach_eligible_at) ORDER BY breach_eligible_at,id LIMIT $limit");$s->execute([$time]);
    $result=['examined'=>0,'breached'=>0,'needs_attribution'=>0,'excused'=>0,'duplicates'=>0];
    foreach($s->fetchAll(PDO::FETCH_COLUMN)as$uuid){if($isolateFailures&&microtime(true)-$started>30)break;$result['examined']++;
        for($attempt=0;$attempt<3;$attempt++){
            try{$out=V2Store::transaction($this->pdo,function()use($uuid,$time){$d=V2Store::one($this->pdo,'SELECT * FROM epi_v2_operational_deadlines WHERE deadline_uuid=? FOR UPDATE',[$uuid]);return $d?$this->evaluateLocked($d,$time):'duplicates';});$result[$out]=($result[$out]??0)+1;break;}
            catch(\Throwable $error){if(!$isolateFailures)throw $error;$number=(int)($error->errorInfo[1]??0);if($attempt<2&&in_array($number,[1205,1213],true)){usleep(random_int(10000,50000));continue;}$result['errors'][]=['deadline_uuid'=>$uuid,'code'=>$number,'message'=>$error->getMessage()];break;}
        }
    }
    return $result;
 }
 public function excuseIncident(string $uuid,int $exceptionId,int $reviewer,string $reason):void {
    if($reviewer!==(int)(V2Store::activation($this->pdo)['approved_by']??0)||trim($reason)==='')throw new RuntimeException('Owner review and reason required');
    V2Store::transaction($this->pdo,function()use($uuid,$exceptionId,$reviewer,$reason){
        $incident=V2Store::one($this->pdo,'SELECT * FROM epi_v2_performance_incidents WHERE incident_uuid=? FOR UPDATE',[$uuid]);
        if(!$incident||!$incident['deadline_uuid'])throw new RuntimeException('Deadline incident required');
        $exception=V2Store::one($this->pdo,"SELECT * FROM epi_v2_exceptions WHERE id=? AND module=? AND object_reference=? AND kind='obligation_excusal' AND approved_by=?",[$exceptionId,$incident['module'],$incident['object_reference'],$reviewer]);
        if(!$exception)throw new RuntimeException('Scoped approved exception required');
        V2Store::audit($this->pdo,'incident|'.$uuid,$reviewer,$reason,$incident,['exception'=>$exception,'decision'=>'excused','reviewed_at'=>Support::timestamp()->format('Y-m-d H:i:s')]);
        $this->pdo->prepare("UPDATE epi_v2_performance_incidents SET eligibility_state='excluded',exclusion_reason='owner_reviewed_exception',current_risk_state='excused' WHERE incident_uuid=?")->execute([$uuid]);
        $this->pdo->prepare("UPDATE epi_v2_operational_deadlines SET state=CASE WHEN fulfilled_at IS NULL THEN 'excused' ELSE state END WHERE deadline_uuid=?")->execute([$incident['deadline_uuid']]);
    });
 }
 private function evaluateLocked(array $d,string $detected):string {
    if($d['breach_incident_uuid']||$detected<=$d['breach_eligible_at']||($d['fulfilled_at']&&$d['fulfilled_at']<=$d['breach_eligible_at']))return 'duplicates';
    $exception=null;
    if($d['exception_id'])$exception=V2Store::one($this->pdo,"SELECT * FROM epi_v2_exceptions WHERE id=? AND module=? AND object_reference=? AND approved_at<=? AND effective_from<=? AND effective_to>? AND kind='obligation_excusal'",[$d['exception_id'],$d['module'],$d['object_reference'],$d['due_at'],$d['due_at'],$d['due_at']]);
    if($exception){$this->pdo->prepare("UPDATE epi_v2_operational_deadlines SET state='excused' WHERE id=?")->execute([$d['id']]);V2Store::audit($this->pdo,'deadline|'.$d['deadline_uuid'],(int)$exception['approved_by'],'approved exception',$d,$exception);return 'excused';}
    $owner=$this->ownership->ownerAt($d['module'],$d['object_reference'],$d['due_at']);
    $employee=$owner?(int)$owner['employee_id']:null;$snap=json_decode($d['policy_snapshot_json'],true)?:[];
    $opportunity=$owner?self::minutes(max($d['starts_at'],$owner['effective_from'],$owner['accepted_at']),$d['due_at'],$snap):0;
    $fair=$owner&&$opportunity>=(int)($snap['policy']['minimum_opportunity_minutes']??30);
    $eligibility=$d['historical_backfill']?'historical_recovered':($fair?'pending_rule':'needs_review');
    $reason=$d['historical_backfill']?'pre_activation':(!$employee?'insufficient_attribution':(!$fair?'insufficient_opportunity':null));
    $meta=['deadline_uuid'=>$d['deadline_uuid'],'obligation_key'=>$d['obligation_key'],'responsible_employee_at_breach'=>$employee,'responsible_team'=>$d['responsible_team'],'ownership_uuid'=>$owner['ownership_uuid']??null,'owner_interval'=>$owner,'sla_version'=>$snap['sla_version']??null,'calendar_version'=>$snap['calendar_version']??null,'policy_snapshot'=>$snap,'starts_at'=>$d['starts_at'],'due_at'=>$d['due_at'],'actual_state'=>$d['fulfilled_at']?'fulfilled_late':'overdue','fulfilment_state'=>['at'=>$d['fulfilled_at'],'by'=>$d['fulfilled_by']],'exception_state'=>$exception,'object_reference'=>$d['object_reference'],'detected_at'=>$detected,'historical_backfill'=>(bool)$d['historical_backfill'],'late_business_minutes'=>self::minutes($d['due_at'],$d['fulfilled_at']??$detected,$snap),'responsibility_status'=>$employee?'attributed':'unattributed','exclusion_reason'=>$reason,'excluded_from_scoring'=>true,'mode'=>'shadow'];
    $uuid=Support::uuidFromHash('deadline-breach|'.$d['deadline_uuid']);
    $this->pdo->prepare('INSERT INTO epi_v2_performance_incidents(incident_uuid,root_incident_id,deadline_uuid,event_key,module,object_reference,responsible_employee_at_breach,occurred_at,due_at,current_risk_state,historical_state,eligibility_state,exclusion_reason,metadata_json,resolved_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$uuid,'deadline:'.$d['deadline_uuid'],$d['deadline_uuid'],$d['breach_event_key'],$d['module'],$d['object_reference'],$employee,$d['due_at'],$d['due_at'],$d['fulfilled_at']?'resolved':'open','breach',$eligibility,$reason,Support::json($meta),$d['fulfilled_at']]);
    $this->pdo->prepare('UPDATE epi_v2_operational_deadlines SET breach_at=due_at,breach_incident_uuid=?,state=? WHERE id=?')->execute([$uuid,$d['fulfilled_at']?'fulfilled':($employee?'breached':'needs_attribution'),$d['id']]);
    return $employee?'breached':'needs_attribution';
 }
 public static function minutes(string $from,string $to,array $snapshot):float {
    $start=Support::timestamp($from);$end=Support::timestamp($to);if($end<=$start)return 0;
    $calendar=[];foreach($snapshot['calendar']??[]as$r)$calendar[$r['business_date']]=$r;
    $hours=$snapshot['hours']??[];$total=0;
    for($day=$start->setTime(0,0);$day<=$end;$day=$day->modify('+1 day')){
        $key=$day->format('Y-m-d');$n=(int)$day->format('N');$override=$calendar[$key]??null;
        if($override){if(!$override['is_working_day'])continue;$open=$override['opens_at'];$close=$override['closes_at'];}
        else{if($n===7)continue;$open=$hours[$n===6?'saturday_open':'weekday_open']??($n===6?'09:00':'08:00');$close=$hours[$n===6?'saturday_close':'weekday_close']??($n===6?'13:00':'17:00');}
        if(!$open||!$close)continue;$a=max($start,Support::timestamp($key.' '.$open));$b=min($end,Support::timestamp($key.' '.$close));if($b>$a)$total+=($b->getTimestamp()-$a->getTimestamp())/60;
    }return round($total,2);
 }
}
