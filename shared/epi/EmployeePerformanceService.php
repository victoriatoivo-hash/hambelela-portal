<?php
declare(strict_types=1);
namespace Hambelela\EPI;

use PDO;
use RuntimeException;
use InvalidArgumentException;

require_once __DIR__.'/RateScoreCalculator.php';
require_once __DIR__.'/Support.php';
require_once __DIR__.'/V2Store.php';
require_once __DIR__.'/EligibilityPolicy.php';

/** Single result contract for profile, workforce and export.
 * Reads NEVER repair/create/recalculate. The explicit worker publishes immutable revisions.
 * Raw operational records must be projected through verified ownership/eligibility first.
 */
final class EmployeePerformanceService
{
    private $db;
    public function __construct(PDO $db) { $this->db=$db; }

    /** Workforce reporting reads the exact same published revisions as each profile.
     * Do not average unlike role scores or calculate a second team percentage.
     */
    public function workforce(string $period,array $employees,$now=null):array
    {
        self::period(1,$period);
        $reports=[];
        foreach($employees as $employee){
            $id=(int)($employee['id']??0);
            if($id<=0)throw new InvalidArgumentException('A stable employee ID is required.');
            $result=$this->profile($id,$period,$now);
            $reports[]=['employee'=>$employee,'performance'=>$result];
        }
        return ['period_start'=>$period,'reports'=>$reports,'calculation'=>'epi-v2-rate-1',
            'financial_use_allowed'=>false,'legacy'=>false];
    }

    /** Same read contract for the existing profile, workforce overview and exports. */
    public function profile(int $employee,string $period,$now=null):array
    {
        self::period($employee,$period);
        require_once __DIR__.'/V2PerformanceQuery.php';
        require_once __DIR__.'/OwnershipPeriodEngine.php';
        $result=$this->read($employee,$period);
        $person=V2Store::one($this->db,'SELECT id,full_name FROM ops_employees WHERE id=?',[$employee]);
        if(!$person)throw new InvalidArgumentException('Employee not found.');
        $result['employee_name']=$person['full_name'];
        $previousPeriod=Support::timestamp($period)->modify('-1 month')->format('Y-m-01');
        $previous=$this->read($employee,$previousPeriod);
        $result['previous_period']=['period_start'=>$previousPeriod,'status'=>$previous['status'],
            'official_score_hundredths'=>$previous['official_score_hundredths']??null];
        $current=$result['official_score_hundredths']??null;$prior=$previous['official_score_hundredths']??null;
        $result['movement_hundredths']=$current!==null&&$prior!==null?$current-$prior:null;
        $result['trend']=[];
        for($i=5;$i>=0;$i--){
            $month=Support::timestamp($period)->modify('-'.$i.' months')->format('Y-m-01');
            $r=$month===$period?$result:$this->read($employee,$month);
            $result['trend'][]=['period_start'=>$month,'status'=>$r['status'],'score_hundredths'=>$r['official_score_hundredths']??null];
        }
        $query=new V2PerformanceQuery($this->db);
        $result['personal_risk']=$query->personalRisk($employee,$now);
        // Team queue is informational, never used as a personal score input.
        $result['team_risk']=[];
        foreach(['front_desk','packers'] as $team)$result['team_risk'][$team]=$query->teamRisk($team,$now);
        $result['historical_incidents']=$query->history($employee,$period,Support::timestamp($period)->modify('last day of this month')->format('Y-m-d'));
        $names=[];foreach($this->db->query('SELECT id,full_name FROM ops_employees')->fetchAll(PDO::FETCH_ASSOC) as $p)$names[(int)$p['id']]=$p['full_name'];
        $roots=[];$work=[];
        foreach($result['evidence']??[] as $index=>$row){
            $result['evidence'][$index]['responsible_name']=$names[(int)$row['employee_id']]??'Identity requires review';
            $result['evidence'][$index]['fulfilled_by_name']=empty($row['fulfilled_by'])?null:($names[(int)$row['fulfilled_by']]??'Identity requires review');
            $work[$row['opportunity_key']]=true;
            if($row['outcome']==='failure'&&!empty($row['root_incident_id']))$roots[$row['root_incident_id']]=true;
        }
        $result['eligible_work_count']=count($work);$result['confirmed_incident_count']=count($roots);
        foreach($result['historical_incidents'] as $index=>$incident){
            $included=[];
            foreach($result['evidence']??[] as $evidence){
                $sources=array_column($evidence['supporting_evidence']??[],'source');
                if(($evidence['root_incident_id']??null)===$incident['root_incident_id']||in_array($incident['root_incident_id'],$sources,true))
                    $included[]=($result['categories'][$evidence['category']]['label']??$evidence['category']).' — '.
                        ($result['categories'][$evidence['category']]['metrics'][$evidence['metric']]['label']??$evidence['metric']);
            }
            $result['historical_incidents'][$index]['published_score_effect']=$included?
                'Included in published result: '.implode(', ',array_unique($included)):
                'Not included in the published result; see eligibility / review evidence';
        }
        foreach($result['categories']??[] as $key=>$category){
            $old=$previous['categories'][$key]['official_score_hundredths']??null;
            $score=$category['official_score_hundredths']??null;
            $result['categories'][$key]['previous_score_hundredths']=$old;
            $result['categories'][$key]['movement_hundredths']=$score!==null&&$old!==null?$score-$old:null;
            foreach($category['metrics'] as $metric=>$value){
                $priorMetric=$previous['categories'][$key]['metrics'][$metric]??null;
                // Compare measured like-for-like outcomes, never a changed formula.
                $comparable=($value['status']??'')==='calculated'&&($priorMetric['status']??'')==='calculated'
                    &&($value['direction']??null)===($priorMetric['direction']??null);
                $result['categories'][$key]['metrics'][$metric]['previous_rate_hundredths']=$comparable?$priorMetric['rate_hundredths']:null;
                $result['categories'][$key]['metrics'][$metric]['movement_hundredths']=$comparable?$value['rate_hundredths']-$priorMetric['rate_hundredths']:null;
            }
        }
        $missing=(int)($result['missing_required_weight_hundredths']??10000);
        $result['management_summary']=count($work).' eligible work records; '.count($roots).' distinct confirmed failures. '.
            ($missing>0?number_format($missing/100,2).'% of required score weight is not fully measured. ':'All required score components are measured. ').
            (!empty($result['data_may_be_stale'])?'New evidence is awaiting recalculation. ':'').
            'Current open work is shown separately from historical performance.';
        return $result;
    }

    /** Presentation only: export the stored result, never a separately calculated score. */
    public static function exportHeaders():array
    {
        return ['Employee ID','Employee','Month','Result status','Official score %','Scorecard version','Category','Category weight %','KPI','Numerator','Eligible volume','Rate %','KPI status','Confidence','Reason','Calculated at','Stale',
            'Numerator meaning','Configured target %','Category movement points','KPI rate movement points'];
    }

    public static function exportRows(array $result):array
    {
        $rows=[];
        foreach($result['categories']??[] as $key=>$category)foreach($category['metrics'] as $metric=>$r)$rows[]=[
            $result['employee_id'],$result['employee_name']??'', $result['period_start'],$result['status'],
            $result['official_score_hundredths']===null?'':$result['official_score_hundredths']/100,
            $result['scorecard_version']??'',$category['label'],$category['weight_hundredths']/100,
            $r['label'],$r['numerator'],$r['eligible_volume'],$r['rate_hundredths']===null?'':$r['rate_hundredths']/100,
            $r['status'],$r['confidence'],$r['reason']??'', $result['last_calculated_at']??'',
            !empty($result['data_may_be_stale'])?'Yes':'No',($r['direction']??'success')==='error'?'Errors':'Successful outcomes',
            isset($r['target_hundredths'])?$r['target_hundredths']/100:'',
            isset($category['movement_hundredths'])?$category['movement_hundredths']/100:'',
            isset($r['movement_hundredths'])?$r['movement_hundredths']/100:''];
        // Keep unconfigured / empty employees visible in a workforce export too.
        if(!$rows)$rows[]=[
            $result['employee_id'],$result['employee_name']??'',$result['period_start'],$result['status'],
            isset($result['official_score_hundredths'])?$result['official_score_hundredths']/100:'',
            $result['scorecard_version']??'','','','','','','',$result['status'],'not_measured',
            'No published KPI result',$result['last_calculated_at']??'',!empty($result['data_may_be_stale'])?'Yes':'No','','','',''];
        return $rows;
    }

    public function read(int $employee,string $period): array
    {
        self::period($employee,$period);
        $q=$this->db->prepare('SELECT h.locked_at,h.proposed_result_id,r.result_json,r.calculated_at,r.id
            FROM epi_v2_employee_result_heads h LEFT JOIN epi_v2_employee_results r
            ON r.id=h.current_result_id AND r.employee_id=h.employee_id AND r.period_start=h.period_start
            WHERE h.employee_id=? AND h.period_start=?');
        $q->execute([$employee,$period]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$row || !$row['result_json']) {
            $assigned=V2Store::one($this->db,'SELECT employee_id FROM epi_v2_scorecard_assignments WHERE employee_id=? AND period_start=?',[$employee,$period]);
            return ['employee_id'=>$employee,'period_start'=>$period,
                'status'=>$assigned?'pending_calculation':'not_configured','official_score_hundredths'=>null,'financial_use_allowed'=>false];
        }
        $result=json_decode($row['result_json'],true,512,JSON_THROW_ON_ERROR);
        $result['result_id']=(int)$row['id'];$result['last_calculated_at']=$row['calculated_at'];
        $result['locked_at']=$row['locked_at'];
        $result['correction_pending']=$row['proposed_result_id']!==null;
        $pending=V2Store::one($this->db,'SELECT requested_generation,processed_generation,last_error
            FROM epi_v2_performance_refresh_queue WHERE employee_id=? AND period_start=?',[$employee,$period]);
        $result['data_may_be_stale']=$pending && ((int)$pending['requested_generation']>(int)$pending['processed_generation'] || $pending['last_error']!==null);
        return $result;
    }

    /** Worker-only write. Coverage must come from an independently verified source adapter.
     * Every opportunity is identified by a stable key; evidence duplicates cannot add volume.
     * Every failed opportunity needs a confirmed root; root evidence duplicates count once.
     */
    public function recalculate(int $employee,string $period,array $opportunities,array $coverage): array
    {
        self::period($employee,$period);
        return V2Store::transaction($this->db,function()use($employee,$period,$opportunities,$coverage){
            V2Store::lock($this->db,'employee-performance:'.$employee.':'.$period);
            $assignment=V2Store::one($this->db,'SELECT a.*,s.policy_json,s.policy_hash
                FROM epi_v2_scorecard_assignments a JOIN epi_v2_scorecard_documents s
                ON s.version=a.scorecard_version WHERE a.employee_id=? AND a.period_start=?',[$employee,$period]);
            if(!$assignment) throw new RuntimeException('No explicitly assigned scorecard.');
            if(!hash_equals($assignment['policy_hash'],hash('sha256',$assignment['policy_json'])))
                throw new RuntimeException('Scorecard integrity check failed.');
            $policy=json_decode($assignment['policy_json'],true,512,JSON_THROW_ON_ERROR);
            $activation=V2Store::activation($this->db);
            $boundary=$activation['enforcement_start_at']??null;
            $projection=self::project($employee,$period,$policy,$opportunities,$coverage,$boundary);
            $result=RateScoreCalculator::calculate($policy,$projection['observations']);
            $result['employee_id']=$employee;$result['period_start']=$period;
            $result['evidence']=$projection['evidence'];$result['excluded']=$projection['excluded'];
            $result['validation_score_hundredths']=$result['official_score_hundredths'];
            $approved=($policy['status']??'')==='approved' && !empty($assignment['validation_approved_at'])
                && !empty($assignment['validation_approved_by']) && $boundary!==null
                && $period.' 00:00:00'>=$boundary && !empty($assignment['official_from'])
                && $period>=$assignment['official_from'];
            $result['status']=$result['official_score_hundredths']===null?'provisional':($approved?'official':'validation');
            if(!$approved) $result['official_score_hundredths']=null;
            // Financial use requires a separate business validation process; never implied by calculation.
            $result['financial_use_allowed']=false;
            $hash=hash('sha256',self::canonical([$assignment,$projection]));
            $head=V2Store::one($this->db,'SELECT * FROM epi_v2_employee_result_heads
                WHERE employee_id=? AND period_start=? FOR UPDATE',[$employee,$period]);
            $latest=V2Store::one($this->db,'SELECT * FROM epi_v2_employee_results
                WHERE employee_id=? AND period_start=? ORDER BY revision DESC LIMIT 1',[$employee,$period]);
            if($latest && hash_equals($latest['input_hash'],$hash)) return $this->read($employee,$period);
            $revision=$latest?(int)$latest['revision']+1:1;
            $this->db->prepare('INSERT INTO epi_v2_employee_results
                (employee_id,period_start,revision,scorecard_version,input_hash,result_json) VALUES(?,?,?,?,?,?)')
                ->execute([$employee,$period,$revision,$policy['version'],$hash,Support::json($result)]);
            $id=(int)$this->db->lastInsertId();
            if(!$head) $this->db->prepare('INSERT INTO epi_v2_employee_result_heads
                (employee_id,period_start,current_result_id) VALUES(?,?,?)')->execute([$employee,$period,$id]);
            elseif($head['locked_at']) $this->db->prepare('UPDATE epi_v2_employee_result_heads
                SET proposed_result_id=? WHERE employee_id=? AND period_start=?')->execute([$id,$employee,$period]);
            else $this->db->prepare('UPDATE epi_v2_employee_result_heads SET current_result_id=?,proposed_result_id=NULL
                WHERE employee_id=? AND period_start=?')->execute([$id,$employee,$period]);
            return $this->read($employee,$period);
        });
    }

    private static function project(int $employee,string $period,array $policy,array $opportunities,array $coverage,?string $boundary): array
    {
        $samples=[];$evidence=[];$excluded=[];$seen=[];$roots=[];
        foreach($policy['categories'] as $category=>$definition) foreach($definition['metrics'] as $metric=>$settings) {
            $samples[$category][$metric]=['eligible_volume'=>0,'numerator'=>0,
                'source_complete'=>($coverage[$category][$metric]['complete']??false)===true,
                'applicable'=>($coverage[$category][$metric]['applicable']??true)!==false];
        }
        // Stable ordering makes identical inputs idempotent regardless of query ordering.
        usort($opportunities,static function(array $a,array $b):int{return strcmp(self::canonical($a),self::canonical($b));});
        foreach($opportunities as $row) {
            $category=(string)($row['category']??'');$metric=(string)($row['metric']??'');
            if(!isset($samples[$category][$metric])) throw new InvalidArgumentException('Unknown canonical KPI.');
            $key=(string)($row['opportunity_key']??'');
            if($key==='') throw new InvalidArgumentException('Stable opportunity key required.');
            $reason=null;
            if(($row['employee_id']??null)!==$employee || ($row['responsibility_confirmed']??false)!==true)
                $reason='insufficient_attribution';
            elseif(substr((string)($row['due_at']??''),0,7)!==substr($period,0,7)) $reason='outside_period';
            elseif($boundary===null || empty($row['starts_at']) || $row['starts_at']<$boundary)
                $reason='before_enforcement_or_missing_start';
            elseif(($row['eligible']??false)!==true) $reason=$row['exclusion_reason']??'not_eligible';
            elseif(empty($row['module'])) $reason='missing_source_module';
            elseif(EligibilityPolicy::exclusionReason(['module'=>$row['module'],'employee_id'=>$employee],$row['metadata']??[])!==null)
                $reason='central_eligibility_exclusion';
            elseif(!in_array($row['outcome']??null,['success','failure'],true)) $reason='not_yet_observed';
            elseif(empty($row['source_reference']) || empty($row['expected']) || empty($row['actual']) || empty($row['ownership']))
                $reason='incomplete_explanation';
            elseif(!self::responsibilityValid($employee,$row)) $reason='invalid_responsibility_snapshot';
            elseif($row['outcome']==='failure' && empty($row['root_incident_id'])) $reason='missing_root_incident';
            if($reason!==null){
                if($reason==='central_eligibility_exclusion' && ($row['metadata']['mode']??'')==='shadow')
                    $samples[$category][$metric]['source_complete']=false;
                if(in_array($reason,['insufficient_attribution','missing_source_module','incomplete_explanation',
                    'invalid_responsibility_snapshot','missing_root_incident','correlation_attribution_changed',
                    'multiple_roots_need_workload_review'],true))
                    $samples[$category][$metric]['source_complete']=false;
                $row['exclusion_reason']=$reason;$excluded[]=$row;continue;
            }
            $identity=$category.'|'.$metric.'|'.$key;
            if(isset($seen[$identity])) {
                if($seen[$identity]!==self::canonical($row)) throw new RuntimeException('Conflicting opportunity revisions require resolution.');
                continue;
            }
            $seen[$identity]=self::canonical($row);
            if($row['outcome']==='failure') {
                $root=(string)$row['root_incident_id'];
                if(isset($roots[$root])) {
                    if($roots[$root]['metric']!==$category.'|'.$metric)
                        throw new RuntimeException('A root incident needs one authoritative KPI allocation.');
                    $row['exclusion_reason']='duplicate_root_incident';$excluded[]=$row;continue;
                }
                $roots[$root]=['identity'=>$identity,'metric'=>$category.'|'.$metric];
            }
            $samples[$category][$metric]['eligible_volume']++;
            $success=$row['outcome']==='success';
            $errorDirection=$policy['categories'][$category]['metrics'][$metric]['direction']==='error';
            if($success!==$errorDirection) $samples[$category][$metric]['numerator']++;
            $evidence[]=$row;
        }
        return ['observations'=>$samples,'evidence'=>$evidence,'excluded'=>$excluded];
    }

    private static function responsibilityValid(int $employee,array $row): bool
    {
        $owner=$row['ownership'];
        if(!is_array($owner) || (int)($owner['employee_id']??0)!==$employee) return false;
        if(($row['module']??'')==='Error Log')
            return !empty($owner['reviewer_id']) && !empty($owner['reviewed_at']);
        $due=(string)($row['due_at']??'');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$due);
        if(!$date || $date->format('Y-m-d H:i:s')!==$due) return false;
        $start=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)($row['starts_at']??''));
        if(!$start || $start->format('Y-m-d H:i:s')!==$row['starts_at'] || $row['starts_at']>$due) return false;
        $responsibilityAt=$due;
        if(($row['attribution_basis']??'')!=='obligation_due' && ($row['outcome']??'')==='success' && !empty($row['fulfilled_at']) && $row['fulfilled_at']<=$due)
            $responsibilityAt=$row['fulfilled_at'];
        $accepted=$owner['accepted_at']??null;
        if(!$accepted && !empty($owner['assigned_by']) &&
            (($owner['source']??'')==='approved_front_roster' ||
            (($owner['source']??'')==='orders_sla_v1' && ($owner['ownership_reason']??'')==='approved_order_assignment')))
            $accepted=$owner['effective_from']??null;
        return !empty($owner['effective_from']) && $owner['effective_from']<=$responsibilityAt &&
            (empty($owner['effective_to']) || $responsibilityAt<$owner['effective_to']) &&
            $accepted!==null && $accepted<=$responsibilityAt;
    }

    private static function period(int $employee,string $period): void
    {
        if($employee<1 || !preg_match('/^(20\d{2})-(0[1-9]|1[0-2])-01$/D',$period))
            throw new InvalidArgumentException('An employee and first-of-month period are required.');
    }

    private static function canonical(array $value): string
    {
        $sort=function(array $a)use(&$sort):array{ksort($a);foreach($a as &$v)if(is_array($v))$v=$sort($v);unset($v);return $a;};
        return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}
