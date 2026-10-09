<?php
require_once dirname(__DIR__).'/shared/task-instructions.php';
require_once dirname(__DIR__).'/shared/task-admin-edit.php';
// Load the actual existing checklist validation/timing helpers without booting the portal or touching its database.
$tokens=token_get_all(file_get_contents(dirname(__DIR__).'/apps/operations/checklists.php'));
$wanted=['checklist_json_items','checklist_completion_validation','checklist_authoritative_deadline','checklist_working_minutes','checklist_elapsed_duration_label','checklist_task_timing','checklist_instruction_text_length','checklist_sanitize_instructions','checklist_normalize_status','checklist_custom_filter_field','checklist_detail_access','checklist_date_label','checklist_render_instructions','checklist_display_task_title','checklist_require_completion'];
for($i=0;$i<count($tokens);$i++){
    if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
    $name=$i+1;while(is_array($tokens[$name])&&$tokens[$name][0]===T_WHITESPACE)$name++;
    if(!is_array($tokens[$name])||!in_array($tokens[$name][1],$wanted,true))continue;
    $code='';$depth=0;$body=false;
    for($j=$i;$j<count($tokens);$j++){ $token=$tokens[$j];$part=is_array($token)?$token[1]:$token;$code.=$part;if($token==='{'){$depth++;$body=true;}if(is_array($token)&&in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))$depth++;if($token==='}')$depth--;if($body&&$depth===0)break; }
    eval($code);
}
function ops_rows(string $sql,array $args=[]):array {global $taskTestDb;$s=$taskTestDb->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
function ops_table_exists(string $name):bool {global $taskTestDb;try{$taskTestDb->query('SELECT 1 FROM '.$name.' LIMIT 1');return true;}catch(Throwable $e){return false;}}
function task_test_db():PDO {
    global $taskTestDb;
    $taskTestDb=new PDO('sqlite::memory:');$taskTestDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $taskTestDb->exec("CREATE TABLE ops_employees(id INTEGER PRIMARY KEY,full_name TEXT,status TEXT);
    INSERT INTO ops_employees VALUES(1,'Original Employee','active'),(2,'Actual Completer','active'),(3,'Owner','active'),(4,'Inactive Employee','inactive');
    CREATE TABLE ops_checklist_tasks(id INTEGER PRIMARY KEY,task_name TEXT,instructions TEXT,notes TEXT,priority TEXT,deadline TEXT,assigned_employee_id INTEGER,status TEXT,completed_by INTEGER,completed_at TEXT,date_completed TEXT,checked_items TEXT,checklist_items TEXT,completion_note TEXT,started_at TEXT,date_assigned TEXT,scheduled_at TEXT,released_at TEXT,active_correction_id INTEGER,created_at TEXT,deleted_at TEXT,completion_evidence_required INTEGER);
    INSERT INTO ops_checklist_tasks VALUES(10,'Fixture task','Read instructions','Read instructions','normal','2026-10-08 17:00:00',1,'new',NULL,NULL,NULL,'[]','[]',NULL,NULL,'2026-10-07 08:00:00',NULL,'2026-10-07 08:00:00',NULL,'2026-10-07 08:00:00',NULL,0);
    CREATE TABLE ops_activity_logs(id INTEGER PRIMARY KEY,entity_type TEXT,entity_id INTEGER,action TEXT,metadata TEXT,created_at TEXT);
    INSERT INTO ops_activity_logs VALUES(1,'checklist_task',10,'task_created','{\"assigned_employee_id\":1}','2026-10-07 08:00:00');
    CREATE TABLE ops_checklist_attachments(id INTEGER PRIMARY KEY,task_id INTEGER,removed_at TEXT);");
    return $taskTestDb;
}
function task_test_input(array $task):array{return ['task_id'=>10,'csrf_token'=>'fixture-csrf','request_key'=>'fixture-save-00001','revision'=>task_admin_revision($task),'task_name'=>'Corrected fixture task','instructions'=>'Read the updated instructions','priority'=>'normal','deadline'=>'2026-10-08T17:00','assigned_employee_id'=>2,'completed_by'=>2,'correction_type'=>'combined','correction_reason'=>'Management verified yesterday’s completion.','mark_complete'=>'1','actual_completed_at'=>'2026-10-08T16:00','completion_note'=>'The employee completed the requested work.'];}
function task_test_assert(bool $value,string $message):void {if(!$value)throw new RuntimeException('FAIL: '.$message);echo 'PASS: '.$message.PHP_EOL;}
function task_test_reject(callable $fn,string $message):void {try{$fn();}catch(Throwable $e){task_test_assert(true,$message);return;}throw new RuntimeException('FAIL: accepted '.$message);}
