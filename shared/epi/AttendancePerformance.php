<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class AttendancePerformance
{
    private PDO $pdo;
    private BusinessTimeEngine $businessTime;
    private DateTimeZone $zone;
    private array $settings = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->businessTime = new BusinessTimeEngine($pdo);
        $this->zone = new DateTimeZone('Africa/Windhoek');
    }

    public function getSummary(array $filters = []): array
    {
        $days = $this->getDailyAttendance($filters);
        $summary = ['scheduled_days'=>0,'present_days'=>0,'approved_leave_days'=>0,'on_time_days'=>0,'within_grace_days'=>0,'late_days'=>0,'possible_unverified_absence'=>0,'insufficient_evidence'=>0,'portal_active_minutes'=>0,'review_required'=>0];
        foreach ($days as $day) {
            if (!empty($day['scheduled'])) $summary['scheduled_days']++;
            if (!empty($day['present'])) $summary['present_days']++;
            if ($day['classification'] === 'Approved Leave') $summary['approved_leave_days']++;
            if ($day['classification'] === 'On Time') $summary['on_time_days']++;
            if ($day['classification'] === 'Within Grace') $summary['within_grace_days']++;
            if ($day['classification'] === 'Late') $summary['late_days']++;
            if ($day['classification'] === 'Possible Unverified Absence') $summary['possible_unverified_absence']++;
            if ($day['classification'] === 'Insufficient Evidence') $summary['insufficient_evidence']++;
            $summary['portal_active_minutes'] += (int)$day['portal_active_minutes'];
            if (!empty($day['review_required'])) $summary['review_required']++;
        }
        $summary['portal_active_time_label'] = $this->durationLabel($summary['portal_active_minutes']);
        $summary['explanation'] = 'Portal active time is an estimate from meaningful authenticated actions. It is not total hours worked or proof of physical attendance.';
        return $summary;
    }

    public function getEmployeeSummary(array $filters = []): array
    {
        $rows = [];
        foreach ($this->employeeOptions($filters) as $employee) {
            $local = $filters; $local['employee_id'] = (int)$employee['id'];
            $rows[] = $employee + $this->getSummary($local);
        }
        return $rows;
    }

    public function getDailyAttendance(array $filters = []): array
    {
        [$from,$to] = $this->period($filters);
        $employees = $this->employeeOptions($filters);
        $sessions = $this->getSessions($filters);
        $activity = $this->meaningfulActivity($filters);
        $bySession = []; foreach ($sessions as $row) $bySession[(int)$row['employee_id']][$row['business_date']][] = $row;
        $byActivity = []; foreach ($activity as $row) $byActivity[(int)$row['employee_id']][substr((string)$row['occurred_at'],0,10)][] = $row;
        $rows = [];
        for ($date=$from; $date <= $to; $date=$date->add(new DateInterval('P1D'))) {
            foreach ($employees as $employee) {
                $id=(int)$employee['id']; $dateKey=$date->format('Y-m-d');
                $schedule=$this->scheduleFor($employee,$date); $leave=$this->approvedLeave($id,$dateKey);
                $daySessions=$bySession[$id][$dateKey]??[]; $dayActivity=$byActivity[$id][$dateKey]??[];
                $first=$this->earliest($daySessions,'login_local');
                $last=$this->latest($dayActivity,'occurred_at') ?: $this->latest($daySessions,'last_seen_local');
                $active=$this->activeMinutes($dayActivity,$schedule);
                $classification='Not Scheduled'; $present=false; $review=false; $lateMinutes=null;
                if ($leave) $classification='Approved Leave';
                elseif ($schedule !== null) {
                    if ($first) {
                        $present=true; $start=new DateTimeImmutable($dateKey.' '.$schedule['start'],$this->zone);
                        $arrival=new DateTimeImmutable($first,$this->zone); $lateMinutes=max(0,(int)floor(($arrival->getTimestamp()-$start->getTimestamp())/60));
                        $grace=$this->settingInt('attendance_late_grace_minutes',10);
                        $classification=$lateMinutes===0?'On Time':($lateMinutes<=$grace?'Within Grace':'Late');
                        $review=$classification==='Late' && $lateMinutes >= $this->settingInt('attendance_significantly_late_minutes',60);
                    } elseif ($date < new DateTimeImmutable('today',$this->zone)) {
                        $classification=($dayActivity ? 'Insufficient Evidence' : 'Possible Unverified Absence'); $review=true;
                    } else $classification='Insufficient Evidence';
                }
                $rows[]=['employee_id'=>$id,'employee_name'=>$employee['full_name'],'role_key'=>$employee['role_key'],'business_date'=>$dateKey,
                    'scheduled'=>$schedule!==null,'schedule'=>$schedule,'approved_leave'=>$leave,'first_login'=>$first,'last_meaningful_activity'=>$last,
                    'session_count'=>count($daySessions),'meaningful_activity_count'=>count($dayActivity),'portal_active_minutes'=>$active,
                    'portal_active_time_label'=>$this->durationLabel($active),'present'=>$present,'late_minutes'=>$lateMinutes,
                    'classification'=>$classification,'review_required'=>$review,
                    'departure_status'=>'Insufficient Evidence','data_note'=>'Portal activity is not total working time. Automatic polling and passive presence heartbeats are excluded.'];
            }
        }
        return $rows;
    }

    public function getSessions(array $filters = []): array
    {
        [$from,$to]=$this->period($filters); $where=['s.login_at < ?','COALESCE(s.logout_at,s.last_seen_at,s.login_at) >= ?'];
        $params=[$to->add(new DateInterval('P1D'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
        if (($filters['employee_id']??'')!=='') {$where[]='s.user_id=?';$params[]=(int)$filters['employee_id'];}
        $sql="SELECT s.id,s.user_id employee_id,e.full_name employee_name,r.role_key,s.login_at,s.last_seen_at,s.logout_at,s.explicit_logout_at,s.end_reason FROM kpi_sessions s LEFT JOIN ops_employees e ON e.id=s.user_id LEFT JOIN ops_roles r ON r.id=e.role_id WHERE ".implode(' AND ',$where).' ORDER BY s.login_at DESC';
        try {$stmt=$this->pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];} catch(Throwable $e){return [];}
        foreach($rows as &$row){$row['login_local']=$this->utcToLocal($row['login_at']);$row['last_seen_local']=$this->utcToLocal($row['last_seen_at']);$row['logout_local']=$this->utcToLocal($row['logout_at']);$row['business_date']=substr($row['login_local'],0,10);$end=$row['logout_local']?:$row['last_seen_local'];$row['session_minutes']=$end?(int)max(0,(strtotime($end)-strtotime($row['login_local']))/60):0;$row['session_status']=$row['logout_at']?'ended':'open';unset($row['login_at'],$row['last_seen_at'],$row['logout_at']);}
        return $rows;
    }

    public function getOnlineEmployees(array $filters = []): array
    {
        $recent=$this->settingInt('attendance_recent_activity_minutes',15); $idle=$this->settingInt('attendance_idle_threshold_minutes',15);
        try {$rows=$this->pdo->query("SELECT p.employee_id,e.full_name,r.role_key,p.last_seen_at,p.page_url FROM ops_board_presence p JOIN ops_employees e ON e.id=p.employee_id LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND COALESCE(r.role_key,'') NOT IN ('owner_admin','accountant') AND LOWER(CONCAT_WS(' ',e.full_name,e.email,COALESCE(r.role_key,''))) NOT REGEXP 'karina|kaarina|test|preview' ORDER BY p.last_seen_at DESC")->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){return [];}
        $now=new DateTimeImmutable('now',$this->zone);$out=[];
        foreach($rows as $row){if(($filters['employee_id']??'')!==''&&(int)$filters['employee_id']!==(int)$row['employee_id'])continue;$seen=new DateTimeImmutable((string)$row['last_seen_at'],$this->zone);$minutes=max(0,(int)(($now->getTimestamp()-$seen->getTimestamp())/60));$row['minutes_since_presence']=$minutes;$row['presence_status']=$minutes<=$idle?'Online / recently active':($minutes<=$recent+45?'Online / idle':'Offline');$row['warning']='Presence is passive and is not counted as meaningful work activity.';$out[]=$row;}
        return $out;
    }

    public function getArrivalPerformance(array $filters = []): array { return array_values(array_filter($this->getDailyAttendance($filters),fn($r)=>$r['scheduled'])); }
    public function getPortalActivity(array $filters = []): array { return ['summary'=>$this->getSummary($filters),'daily'=>$this->getDailyAttendance($filters),'timeline'=>$this->getTimeline($filters,250)]; }

    public function getNotificationResponse(array $filters = []): array
    {
        [$from,$to]=$this->period($filters);$where=['n.created_at>=?','n.created_at<?'];$params=[$from->format('Y-m-d 00:00:00'),$to->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00')];if(($filters['employee_id']??'')!==''){$where[]='nr.employee_id=?';$params[]=(int)$filters['employee_id'];}
        try{$s=$this->pdo->prepare('SELECT nr.employee_id,e.full_name,n.id notification_id,n.module,n.priority,n.created_at,nr.delivered_at,nr.read_at,nr.cleared_at,TIMESTAMPDIFF(MINUTE,n.created_at,nr.read_at) response_minutes FROM notifications n JOIN notification_recipients nr ON nr.notification_id=n.id LEFT JOIN ops_employees e ON e.id=nr.employee_id WHERE '.implode(' AND ',$where).' ORDER BY n.created_at DESC');$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){$rows=[];}
        $read=array_values(array_filter($rows,fn($r)=>$r['read_at']!==null));$avg=$read?round(array_sum(array_map(fn($r)=>(int)$r['response_minutes'],$read))/count($read),1):null;
        return ['delivered'=>count($rows),'read'=>count($read),'unread'=>count($rows)-count($read),'average_response_minutes'=>$avg,'records'=>$rows];
    }

    public function getCurrentIssues(array $filters = []): array
    {
        $derived=array_values(array_filter($this->getDailyAttendance($filters),fn($r)=>$r['review_required']));
        try{$s=$this->pdo->query("SELECT * FROM epi_attendance_exceptions WHERE review_status='pending_review' ORDER BY starts_at DESC LIMIT 250");$manual=$s->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){$manual=[];}
        return ['derived_review_events'=>$derived,'recorded_exceptions'=>$manual,'note'=>'These are review events only. They are not absence findings, deductions, or scores.'];
    }

    public function getEvidence(array $filters = [], int $limit = 250): array
    {
        $where=["module IN ('Attendance','Portal Activity','Notifications')"];$params=[];if(($filters['employee_id']??'')!==''){$where[]='employee_id=?';$params[]=(int)$filters['employee_id'];}[$from,$to]=$this->period($filters);$where[]='occurred_at>=?';$where[]='occurred_at<?';$params[]=$from->format('Y-m-d 00:00:00');$params[]=$to->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00');$s=$this->pdo->prepare('SELECT * FROM epi_employee_evidence WHERE '.implode(' AND ',$where).' ORDER BY occurred_at DESC,id DESC LIMIT '.max(1,min(1000,$limit)));$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function getTimeline(array $filters = [], int $limit = 250): array { return array_slice($this->meaningfulActivity($filters),0,max(1,min(1000,$limit))); }

    public function employeeOptions(array $filters=[]): array
    {
        $where=["e.status='active'","COALESCE(r.role_key,'') NOT IN ('owner_admin','accountant')","LOWER(CONCAT_WS(' ',e.full_name,e.email,COALESCE(r.role_key,''))) NOT REGEXP 'karina|kaarina|test|preview'"];$params=[];if(($filters['employee_id']??'')!==''){$where[]='e.id=?';$params[]=(int)$filters['employee_id'];}if(($filters['role']??'')!==''){$where[]='r.role_key=?';$params[]=$filters['role'];}$sql="SELECT e.id,e.full_name,e.status,r.role_key,r.name role_name FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE ".implode(' AND ',$where)." ORDER BY e.full_name";$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    private function meaningfulActivity(array $filters): array
    {
        [$from,$to]=$this->period($filters);$where=['occurred_at>=?','occurred_at<?',"LOWER(activity_type) NOT REGEXP 'heartbeat|poll|refresh|presence|page_view'","LOWER(activity_source) NOT REGEXP 'heartbeat|poll|auto_refresh|background|presence'"];$params=[$from->format('Y-m-d 00:00:00'),$to->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00')];if(($filters['employee_id']??'')!==''){$where[]='employee_id=?';$params[]=(int)$filters['employee_id'];}$s=$this->pdo->prepare('SELECT * FROM epi_employee_activity WHERE '.implode(' AND ',$where).' ORDER BY occurred_at DESC,id DESC');$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    private function scheduleFor(array $employee,DateTimeImmutable $date): ?array
    {
        $dow=(int)$date->format('N');$dateKey=$date->format('Y-m-d');
        try{$s=$this->pdo->prepare("SELECT * FROM epi_attendance_schedules WHERE day_of_week=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) AND (employee_id=? OR (employee_id IS NULL AND role_key=?)) ORDER BY employee_id IS NOT NULL DESC,id DESC LIMIT 1");$s->execute([$dow,$dateKey,$dateKey,(int)$employee['id'],(string)$employee['role_key']]);$r=$s->fetch(PDO::FETCH_ASSOC);if($r){if(!$r['scheduled_start']||!$r['scheduled_end'])return null;return ['start'=>substr($r['scheduled_start'],0,5),'end'=>substr($r['scheduled_end'],0,5),'break_start'=>$r['break_start']?substr($r['break_start'],0,5):null,'break_end'=>$r['break_end']?substr($r['break_end'],0,5):null,'source'=>'configured'];}}catch(Throwable $e){}
        $window=$this->businessTime->windowForDate($date);if($window===null)return null;return ['start'=>$window[0]->format('H:i'),'end'=>$window[1]->format('H:i'),'break_start'=>$this->setting('attendance_lunch_start','12:00'),'break_end'=>$this->setting('attendance_lunch_end','13:00'),'source'=>'business-calendar-default'];
    }

    private function approvedLeave(int $employeeId,string $date): bool
    {
        try{
            $s=$this->pdo->prepare('SELECT hr_employee_id FROM employee_user_links WHERE ops_employee_id=? LIMIT 1');
            $s->execute([$employeeId]);$hrId=(int)$s->fetchColumn();if($hrId<=0)return false;
            if(function_exists('ops_hr_rows')){
                $rows=\ops_hr_rows("SELECT COUNT(*) total FROM leave_requests WHERE employee_id=? AND LOWER(status)='approved' AND ? BETWEEN DATE(start_date) AND DATE(end_date)",[$hrId,$date]);
                return (int)($rows[0]['total']??0)>0;
            }
        }catch(Throwable $e){}
        return false;
    }

    private function activeMinutes(array $rows,?array $schedule): int
    {
        if(!$rows)return 0;usort($rows,fn($a,$b)=>strcmp($a['occurred_at'],$b['occurred_at']));$threshold=$this->settingInt('attendance_idle_threshold_minutes',15);$total=0;$endLimit=$schedule?strtotime(substr($rows[0]['occurred_at'],0,10).' '.$schedule['end']):PHP_INT_MAX;foreach($rows as$i=>$row){$start=strtotime($row['occurred_at']);$next=isset($rows[$i+1])?strtotime($rows[$i+1]['occurred_at']):$start+$threshold*60;$end=min($start+$threshold*60,$next,$endLimit);if($end>$start)$total+=(int)(($end-$start)/60);}return $total;
    }

    private function period(array $f): array
    {
        $today=new DateTimeImmutable('today',$this->zone);$p=(string)($f['period']??'previous_month');if($p==='custom'&&!empty($f['date_from'])&&!empty($f['date_to']))return[new DateTimeImmutable($f['date_from'],$this->zone),new DateTimeImmutable($f['date_to'],$this->zone)];
        switch($p){
            case 'today':return[$today,$today];
            case 'yesterday':return[$today->modify('-1 day'),$today->modify('-1 day')];
            case 'this_week':return[$today->modify('monday this week'),$today];
            case 'last_week':return[$today->modify('monday last week'),$today->modify('sunday last week')];
            case 'this_month':return[$today->modify('first day of this month'),$today];
            case 'quarter':return[$today->modify('-2 months')->modify('first day of this month'),$today];
            case 'year':return[$today->setDate((int)$today->format('Y'),1,1),$today];
            default:return[$today->modify('first day of last month'),$today->modify('last day of last month')];
        }
    }
    private function setting(string $key,string $default):string{if(!array_key_exists($key,$this->settings)){try{$s=$this->pdo->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=?');$s->execute([$key]);$v=$s->fetchColumn();$this->settings[$key]=$v===false?$default:(string)$v;}catch(Throwable $e){$this->settings[$key]=$default;}}return$this->settings[$key];}
    private function settingInt(string $key,int $default):int{return max(1,(int)$this->setting($key,(string)$default));}
    private function utcToLocal($value):?string{if(!$value)return null;return(new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))->setTimezone($this->zone)->format('Y-m-d H:i:s');}
    private function earliest(array $rows,string $key):?string{$v=array_values(array_filter(array_column($rows,$key)));sort($v);return$v[0]??null;}
    private function latest(array $rows,string $key):?string{$v=array_values(array_filter(array_column($rows,$key)));rsort($v);return$v[0]??null;}
    private function durationLabel(int $minutes):string{return sprintf('%dh %02dm',intdiv($minutes,60),$minutes%60);}
}
