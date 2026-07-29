<?php

declare(strict_types=1);

function notifications_task_reminder_migrate(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_schema_migrations (migration_key VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $key = '2026-07-29-task-notification-reminders-v1';
        $check = $pdo->prepare('SELECT 1 FROM portal_schema_migrations WHERE migration_key = ? LIMIT 1');
        $check->execute([$key]);
        if (!$check->fetchColumn()) {
            $changes = [
                'ALTER TABLE notifications ADD COLUMN deadline_state VARCHAR(24) NULL AFTER priority',
                'ALTER TABLE notifications ADD COLUMN sound_key VARCHAR(24) NULL AFTER deadline_state',
                'ALTER TABLE notifications ADD COLUMN scheduled_at DATETIME NULL AFTER sound_key',
                'ALTER TABLE notifications ADD COLUMN deduplication_key VARCHAR(190) NULL AFTER scheduled_at',
                'ALTER TABLE notifications ADD UNIQUE KEY uniq_notification_deduplication (deduplication_key)',
                'ALTER TABLE notification_recipients ADD COLUMN snoozed_until DATETIME NULL AFTER next_reminder_at',
                'ALTER TABLE notification_preferences ADD COLUMN sound_volume TINYINT UNSIGNED NOT NULL DEFAULT 65 AFTER sound_enabled',
                'ALTER TABLE notification_preferences ADD COLUMN sound_prompt_seen TINYINT(1) NOT NULL DEFAULT 0 AFTER sound_volume',
                'ALTER TABLE notification_preferences ALTER COLUMN sound_enabled SET DEFAULT 0',
            ];
            foreach ($changes as $sql) {
                try { $pdo->exec($sql); } catch (Throwable $ignored) {}
            }
            $pdo->prepare('INSERT IGNORE INTO portal_schema_migrations (migration_key) VALUES (?)')->execute([$key]);
        }
        $ready = true;
    } catch (Throwable $error) {
        error_log('Task reminder migration failed: ' . $error->getMessage());
        $ready = false;
    }
    return $ready;
}

function notifications_windhoek_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Africa/Windhoek'));
}

function notifications_task_deadline_state(string $deadline, ?DateTimeImmutable $now = null): string
{
    if ($deadline === '') return 'normal';
    $tz = new DateTimeZone('Africa/Windhoek');
    $now = $now ?: notifications_windhoek_now();
    $due = notifications_task_deadline($deadline);
    if ($due < $now) return 'overdue';
    if ($due->format('Y-m-d') === $now->format('Y-m-d')) return 'due_today';
    if ($due <= $now->modify('+24 hours')) return 'upcoming';
    return 'normal';
}

function notifications_task_deadline(string $deadline): DateTimeImmutable
{
    $tz = new DateTimeZone('Africa/Windhoek');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($deadline))) {
        return new DateTimeImmutable(trim($deadline) . ' 23:59:59', $tz);
    }
    return DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $deadline, $tz) ?: new DateTimeImmutable($deadline, $tz);
}

function notifications_working_hours(DateTimeImmutable $now): bool
{
    $day = (int) $now->format('N');
    $time = $now->format('H:i');
    if ($day <= 5) return $time >= '08:00' && $time <= '17:00';
    if ($day === 6) return $time >= '09:00' && $time <= '13:30';
    return false;
}

function notifications_overdue_duration(DateTimeImmutable $due, DateTimeImmutable $now): string
{
    $minutes = max(1, (int) floor(($now->getTimestamp() - $due->getTimestamp()) / 60));
    $days = intdiv($minutes, 1440); $minutes %= 1440;
    $hours = intdiv($minutes, 60); $minutes %= 60;
    $parts = [];
    if ($days) $parts[] = $days . ' day' . ($days === 1 ? '' : 's');
    if ($hours) $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
    if ($minutes || !$parts) $parts[] = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    return implode(' ', array_slice($parts, 0, 2));
}

function notifications_task_reminder_log(string $action, int $taskId, int $employeeId, string $type, string $channel): void
{
    if (function_exists('ops_activity_log')) {
        ops_activity_log($action, 'checklist_task', $taskId, ['employee_id'=>$employeeId, 'reminder_type'=>$type, 'delivery_channel'=>$channel, 'timestamp'=>gmdate(DATE_ATOM)]);
    }
}

function notifications_schedule_task_reminders(?int $onlyEmployeeId = null, bool $loginCatchup = false): int
{
    if (!notifications_task_reminder_migrate()) return 0;
    $now = notifications_windhoek_now();
    $pdo = db();
    try {
        $pdo->exec("UPDATE notification_recipients nr JOIN notifications n ON n.id=nr.notification_id LEFT JOIN ops_checklist_tasks t ON t.id=n.related_id AND n.related_type='checklist_task' SET nr.cleared_at=COALESCE(nr.cleared_at,NOW()) WHERE n.related_type='checklist_task' AND n.deduplication_key LIKE 'task:%' AND (t.id IS NULL OR t.status IN ('complete','completed','done','archived','deleted') OR t.archived_at IS NOT NULL OR t.deleted_at IS NOT NULL OR t.deadline IS NULL OR t.assigned_employee_id<>nr.employee_id)");
    } catch (Throwable $ignored) {}
    $sql = "SELECT t.id,t.task_name,t.deadline,t.priority,t.assigned_employee_id,e.full_name AS assigned_name
            FROM ops_checklist_tasks t LEFT JOIN ops_employees e ON e.id=t.assigned_employee_id
            WHERE t.assigned_employee_id IS NOT NULL AND t.deadline IS NOT NULL
              AND t.status NOT IN ('complete','completed','done','archived','deleted')
              AND t.archived_at IS NULL AND t.deleted_at IS NULL";
    $params = [];
    if ($onlyEmployeeId) { $sql .= ' AND t.assigned_employee_id = ?'; $params[] = $onlyEmployeeId; }
    $tasks = ops_rows($sql, $params); $created = 0; $tz = new DateTimeZone('Africa/Windhoek');
    foreach ($tasks as $task) {
        $taskId=(int)$task['id']; $employeeId=(int)$task['assigned_employee_id'];
        $due=notifications_task_deadline((string)$task['deadline']);
        $state=notifications_task_deadline_state((string)$task['deadline'],$now);
        $clear=$pdo->prepare("UPDATE notification_recipients nr JOIN notifications n ON n.id=nr.notification_id SET nr.cleared_at=COALESCE(nr.cleared_at,NOW()) WHERE nr.employee_id=? AND n.related_type='checklist_task' AND n.related_id=? AND n.deduplication_key LIKE 'task:%' AND n.deadline_state IS NOT NULL AND n.deadline_state<>?");
        $clear->execute([$employeeId,$taskId,$state]);
        if (!in_array($state,['due_today','overdue','upcoming'],true)) continue;
        $type=$state; $slot=''; $sound=null;
        if ($state==='due_today') {
            if ($loginCatchup) $slot='login-'.$now->format('Y-m-d');
            elseif ($now->format('H:i')>='15:00') $slot='15-'.$now->format('Y-m-d');
            elseif ($now->format('H:i')>='08:00') $slot='08-'.$now->format('Y-m-d');
            if ($due->format('H:i:s')!=='23:59:59' && $now >= $due->modify('-2 hours')) $slot='two-hours-'.$due->format('YmdHi');
            if ($slot==='') continue;
            $sound = (strpos($slot,'08-')===0 || strpos($slot,'login-')===0) ? 'due_today' : null;
            $message='Due today at '.$due->format('H:i');
        } elseif ($state==='overdue') {
            $prior=(int)(ops_rows("SELECT COUNT(*) AS total FROM notifications n JOIN notification_recipients nr ON nr.notification_id=n.id WHERE n.related_type='checklist_task' AND n.related_id=? AND nr.employee_id=? AND n.deadline_state='overdue'",[$taskId,$employeeId])[0]['total']??0);
            if ($prior===0) $slot='became-overdue-'.$due->format('YmdHi');
            elseif ($loginCatchup) $slot='login-'.$now->format('Y-m-d');
            elseif (notifications_working_hours($now)) $slot='work-'.$now->format('Y-m-d').'-'.intdiv((int)$now->format('H'), 2);
            if ($slot==='') continue;
            $type='overdue'; $message='Overdue by '.notifications_overdue_duration($due,$now);
            $audible=(int)(ops_rows("SELECT COUNT(*) AS total FROM notifications n JOIN notification_recipients nr ON nr.notification_id=n.id WHERE nr.employee_id=? AND n.deadline_state='overdue' AND n.sound_key='overdue' AND DATE(n.created_at)=?",[$employeeId,$now->format('Y-m-d')])[0]['total']??0);
            $sound=notifications_working_hours($now)&&$audible<3?'overdue':null;
        } else {
            $slot='upcoming-'.$due->format('YmdHi'); $message='Due '.$due->format('d M Y').' at '.$due->format('H:i'); $sound=null;
        }
        $dedup="task:{$taskId}:user:{$employeeId}:type:{$type}:{$slot}";
        $id=notifications_create(['title'=>strtoupper(str_replace('_',' ',$type)).' — '.$task['task_name'],'message'=>$message,'module'=>'tasks','priority'=>$task['priority']==='urgent'?'urgent':'normal','deadline_state'=>$state,'sound_key'=>$sound,'scheduled_at'=>$now->format('Y-m-d H:i:s'),'deduplication_key'=>$dedup,'required_delivery'=>true,'related_type'=>'checklist_task','related_id'=>$taskId,'action_link'=>BASE_URL.'/apps/operations/checklists.php?task_view=active&task_id='.$taskId],[$employeeId]);
        if ($id) { $created++; notifications_task_reminder_log('task_reminder_generated',$taskId,$employeeId,$type,'portal'); }
    }
    return $created;
}

function notifications_snooze_task(int $notificationId, string $duration): bool
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || !in_array($duration,['30','60','tomorrow'],true)) return false;
    $until = $duration==='tomorrow' ? notifications_windhoek_now()->modify('tomorrow 08:00') : notifications_windhoek_now()->modify('+'.(int)$duration.' minutes');
    $row=ops_rows("SELECT n.related_id,n.deadline_state FROM notifications n JOIN notification_recipients nr ON nr.notification_id=n.id WHERE n.id=? AND nr.employee_id=? AND n.related_type='checklist_task' LIMIT 1",[$notificationId,$employeeId])[0]??null;
    if(!$row || ($duration==='tomorrow' && ($row['deadline_state']??'')==='overdue' && !user_has_role('owner_admin'))) return false;
    $stmt=db()->prepare('UPDATE notification_recipients SET snoozed_until=?, next_reminder_at=? WHERE notification_id=? AND employee_id=?');
    $stmt->execute([$until->format('Y-m-d H:i:s'),$until->format('Y-m-d H:i:s'),$notificationId,$employeeId]);
    notifications_task_reminder_log('task_reminder_snoozed',(int)$row['related_id'],$employeeId,(string)$row['deadline_state'],'portal');
    return $stmt->rowCount()>0;
}
