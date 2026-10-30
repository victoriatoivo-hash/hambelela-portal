<?php
declare(strict_types=1);
namespace Hambelela\EPI;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/** Owner-directed duty intervals, not employee acceptance, attendance or work authorship. */
final class OwnerFrontCoverage
{
    public static function employees(PDO $db): array
    {
        return $db->query("SELECT e.id,e.full_name,r.name AS role_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key IN ('front_desk_admin','marketing_sales') ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function date(string $value): string
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Africa/Windhoek'));
        if(!$date || $date->format('Y-m-d')!==$value)throw new RuntimeException('Choose a valid date.');
        return $value;
    }

    public static function rows(PDO $db,string $day): array
    {
        self::date($day);
        $q=$db->prepare("SELECT d.*,e.full_name FROM epi_v2_duty_periods d JOIN ops_employees e ON e.id=d.employee_id WHERE d.duty_key='front_desk' AND d.superseded_at IS NULL AND d.effective_from<? AND (d.effective_to IS NULL OR d.effective_to>?) ORDER BY d.effective_from,d.id");
        $q->execute([$day.' 23:59:59',$day.' 00:00:00']);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function revision(array $rows): string
    {
        return hash('sha256',Support::json($rows));
    }

    /** Pure plan validation; saving today's plan never changes elapsed responsibility. */
    public static function intervals(array $input,string $now): array
    {
        $day=self::date((string)($input['day']??''));
        if($day<substr($now,0,10))throw new RuntimeException('Past coverage cannot be rewritten. Choose today or a future date.');
        $stamp=function(string $key)use($day,$input):string{
            $time=(string)($input[$key]??'');
            if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))throw new RuntimeException('Enter valid start and end times.');
            return $day.' '.$time.':00';
        };
        $start=$stamp('start');$end=$stamp('end');$primary=(int)($input['primary']??0);$cover=(int)($input['cover']??0);
        if($primary<1 || $end<=$start || $end<=$now)throw new RuntimeException('Choose a Front Desk employee and an end time after the start and current time.');
        $segments=[];
        if($cover>0){
            $lunchStart=$stamp('lunch_start');$lunchEnd=$stamp('lunch_end');
            if($cover===$primary || $lunchStart<$start || $lunchEnd>$end || $lunchEnd<=$lunchStart)throw new RuntimeException('Lunch cover must be a different employee, within the Front Desk hours.');
            $segments=[[$primary,$start,$lunchStart,'primary'],[$cover,$lunchStart,$lunchEnd,'coverage'],[$primary,$lunchEnd,$end,'primary']];
        }else $segments=[[$primary,$start,$end,'primary']];
        $result=[];
        foreach($segments as $s){$s[1]=max($s[1],$now);if($s[1]<$s[2])$result[]=$s;}
        return $result;
    }

    public static function save(PDO $db,?PDO $hr,int $owner,array $input,$clock=null): array
    {
        $authority=V2Store::one($db,"SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin'",[$owner]);
        if(!$authority)throw new RuntimeException('Only the Owner can manage Front Desk coverage.');
        $now=Support::timestamp($clock)->format('Y-m-d H:i:s');
        $segments=self::intervals($input,$now);$day=self::date((string)$input['day']);
        $reason=trim((string)($input['reason']??''));
        if(strlen($reason)>500)throw new RuntimeException('Keep the note within 500 characters.');
        return V2Store::transaction($db,function()use($db,$hr,$owner,$input,$now,$segments,$day,$reason){
            V2Store::lock($db,'duty|front_desk');
            $before=self::rows($db,$day);
            if(!hash_equals(self::revision($before),(string)($input['revision']??'')))throw new RuntimeException('This day was changed elsewhere. Reload the schedule before saving. Your form has been kept.');
            $eligible=array_column(self::employees($db),'id');
            foreach($segments as $s){
                if(!in_array($s[0],$eligible))throw new RuntimeException('Choose an active Front Desk or Marketing & Sales employee.');
                $absence=HrAbsenceEvidence::inspect($db,$hr,$s[0],$s[1]);
                if($absence['state']!=='no_approved_absence')throw new RuntimeException($absence['state']==='approved_absence'?'This employee has approved leave on the selected day. Choose another employee.':'HR availability could not be verified. Check the employee’s HR link and try again.');
                if(V2Store::one($db,"SELECT id FROM epi_v2_exceptions WHERE employee_id=? AND kind='absence' AND effective_from<? AND effective_to>? AND approved_at<=? LIMIT 1",[$s[0],$s[2],$s[1],$now]))throw new RuntimeException('This employee has an approved absence during the selected hours.');
            }
            $from=$segments[0][1];$to=$segments[count($segments)-1][2];
            foreach($before as $row){
                if($row['responsibility_level']==='secondary' || $row['effective_from']>=$to || ($row['effective_to']!==null && $row['effective_to']<=$from))continue;
                // Preserve the elapsed head and any future tail outside this explicit edit.
                if($row['effective_from']<$from)$db->prepare('UPDATE epi_v2_duty_periods SET effective_to=? WHERE id=?')->execute([$from,$row['id']]);
                else $db->prepare('UPDATE epi_v2_duty_periods SET superseded_at=? WHERE id=?')->execute([$now,$row['id']]);
                if($row['effective_to']===null || $row['effective_to']>$to)self::insert($db,array_replace($row,['effective_from'=>$to]));
            }
            foreach($segments as $s)self::insert($db,[
                'employee_id'=>$s[0],'effective_from'=>$s[1],'effective_to'=>$s[2],'responsibility_level'=>$s[3],
                'assigned_by'=>$owner,'accepted_by'=>null,'accepted_at'=>null,
                // Existing audited Owner-approved roster authority. No fabricated acceptance.
                'source'=>'approved_front_roster','shift_id'=>null,'exception_id'=>null,
            ]);
            $after=self::rows($db,$day);
            V2Store::audit($db,'duty|front_desk',$owner,'Owner Settings: daily and lunch coverage',$before,['day'=>$day,'changed_from'=>$from,'note'=>$reason,'schedule'=>$after]);
            return ['from'=>$from,'to'=>$to,'rows'=>$after];
        });
    }

    private static function insert(PDO $db,array $row): void
    {
        $db->prepare("INSERT INTO epi_v2_duty_periods(duty_uuid,duty_key,employee_id,responsibility_level,effective_from,effective_to,assigned_by,accepted_by,accepted_at,source,shift_id,exception_id) VALUES(?,'front_desk',?,?,?,?,?,?,?,?,?,?)")->execute([
            Support::uuid(),$row['employee_id'],$row['responsibility_level'],$row['effective_from'],$row['effective_to'],$row['assigned_by'],$row['accepted_by'],$row['accepted_at'],$row['source'],$row['shift_id']??null,$row['exception_id']??null,
        ]);
    }
}
