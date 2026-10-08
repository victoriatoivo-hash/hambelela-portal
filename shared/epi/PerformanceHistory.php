<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;

/** Read-only continuity adapter. Historical evidence is never a V2 scoring input.
 * Schema inspection supports older installations without silently inventing empty history.
 */
final class PerformanceHistory
{
    private $db;
    private $columns=[];
    public function __construct(PDO $db){$this->db=$db;}
    private function columns(string $table):array {
        if(!isset($this->columns[$table])){
            $q=$this->db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->execute([$table]);$this->columns[$table]=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
        }
        return $this->columns[$table];
    }
    public function read(int $employee,string $period,int $page=1):array {
        $end=Support::timestamp($period)->modify('+1 month')->format('Y-m-d');
        $out=['status'=>'history_source_unavailable','records'=>[],'saved_results'=>[], 'sources'=>[],
            'months'=>[],'total'=>null,'all_time_total'=>null,'page'=>max(1,$page),'page_size'=>100,
            'methodology'=>'Historical EPI Evidence / Legacy Performance — preserved, not re-scored as V2'];
        $cols=$this->columns('epi_employee_evidence');
        if(!array_diff(['id','employee_id','business_date','occurred_at','evidence_uuid','module','action'],$cols)){
            $q=$this->db->prepare('SELECT business_date,COUNT(*) total FROM epi_employee_evidence WHERE employee_id=? GROUP BY business_date ORDER BY business_date DESC');
            $q->execute([$employee]);$months=[];$total=0;
            $undated=0;
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
                $total+=(int)$row['total'];
                if(empty($row['business_date'])){$undated+=(int)$row['total'];continue;}
                $month=substr($row['business_date'],0,7);$months[$month]=($months[$month]??0)+(int)$row['total'];
            }
            $out['undated_records']=$undated;
            $q->closeCursor();$out['months']=$months;$out['all_time_total']=$total;$out['total']=$months[substr($period,0,7)]??0;
            $out['page']=min(max(1,$page),max(1,(int)ceil($out['total']/100)));
            $offset=($out['page']-1)*100;
            $q=$this->db->prepare('SELECT * FROM epi_employee_evidence WHERE employee_id=? AND business_date>=? AND business_date<? ORDER BY occurred_at DESC,id DESC LIMIT 100 OFFSET '.$offset);
            $q->execute([$employee,$period,$end]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            $events=[];
            if(!array_diff(['employee_id','evidence_uuid','confirmation_status','applied_at','reversed'],$this->columns('epi_performance_score_events')) && $rows){
                $ids=array_column($rows,'evidence_uuid');
                $s=$this->db->prepare('SELECT * FROM epi_performance_score_events WHERE employee_id=? AND evidence_uuid IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id');
                $s->execute(array_merge([$employee],$ids));foreach($s->fetchAll(PDO::FETCH_ASSOC) as $event)$events[$event['evidence_uuid']][]=$event;$s->closeCursor();
            }
            foreach($rows as $row){
                $meta=json_decode($row['metadata_json']??'{}',true);if(!is_array($meta))$meta=[];
                $linked=$events[$row['evidence_uuid']]??[];$effects=[];
                foreach($linked as $event)$effects[]=(($event['reversed']??0)?'Reversed':(!empty($event['applied_at'])?'Recorded as applied':'Not recorded as applied')).' · '.($event['confirmation_status']??'unknown').' · rule version '.($event['rule_version_id']??'not recorded');
                $out['records'][]=['key'=>'legacy:'.$row['evidence_uuid'],'evidence_uuid'=>$row['evidence_uuid'],
                    'date'=>$row['occurred_at'],'reference'=>$row['reference_number']??'', 'category'=>$row['module'],
                    'incident'=>($row['action_description']??null)?:$row['action'],'recorded_employee_id'=>$row['employee_id'],
                    'responsibility'=>'Historical association only; responsibility must be verified from original evidence',
                    'expected'=>$linked[0]['expected_result']??'Not recorded',
                    'actual'=>$linked[0]['actual_result']??($row['status_after']??'Not recorded'),
                    'resolution'=>$meta['resolution_status']??'Not recorded',
                    'score_effect'=>$effects?implode('; ',$effects):'No linked legacy score event; V2 effect not inferred',
                    'eligibility'=>$row['eligibility_state']??'Not recorded',
                    'exclusion_reason'=>$row['eligibility_reason']??($meta['exclusion_reason']??(!empty($meta['excluded_from_scoring'])?'Excluded from scoring':'Historical visibility only — not imported into V2 calculation'))];
            }
            $eligibility=in_array('eligibility_state',$cols,true)?'eligibility_state':"'unknown'";
            $q=$this->db->prepare('SELECT module,'.$eligibility.' eligibility,COUNT(*) records FROM epi_employee_evidence WHERE employee_id=? AND business_date>=? AND business_date<? GROUP BY module,'.$eligibility.' ORDER BY module');
            $q->execute([$employee,$period,$end]);$out['sources']=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            $out['status']='available';
        }
        $cols=$this->columns('epi_scoring_monthly_scores');
        if(!array_diff(['employee_id','score_year','score_month','final_hundredths'],$cols)){
            $q=$this->db->prepare('SELECT * FROM epi_scoring_monthly_scores WHERE employee_id=? ORDER BY score_year DESC,score_month DESC');
            $q->execute([$employee]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
                $out['saved_results'][]=['period'=>sprintf('%04d-%02d',$row['score_year'],$row['score_month']),
                    'recorded_score_hundredths'=>array_key_exists('official_score_hundredths',$row)?$row['official_score_hundredths']:$row['final_hundredths'],
                    'methodology'=>$row['calculation_version']??'Legacy methodology not recorded',
                    'status'=>$row['result_type']??($row['score_status']??'Historical result'),
                    'locked'=>!empty($row['locked']),'superseded'=>!empty($row['superseded']),
                    'confidence'=>$row['confidence_label']??'Not recorded'];
            }$q->closeCursor();
        }
        return $out;
    }
}
