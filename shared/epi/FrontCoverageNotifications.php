<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;

/** Background notification delivery, not a page-read side effect. */
final class FrontCoverageNotifications
{
    public static function run(PDO $db,?PDO $hr,$now=null): array {
        $coverage=new FrontDeskCoverage($db,$hr);
        return $coverage->reminders(static function(int $recipient,string $message,string $key)use($db){
            return V2Store::transaction($db,function()use($db,$recipient,$message,$key){
                $existing=V2Store::one($db,'SELECT id FROM notifications WHERE deduplication_key=?',[$key]);
                if($existing)$id=(int)$existing['id'];
                else {
                    $db->prepare("INSERT INTO notifications(title,message,module,related_type,priority,deduplication_key,action_link,created_by) VALUES(?,?,'system','front_coverage','normal',?, ?,NULL)")->execute(['Front Desk coverage',$message,$key,'/apps/operations/index.php']);
                    $id=(int)$db->lastInsertId();
                }
                $db->prepare('INSERT IGNORE INTO notification_recipients(notification_id,employee_id) VALUES(?,?)')->execute([$id,$recipient]);
                return $id;
            });
        },$now);
    }
}
