<?php
declare(strict_types=1);
/** Additive only: existing obligations and evidence remain untouched. */
function acknowledgments_migrate_scheduling(PDO $db):void{
    $columns=[
        'portal_ack_instructions'=>['scheduled_at'=>'DATETIME NULL','released_at'=>'DATETIME NULL','release_state'=>"VARCHAR(20) NOT NULL DEFAULT 'released'",'schedule_revision'=>'INT NOT NULL DEFAULT 0'],
        'portal_ack_recipients'=>['schedule_active'=>'TINYINT(1) NOT NULL DEFAULT 1'],
    ];
    foreach($columns as $table=>$definitions){
        $existing=array_column($db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),'Field');
        foreach($definitions as $name=>$definition)if(!in_array($name,$existing,true))$db->exec("ALTER TABLE $table ADD COLUMN $name $definition");
    }
    $indexes=array_column($db->query('SHOW INDEX FROM portal_ack_instructions')->fetchAll(PDO::FETCH_ASSOC),'Key_name');
    if(!in_array('scheduled_release',$indexes,true))$db->exec('ALTER TABLE portal_ack_instructions ADD INDEX scheduled_release(release_state,scheduled_at)');
}
