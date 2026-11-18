<?php
require __DIR__.'/../apps/operations/packing-monthly-history.php';
function ops_column_exists($t,$c){return true;}
function ops_table_exists($t){return true;}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE ops_packing_tasks(id INTEGER PRIMARY KEY,assigned_employee_id INT,packing_status TEXT,quantity_packed TEXT,quantity_planned TEXT,received_weight TEXT,date_completed TEXT,archived_at TEXT,deleted_at TEXT,duplicate_removed_at TEXT,cancelled_at TEXT,reversed_at TEXT);
CREATE TABLE ops_packing_assignment_log(id INTEGER PRIMARY KEY,packing_task_id INT,old_employee_id INT,changed_at TEXT)");
$q=$db->prepare("INSERT INTO ops_packing_tasks(assigned_employee_id,packing_status,quantity_packed,quantity_planned,date_completed) VALUES(?,?,'10','20',?)");
foreach(['2026-10-31 23:59:59','2026-11-01 00:00:00','2026-11-30 23:59:59','2026-12-01 00:00:00'] as $d)$q->execute([1,'done',$d]);
$db->exec("INSERT INTO ops_packing_assignment_log VALUES(1,2,2,'2026-11-03'),(2,2,3,'2026-11-04');UPDATE ops_packing_tasks SET archived_at='saved' WHERE id=3");
foreach(['deleted_at','duplicate_removed_at','reversed_at','cancelled_at'] as $col){$q->execute([1,'done','2026-11-15']);$db->exec("UPDATE ops_packing_tasks SET $col='yes' WHERE id=last_insert_rowid()");}
$q->execute([2,'packing',null]);$q->execute([1,'reversed','2026-11-01']);$q->execute([2,'not_started',null]);$db->exec("UPDATE ops_packing_tasks SET archived_at='yes' WHERE id=last_insert_rowid()");
$rows=packing_monthly_history($db,new DateTimeImmutable('2026-11-01'));$seen=[];
foreach($rows as $r)$seen[$r['profile_id'].':'.$r['bucket']]=(int)$r['quantity']*(int)$r['batches'];
ksort($seen);if($seen!==['1:packed'=>10,'2:packed'=>10,'2:pending'=>20])throw new Exception(json_encode($seen));
$db->exec("INSERT INTO ops_packing_tasks(assigned_employee_id,packing_status,quantity_packed,received_weight,date_completed) VALUES(3,'done','5','12units','2026-11-09'),(3,'done','5','12kg','2026-11-09')");
$basis=[];
foreach(packing_monthly_history($db,new DateTimeImmutable('2026-11-01')) as $r)if((int)$r['profile_id']===3)$basis[$r['received_stock']]=(int)$r['batches'];
ksort($basis);if($basis!==['12kg'=>1,'12units'=>1])throw new Exception('Mixed units were grouped together.');
echo "PASS month boundaries, completion owner, actual vs plan, reopened work, completed archives, inactive rows and duplicate events\n";
