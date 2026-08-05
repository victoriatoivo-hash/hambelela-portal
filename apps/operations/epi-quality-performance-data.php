<?php
declare(strict_types=1);
require_once __DIR__ . '/operations.php';
header('Content-Type: application/json');

function epi_quality_json(array $payload, int $status=200): void { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_SLASHES); exit; }
function epi_quality_decimal($value): string { $v=trim((string)$value); if(!preg_match('/^-?\d{1,11}(?:\.\d{1,2})?$/',$v)) throw new InvalidArgumentException('Invalid monetary amount.'); return number_format((float)$v,2,'.',''); }

try {
    require_role('owner_admin');
    $service=new \Hambelela\EPI\QualityPerformance(db());
    $filters=[]; foreach(['period','date_from','date_to','category','severity','status','responsibility_type','responsible_employee_id'] as $key) $filters[$key]=trim((string)($_GET[$key]??''));
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        $kind=trim((string)($_GET['kind']??'summary'));
        $map=['summary'=>'getSummary','employee'=>'getEmployeeSummary','reporting'=>'getReportingSummary','category'=>'getCategorySummary','severity'=>'getSeveritySummary','resolution'=>'getResolutionPerformance','repeat'=>'getRepeatErrors','financial'=>'getFinancialImpact','risk'=>'getCurrentRisk','corrective'=>'getCorrectiveActions','evidence'=>'getEvidence','timeline'=>'getTimeline'];
        if(!isset($map[$kind])) throw new InvalidArgumentException('Unknown quality request.');
        epi_quality_json(['ok'=>true,'data'=>$service->{$map[$kind]}($filters)]);
    }
    $payload=json_decode((string)file_get_contents('php://input'),true); if(!is_array($payload))$payload=$_POST;
    $token=(string)($payload['csrf_token']??''); $sessionToken=(string)($_SESSION['epi_quality_csrf_token']??'');
    if($sessionToken===''||!hash_equals($sessionToken,$token)) epi_quality_json(['ok'=>false,'message'=>'Invalid request token.'],403);
    $actor=ops_current_employee_id(); $errorId=(int)($payload['error_id']??0); if($errorId<=0) throw new InvalidArgumentException('Choose an error record.');
    $action=(string)($payload['action']??''); $pdo=db(); $pdo->beginTransaction();
    if($action==='owner_review'){
        $decision=(string)($payload['decision']??''); $valid=['pending_review','confirmed_employee_error','confirmed_business_error','confirmed_system_error','confirmed_supplier_error','confirmed_courier_error','confirmed_customer_error','shared_responsibility','excused','dismissed','duplicate'];
        if(!in_array($decision,$valid,true)) throw new InvalidArgumentException('Invalid owner decision.');
        $reason=trim((string)($payload['reason']??'')); if($reason==='') throw new InvalidArgumentException('Owner review reason is required.');
        $responsible=(int)($payload['responsible_employee_id']??0); if($decision==='confirmed_employee_error'&&$responsible===$actor) throw new RuntimeException('Employees cannot approve an error recorded against themselves.');
        $uuid=\Hambelela\EPI\Support::uuid();
        $pdo->prepare('INSERT INTO epi_quality_owner_reviews(review_uuid,error_id,reviewer_employee_id,decision,reason,supporting_evidence,responsibility_percentage,related_root_incident_id,reviewed_at,recording_mode,metadata_json) VALUES(?,?,?,?,?,?,?,?,NOW(),?,?)')->execute([$uuid,$errorId,$actor,$decision,$reason,trim((string)($payload['supporting_evidence']??''))?:null,isset($payload['responsibility_percentage'])?(float)$payload['responsibility_percentage']:null,($payload['related_root_incident_id']??null)?:null,\Hambelela\EPI\Performance::mode()==='test'?'test':'manual',json_encode(['excluded_from_scoring'=>true])]);
        $pdo->prepare('UPDATE epi_quality_error_profiles SET owner_reviewed_by_employee_id=?,current_responsible_employee_id=COALESCE(NULLIF(?,0),current_responsible_employee_id),responsibility_type=?,root_cause_key=COALESCE(NULLIF(?,\'\'),root_cause_key),root_cause_note=COALESCE(NULLIF(?,\'\'),root_cause_note),updated_at=NOW() WHERE error_id=?')->execute([$actor,$responsible,str_replace('confirmed_','',$decision),trim((string)($payload['root_cause_key']??'')),trim((string)($payload['root_cause_note']??'')),$errorId]);
        $event='error_owner_reviewed'; $result=['review_uuid'=>$uuid];
    } elseif($action==='responsibility_allocation'){
        $allocations=(array)($payload['allocations']??[]); $total=0; foreach($allocations as$a)$total+=(float)($a['percentage']??0); if(abs($total-100)>0.001)throw new InvalidArgumentException('Shared responsibility must total exactly 100%.');
        foreach($allocations as$a){$reason=trim((string)($a['reason']??''));if($reason==='')throw new InvalidArgumentException('Every allocation requires a reason.');$pdo->prepare('INSERT INTO epi_quality_responsibility_allocations(allocation_uuid,error_id,responsible_type,responsible_employee_id,responsibility_percentage,reason,approved_by_employee_id,approved_at,recording_mode) VALUES(?,?,?,?,?,?,?,NOW(),?)')->execute([\Hambelela\EPI\Support::uuid(),$errorId,(string)($a['type']??'employee_error'),($a['employee_id']??null)?:null,(float)$a['percentage'],$reason,$actor,\Hambelela\EPI\Performance::mode()==='test'?'test':'manual']);}
        $event='error_shared_responsibility_approved';$result=['total_percentage'=>$total];
    } elseif($action==='financial_impact'){
        $keys=['direct_product_cost','courier_cost','refund_amount','replacement_cost','compensation_amount','labour_rework_estimate','recoverable_amount','recovered_amount'];$v=[];foreach($keys as$k)$v[$k]=epi_quality_decimal($payload[$k]??'0');
        $net=round(array_sum(array_map('floatval',array_slice($v,0,6)))-(float)$v['recovered_amount'],2);$uuid=\Hambelela\EPI\Support::uuid();
        $pdo->prepare('INSERT INTO epi_quality_financial_impacts(impact_uuid,error_id,direct_product_cost,courier_cost,refund_amount,replacement_cost,compensation_amount,labour_rework_estimate,recoverable_amount,recovered_amount,net_financial_impact,valuation_status,currency,confirmed_by_employee_id,confirmed_at,reason,recording_mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)')->execute([$uuid,$errorId,$v['direct_product_cost'],$v['courier_cost'],$v['refund_amount'],$v['replacement_cost'],$v['compensation_amount'],$v['labour_rework_estimate'],$v['recoverable_amount'],$v['recovered_amount'],number_format($net,2,'.',''),(string)($payload['valuation_status']??'estimated'),'NAD',$actor,trim((string)($payload['reason']??''))?:null,\Hambelela\EPI\Performance::mode()==='test'?'test':'manual']);
        $event='error_financial_impact_changed';$result=['impact_uuid'=>$uuid,'net_financial_impact'=>$net];
    } elseif($action==='corrective_action'){
        $description=trim((string)($payload['description']??''));$type=trim((string)($payload['action_type']??''));if($type==='')throw new InvalidArgumentException('Corrective action type is required.');$uuid=\Hambelela\EPI\Support::uuid();
        $pdo->prepare('INSERT INTO epi_quality_corrective_actions(action_uuid,error_id,action_type,action_description,responsible_employee_id,due_at,status,related_task_id,created_by_employee_id,recording_mode) VALUES(?,?,?,?,?,?,?,NULLIF(?,0),?,?)')->execute([$uuid,$errorId,$type,$description?:null,($payload['responsible_employee_id']??null)?:null,($payload['due_at']??null)?:null,(string)($payload['status']??'open'),(int)($payload['related_task_id']??0),$actor,\Hambelela\EPI\Performance::mode()==='test'?'test':'manual']);
        $event='error_corrective_action_created';$result=['action_uuid'=>$uuid];
    } elseif($action==='repeat_review'){
        $status=(string)($payload['repeat_status']??'');$reason=trim((string)($payload['reason']??''));if($reason==='')throw new InvalidArgumentException('Repeat review reason is required.');$uuid=\Hambelela\EPI\Support::uuid();
        $pdo->prepare('INSERT INTO epi_quality_repeat_reviews(review_uuid,error_id,related_error_id,repeat_status,window_days,reviewed_by_employee_id,reason,reviewed_at,recording_mode) VALUES(?,?,?,?,?,?,?,NOW(),?)')->execute([$uuid,$errorId,($payload['related_error_id']??null)?:null,$status,($payload['window_days']??null)?:null,$actor,$reason,\Hambelela\EPI\Performance::mode()==='test'?'test':'manual']);$pdo->prepare('UPDATE epi_quality_error_profiles SET repeat_status=?,updated_at=NOW() WHERE error_id=?')->execute([$status,$errorId]);
        $event='error_repeat_reviewed';$result=['review_uuid'=>$uuid];
    } else throw new InvalidArgumentException('Unknown quality action.');
    $pdo->commit(); ops_activity_log($event,'error_log',$errorId,array_merge($payload,['actor_employee_id'=>$actor,'excluded_from_scoring'=>true])); epi_quality_json(['ok'=>true,'data'=>$result]);
} catch(Throwable $error){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();epi_quality_json(['ok'=>false,'message'=>$error->getMessage()],http_response_code()>=400?http_response_code():400);}
