<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;

/** Physical attendance requires a reviewed record; login duration is not attendance. */
final class AttendanceEvidenceProjector
{
    public static function employee(PDO $db,int $employee,string $from,string $to,array &$coverage):array
    {
        if(!isset($coverage['attendance']))return [];
        $tables=(int)V2Store::one($db,"SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME IN('epi_attendance_schedules','kpi_portal_presence_reviews')")['n'];
        if($tables!==2){self::missing($coverage,'Approved schedule or physical-attendance source is unavailable.');return [];}
        $q=$db->prepare('SELECT * FROM epi_attendance_schedules WHERE employee_id=? AND approved_by IS NOT NULL
            AND effective_from<? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from,id');
        $q->execute([$employee,substr($to,0,10),substr($from,0,10)]);$schedules=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$schedules){self::missing($coverage,'No explicitly approved employee attendance schedule.');return [];}
        $q=$db->prepare('SELECT * FROM kpi_portal_presence_reviews WHERE employee_id=? AND evidence_date>=? AND evidence_date<? ORDER BY id');
        $q->execute([$employee,substr($from,0,10),substr($to,0,10)]);$reviews=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$reviews[$r['evidence_date']]=$r;$q->closeCursor();
        $out=[];$now=Support::timestamp()->format('Y-m-d H:i:s');
        for($day=Support::timestamp($from);$day->format('Y-m-d H:i:s')<$to;$day=$day->modify('+1 day')){
            $date=$day->format('Y-m-d');$matches=[];
            foreach($schedules as $s)if((int)$s['day_of_week']===(int)$day->format('N') && $s['effective_from']<=$date
                && (empty($s['effective_to'])||$s['effective_to']>=$date))$matches[]=$s;
            if(!$matches)continue;
            if(count($matches)!==1){self::missing($coverage,'Overlapping attendance schedules require review.');continue;}
            $s=$matches[0];$start=$date.' '.$s['scheduled_start'];$due=$date.' '.$s['scheduled_end'];
            if(!$s['scheduled_start']||!$s['scheduled_end']||$due<=$start){self::missing($coverage,'Invalid attendance schedule.');continue;}
            if($due>$now)continue;
            if(($s['created_at']??'9999')>$start){self::missing($coverage,'Attendance schedule was not established prospectively.');continue;}
            if(V2Store::absence($db,$employee,$start))continue;
            $review=$reviews[$date]??null;$source=$review?(json_decode($review['source_snapshot_json'],true)?:[]):[];
            $activation=V2Store::activation($db);
            if(!$review || (int)$review['reviewed_by']!==(int)$activation['approved_by'] || empty($source['attendance_evidence_reference'])
                || empty($review['owner_note']) || !in_array($review['classification'],['confirmed_present','confirmed_late_arrival','confirmed_unapproved_absence','approved_absence','rest_day','public_holiday'],true)){
                self::missing($coverage,'Physical attendance is not confirmed. Portal activity is not a substitute.');continue;
            }
            if(in_array($review['classification'],['approved_absence','rest_day','public_holiday'],true))continue;
            $present=$review['classification']!=='confirmed_unapproved_absence';
            foreach(['presence','punctuality'] as $metric){
                if(!isset($coverage['attendance'][$metric]) || ($metric==='punctuality'&&!$present))continue;
                $success=$metric==='presence'?$present:$review['classification']==='confirmed_present';
                $approved=($source['score_eligible_at_capture']??false)===true;
                $out[]=['employee_id'=>$employee,'category'=>'attendance','metric'=>$metric,
                    'opportunity_key'=>'attendance:'.$employee.':'.$date.':'.$metric,
                    'root_incident_id'=>$success?null:'attendance:'.$employee.':'.$date,
                    'module'=>'Attendance','source_reference'=>'Attendance '.$date,'starts_at'=>$start,'due_at'=>$due,
                    'deadline_at'=>$metric==='punctuality'?$start:$due,'observed_at'=>$review['reviewed_at'],
                    'fulfilled_at'=>null,'fulfilled_by'=>null,'eligible'=>true,'responsibility_confirmed'=>true,
                    'outcome'=>$success?'success':'failure','metadata'=>['excluded_from_scoring'=>!$approved,'mode'=>$approved?'approved_prospective':'shadow'],
                    'ownership'=>['employee_id'=>$employee,'effective_from'=>$start,'effective_to'=>null,
                        'assigned_by'=>(int)$s['approved_by'],'source'=>'approved_attendance_schedule','accepted_at'=>$s['created_at']],
                    'expected'=>$metric==='presence'?'Attend the approved scheduled shift':'Arrive by the approved scheduled start',
                    'actual'=>str_replace('_',' ',$review['classification']).': '.$review['owner_note'],
                    'supporting_evidence'=>[['source'=>$source['attendance_evidence_reference'],'reviewer'=>$review['reviewed_by'],'reviewed_at'=>$review['reviewed_at']]]];
            }
        }
        return $out;
    }
    private static function missing(array &$coverage,string $reason):void
    {
        foreach($coverage['attendance'] as &$metric)$metric=['complete'=>false,'reason'=>$reason];unset($metric);
    }
}
