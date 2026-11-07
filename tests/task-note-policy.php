<?php
$source = file_get_contents(__DIR__ . '/../apps/operations/checklists.php');
$start = strpos($source, 'function checklist_json_items(');
$end = strpos($source, 'function checklist_items_from_text(', $start);
eval(substr($source, $start, $end - $start));
function checklist_normalize_status(string $status): string { return $status; }
function verify($ok, $message) { if (!$ok) throw new RuntimeException($message); }
foreach (['new', 'in_progress', 'pending'] as $status) {
    foreach (['', '  ', 'OK', 'Started work using Start Task.'] as $note) {
        $result = checklist_require_progress_note($note, $status);
        verify($result === ($note === 'OK' ? 'OK' : ''), 'Non-completion note policy');
    }
}
$task = ['status'=>'in_progress', 'started_at'=>'2026-11-07 08:00:00', 'checklist_items'=>'[]'];
foreach (['', '   ', 'Started work using Start Task.', 'Done'] as $note) {
    $blocked = false;
    try { checklist_require_completion($task, [], $note); } catch (RuntimeException $e) { $blocked = true; }
    verify($blocked, 'Completion must reject empty/automatic/short note');
}
checklist_require_completion($task, [], 'Packed all items and checked labels.');
verify(checklist_require_progress_note('Existing employee note', 'in_progress') === 'Existing employee note', 'Preserve employee notes');
$task['checklist_items'] = '["Check labels"]';
verify(!checklist_completion_validation($task, [], 'Packed all items.')['valid'], 'Checklist safeguard');
verify(checklist_completion_validation($task, ['Check labels'], 'Packed all items.')['valid'], 'Valid completion');
verify(strpos($source, "body.set('completion_note', existingNote") === false, 'Start must not fabricate a note');
verify(strpos($source, "validateTaskProgressForm(completionForm, 'complete')") !== false, 'Status menu must force completion validation');
echo "Task note server policy and safeguards passed.\n";
