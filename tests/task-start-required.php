<?php
// Exercise the production completion guard without loading the application.
$source = file_get_contents(__DIR__ . '/../apps/operations/checklists.php');
$start = strpos($source, 'function checklist_require_completion(');
$end = strpos($source, 'function checklist_require_progress_note(', $start);
eval(substr($source, $start, $end - $start));
function checklist_normalize_status(string $status): string { return $status; }
function checklist_completion_validation(...$args): array { return ['valid'=>true]; }
foreach ([['new', null, false], ['new', '2026-09-05 09:00:00', false], ['in_progress', null, false], ['in_progress', '2026-09-05 09:00:00', true], ['complete', null, true]] as [$status, $started, $expected]) {
    $allowed = true;
    try { checklist_require_completion(['status'=>$status, 'started_at'=>$started]); }
    catch (RuntimeException $error) { $allowed = false; }
    if ($allowed !== $expected) throw new RuntimeException('Start guard failed: ' . $status);
}
echo "Task start completion safeguards passed.\n";
