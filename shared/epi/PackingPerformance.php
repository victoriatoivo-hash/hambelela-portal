<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateInterval;
use DateTimeImmutable;
use PDO;

/** Read-only Phase 3 analytics. Calculations use EPI evidence only. */
final class PackingPerformance
{
    private $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}

    public function getEmployeeSummary(array $filters=[]): array
    {
        $rows=$this->rows($filters,10000);$completed=[];$started=[];$assigned=[];$reopened=0;$corrections=0;$website=0;$websiteRemoved=0;
        $loadedStart=[];$startedDone=[];$loadedDone=[];$quantity=['exact'=>0,'overpacked'=>0,'underpacked'=>0,'invalid_or_descriptive'=>0,'over_units'=>0.0,'under_units'=>0.0];
        $workload=['liquid'=>0,'solid'=>0,'powder'=>0,'butter'=>0,'herb'=>0,'other'=>0,'heavy'=>0,'very_heavy'=>0,'low_volume'=>0,'medium_volume'=>0,'high_volume'=>0,'mass_grams'=>0.0,'volume_ml'=>0.0];
        $priority=[];$priorityCompliance=['started_late'=>0,'completed_late'=>0,'bypass_review'=>0];$current=[];$evidence=0;$deductions=0;$bonuses=0;$workloadPoints=0.0;
        foreach($rows as $row){$m=$this->metadata($row);$row['metadata']=$m;$ref=(string)$row['reference_number'];$evidence++;
            if($row['action']==='packing_assignment_changed')$assigned[$ref]=true;
            if($row['action']==='packing_item_started'){$started[$ref]=true;if(($m['loaded_to_started_minutes']??null)!==null)$loadedStart[]=(float)$m['loaded_to_started_minutes'];}
            if($row['action']==='packing_item_completed'){$completed[$ref]=true;if(($m['started_to_done_minutes']??null)!==null)$startedDone[]=(float)$m['started_to_done_minutes'];if(($m['loaded_to_completed_minutes']??null)!==null)$loadedDone[]=(float)$m['loaded_to_completed_minutes'];
                $class=(string)($m['quantity_class']??'invalid_or_descriptive');if(isset($quantity[$class]))$quantity[$class]++;$variance=$m['quantity_variance']??null;if(is_numeric($variance)){if($variance>0)$quantity['over_units']+=(float)$variance;elseif($variance<0)$quantity['under_units']+=abs((float)$variance);}
                $state=(string)($m['product_state']??'other');if(isset($workload[$state]))$workload[$state]++;$size=(string)($m['workload_class']??'');if(isset($workload[$size]))$workload[$size]++;$amount=$m['normalized_amount']??null;$unit=$m['normalized_unit']??null;if(is_numeric($amount)&&$unit==='g')$workload['mass_grams']+=(float)$amount;if(is_numeric($amount)&&$unit==='ml')$workload['volume_ml']+=(float)$amount;
                $p=strtolower((string)($row['priority']??$m['priority']??'unknown'));$priority[$p]=($priority[$p]??0)+1;
                $workloadPoints+=(float)($m['workload_points']??0);
            }
            if($row['action']==='packing_item_reopened')$reopened++;
            if(strpos((string)$row['action'],'correction')!==false)$corrections++;
            if($row['action']==='packer_website_confirmation_added')$website++;
            if($row['action']==='packer_website_confirmation_removed')$websiteRemoved++;
            if(strpos((string)$row['action'],'deduction_candidate_')===0)$deductions++;
            if($row['action']==='deduction_candidate_packing_started_late')$priorityCompliance['started_late']++;
            if($row['action']==='deduction_candidate_packing_completed_late')$priorityCompliance['completed_late']++;
            if($row['action']==='deduction_candidate_priority_bypass')$priorityCompliance['bypass_review']++;
            if(strpos((string)$row['action'],'bonus_candidate_')===0)$bonuses++;
            if(!isset($current[$ref]))$current[$ref]=$row;
        }
        $currentCounts=['not_started'=>0,'in_progress'=>0,'done'=>0,'overdue'=>0,'priority_overdue'=>0,'website_update_pending'=>0,'quantity_review_required'=>0];$oldest=null;
        foreach($current as $row){$m=$row['metadata'];$s=strtolower((string)($row['status_after']??$m['packing_status']??''));if(in_array($s,['done','website','label_created','packed_label_needed','done_needs_label','done_needs_correction'],true))$currentCounts['done']++;elseif(in_array($s,['packing','in_progress'],true))$currentCounts['in_progress']++;else $currentCounts['not_started']++;if(($m['quantity_class']??'')!=='exact'&&($m['quantity_class']??'')!=='invalid_or_descriptive')$currentCounts['quantity_review_required']++;if(empty($m['packer_website_confirmed'])&&in_array($s,['done','website','label_created','packed_label_needed','done_needs_label','done_needs_correction'],true))$currentCounts['website_update_pending']++;if($oldest===null||$row['occurred_at']<$oldest['occurred_at'])$oldest=$row;}
        $eligible=$quantity['exact']+$quantity['overpacked']+$quantity['underpacked'];
        return ['period'=>$this->periodPayload($filters),'items_assigned'=>count($assigned),'items_started'=>count($started),'items_completed'=>count($completed),
            'turnaround'=>['loaded_to_started'=>$this->stats($loadedStart),'started_to_done'=>$this->stats($startedDone),'loaded_to_completed'=>$this->stats($loadedDone)],
            'quantity'=>array_merge($quantity,['accuracy_percent'=>$eligible?round($quantity['exact']*100/$eligible,2):null]),'workload'=>array_merge($workload,['workload_points'=>$workloadPoints]),'priority'=>['completed_by_priority'=>$priority,'compliance'=>$priorityCompliance],
            'quality'=>['reopened'=>$reopened,'corrections'=>$corrections],'website'=>['confirmed'=>$website,'removed'=>$websiteRemoved],
            'current'=>array_merge($currentCounts,['oldest_outstanding'=>$oldest]),'evidence_count'=>$evidence,'activity_count'=>$this->activityCount($filters),
            'deduction_candidates'=>$deductions,'bonus_candidates'=>$bonuses,'scoring_status'=>'not_calculated'];
    }

    public function getOrderSummary(array $filters=[]): array
    {
        list($from,$to)=$this->period($filters);$where=["module='Packing'","action='order_packed'",'occurred_at>=?','occurred_at<?'];$params=[$from->format('Y-m-d 00:00:00'),$to->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00')];if(isset($filters['employee_id'])&&$filters['employee_id']!==''){$where[]='employee_id=?';$params[]=(int)$filters['employee_id'];}
        $s=$this->pdo->prepare('SELECT * FROM epi_employee_evidence WHERE '.implode(' AND ',$where).' ORDER BY occurred_at DESC');$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];$unique=[];$modes=['collection'=>0,'delivery'=>0,'courier'=>0,'walk_in_assistance'=>0,'other'=>0];$minutes=[];
        foreach($rows as $row){$m=$this->metadata($row);$ref=(string)$row['reference_number'];if(isset($unique[$ref]))continue;$unique[$ref]=true;$mode=strtolower((string)($m['order_type']??'other'));if(!empty($m['is_walk_in']))$mode='walk_in_assistance';if(!isset($modes[$mode]))$mode='other';$modes[$mode]++;if($row['working_minutes']!==null)$minutes[]=(float)$row['working_minutes'];}
        return ['distinct_orders_packed'=>count($unique),'modes'=>$modes,'new_to_packed'=>$this->stats($minutes),'historical_limit'=>'Order In Progress means ready for next stage; actual packing time requires separate start evidence.'];
    }
    public function getPackingListSummary(array $filters=[]): array{return $this->getEmployeeSummary($filters);}
    public function getTurnaroundMetrics(array $filters=[]): array{return $this->getEmployeeSummary($filters)['turnaround'];}
    public function getPriorityCompliance(array $filters=[]): array{return $this->getEmployeeSummary($filters)['priority'];}
    public function getQuantityAccuracy(array $filters=[]): array{return $this->getEmployeeSummary($filters)['quantity'];}
    public function getWorkloadProfile(array $filters=[]): array{return $this->getEmployeeSummary($filters)['workload'];}
    public function getWebsiteUpdateCompliance(array $filters=[]): array{return $this->getEmployeeSummary($filters)['website'];}
    public function getCurrentStatus(array $filters=[]): array{return $this->getEmployeeSummary($filters)['current'];}
    public function getEvidence(array $filters=[],int $limit=250): array{return $this->rows($filters,$limit);}
    public function employeeOptions(): array{$q=$this->pdo->query("SELECT DISTINCT e.id,e.full_name FROM ops_employees e JOIN epi_employee_evidence ev ON ev.employee_id=e.id AND ev.module IN ('Packing List','Orders') LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND COALESCE(r.role_key,'') NOT IN ('owner_admin','accountant') AND LOWER(CONCAT_WS(' ',e.full_name,e.email,COALESCE(r.role_key,''))) NOT REGEXP 'karina|kaarina|test|preview' ORDER BY e.full_name");return $q->fetchAll(PDO::FETCH_ASSOC)?:[];}

    private function rows(array $filters,int $limit): array
    {list($from,$to)=$this->period($filters);$where=["module='Packing List'",'occurred_at>=?','occurred_at<?'];$params=[$from->format('Y-m-d 00:00:00'),$to->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00')];foreach(['employee_id','priority','packing_status','product_state','workload_class'] as $key){if(!isset($filters[$key])||$filters[$key]==='')continue;if($key==='employee_id'){$where[]='employee_id=?';$params[]=(int)$filters[$key];}elseif($key==='priority'){$where[]='priority=?';$params[]=(string)$filters[$key];}else{$where[]="JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.".$key."'))=?";$params[]=(string)$filters[$key];}}$limit=max(1,min(10000,$limit));$s=$this->pdo->prepare('SELECT * FROM epi_employee_evidence WHERE '.implode(' AND ',$where).' ORDER BY occurred_at DESC,id DESC LIMIT '.$limit);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    private function metadata(array $row): array{$m=json_decode((string)($row['metadata_json']??''),true);return is_array($m)?$m:[];}
    private function stats(array $values): array{if(!$values)return ['average'=>null,'median'=>null,'fastest'=>null,'slowest'=>null,'count'=>0,'status'=>'insufficient_historical_data'];sort($values,SORT_NUMERIC);$n=count($values);$mid=intdiv($n,2);return ['average'=>round(array_sum($values)/$n,2),'median'=>$n%2?$values[$mid]:round(($values[$mid-1]+$values[$mid])/2,2),'fastest'=>$values[0],'slowest'=>$values[$n-1],'count'=>$n,'status'=>'verified'];}
    private function activityCount(array $filters): int{list($from,$to)=$this->period($filters);$where=["module='Packing List'",'occurred_at>=?','occurred_at<?'];$params=[$from->format('Y-m-d 00:00:00'),$to->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00')];if(isset($filters['employee_id'])&&$filters['employee_id']!==''){$where[]='employee_id=?';$params[]=(int)$filters['employee_id'];}$s=$this->pdo->prepare('SELECT COUNT(*) FROM epi_employee_activity WHERE '.implode(' AND ',$where));$s->execute($params);return (int)$s->fetchColumn();}
    private function periodPayload(array $filters): array{list($from,$to)=$this->period($filters);return ['from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d')];}
    private function period(array $filters): array{$today=new DateTimeImmutable('today');$key=(string)($filters['period']??'previous_month');if($key==='custom'&&!empty($filters['date_from'])&&!empty($filters['date_to']))return[new DateTimeImmutable($filters['date_from']),new DateTimeImmutable($filters['date_to'])];$map=['today'=>[$today,$today],'yesterday'=>[$today->modify('-1 day'),$today->modify('-1 day')],'this_week'=>[$today->modify('monday this week'),$today],'last_week'=>[$today->modify('monday last week'),$today->modify('sunday last week')],'this_month'=>[$today->modify('first day of this month'),$today],'previous_month'=>[$today->modify('first day of last month'),$today->modify('last day of last month')],'quarter'=>[$today->modify('-'.(((int)$today->format('n')-1)%3).' months')->modify('first day of this month'),$today],'year'=>[$today->setDate((int)$today->format('Y'),1,1),$today]];return $map[$key]??$map['previous_month'];}
}
