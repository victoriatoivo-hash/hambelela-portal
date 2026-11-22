<?php
// Connection-local synthetic date overlay. Persistent fixture and production records untouched.
function owner_view_fixture(PDO $db):void {
    $rows=$db->query('SELECT * FROM delivery_jobs')->fetchAll(PDO::FETCH_ASSOC);
    $db->exec('CREATE TEMPORARY TABLE owner_view_jobs LIKE delivery_jobs');
    $db->exec('ALTER TABLE owner_view_jobs RENAME TO delivery_jobs');
    if($rows){$keys=array_keys($rows[0]);$q=$db->prepare('INSERT INTO delivery_jobs (`'.implode('`,`',$keys).'`) VALUES('.implode(',',array_fill(0,count($keys),'?')).')');foreach($rows as $row)$q->execute(array_values($row));}
    $q=$db->prepare('UPDATE delivery_jobs SET scheduled_date=?');$q->execute([(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d')]);
}
