<?php
declare(strict_types=1);

function portal_presence_online_seconds(): int { return 300; }
function portal_presence_recent_seconds(): int { return 300; }

// Widget connection leases are not attendance, paid time or KPI sessions.
const PORTAL_WIDGET_LEASE_SECONDS = 120;
function portal_widget_key(): string {
    return hash('sha256', 'online:'.session_id());
}
function portal_widget_touch(PDO $db,int $employee,string $key,int $now,int $login,int $expires): void {
    $sql="INSERT INTO portal_online_leases(employee_id,connection_key,started_at,seen_at,expires_at) VALUES(?,?,?,?,?)
          ON DUPLICATE KEY UPDATE started_at=IF(expires_at<=VALUES(seen_at),VALUES(seen_at),started_at),
          seen_at=VALUES(seen_at),expires_at=VALUES(expires_at)";
    $args=[$employee,$key,$login>0 && $now-$login<PORTAL_WIDGET_LEASE_SECONDS?min($login,$now):$now,$now,min($expires,$now+PORTAL_WIDGET_LEASE_SECONDS)];
    try {$db->prepare($sql)->execute($args);}
    catch(PDOException $e){
        if($e->getCode()!=='42S02')throw $e;
        // Only on first use; existing employee, session and attendance tables are untouched.
        $db->exec("CREATE TABLE IF NOT EXISTS portal_online_leases(employee_id INT NOT NULL,connection_key CHAR(64) CHARACTER SET ascii NOT NULL,
          started_at BIGINT NOT NULL,seen_at BIGINT NOT NULL,expires_at BIGINT NOT NULL,
          PRIMARY KEY(employee_id,connection_key),INDEX active_leases(expires_at,employee_id)) ENGINE=InnoDB");
        $db->prepare($sql)->execute($args);
    }
    if(random_int(1,100)===1)$db->prepare('DELETE FROM portal_online_leases WHERE expires_at<? LIMIT 250')->execute([$now-86400]);
}
function portal_widget_roster(PDO $db,int $now,bool $admin): array {
    $q=$db->prepare("SELECT e.id,e.full_name".($admin?",r.name role_name,MIN(l.started_at) started_at":"")."
        FROM portal_online_leases l JOIN ops_employees e ON e.id=l.employee_id JOIN ops_roles r ON r.id=e.role_id
        WHERE e.status='active' AND l.expires_at>? ".($admin?"":"AND r.role_key<>'owner_admin'")."
        GROUP BY e.id,e.full_name".($admin?",r.name":"")." ORDER BY e.full_name,e.id");
    $q->execute([$now]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $person=['id'=>(int)$r['id'],'name'=>$r['full_name'],'presence'=>'online'];
        if($admin)$person+=['role'=>$r['role_name'],'session_duration_seconds'=>max(0,$now-(int)$r['started_at'])];
        $out[]=$person;
    }
    return $out;
}
function portal_widget_logout(PDO $db,int $employee,string $key): void {
    try{$db->prepare('DELETE FROM portal_online_leases WHERE employee_id=? AND connection_key=?')->execute([$employee,$key]);}
    catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;}
}

function portal_presence_employee(int $employeeId): array {
    $online=portal_presence_online_seconds(); $recent=portal_presence_recent_seconds();
    $row=ops_rows("SELECT e.id,e.full_name,r.name role_name,bp.page,bp.path,bp.last_seen_at,TIMESTAMPDIFF(SECOND,bp.last_seen_at,NOW()) seconds_since_activity,DATE_ADD(s.login_at,INTERVAL 2 HOUR) session_started_at,TIMESTAMPDIFF(SECOND,s.login_at,UTC_TIMESTAMP()) session_duration_seconds,s.id session_id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id LEFT JOIN ops_board_presence bp ON bp.employee_id=e.id LEFT JOIN kpi_sessions s ON s.id=(SELECT latest.id FROM kpi_sessions latest WHERE latest.user_id=e.id AND latest.logout_at IS NULL ORDER BY latest.login_at DESC,latest.id DESC LIMIT 1) WHERE e.id=? AND e.status='active' LIMIT 1",[$employeeId])[0]??[];
    if(!$row)return ['available'=>false,'employee_id'=>$employeeId,'state'=>'unavailable'];
    $seconds=isset($row['seconds_since_activity'])?max(0,(int)$row['seconds_since_activity']):null;
    $state=$seconds!==null && $seconds<$online?'online':'offline';
    return ['available'=>true,'employee_id'=>(int)$row['id'],'employee_name'=>(string)$row['full_name'],'role'=>(string)$row['role_name'],'state'=>$state,'is_online'=>$state==='online','page'=>(string)($row['page']?:'Business Portal'),'path'=>(string)($row['path']??''),'last_seen_at'=>$row['last_seen_at']??null,'seconds_since_activity'=>$seconds,'session_started_at'=>$state==='online'?($row['session_started_at']??null):null,'session_duration_seconds'=>$state==='online'?max(0,(int)($row['session_duration_seconds']??0)):0,'session_id'=>$row['session_id']?(int)$row['session_id']:null,'online_threshold_seconds'=>$online,'recent_threshold_seconds'=>$recent,'identity_mapping'=>'ops_board_presence.employee_id = ops_employees.id = kpi_sessions.user_id','refreshed_at'=>(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format(DATE_ATOM)];
}
