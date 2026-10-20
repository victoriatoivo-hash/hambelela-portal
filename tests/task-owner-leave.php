<?php
/** Isolated synthetic database only; never reads production credentials. */
require_once __DIR__.'/../shared/task-admin-edit.php';
function verify($condition,string $message): void {if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
function checklist_sanitize_instructions($v){return strip_tags($v,'<p><b>');}
function checklist_instruction_text_length($v){return strlen(strip_tags($v));}
function checklist_json_items($v){return json_decode($v,true)?:[];}
function checklist_completion_validation($task,$checked,$note){return ['valid'=>!array_diff(checklist_json_items($task['checklist_items']??'[]'),$checked),'message'=>'Complete required checklist.'];}
function checklist_task_timing($task){return [];}
function task_instructions_sanitize_html($v){return $v;}
$events=[];
function ops_activity_log($action,$type,$id,$meta){global $events;$events[]=[$action,$id,$meta];}
$now=new DateTimeImmutable('2026-10-20 12:00:00',new DateTimeZone('Africa/Windhoek'));
$task=['id'=>1,'task_name'=>'Read guidelines','instructions'=>'Read carefully','notes'=>'','priority'=>'normal','deadline'=>'2026-10-21 12:00:00','assigned_employee_id'=>7,'status'=>'new','created_at'=>'2026-10-19 08:00:00','date_assigned'=>'2026-10-19 08:00:00','checklist_items'=>'[]','checked_items'=>'[]','completion_note'=>'','completion_evidence_required'=>0,'completed_by'=>null,'completed_at'=>null,'date_completed'=>null,'started_at'=>null,'scheduled_at'=>null,'released_at'=>null,'active_correction_id'=>null,'recurring_template_id'=>null,'recurrence_key'=>null,'deleted_at'=>null,'archived_at'=>null];
$input=array_merge($task,['mark_complete'=>1,'completed_by'=>'owner','actual_completed_at'=>'2026-10-20 11:00:00','correction_reason'=>'Owner completed work during approved leave.','correction_type'=>'completion']);
$owner=['id'=>99,'name'=>'Synthetic Owner'];
$result=task_admin_validate($input,$task,[7=>'Synthetic Employee',8=>'Covering Employee'],$now,$owner);
verify($result['completed_by']===null,'Owner does not use an employee ID');
verify(task_completion_actor($result)['id']===99,'Owner identity comes from authenticated editor');
verify($result['assigned_employee_id']===7,'Original assignee is preserved');
verify(task_completion_actor($result)['recorded_by_id']===99,'Recorder is stored separately');
$employee=task_admin_validate(array_merge($input,['completed_by'=>8]),$task,[7=>'Synthetic Employee',8=>'Covering Employee'],$now,$owner);
verify($employee['completed_by']===8&&task_completion_actor($employee)['id']===8,'Owner recording employee work retains actual employee');
try{task_admin_validate(array_merge($input,['completed_by'=>'']),$task,[7=>'Synthetic Employee'],$now,$owner);throw new LogicException('Missing actor accepted');}catch(RuntimeException $e){verify(!($e instanceof LogicException),'Completion never defaults silently to Owner');}
try{task_admin_validate(array_merge($input,['actual_completed_at'=>'2026-10-21 11:00:00']),$task,[7=>'Synthetic Employee'],$now,$owner);throw new LogicException('Future accepted');}catch(RuntimeException $e){verify(!($e instanceof LogicException),'Future completion is rejected');}
$dsn=getenv('TASK_TEST_DSN');
if(!$dsn) {echo "DB checks require isolated TASK_TEST_DSN in release CI.\n";exit(0);}
if(strpos($dsn,'mysql:host=127.0.0.1;')!==0)throw new RuntimeException('Only isolated CI localhost database is permitted');
$pdo=new PDO($dsn,'root',getenv('TASK_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='task_test_'.bin2hex(random_bytes(6));$pdo->exec("CREATE DATABASE $database");$pdo->exec("USE $database");
try{
    $columns=[];
    foreach($task as $key=>$value)$columns[]=$key==='id'?'id INT PRIMARY KEY':"$key ".(in_array($key,['assigned_employee_id','completed_by','active_correction_id','completion_evidence_required','recurring_template_id'],true)?'INT NULL':'TEXT NULL');
    $pdo->exec('CREATE TABLE ops_checklist_tasks ('.implode(',',$columns).')');
    $pdo->exec('CREATE TABLE ops_employees(id INT PRIMARY KEY,full_name TEXT,status TEXT)');
    $pdo->exec("INSERT INTO ops_employees VALUES(7,'Synthetic Employee','active'),(8,'Covering Employee','active')");
    $pdo->exec('CREATE TABLE employee_user_links(id INT PRIMARY KEY,portal_user_id INT,hr_employee_id INT,linked_at DATETIME,active INT)');
    $pdo->exec("INSERT INTO employee_user_links VALUES(1,7,70,'2026-01-01 00:00:00',1),(2,8,80,'2026-01-01 00:00:00',1)");
    $pdo->exec('CREATE TABLE employees(id INT PRIMARY KEY)');
    $pdo->exec('INSERT INTO employees VALUES(70),(80)');
    $pdo->exec('CREATE TABLE leave_requests(id INT PRIMARY KEY,employee_id INT,status TEXT,start_date DATE,end_date DATE,approved_at DATETIME,approved_by INT,is_half_day INT DEFAULT 0)');
    $pdo->exec("INSERT INTO leave_requests VALUES(1,70,'approved','2026-10-20','2026-10-21','2026-10-19 10:00:00',99,0)");
    task_leave_schema($pdo);task_admin_schema($pdo);
    $insert=$pdo->prepare('INSERT INTO ops_checklist_tasks('.implode(',',array_keys($task)).') VALUES('.implode(',',array_fill(0,count($task),'?')).')');
    $insert->execute(array_values($task));
    $clock=new \Hambelela\EPI\BusinessTimeEngine($pdo);
    $approved=task_leave_evidence($pdo,$pdo,7,'2026-10-20 12:00:00');
    verify($approved['state']==='approved_absence','Existing HR identity links resolve approved absence');
    verify(task_leave_evidence($pdo,$pdo,8,'2026-10-20 12:00:00')['state']==='no_approved_absence','Available covering employee remains active');
    verify(task_leave_evidence($pdo,null,7,'2026-10-20 12:00:00')['state']==='needs_review','Unavailable HR is not treated as no leave');
    verify(task_leave_evidence($pdo,$pdo,15,'2026-10-20 12:00:00')['state']==='needs_review','Missing HR identity requires review');
    $paused=task_leave_transition($task,$approved,'2026-10-20 12:00:00',$clock);
    verify($paused['state']==='paused'&&$paused['remaining_minutes']===780,'Deferrable task preserves configured working minutes');
    $withPause=array_merge($task,['availability_json'=>json_encode($paused)]);
    verify(task_leave_transition($withPause,$approved,'2026-10-20 13:00:00',$clock)===$paused,'Repeated polling is idempotent');
    $clear=['state'=>'no_approved_absence','evidence'=>[]];
    $resume=task_leave_transition($withPause,$clear,'2026-10-22 09:00:00',$clock);
    verify($resume['state']==='resumed'&&$resume['adjusted_deadline']==='2026-10-23 12:00:00','Return resumes remaining business time');
    verify($resume['original_deadline']===$task['deadline'],'Original deadline retained separately');
    verify(task_active_working_minutes($pdo,array_merge($task,['availability_json'=>json_encode($resume)]),'2026-10-19 08:00:00','2026-10-22 09:00:00')===600.0,'Employee timing excludes approved pause from late-start and duration calculations');
    $extended=$approved;
    $extended['evidence'][0]['effective_to']='2026-10-23 00:00:00';
    $extension=task_leave_transition($withPause,$extended,'2026-10-21 12:00:00',$clock);
    verify($extension['pause_until']==='2026-10-23 00:00:00'&&$extension['remaining_minutes']===780,'Leave extension preserves remaining working time');
    $cancelled=task_leave_transition($withPause,$clear,'2026-10-20 13:00:00',$clock);
    verify($cancelled['resumed_at']==='2026-10-20 13:00:00','Cancellation resumes at observed return without rewriting past evidence');
    $lateApproval=$approved;$lateApproval['evidence'][0]['approved_at']='2026-10-20 10:00:00';
    $lateApproved=task_leave_transition($task,$lateApproval,'2026-10-20 12:00:00',$clock);
    verify($lateApproved['pause_from']==='2026-10-20 10:00:00'&&$lateApproved['late_approval'],'Late approval is prospective and explicitly recorded');
    $urgent=task_leave_transition(array_merge($task,['priority'=>'urgent']),$approved,'2026-10-20 12:00:00',$clock);
    verify($urgent['state']==='coverage','Urgent task requires coverage');
    $recurring=task_leave_transition(array_merge($task,['recurring_template_id'=>42]),$approved,'2026-10-20 12:00:00',$clock);
    verify($recurring['state']==='coverage','Recurring occurrence requires coverage without changing its template');
    $unknown=task_leave_transition(array_merge($task,['availability_json'=>json_encode($urgent)]),['state'=>'needs_review','reason'=>'hr_unavailable'],'2026-10-21 10:00:00',$clock);
    $returned=task_leave_transition(array_merge($task,['availability_json'=>json_encode($unknown)]),$clear,'2026-10-22 10:00:00',$clock);
    verify($returned['state']==='coverage','Unresolved coverage survives HR outage and return');
    $late=task_leave_transition(array_merge($task,['deadline'=>'2026-10-19 12:00:00']),$approved,'2026-10-20 12:00:00',$clock);
    verify($late['prior_late_minutes']===300,'Genuine lateness before leave remains recorded');
    $lateReturn=task_leave_transition(array_merge($task,['availability_json'=>json_encode($late)]),$clear,'2026-10-22 09:00:00',$clock);
    verify($lateReturn['adjusted_deadline']==='2026-10-22 08:00:00','Zero remaining time resumes at working-day opening, not legacy midnight');
    $legacy=task_leave_transition(array_merge($task,['deadline'=>'2026-10-21 00:00:00']),$approved,'2026-10-20 12:00:00',$clock);
    verify($legacy['remaining_minutes']===1080,'Legacy midnight due dates retain the existing 17:00 convention');
    $pdo->exec('UPDATE leave_requests SET is_half_day=1');
    verify(task_leave_evidence($pdo,$pdo,7,'2026-10-20 12:00:00')['state']==='needs_review','Partial-day absence is not silently treated as a full day');
    $pdo->exec("UPDATE leave_requests SET is_half_day=0,status='pending'");
    verify(task_leave_evidence($pdo,$pdo,7,'2026-10-20 12:00:00')['state']==='no_approved_absence','Pending leave does not pause tasks');
    $pdo->exec("UPDATE leave_requests SET status='approved'");
    task_leave_sync($pdo,$pdo,'2026-10-20 12:00:00');
    $saved=$pdo->query('SELECT * FROM ops_checklist_tasks WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    verify(task_leave_data($saved)['state']==='paused','Availability persisted to existing task');
    $auditCount=(int)$pdo->query('SELECT COUNT(*) FROM ops_task_management_audits')->fetchColumn();
    task_leave_sync($pdo,$pdo,'2026-10-20 12:05:00');
    verify((int)$pdo->query('SELECT COUNT(*) FROM ops_task_management_audits')->fetchColumn()===$auditCount,'Polling creates no duplicate audits');
    $coverTask=array_merge($task,['id'=>2,'priority'=>'urgent']);
    $insert->execute(array_values($coverTask));
    task_leave_sync($pdo,$pdo,'2026-10-20 12:05:00');
    $coverSaved=$pdo->query('SELECT * FROM ops_checklist_tasks WHERE id=2')->fetch(PDO::FETCH_ASSOC);
    $coverInput=array_merge($input,['mark_complete'=>0,'status'=>'new','task_id'=>2,'assigned_employee_id'=>8,'priority'=>'urgent','csrf_token'=>'test-csrf','request_key'=>'synthetic-cover-123456','revision'=>task_admin_revision($coverSaved)]);
    $cover=task_admin_handle($pdo,'admin_update_task',true,'test-csrf',$coverInput,$owner);
    task_leave_sync($pdo,$pdo,'2026-10-20 12:10:00');
    $coverSaved=$pdo->query('SELECT * FROM ops_checklist_tasks WHERE id=2')->fetch(PDO::FETCH_ASSOC);
    verify($coverSaved['assigned_employee_id']==8&&!task_leave_blocked($coverSaved),'Covering employee receives an active task');
    verify(task_leave_data($coverSaved)['original_employee_id']===7,'Coverage preserves original responsibility');
    $events=[];
    $saveInput=array_merge($input,['actual_completed_at'=>(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->modify('-1 minute')->format('Y-m-d H:i:s'),'csrf_token'=>'test-csrf','task_id'=>1,'request_key'=>'synthetic-request-123456','revision'=>task_admin_revision($saved)]);
    try{task_admin_handle($pdo,'admin_update_task',false,'test-csrf',$saveInput,$owner);throw new LogicException('Employee allowed');}catch(RuntimeException $e){verify(!($e instanceof LogicException),'Employee cannot use management completion');}
    $save=task_admin_handle($pdo,'admin_update_task',true,'test-csrf',$saveInput,$owner);
    verify($save['success']&&task_completion_actor($save['task'])['kind']==='owner','Owner completion persists through real transaction');
    verify($save['task']['assigned_employee_id']==7&&$save['task']['completed_by']===null,'Owner save does not credit or reassign employee');
    verify(($events[0][2]['completion_actor']['kind']??'')==='owner'&&$events[0][2]['employee_id']===null,'Performance bridge receives Owner actor without employee attribution');
    $replay=task_admin_handle($pdo,'admin_update_task',true,'test-csrf',$saveInput,$owner);
    verify(!empty($replay['replayed']),'Duplicate submission is idempotent');
    task_leave_sync($pdo,$pdo,'2026-10-22 12:00:00');
    verify($pdo->query('SELECT status FROM ops_checklist_tasks WHERE id=1')->fetchColumn()==='complete','HR sync preserves completed records');
    echo "All Task Owner/leave database checks passed.\n";
}finally{$pdo->exec("DROP DATABASE $database");}
