<?php
declare(strict_types=1);
namespace Hambelela\EPI;

use PDO;
use RuntimeException;
require_once __DIR__.'/PerformanceCapturePolicy.php';

/** Read-only projection of actual obligations; never substitutes actor for owner.
 * Source coverage is an explicit scorecard contract, not inferred from finding rows.
 */
final class OperationalPerformanceProjector
{
    private $db;
    private const DEADLINES = [
        'Orders|acknowledge_order'=>['orders','progression'],
        'Orders|move_order_out_of_new'=>['orders','progression'],
        'Orders|complete_order'=>['orders','completion'],
        'Orders|customer_followup'=>['communication','followup'],
        'Orders|customer_response'=>['communication','response'],
        'Tasks|start_task'=>['tasks','start'],
        'Tasks|complete_task'=>['tasks','timeliness'],
        'Tasks|complete_handover'=>['communication','handover'],
        'Packing|pack_order'=>['orders','progression'],
        'Packing|start_packing'=>['packing','start'],
        'Packing|complete_packing'=>['packing','timeliness'],
        'Packing List|start_packing'=>['packing','start'],
        'Packing List|complete_packing'=>['packing','timeliness'],
        'Courier|send_courier_documents'=>['courier','timeliness'],
        'Courier|upload_waybill'=>['courier','upload'],
        'Inventory|update_website_stock'=>['inventory','timeliness'],
        'Bookkeeping|enter_cash_transaction'=>['bookkeeping','timeliness'],
        'Bookkeeping|record_opening_balance'=>['bookkeeping','controls'],
        'Bookkeeping|complete_cash_reconciliation'=>['bookkeeping','controls'],
    ];

    public function __construct(PDO $db){$this->db=$db;}

    /** Canonical work/KPI allocation choices shared with the owner review form. */
    public static function unitTargets(string $key,string $role=''):array
    {
        if(strpos($key,'ops_whatsapp_conversation:')===0)return [['communication','accuracy']];
        if(strpos($key,'cash_entry:')===0)return [['bookkeeping','accuracy']];
        $targets=[];
        if($role!=='packer'||strpos($key,'packing_task:')===0||strpos($key,'order_packing:')===0)
            $targets[]=['quality','first_time_right'];
        if(strpos($key,'order:')===0)$targets[]=['orders','first_time_right'];
        if(strpos($key,'checklist_task:')===0)$targets[]=['tasks','compliance'];
        return $targets;
    }

    public function employee(int $employee,string $period): array
    {
        if($employee<1 || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])-01$/D',$period))throw new RuntimeException('Invalid projection period.');
        $row=V2Store::one($this->db,'SELECT s.policy_json FROM epi_v2_scorecard_assignments a
            JOIN epi_v2_scorecard_documents s ON s.version=a.scorecard_version
            WHERE a.employee_id=? AND a.period_start=?',[$employee,$period]);
        if(!$row)throw new RuntimeException('No assigned scorecard.');
        $policy=json_decode($row['policy_json'],true,512,JSON_THROW_ON_ERROR);
        $start=$period.' 00:00:00';$end=Support::timestamp($start)->modify('+1 month')->format('Y-m-d H:i:s');
        $coverage=[];
        foreach($policy['categories'] as $category=>$definition)foreach($definition['metrics'] as $metric=>$config){
            $source=$config['source_coverage']??[];
            $coverage[$category][$metric]=['complete'=>($source['verified']??false)===true &&
                !empty($source['verified_by']) && ($source['from']??'9999')<=$start && ($source['to']??'')>=$end,
                'reason'=>'Required source coverage has not been verified for the whole period.'];
        }
        $q=$this->db->prepare('SELECT d.*,i.responsible_employee_at_breach,i.root_incident_id,
            i.metadata_json AS incident_metadata,i.eligibility_state,i.exclusion_reason AS incident_exclusion,
            c.employee_id AS completion_owner,c.ownership_json AS completion_ownership,c.eligible_at_capture
            FROM epi_v2_operational_deadlines d LEFT JOIN epi_v2_performance_incidents i
            ON i.deadline_uuid=d.deadline_uuid LEFT JOIN epi_v2_deadline_completion_eligibility c
            ON c.deadline_uuid=d.deadline_uuid WHERE d.due_at>=? AND d.due_at<? ORDER BY d.due_at,d.id');
        $q->execute([$start,$end]);$deadlines=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $owners=new OwnershipPeriodEngine($this->db);$opportunities=[];
        foreach($deadlines as $d){
            $map=self::DEADLINES[$d['module'].'|'.$d['obligation_key']]??null;
            if(!$map || !isset($policy['categories'][$map[0]]['metrics'][$map[1]]))continue;
            $meta=json_decode((string)($d['incident_metadata']??''),true)?:[];
            $breached=!empty($d['root_incident_id']);
            $complete=!empty($d['fulfilled_at']);
            $success=$complete && $d['fulfilled_at']<=$d['breach_eligible_at'];
            $corrected=$breached && $success;
            // Completion rate and completion SLA are separate rates. An unfinished
            // obligation fails completion; once finished its one breach belongs to
            // timeliness. Never apply the same root to both weighted metrics.
            $completionMetric=in_array($d['obligation_key'],['complete_task','complete_packing','enter_cash_transaction'],true)
                && isset($policy['categories'][$map[0]]['metrics']['completion']);
            if($completionMetric && !$complete)$map[1]='completion';
            // Positive work also uses the responsibility interval, not the person who clicked.
            $owner=$breached&&!$corrected?($meta['owner_interval']??null):(json_decode((string)($d['completion_ownership']??''),true)?:null);
            $responsible=$breached&&!$corrected?(int)$d['responsible_employee_at_breach']:(int)($d['completion_owner']??0);
            if($responsible!==$employee)continue;
            $snapshot=json_decode((string)$d['policy_snapshot_json'],true)?:[];
            if(!$breached || $corrected) {
                $approved=(int)($d['eligible_at_capture']??0)===1;
                $meta=['excluded_from_scoring'=>!$approved,'mode'=>$approved?'approved_prospective':'shadow'];
            }
            $reason=($d['exclusion_reason']??null)?:($d['incident_exclusion']?:null);
            if((int)$d['historical_backfill'])$reason='historical_backfill';
            elseif(empty($snapshot['event']['score_eligible']))$reason='non_employee_obligation';
            elseif($d['state']==='excused')$reason='approved_exception';
            elseif($d['state']==='cancelled'&&!$breached)$reason='cancelled_before_breach';
            elseif(!$success&&!$breached)$reason='awaiting_watchdog_or_fulfilment';
            $opportunity=['employee_id'=>$employee,'category'=>$map[0],'metric'=>$map[1],
                'opportunity_key'=>'deadline:'.$d['deadline_uuid'],'root_incident_id'=>$d['root_incident_id']??null,
                'module'=>$d['module'],'source_reference'=>$d['object_reference'],'source_deadline_uuid'=>$d['deadline_uuid'],
                'starts_at'=>$d['starts_at'],'due_at'=>$d['due_at'],'fulfilled_at'=>$d['fulfilled_at'],
                'fulfilled_by'=>$d['fulfilled_by']===null?null:(int)$d['fulfilled_by'],
                'ownership'=>$owner,'responsibility_confirmed'=>$owner!==null,'eligible'=>$reason===null,
                'exclusion_reason'=>$reason,'metadata'=>$meta,'outcome'=>$success?'success':'failure',
                'expected'=>ucwords(str_replace('_',' ',$d['obligation_key'])).' by '.$d['due_at'],
                'actual'=>$complete?'Fulfilled at '.$d['fulfilled_at']:($breached?'Still unfulfilled when deadline expired':'Pending'),
                'current_state'=>$d['state'],'historical_state'=>$corrected?'breach_corrected_by_fulfilment_evidence':($breached?'breach_retained':'on_time'),
                'late_business_minutes'=>$meta['late_business_minutes']??null];
            $opportunities[]=$opportunity;
            if($completionMetric && $complete){
                $completion=$opportunity;
                $completion['metric']='completion';$completion['outcome']='success';
                $completion['root_incident_id']=null;
                if($breached&&!$corrected)$completion['attribution_basis']='obligation_due';
                $completion['expected']='Complete the assigned obligation';
                $opportunities[]=$completion;
            }
        }
        $opportunities=array_merge($opportunities,$this->completedUnits($employee,$start,$end,$policy,$coverage));
        require_once __DIR__.'/AttendanceEvidenceProjector.php';
        $opportunities=array_merge($opportunities,AttendanceEvidenceProjector::employee($this->db,$employee,$start,$end,$coverage));
        return ['opportunities'=>$opportunities,'coverage'=>$coverage];
    }

    private function completedUnits(int $employee,string $start,string $end,array $policy,array &$coverage):array
    {
        $unlinked=V2Store::one($this->db,'SELECT COUNT(*) n FROM epi_v2_quality_revisions r
            LEFT JOIN epi_v2_incident_correlations c ON c.source_key=r.root_incident_id AND c.superseded_at IS NULL
            JOIN epi_v2_performance_incidents i ON i.root_incident_id=r.root_incident_id
            LEFT JOIN epi_v2_completed_work_units u ON u.opportunity_key=c.opportunity_key
                AND u.employee_id=r.employee_id
            WHERE r.employee_id=? AND r.superseded_at IS NULL AND r.eligible=1
            AND (c.id IS NULL OR u.opportunity_key IS NULL OR u.completed_at<? OR u.completed_at>=?)
            AND i.occurred_at>=? AND i.occurred_at<?',[$employee,$start,$end,$start,$end]);
        if((int)$unlinked['n']>0)foreach([['quality','first_time_right'],['communication','accuracy']] as $target)
            if(isset($coverage[$target[0]][$target[1]]))$coverage[$target[0]][$target[1]]=[
                'complete'=>false,'reason'=>'Confirmed incidents require a reviewed work-opportunity/root correlation.'];
        $q=$this->db->prepare('SELECT * FROM epi_v2_completed_work_units WHERE employee_id=? AND completed_at>=? AND completed_at<? ORDER BY opportunity_key');
        $q->execute([$employee,$start,$end]);$units=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $q=$this->db->prepare('SELECT c.*,r.snapshot_json,r.eligible FROM epi_v2_incident_correlations c
            JOIN epi_v2_quality_revisions r ON r.root_incident_id=c.source_key AND r.superseded_at IS NULL
            WHERE c.employee_id=? AND c.superseded_at IS NULL ORDER BY c.id');
        $q->execute([$employee]);$links=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $out=[];
        foreach($units as $unit){
            $snapshot=json_decode($unit['source_snapshot_json'],true)?:[];
            $cash=strpos($unit['opportunity_key'],'cash_entry:')===0;
            $targets=self::unitTargets($unit['opportunity_key'],$policy['role']??'');
            foreach($targets as $target){
                [$category,$metric]=$target;
                if(!isset($policy['categories'][$category]['metrics'][$metric]))continue;
                $approved=($snapshot['score_eligible_at_capture']??false)===true;
                $row=['employee_id'=>$employee,'category'=>$category,'metric'=>$metric,
                    'opportunity_key'=>$unit['opportunity_key'],'root_incident_id'=>null,'module'=>$unit['module'],
                    'source_reference'=>$unit['object_reference'],'starts_at'=>$unit['starts_at'],'due_at'=>$unit['completed_at'],
                    'deadline_applies'=>false,'observed_at'=>$unit['completed_at'],
                    'fulfilled_at'=>$unit['completed_at'],'fulfilled_by'=>$unit['fulfiller_id']===null?null:(int)$unit['fulfiller_id'],
                    'ownership'=>json_decode((string)$unit['ownership_json'],true),'responsibility_confirmed'=>true,
                    'eligible'=>true,'outcome'=>'success','metadata'=>['excluded_from_scoring'=>!$approved,'mode'=>$approved?'approved_prospective':'shadow'],
                    'expected'=>'Complete this work accurately on the first attempt',
                    'actual'=>'Completed work; no confirmed correlated failure recorded',
                    'supporting_evidence'=>[]];
                if($cash){
                    $cashProcess=$snapshot['record']['cash_process']??[];
                    $row['cash_evidence']=$cashProcess;
                    $row['expected']='Record the received cash amount against its verified order allocation';
                    $row['actual']=$cashProcess['actual']??'Cash amount evidence is incomplete';
                    if(empty($cashProcess['measured'])){
                        $row['eligible']=false;$row['exclusion_reason']='incomplete_explanation';
                        $row['amount_review_pending']=true;
                    }
                }
                if(strpos($unit['opportunity_key'],'packing_task:')===0){
                    require_once __DIR__.'/PackingProcessEvidence.php';
                    $packing=$snapshot['packing_process']??PackingProcessEvidence::evaluate($snapshot['record']??[]);
                    $row['packing_evidence']=$packing;$row['actual']=$packing['actual'];
                    if(empty($packing['measured'])){
                        $row['eligible']=false;$row['exclusion_reason']='incomplete_explanation';
                        $row['quantity_review_pending']=true;
                    }
                }
                if($category==='tasks' && $metric==='compliance'){
                    $process=$snapshot['process']??[];
                    if(empty($process['measured'])){
                        $row['eligible']=false;$row['exclusion_reason']='incomplete_explanation';
                    }else{
                        $row['outcome']=$process['outcome'];
                        $row['root_incident_id']=$process['outcome']==='failure'?'task-process:'.$unit['opportunity_key']:null;
                        $row['expected']='Complete the required checklist, meaningful note and supporting proof';
                        $row['actual']=$process['actual'];$row['process_evidence']=$process;
                    }
                }
                foreach($links as $link){
                    if($link['opportunity_key']!==$unit['opportunity_key'] || $link['category_key']!==$category || $link['metric_key']!==$metric)continue;
                    $quality=json_decode($link['snapshot_json'],true)?:[];
                    if(!(int)$link['eligible']) {
                        $type=$quality['source_record']['attribution_type']??'';
                        if(in_array($type,['system','business','supplier','courier','customer','delivery_driver'],true)
                            && !empty($quality['reviewer_id']) && !empty($quality['reviewed_at'])){
                            if(!empty($row['amount_review_pending'])||!empty($row['quantity_review_pending'])){
                                // Reviewed external variance is neither an employee
                                // failure nor a successful employee observation.
                                $row['eligible']=false;$row['exclusion_reason']='reviewed_non_employee_variance';
                                $row['metadata']['excluded_from_scoring']=true;
                                $row['supporting_evidence'][]=['source'=>$link['source_key'],'reviewer'=>$quality['reviewer_id'],
                                    'reviewed_at'=>$quality['reviewed_at'],'exclusion'=>'reviewed_non_employee_variance'];
                            }
                            continue;
                        }
                        $row['eligible']=false;$row['exclusion_reason']='correlation_attribution_changed';continue;
                    }
                    if((int)($quality['responsible_employee_id']??0)!==$employee || empty($quality['responsibility_confirmed'])){
                        $row['eligible']=false;$row['exclusion_reason']='correlation_attribution_changed';continue;
                    }
                    // A confirmed, explicitly correlated employee incident resolves
                    // the quantity-fault ambiguity; mere mismatch never deducts.
                    if((!empty($row['quantity_review_pending']) || !empty($row['amount_review_pending'])) && empty($quality['excluded_from_scoring'])){
                        $row['eligible']=true;unset($row['exclusion_reason']);
                    }
                    // A superseded/excused/system record cannot become a failure through a stale link.
                    if($row['root_incident_id']!==null && $row['root_incident_id']!==$link['root_incident_id']){
                        $row['eligible']=false;$row['exclusion_reason']='multiple_roots_need_workload_review';continue;
                    }
                    $row['root_incident_id']=$link['root_incident_id'];$row['outcome']='failure';
                    $row['module']='Error Log';$row['metadata']=$quality;
                    // Later review cannot promote an unapproved captured work unit.
                    if(!$approved){$row['metadata']['excluded_from_scoring']=true;$row['metadata']['mode']='shadow';}
                    $row['ownership']=['employee_id'=>$employee,'reviewer_id'=>$quality['reviewer_id']??null,
                        'reviewed_at'=>$quality['reviewed_at']??null,'operational_interval'=>$row['ownership']];
                    $row['actual']='Confirmed attributable error: '.($quality['source_record']['error_title']??$link['source_key']);
                    $row['supporting_evidence'][]=['source'=>$link['source_key'],'reviewer'=>$quality['reviewer_id']??null,
                        'reporter'=>$quality['reporter_employee_id']??null,'reviewed_at'=>$quality['reviewed_at']??null];
                }
                $out[]=$row;
            }
        }
        return $out;
    }
}
