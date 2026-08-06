<?php

declare(strict_types=1);

function kpi_task_json_list($value): array
{
    if (is_array($value)) return array_values(array_unique(array_map('strval', $value)));
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? array_values(array_unique(array_map('strval', $decoded))) : [];
}

function kpi_task_median(array $values): ?float
{
    $values = array_values(array_filter($values, static fn($value): bool => $value !== null));
    if (!$values) return null;
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);
    return round((float) (count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2), 1);
}

function kpi_task_duration_stats(array $values): array
{
    $values = array_values(array_map('floatval', array_filter($values, static fn($v): bool => $v !== null)));
    sort($values, SORT_NUMERIC);
    return [
        'n' => count($values),
        'total' => $values ? round(array_sum($values), 1) : null,
        'average' => $values ? round(array_sum($values) / count($values), 1) : null,
        'median' => kpi_task_median($values),
        'fastest' => $values ? round((float) min($values), 1) : null,
        'slowest' => $values ? round((float) max($values), 1) : null,
    ];
}

function kpi_task_meaningful_note(string $note): bool
{
    $note = trim(preg_replace('/\s+/', ' ', $note) ?? '');
    $length = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
    if ($length < 26) return false;
    return !in_array(strtolower(rtrim($note, '.!')), ['done', 'completed', 'complete', 'finished', 'task done', 'all done'], true);
}

function kpi_task_management_performance(array $employee, string $fromSql, string $toSql, array $holidays = []): array
{
    $zone = new DateTimeZone('Africa/Windhoek');
    $employeeId = (int) $employee['id'];
    $tasks = ops_rows("SELECT t.id,'task' timeline_module,t.task_name,t.instructions,t.notes,t.checklist_type,t.priority,t.assigned_employee_id,t.date_assigned,t.employee_visible,t.created_at,t.deadline,t.status,t.started_at,t.completed_at,t.date_completed,t.completed_by,t.checklist_items,t.checked_items,t.completion_note,t.completion_note_required,t.completion_evidence_required,t.performance_scored,t.recurrence_key,t.recurring_template_id,t.source_template_id,t.archived_at,t.deleted_at,assignee.full_name assigned_to,creator.full_name created_by_name,completer.full_name completed_by_name FROM ops_checklist_tasks t LEFT JOIN ops_employees assignee ON assignee.id=t.assigned_employee_id LEFT JOIN ops_employees creator ON creator.id=t.created_by LEFT JOIN ops_employees completer ON completer.id=t.completed_by WHERE t.assigned_employee_id=? AND t.date_assigned BETWEEN ? AND ? AND t.employee_visible=1 AND t.performance_scored=1 AND t.deleted_at IS NULL AND t.archived_at IS NULL AND LOWER(t.status) NOT IN ('cancelled','deleted','trashed') ORDER BY t.date_assigned DESC,t.id DESC LIMIT 500", [$employeeId, $fromSql, $toSql]);

    $eventsByTask = [];
    foreach (kpi_unified_events((new DateTimeImmutable($fromSql, $zone))->modify('-14 days')->format('Y-m-d H:i:s'), $toSql, null, 'tasks') as $event) {
        $eventsByTask[(int) $event['record_id']][] = $event;
    }
    $attachmentsByTask = [];
    if ($tasks && ops_table_exists('ops_checklist_attachments')) {
        $ids = array_column($tasks, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach (ops_rows("SELECT id,task_id,uploaded_by,uploaded_by_name,created_at,removed_at,removed_by FROM ops_checklist_attachments WHERE task_id IN ({$marks}) ORDER BY created_at,id", $ids) as $attachment) {
            $attachmentsByTask[(int) $attachment['task_id']][] = $attachment;
        }
    }

    $completeStatuses = ['complete', 'completed', 'approved'];
    $openStatuses = ['new', 'not_started', 'pending', 'in_progress', 'progress', 'started', 'awaiting_evidence', 'awaiting evidence', 'blocked'];
    $now = new DateTimeImmutable('now', $zone);
    $counts = ['assigned'=>count($tasks),'completed'=>0,'completed_eligible'=>0,'completion_eligible'=>0,'early'=>0,'exact_due'=>0,'on_time'=>0,'late'=>0,'deadline_eligible'=>0,'pending'=>0,'pending_upcoming'=>0,'in_progress'=>0,'overdue'=>0,'direct_complete'=>0,'reopened'=>0,'returned'=>0,'attribution_conflict'=>0,'review'=>0,'checklist_eligible'=>0,'checklist_compliant'=>0,'note_eligible'=>0,'note_compliant'=>0,'proof_eligible'=>0,'proof_compliant'=>0,'normal_assigned'=>0,'important_assigned'=>0,'urgent_assigned'=>0,'normal_completed'=>0,'important_completed'=>0,'urgent_completed'=>0];
    $durations = ['ack'=>[], 'active'=>[], 'turnaround'=>[], 'early'=>[], 'completed_late'=>[], 'current_overdue'=>[]];
    $byStatus = [];
    $rows = [];

    foreach ($tasks as $task) {
        $events = $eventsByTask[(int) $task['id']] ?? [];
        $assignmentEvent = $startEvent = $completeEvent = null;
        $directComplete = $reopened = $returned = false;
        $completionSnapshot = [];
        foreach ($events as $event) {
            $action = strtolower((string) ($event['action'] ?? ''));
            $old = strtolower(trim((string) ($event['previous_status'] ?? '')));
            $new = strtolower(trim((string) ($event['new_status'] ?? '')));
            $metadata = (array) ($event['metadata'] ?? []);
            if (!$assignmentEvent && in_array($action, ['task_created','task_assigned','task_reassigned','task_admin_updated'], true) && (int) ($metadata['assigned_employee_id'] ?? $employeeId) === $employeeId) $assignmentEvent = $event;
            if (!$startEvent && in_array($new, ['in_progress','progress','started'], true)) $startEvent = $event;
            if (!$completeEvent && in_array($new, $completeStatuses, true)) {
                $completeEvent = $event;
                $directComplete = !in_array($old, ['in_progress','progress','started'], true);
                $completionSnapshot = $metadata;
            }
            if ($completeEvent && in_array($old, $completeStatuses, true) && !in_array($new, $completeStatuses, true)) $reopened = true;
            if (strpos($new, 'return') !== false || strpos($action, 'return') !== false) $returned = true;
        }

        $assigned = !empty($task['date_assigned']) ? new DateTimeImmutable((string) $task['date_assigned'], $zone) : null;
        $created = !empty($task['created_at']) ? new DateTimeImmutable((string) $task['created_at'], $zone) : null;
        $startedSource = !empty($startEvent['occurred_at']) ? $startEvent['occurred_at'] : (!empty($task['started_at']) ? $task['started_at'] : null);
        $completedSource = !empty($completeEvent['occurred_at']) ? $completeEvent['occurred_at'] : (!empty($task['completed_at']) ? $task['completed_at'] : (!empty($task['date_completed']) ? $task['date_completed'] : null));
        $started = $startedSource ? new DateTimeImmutable((string) $startedSource, $zone) : null;
        $completed = $completedSource ? new DateTimeImmutable((string) $completedSource, $zone) : null;
        $deadline = !empty($task['deadline']) ? new DateTimeImmutable((string) $task['deadline'], $zone) : null;
        $status = strtolower(trim((string) $task['status']));
        $currentComplete = in_array($status, $completeStatuses, true);
        $invalid = !$assigned || ($created && $assigned < $created) || ($started && $started < $assigned) || ($completed && ($completed < $assigned || ($started && $completed < $started)));
        $completionActorId = (int) ($completeEvent['actor_user_id'] ?? $task['completed_by'] ?? 0);
        $personalCompletion = $completed && ($completionActorId === 0 || $completionActorId === $employeeId);
        $attribution = ($startEvent && (int) ($startEvent['actor_user_id'] ?? 0) > 0 && (int) $startEvent['actor_user_id'] !== $employeeId) || ($completed && !$personalCompletion);

        $ackMinutes = !$invalid && $assigned && $started ? kpi_business_minutes($assigned, $started, $holidays) : null;
        $activeMinutes = !$invalid && $started && $completed ? kpi_business_minutes($started, $completed, $holidays) : null;
        $turnaroundMinutes = !$invalid && $assigned && $completed ? kpi_business_minutes($assigned, $completed, $holidays) : null;
        if (!$attribution && $ackMinutes !== null) $durations['ack'][] = $ackMinutes;
        if (!$attribution && $activeMinutes !== null) $durations['active'][] = $activeMinutes;
        if (!$attribution && $turnaroundMinutes !== null) $durations['turnaround'][] = $turnaroundMinutes;

        $priority = strtolower((string) $task['priority']);
        if (!in_array($priority, ['normal','important','urgent'], true)) $priority = 'normal';
        $counts[$priority . '_assigned']++;
        $deadlineResult = 'No due date';
        $earlyMinutes = $lateMinutes = $currentOverdueMinutes = null;
        if ($deadline && $personalCompletion) {
            $counts['deadline_eligible']++;
            if ($completed < $deadline) {
                $deadlineResult = 'Completed early';
                $counts['early']++; $counts['on_time']++;
                $earlyMinutes = kpi_business_minutes($completed, $deadline, $holidays);
                $durations['early'][] = $earlyMinutes;
            } elseif ($completed == $deadline) {
                $deadlineResult = 'Completed on time';
                $counts['exact_due']++; $counts['on_time']++;
            } else {
                $deadlineResult = 'Completed late';
                $counts['late']++;
                $lateMinutes = kpi_business_minutes($deadline, $completed, $holidays);
                $durations['completed_late'][] = $lateMinutes;
            }
        } elseif ($deadline && $completed) {
            $deadlineResult = 'Completed by another actor — review';
        } elseif ($deadline && !$currentComplete) {
            $counts['pending']++;
            if ($now > $deadline) {
                $deadlineResult = 'Overdue open';
                $counts['overdue']++;
                $currentOverdueMinutes = kpi_business_minutes($deadline, $now, $holidays);
                $durations['current_overdue'][] = $currentOverdueMinutes;
                $byStatus[$status ?: 'unknown'] = ($byStatus[$status ?: 'unknown'] ?? 0) + 1;
            } else {
                $deadlineResult = 'Pending upcoming';
                $counts['pending_upcoming']++;
            }
        } elseif (!$currentComplete) {
            $counts['pending']++;
        }
        if (in_array($status, ['in_progress','progress','started'], true)) $counts['in_progress']++;
        if ($personalCompletion) { $counts['completed']++; $counts[$priority . '_completed']++; }
        if ($directComplete) $counts['direct_complete']++;
        if ($reopened) $counts['reopened']++;
        if ($returned) $counts['returned']++;
        if ($attribution) $counts['attribution_conflict']++;

        $required = kpi_task_json_list($task['checklist_items']);
        $checkedAtCompletion = kpi_task_json_list($completionSnapshot['checked_items'] ?? $task['checked_items']);
        $missingChecklist = $personalCompletion && $required ? array_values(array_diff($required, $checkedAtCompletion)) : [];
        $checklistResult = 'No checklist required';
        if ($personalCompletion && $required) {
            $counts['checklist_eligible']++;
            if (!$missingChecklist) { $counts['checklist_compliant']++; $checklistResult = 'Checklist complete before task completion'; }
            else $checklistResult = 'Incomplete checklist';
        }
        $noteRequired = (int) $task['completion_note_required'] === 1;
        $noteText = (string) ($completionSnapshot['completion_note'] ?? $task['completion_note'] ?? '');
        $noteValid = $personalCompletion && kpi_task_meaningful_note($noteText);
        if ($personalCompletion && $noteRequired) { $counts['note_eligible']++; if ($noteValid) $counts['note_compliant']++; }
        $proofRequired = (int) $task['completion_evidence_required'] === 1;
        $validProof = false; $proofCount = 0;
        foreach ($attachmentsByTask[(int) $task['id']] ?? [] as $attachment) {
            if (!empty($attachment['removed_at']) || (int) $attachment['uploaded_by'] !== $employeeId) continue;
            $proofCount++;
            if ($completed && strtotime((string) $attachment['created_at']) <= $completed->getTimestamp()) $validProof = true;
        }
        if ($personalCompletion && $proofRequired) { $counts['proof_eligible']++; if ($validProof) $counts['proof_compliant']++; }

        $missingExactAssignment = !$assignmentEvent;
        $requiresReview = $invalid || $attribution || ($currentComplete && !$completed);
        if ($requiresReview) $counts['review']++;
        else { $counts['completion_eligible']++; if ($personalCompletion) $counts['completed_eligible']++; }
        $rows[] = $task + [
            'category'=>trim((string) $task['checklist_type']) ?: 'Uncategorised','assigned_at'=>$task['date_assigned'],
            'assignment_evidence'=>$assignmentEvent ? $assignmentEvent['source_log'].' #'.$assignmentEvent['source_event_id'] : 'Authoritative task date_assigned',
            'started_at_evidence'=>$started ? $started->format('Y-m-d H:i:s') : null,'completed_at_evidence'=>$completed ? $completed->format('Y-m-d H:i:s') : null,
            'started_by'=>$startEvent['actor_name'] ?? ($started ? $task['assigned_to'] : '—'),'completed_by_evidence'=>$completeEvent['actor_name'] ?? $task['completed_by_name'] ?? '—',
            'acknowledgement_minutes'=>$ackMinutes,'active_minutes'=>$activeMinutes,'turnaround_minutes'=>$turnaroundMinutes,
            'early_minutes'=>$earlyMinutes,'late_minutes'=>$lateMinutes,'current_overdue_minutes'=>$currentOverdueMinutes,
            'deadline_result'=>$deadlineResult,'status_process'=>$directComplete?'Completed without In Progress':($reopened?'Reopened after completion':($returned?'Returned for correction':'Expected sequence')),
            'checklist_result'=>$checklistResult,'checklist_total'=>count($required),'checklist_checked'=>count($checkedAtCompletion),'missing_checklist'=>count($missingChecklist)>0,
            'completion_note_result'=>$noteRequired?($noteValid?'Meaningful required note':'Required detailed note missing'):'Not required','note_meaningful'=>$noteValid,'notes_state'=>trim($noteText)!==''?'Present':'Missing',
            'proof_result'=>$proofRequired?($validProof?'Required proof supplied':'Required proof missing'):($proofCount?'Optional proof — evidence only':'Proof not required'),'proof_valid'=>$validProof,'proof_required'=>$proofRequired,
            'attribution'=>$attribution?'Attribution conflict':($completed?'Personally completed':'Assigned employee'),'result'=>$requiresReview?'Requires owner review':'Reliable evidence','evidence_count'=>count($events),
            'last_activity_at'=>$events ? end($events)['occurred_at'] : ($completed ? $completed->format('Y-m-d H:i:s') : ($started ? $started->format('Y-m-d H:i:s') : ($assigned ? $assigned->format('Y-m-d H:i:s') : null))),
        ];
    }

    $percent = static fn(int $n, int $d): ?float => $d ? round(100 * $n / $d, 1) : null;
    $completionRate = $percent($counts['completed_eligible'], $counts['completion_eligible']);
    $onTime = $percent($counts['on_time'], $counts['deadline_eligible']);
    $checklistRate = $percent($counts['checklist_compliant'], $counts['checklist_eligible']);
    $noteRate = $percent($counts['note_compliant'], $counts['note_eligible']);
    $proofRate = $percent($counts['proof_compliant'], $counts['proof_eligible']);
    $reworkRate = $counts['completed'] ? max(0, round(100 - 100 * $counts['reopened'] / $counts['completed'], 1)) : null;
    $score = kpi_weighted_subscore([['share'=>40,'score'=>$onTime],['share'=>25,'score'=>$completionRate],['share'=>15,'score'=>$checklistRate],['share'=>10,'score'=>$noteRate],['share'=>10,'score'=>$reworkRate]]);
    $stats = array_map('kpi_task_duration_stats', $durations);
    $metrics = [
        ['label'=>'Tasks Assigned','value'=>$counts['assigned']],['label'=>'Tasks Completed','value'=>$counts['completed']],
        ['label'=>'Task Completion Rate','value'=>$completionRate,'format'=>'percent','numerator'=>$counts['completed_eligible'],'denominator'=>$counts['completion_eligible']],
        ['label'=>'Completed Early','value'=>$counts['early'],'numerator'=>$counts['early'],'denominator'=>$counts['deadline_eligible'],'explanation'=>'Exact completed timestamp is before the due timestamp.'],
        ['label'=>'Completed On Time','value'=>$counts['on_time'],'numerator'=>$counts['on_time'],'denominator'=>$counts['deadline_eligible'],'explanation'=>'Includes early and exactly-at-due completions; no double-counting.'],
        ['label'=>'Completed Late','value'=>$counts['late'],'numerator'=>$counts['late'],'denominator'=>$counts['deadline_eligible']],
        ['label'=>'On-time Completion Rate','value'=>$onTime,'format'=>'percent','numerator'=>$counts['on_time'],'denominator'=>$counts['deadline_eligible']],
        ['label'=>'Pending Upcoming','value'=>$counts['pending_upcoming']],['label'=>'Current Overdue Tasks','value'=>$counts['overdue']],
        ['label'=>'Average Current Overdue Duration','value'=>$stats['current_overdue']['average'],'format'=>'minutes','denominator'=>$stats['current_overdue']['n']],
        ['label'=>'Median Current Overdue Duration','value'=>$stats['current_overdue']['median'],'format'=>'minutes','denominator'=>$stats['current_overdue']['n']],
        ['label'=>'Total Current Open Overdue','value'=>$stats['current_overdue']['total'],'format'=>'minutes','denominator'=>$stats['current_overdue']['n']],
        ['label'=>'Average Completed-Late Duration','value'=>$stats['completed_late']['average'],'format'=>'minutes','denominator'=>$stats['completed_late']['n']],
        ['label'=>'Median Completed-Late Duration','value'=>$stats['completed_late']['median'],'format'=>'minutes','denominator'=>$stats['completed_late']['n']],
        ['label'=>'Total Historical Completed-Late','value'=>$stats['completed_late']['total'],'format'=>'minutes','denominator'=>$stats['completed_late']['n']],
        ['label'=>'Median Assignment-to-Start','value'=>$stats['ack']['median'],'format'=>'minutes','denominator'=>$stats['ack']['n']],
        ['label'=>'Median Active Task Time','value'=>$stats['active']['median'],'format'=>'minutes','denominator'=>$stats['active']['n']],
        ['label'=>'Median Employee Turnaround','value'=>$stats['turnaround']['median'],'format'=>'minutes','denominator'=>$stats['turnaround']['n']],
        ['label'=>'Completion-note Compliance','value'=>$noteRate,'format'=>'percent','numerator'=>$counts['note_compliant'],'denominator'=>$counts['note_eligible']],
        ['label'=>'Required-proof Compliance','value'=>$proofRate,'format'=>'percent','numerator'=>$counts['proof_compliant'],'denominator'=>$counts['proof_eligible'],'status'=>$counts['proof_eligible']?'ok':'unmeasured'],
    ];
    $breakdown = [
        ['label'=>'Assigned','count'=>$counts['assigned'],'denominator'=>$counts['assigned']],['label'=>'Completed','count'=>$counts['completed'],'denominator'=>$counts['assigned']],
        ['label'=>'Completed Early','count'=>$counts['early'],'denominator'=>$counts['assigned']],['label'=>'Completed On Time','count'=>$counts['on_time'],'denominator'=>$counts['assigned']],
        ['label'=>'Completed Late','count'=>$counts['late'],'denominator'=>$counts['assigned']],['label'=>'Pending Upcoming','count'=>$counts['pending_upcoming'],'denominator'=>$counts['assigned']],
        ['label'=>'In Progress','count'=>$counts['in_progress'],'denominator'=>$counts['assigned']],['label'=>'Overdue Open','count'=>$counts['overdue'],'denominator'=>$counts['assigned']],
        ['label'=>'Reopened','count'=>$counts['reopened'],'denominator'=>$counts['assigned']],['label'=>'Returned for Correction','count'=>$counts['returned'],'denominator'=>$counts['assigned']],
    ];
    $riskRows = array_values(array_filter($rows, static fn(array $row): bool => $row['current_overdue_minutes'] !== null || $row['deadline_result']==='Completed late' || $row['missing_checklist'] || ($row['proof_required'] && !$row['proof_valid']) || strpos($row['completion_note_result'],'missing') !== false || $row['status_process']!=='Expected sequence' || $row['attribution']==='Attribution conflict'));
    return ['rows'=>$rows,'metrics'=>$metrics,'counts'=>$counts,'duration_stats'=>$stats,'status_breakdown'=>$breakdown,'overdue_by_status'=>$byStatus,'risk_rows'=>$riskRows,
        'median_ack'=>$stats['ack']['median'],'median_active'=>$stats['active']['median'],'median_turnaround'=>$stats['turnaround']['median'],'completion_rate'=>$completionRate,'on_time_rate'=>$onTime,
        'task_score'=>$score,'score_detail'=>['on_time'=>$onTime,'completion'=>$completionRate,'checklist'=>$checklistRate,'notes'=>$noteRate,'rework'=>$reworkRate,'weights'=>['on_time'=>40,'completion'=>25,'checklist'=>15,'notes'=>10,'rework'=>10],'coverage'=>['assigned'=>$counts['assigned'],'deadline_eligible'=>$counts['deadline_eligible'],'timing_ack'=>$stats['ack']['n'],'timing_active'=>$stats['active']['n'],'timing_turnaround'=>$stats['turnaround']['n']]],
        'methodology'=>'Exact due datetime is compared with the authoritative completion timestamp. Assignment → Start, Start → Complete, and Assignment → Complete remain separate business-time measures.'];
}
