<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/apps/operations/operations.php';
require_once BASE_PATH.'/shared/epi/HrAbsenceEvidence.php';
require_once BASE_PATH.'/shared/epi/FrontDeskCoverage.php';
// Run every five minutes after the explicit feature migration and activation.
$service=new \Hambelela\EPI\FrontDeskCoverage(db(),ops_hr_db());
$result=$service->reminders(static function(int $recipient,string $message,string $key){
    $q=db()->prepare('SELECT n.id FROM notifications n JOIN notification_recipients r ON r.notification_id=n.id WHERE n.deduplication_key=? AND r.employee_id=?');
    $q->execute([$key,$recipient]);$existing=$q->fetchColumn();$q->closeCursor();
    if($existing) return (int)$existing;
    return notifications_create(['title'=>'Front Desk coverage','message'=>$message,'module'=>'system',
        'required_delivery'=>1,'deduplication_key'=>$key,'action_link'=>BASE_URL.'/apps/operations/index.php'],[$recipient]);
});
echo json_encode($result).PHP_EOL;
exit($result['failed']?1:0);
