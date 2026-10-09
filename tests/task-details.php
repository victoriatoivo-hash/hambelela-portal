<?php
require __DIR__.'/task-admin-fixture.php';
function task_details_fixture(array $changes=[], bool $owner=true, int $employee=3): string {
    $task=array_merge(['id'=>10,'task_name'=>'Prepare dispatch','assigned_employee_id'=>1,'assigned_name'=>'Assigned Employee','priority'=>'normal','status'=>'new','deadline'=>'2026-10-10 17:00:00','scheduled_at'=>null,'released_at'=>'2026-10-09 08:00:00','employee_visible'=>1,'started_at'=>null,'checklist_items'=>'["Count the items","Pack the items"]','checked_items'=>'[]','instructions'=>'<p>Read <strong>carefully</strong>.</p><ol><li>Count items.</li><li>Pack items.</li></ol><style>.bad{color:red}</style><script>alert(1)</script>','notes'=>'','completion_note'=>'','completed_at'=>null,'date_completed'=>null,'completed_by_name'=>null,'completed_by'=>null,'completion_evidence_required'=>0],$changes);
    $canManage=$owner;$currentEmployeeId=$employee;$panelId=10;$panelSavedStatus=checklist_normalize_status($task['status']);$panelDueState=['value'=>'upcoming'];$taskKind='manual';$activeCorrection=null;$panelCorrections=[];$attachmentsByTask=[];$taskAttachmentCsrf='fixture-csrf';$employees=[['id'=>1,'full_name'=>'Assigned Employee'],['id'=>2,'full_name'=>'Actual Completer'],['id'=>3,'full_name'=>'Owner']];$priorities=['normal'=>'Normal','important'=>'Important','urgent'=>'Urgent'];$statuses=['new'=>'New','in_progress'=>'In Progress','complete'=>'Complete'];$items=checklist_json_items($task['checklist_items']);$checked=checklist_json_items($task['checked_items']);
    ob_start();include dirname(__DIR__).'/apps/operations/partials/task-details-drawer.php';return ob_get_clean();
}
function detail_dom(string $html): DOMXPath {$d=new DOMDocument();@$d->loadHTML('<?xml encoding="UTF-8">'.$html);return new DOMXPath($d);}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $owner=task_details_fixture();$dom=detail_dom($owner);
    task_test_assert($dom->query('//*[@data-task-admin-edit]')->length===2 && $dom->query('//*[@data-save-task or @data-task-detail-start]')->length===0,'Owner has header/footer edit actions without employee actions');
    task_test_assert($dom->query('//form[@data-task-progress-form]')->length===0 && $dom->query('//div[contains(@class,"task-detail-checklist__rows")]//input[@disabled]')->length===2,'Owner checklist is read-only and cannot submit employee progress');
    task_test_assert($dom->query('//dl/div/dt')->length===5 && strpos($owner,'Assigned Employee')!==false,'Compact summary renders assignment, priority, due, type and status');
    $instructions=$dom->query('//*[@data-readonly-instructions]')->item(0)->textContent;
    task_test_assert($dom->query('//*[@data-readonly-instructions]//strong')->length===1 && $dom->query('//*[@data-readonly-instructions]//ol/li')->length===2 && strpos($instructions,'.bad')===false && strpos($instructions,'alert(1)')===false,'Formatted instructions preserve lists/bold and remove executable/style content');
    $scheduled=task_details_fixture(['scheduled_at'=>'2026-10-11 12:00:00','released_at'=>null,'employee_visible'=>0]);
    task_test_assert(strpos($scheduled,'Release Now')!==false && strpos($scheduled,'Cancel Scheduled Task')!==false,'Scheduled Owner drawer exposes styled release/cancel actions');
    task_test_assert(trim(task_details_fixture(['scheduled_at'=>'2026-10-11 12:00:00','released_at'=>null,'employee_visible'=>0],false,1))==='','Unreleased scheduled task renders no employee interface');
    task_test_assert(trim(task_details_fixture([],false,2))==='','Unassigned employee cannot render another employee task');
    task_test_assert(trim(task_details_fixture(['archived_at'=>'2026-10-09 09:00:00'],false,1))==='','Archived task is unavailable to employee actions');
    $new=detail_dom(task_details_fixture([],false,1));
    task_test_assert($new->query('//*[@data-task-detail-start]')->length===1 && $new->query('//*[@data-task-admin-edit or @data-save-task or @data-task-audit-disclosure]')->length===0,'New assigned task offers Start only, without management controls');
    $work=detail_dom(task_details_fixture(['status'=>'in_progress','started_at'=>'2026-10-09 09:00:00'],false,1));
    task_test_assert($work->query('//form[@data-task-progress-form]')->length===1 && $work->query('//*[@data-save-task and @disabled]')->length===1 && $work->query('//div[contains(@class,"task-detail-checklist__rows")]//input[@disabled]')->length===0,'Started assigned task has interactive checklist and guarded completion');
    $completed=task_details_fixture(['status'=>'complete','completed_by_name'=>'Actual Completer','completed_by'=>2,'completed_at'=>'2026-10-09 10:00:00','date_completed'=>'2026-10-09 10:00:00'],false,1);
    task_test_assert(strpos($completed,'Actual Completer')!==false && detail_dom($completed)->query('//*[@data-save-task or @data-task-detail-start]')->length===0,'Completed task shows actual worker and cannot be started or completed again');
    task_test_reject(function(){checklist_require_completion(['status'=>'new','checklist_items'=>'[]','completion_note'=>'Finished the work']);},'Completion requires an actual recorded start');
}
