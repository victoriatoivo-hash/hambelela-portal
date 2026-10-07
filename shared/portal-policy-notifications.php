<?php
declare(strict_types=1);
require_once __DIR__.'/hr-access.php';
require_once dirname(__DIR__).'/apps/hr-portal/includes/policy-system.php';

/** HR pages use a separate connection; never replace the HR db() function/session. */
function portal_policy_database(): PDO {
    $localPath=dirname(__DIR__).'/config.local.php';
    $local=is_file($localPath) ? require $localPath : array();
    if(!is_array($local)) $local=array();
    $host=getenv('HAMBELELA_DB_HOST') ?: ($local['db_host']??'localhost');
    $name=getenv('HAMBELELA_DB_NAME') ?: ($local['db_name']??'hambelela_portal');
    $user=getenv('HAMBELELA_DB_USER') ?: ($local['db_user']??'root');
    $pass=getenv('HAMBELELA_DB_PASS') ?: ($local['db_pass']??'');
    return new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',$user,$pass,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
}

function portal_policy_sync(PDO $portal, PDO $hr, ?int $employeeId=null): int {
    hrPolicyAssignmentSchema($hr);
    $settings=hrPolicySettings($hr);
    $portal->exec("CREATE TABLE IF NOT EXISTS hr_policy_portal_delivery (
        assignment_id BIGINT UNSIGNED NOT NULL, portal_user_id INT NOT NULL,
        notification_id INT NULL, reminder_sequence INT UNSIGNED NOT NULL DEFAULT 0,
        next_reminder_at DATETIME NULL, PRIMARY KEY(assignment_id,portal_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $links=$portal->query("SELECT l.portal_user_id,l.hr_employee_id FROM employee_user_links l
        JOIN ops_employees e ON e.id=l.portal_user_id WHERE l.active=1 AND e.status='active'")->fetchAll(PDO::FETCH_ASSOC);
    $synced=0;
    foreach($links as $link){
        if($employeeId!==null && (int)$link['hr_employee_id']!==$employeeId) continue;
        $health=hr_access_health($portal,$hr,(int)$link['portal_user_id']);
        if($health['state']!=='ready') continue;
        $q=$hr->prepare("SELECT s.*,v.title,a.signed_at FROM hr_policy_assignments s
            JOIN hr_policy_versions v ON v.id=s.version_id
            JOIN hr_policies p ON p.current_version_id=v.id AND p.id=v.policy_id
            LEFT JOIN hr_policy_acknowledgements a ON a.version_id=v.id AND a.employee_id=s.employee_id
            WHERE s.employee_id=? AND s.user_id=? AND v.status='published' AND v.acknowledgement_required=1");
        $q->execute(array($health['profile']['id'],$health['account']['id']));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $assignment){
            if($portal->inTransaction()) throw new RuntimeException('Policy delivery requires its own transaction.');
            $portal->beginTransaction();
            try {
                $args=array($assignment['id'],$link['portal_user_id']);
                if(!$assignment['signed_at']) $portal->prepare('INSERT IGNORE INTO hr_policy_portal_delivery(assignment_id,portal_user_id) VALUES (?,?)')->execute($args);
                $s=$portal->prepare('SELECT * FROM hr_policy_portal_delivery WHERE assignment_id=? AND portal_user_id=? FOR UPDATE');$s->execute($args);$delivery=$s->fetch(PDO::FETCH_ASSOC);
                if(!$delivery){$portal->commit();continue;}
                $notificationId=(int)$delivery['notification_id'];
                if($assignment['signed_at']){
                    $portal->prepare('UPDATE notification_recipients SET read_at=COALESCE(read_at,NOW()),cleared_at=COALESCE(cleared_at,NOW()),next_reminder_at=NULL WHERE notification_id=? AND employee_id=?')->execute(array($notificationId,$link['portal_user_id']));
                    $portal->commit(); continue;
                }
                $deadline=$assignment['acknowledgement_deadline'];
                $message='You have been assigned the Hambelela Organic Employee Handbook & Company Policy. Please read and acknowledge it'.($deadline?' by '.date('j F Y',strtotime($deadline)):'').'.';
                $url='/apps/hr-portal/portal-login.php?return='.rawurlencode('policy-view.php?id='.(int)$assignment['version_id']);
                if(!$notificationId){
                    $key='hr-policy:'.$assignment['id'].':'.$link['portal_user_id'];
                    $portal->prepare("INSERT IGNORE INTO notifications(title,message,module,related_type,related_id,priority,action_link,deduplication_key) VALUES (?,?,'hr','hr_policy_assignment',?,'normal',?,?)")->execute(array('HR POLICY — ACTION REQUIRED',$message,$assignment['id'],$url,$key));
                    $n=$portal->prepare('SELECT id FROM notifications WHERE deduplication_key=?');$n->execute(array($key));$notificationId=(int)$n->fetchColumn();
                    if(!$notificationId) throw new RuntimeException('Policy notification could not be saved.');
                    $portal->prepare('INSERT IGNORE INTO notification_recipients(notification_id,employee_id) VALUES (?,?)')->execute(array($notificationId,$link['portal_user_id']));
                }
                $manual=(int)$delivery['reminder_sequence']!==(int)$assignment['reminder_sequence'];
                $automatic=$settings['policy_reminders']==='1' && $delivery['next_reminder_at'] && $delivery['next_reminder_at']<=date('Y-m-d H:i:s');
                $portal->prepare('UPDATE notifications SET message=?,action_link=? WHERE id=?')->execute(array($message,$url,$notificationId));
                // A dismissed popup/read notification cannot clear the unsigned obligation.
                $portal->prepare('UPDATE notification_recipients SET cleared_at=NULL WHERE notification_id=? AND employee_id=?')->execute(array($notificationId,$link['portal_user_id']));
                if(!$delivery['notification_id'] || $manual || $automatic){
                    $portal->prepare('UPDATE notification_recipients SET read_at=NULL,delivered_at=NULL,next_reminder_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE notification_id=? AND employee_id=?')->execute(array($notificationId,$link['portal_user_id']));
                    $portal->prepare('UPDATE hr_policy_portal_delivery SET notification_id=?,reminder_sequence=?,next_reminder_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE assignment_id=? AND portal_user_id=?')->execute(array($notificationId,$assignment['reminder_sequence'],$assignment['id'],$link['portal_user_id']));
                }
                $portal->commit();$synced++;
            } catch(Throwable $error){if($portal->inTransaction()) $portal->rollBack();throw $error;}
        }
    }
    return $synced;
}
