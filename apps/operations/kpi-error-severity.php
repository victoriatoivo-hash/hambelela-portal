<?php
declare(strict_types=1);
function kpi_error_severity_weight(array $incident): int {
    if (!empty($incident['verified_false_completion'])) return 6;
    return ['critical'=>4,'high'=>3,'medium'=>2,'low'=>1][strtolower((string)($incident['severity']??''))]??2;
}
function kpi_false_completion_verified(array $row,int $employeeId): bool {
    if (($row['category']??'')!=='task_false_completion' || empty($row['affects_kpi_accuracy'])) return false;
    if (!preg_match('/\bTask\s*#\s*(\d+)\b/i',(string)($row['description']??''),$match))return false;
    $task=ops_rows('SELECT id,assigned_employee_id,completed_by,completed_at,date_completed,correction_round_count FROM ops_checklist_tasks WHERE id=?',[(int)$match[1]])[0]??[];
    return (int)($task['assigned_employee_id']??0)===$employeeId && (!empty($task['completed_at'])||!empty($task['date_completed'])||(int)($task['correction_round_count']??0)>0);
}
