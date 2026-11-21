<?php
/** Connection-only deterministic fixture for older read-screen regression tests. */
function isolate_ready_screen_test(PDO $db):void {
 if(PHP_SAPI!=='cli'||$db->query('SELECT DATABASE()')->fetchColumn()!=='driver_review')throw new RuntimeException('Private test database only.');
 $row=$db->query("SELECT * FROM delivery_jobs WHERE driver_employee_id=3 AND source='hambelela' AND status='ready' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Ready synthetic fixture required');
 $ddl=$db->query('SHOW CREATE TABLE delivery_jobs')->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*FOREIGN KEY[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\s*\) ENGINE/',"\n) ENGINE",$ddl);$db->exec($ddl);
 $row['scheduled_date']=(new DateTimeImmutable('today',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d');$db->prepare('INSERT INTO delivery_jobs (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
}
