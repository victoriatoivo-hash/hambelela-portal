<?php
declare(strict_types=1);

function portal_presence_online_seconds(): int { return 120; }
function portal_presence_recent_seconds(): int { return 900; }

function portal_presence_employee(int $employeeId): array {
    $online=portal_presence_online_seconds(); $recent=portal_presence_recent_seconds();
    $row=ops_rows("SELECT e.id,e.full_name,r.name role_name,bp.page,bp.path,bp.last_seen_at,TIMESTAMPDIFF(SECOND,bp.last_seen_at,NOW()) seconds_since_activity,DATE_ADD(s.login_at,INTERVAL 2 HOUR) session_started_at,TIMESTAMPDIFF(SECOND,s.login_at,UTC_TIMESTAMP()) session_duration_seconds,s.id session_id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id LEFT JOIN ops_board_presence bp ON bp.employee_id=e.id LEFT JOIN kpi_sessions s ON s.id=(SELECT latest.id FROM kpi_sessions latest WHERE latest.user_id=e.id AND latest.logout_at IS NULL ORDER BY latest.login_at DESC,latest.id DESC LIMIT 1) WHERE e.id=? AND e.status='active' LIMIT 1",[$employeeId])[0]??[];
    if(!$row)return ['available'=>false,'employee_id'=>$employeeId,'state'=>'unavailable'];
    $seconds=isset($row['seconds_since_activity'])?max(0,(int)$row['seconds_since_activity']):null;
    $state=$seconds===null?'offline':($seconds<=$online?'online':($seconds<=$recent?'recently_active':'offline'));
    return ['available'=>true,'employee_id'=>(int)$row['id'],'employee_name'=>(string)$row['full_name'],'role'=>(string)$row['role_name'],'state'=>$state,'is_online'=>$state==='online','page'=>(string)($row['page']?:'Business Portal'),'path'=>(string)($row['path']??''),'last_seen_at'=>$row['last_seen_at']??null,'seconds_since_activity'=>$seconds,'session_started_at'=>$state==='online'?($row['session_started_at']??null):null,'session_duration_seconds'=>$state==='online'?max(0,(int)($row['session_duration_seconds']??0)):0,'session_id'=>$row['session_id']?(int)$row['session_id']:null,'online_threshold_seconds'=>$online,'recent_threshold_seconds'=>$recent,'identity_mapping'=>'ops_board_presence.employee_id = ops_employees.id = kpi_sessions.user_id','refreshed_at'=>(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format(DATE_ATOM)];
}
