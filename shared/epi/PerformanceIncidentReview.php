<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;
require_once __DIR__.'/IncidentCorrelation.php';
require_once __DIR__.'/OperationalPerformanceProjector.php';

/** Review support for the EXISTING employee profile. Reads never write. */
final class PerformanceIncidentReview
{
    public static function read(PDO $db,int $employee,string $period):array
    {
        if($employee<1||!preg_match('/^20\d{2}-(0[1-9]|1[0-2])-01$/D',$period))throw new RuntimeException('Invalid review period.');
        $from=$period.' 00:00:00';$to=Support::timestamp($from)->modify('+1 month')->format('Y-m-d H:i:s');
        $q=$db->prepare('SELECT r.*,i.occurred_at FROM epi_v2_quality_revisions r JOIN epi_v2_performance_incidents i
            ON i.root_incident_id=r.root_incident_id WHERE r.superseded_at IS NULL AND i.occurred_at>=? AND i.occurred_at<?
            AND (r.employee_id=? OR JSON_UNQUOTE(JSON_EXTRACT(r.snapshot_json,\'$.source_record.attributed_employee_id\'))=?
            OR JSON_UNQUOTE(JSON_EXTRACT(r.snapshot_json,\'$.source_record.responsible_employee_id\'))=?) ORDER BY i.occurred_at,r.id');
        $q->execute([$from,$to,$employee,(string)$employee,(string)$employee]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $reviews=[];$names=[];
        foreach($db->query('SELECT id,full_name FROM ops_employees')->fetchAll(PDO::FETCH_ASSOC) as $person)$names[(int)$person['id']]=$person['full_name'];
        foreach($rows as $row){
            $s=json_decode($row['snapshot_json'],true)?:[];$source=$s['source_record']??[];
            $reviews[]=['source_key'=>$row['root_incident_id'],'revision_id'=>(int)$row['id'],
                'title'=>$source['error_title']??('Error #'.($source['id']??'?')),'occurred_at'=>$row['occurred_at'],
                'category'=>$source['category']??'Unclassified','responsibility_type'=>$source['attribution_type']??'unconfirmed',
                'responsible_employee_id'=>$s['responsible_employee_id']??null,'reporter_employee_id'=>$s['reporter_employee_id']??null,
                'responsible_name'=>$names[(int)($s['responsible_employee_id']??0)]??'Not confirmed',
                'reporter_name'=>$names[(int)($s['reporter_employee_id']??0)]??'Not recorded',
                'reviewer_name'=>$names[(int)($s['reviewer_id']??0)]??'Not reviewed',
                'reviewer_id'=>$s['reviewer_id']??null,'reviewed_at'=>$s['reviewed_at']??null,
                'eligible'=>(bool)$row['eligible']&&empty($s['excluded_from_scoring']),
                'exclusion_reason'=>$s['exclusion_reason']??(!empty($s['excluded_from_scoring'])?'Not approved for scoring':null),
                'correlation'=>IncidentCorrelation::current($db,$row['root_incident_id'])];
        }
        $assignment=V2Store::one($db,'SELECT s.policy_json FROM epi_v2_scorecard_assignments a JOIN epi_v2_scorecard_documents s
            ON s.version=a.scorecard_version WHERE a.employee_id=? AND a.period_start=?',[$employee,$period]);
        $policy=$assignment?(json_decode($assignment['policy_json'],true)?:[]):[];
        $q=$db->prepare('SELECT opportunity_key,object_reference,completed_at,source_snapshot_json FROM epi_v2_completed_work_units
            WHERE employee_id=? AND completed_at>=? AND completed_at<? ORDER BY completed_at,opportunity_key');
        $q->execute([$employee,$from,$to]);$units=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$work=[];
        foreach($units as $unit){
            $snapshot=json_decode($unit['source_snapshot_json'],true)?:[];
            if(($snapshot['score_eligible_at_capture']??false)!==true)continue;
            $targets=[];
            foreach(OperationalPerformanceProjector::unitTargets($unit['opportunity_key'],$policy['role']??'') as [$category,$metric]){
                if(!isset($policy['categories'][$category]['metrics'][$metric]))continue;
                $targets[]=['category'=>$category,'metric'=>$metric,'label'=>($policy['categories'][$category]['label']??$category).
                    ' — '.($policy['categories'][$category]['metrics'][$metric]['label']??$metric)];
            }
            if($targets)$work[]=['key'=>$unit['opportunity_key'],'reference'=>$unit['object_reference'],'completed_at'=>$unit['completed_at'],'targets'=>$targets];
        }
        return ['incidents'=>$reviews,'work'=>$work];
    }

    public static function save(PDO $db,int $employee,string $period,array $input,int $reviewer,string $at):void
    {
        V2Store::transaction($db,function()use($db,$employee,$period,$input,$reviewer,$at):void{
            // Same lock as quality capture prevents a concurrent attribution change.
            $source=(string)($input['source_key']??'');
            if(!preg_match('/^error:[1-9]\d*$/D',$source))throw new RuntimeException('Choose an existing quality incident.');
            V2Store::lock($db,$source);
            $review=self::read($db,$employee,$period);$incident=null;$work=null;
            foreach($review['incidents'] as $row)if($row['source_key']===$source)$incident=$row;
            if(!$incident||!$incident['eligible']||(int)$incident['responsible_employee_id']!==$employee)
                throw new RuntimeException('Confirm employee responsibility in Error Log before linking performance evidence.');
            if($incident['revision_id']!==(int)($input['revision_id']??0))throw new RuntimeException('This incident changed. Refresh and review its latest evidence.');
            foreach($review['work'] as $row)if($row['key']===($input['opportunity_key']??''))$work=$row;
            if(!$work)throw new RuntimeException('Choose verified work belonging to this employee and reporting period.');
            $valid=false;
            foreach($work['targets'] as $target)if($target['category']===($input['category_key']??'')&&$target['metric']===($input['metric_key']??''))$valid=true;
            if(!$valid)throw new RuntimeException('This work does not supply the selected performance measure.');
            $root=(string)($input['root_incident_id']??$source);
            if($root!==$source){
                $existing=V2Store::one($db,'SELECT * FROM epi_v2_incident_correlations WHERE root_incident_id=? AND superseded_at IS NULL LIMIT 1',[$root]);
                if(!$existing||(int)$existing['employee_id']!==$employee||$existing['opportunity_key']!==$work['key']
                    ||$existing['category_key']!==$input['category_key']||$existing['metric_key']!==$input['metric_key'])
                    throw new RuntimeException('A shared root must describe this same employee, work and performance measure.');
            }
            $destination=['employee_id'=>$employee,'opportunity_key'=>$work['key'],'category_key'=>$input['category_key'],'metric_key'=>$input['metric_key']];
            $previous=$incident['correlation'];
            if($previous&&$previous['root_incident_id']===$root){
                IncidentCorrelation::correctRoot($db,$root,$destination,$reviewer,(string)($input['reason']??''),$at);
            }else{
                IncidentCorrelation::link($db,$destination+['source_key'=>$source,'root_incident_id'=>$root],$reviewer,(string)($input['reason']??''),$at);
            }
            // A repeated confirmation still records the owner's explanation.
            // Do not return "saved / queued" while silently discarding it.
            V2Store::audit($db,'performance-review|'.$source,$reviewer,(string)$input['reason'],$previous,
                ['destination'=>$destination,'root_incident_id'=>$root,'quality_revision_id'=>$incident['revision_id'],'reviewed_at'=>$at]);
            PerformanceRefreshRuntime::invalidate($db,'owner performance evidence review');
        });
    }
}
