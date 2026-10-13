<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/TrackingPolicy.php';

/** Neutral availability notices, using existing portal notification tables. */
final class TrackingAlerts
{
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    private function rows(string $sql,array $args=[]):array{$s=$this->db->prepare($sql);$s->execute($args);return $s->fetchAll(\PDO::FETCH_ASSOC);}
    public function check(bool $enabled,int $now=0):int
    {
        $now=$now?:time();$sent=0;
        foreach($this->rows('SELECT id FROM delivery_tracking_devices') as $entry){
            $this->db->beginTransaction();
            try{
                $d=$this->rows('SELECT * FROM delivery_tracking_devices WHERE id=? FOR UPDATE',[$entry['id']])[0];
                $settings=TrackingPolicy::settings(json_decode($d['settings_json'],true));$window=TrackingPolicy::window($now,$settings);
                $driver=$this->rows("SELECT e.full_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.id=? AND e.status='active' AND r.role_key='delivery_driver' AND p.active=1",[$d['employee_id']]);
                $last=$this->rows("SELECT * FROM delivery_tracking_alerts WHERE device_id=? AND kind='offline' ORDER BY id DESC LIMIT 1",[$d['id']]);
                $open=$last?json_decode($last[0]['detail_json'],true):null;
                // Arm only after the phone has contacted the server. Setup alone is not an outage.
                $eligible=$enabled&&(bool)$d['active']&&!empty($d['token_hash'])&&$driver&&$window&&$d['last_contact']!==null;
                $fresh=$eligible&&(bool)$d['gps_available']&&$d['last_position']!==null&&$now-(int)$d['last_position']<$settings['offline_seconds']&&$now-(int)$d['last_contact']<$settings['offline_seconds'];
                if($open&&empty($open['closed_at'])&&(!$eligible||$fresh||(int)$open['employee_id']!==(int)$d['employee_id'])){
                    $open['closed_at']=$now;$open['closed_reason']=$fresh?'updates_resumed':'monitoring_window_ended';
                    $this->db->prepare('UPDATE delivery_tracking_alerts SET detail_json=? WHERE id=?')->execute([json_encode($open),$last[0]['id']]);
                    $open=null;
                }
                $start=$d['last_position'];
                if($eligible&&$start===null){$first=$this->rows("SELECT MIN(recorded_at) started FROM delivery_tracking_audit WHERE device_id=? AND event_type='tracking_started' AND recorded_at>=?",[$d['id'],$d['assigned_at']]);$start=$first[0]['started']??$d['assigned_at'];}
                $since=$eligible?max((int)$window['start'],(int)$d['assigned_at'],min((int)$d['last_contact'],(int)$start)):$now;
                if(!$eligible||$fresh||$now-$since<$settings['offline_seconds']||($open&&empty($open['closed_at']))){$this->db->commit();continue;}
                $owners=$this->rows("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key='owner_admin'");
                if(!$owners){$this->db->commit();continue;}
                $key=hash('sha256',$d['id'].':'.$d['employee_id'].':'.$window['start'].':'.$since.':'.($last[0]['id']??0));
                $detail=['employee_id'=>(int)$d['employee_id'],'last_contact'=>$d['last_contact'],'last_position'=>$d['last_position'],'window_start'=>$window['start'],'closed_at'=>null];
                $this->db->prepare("INSERT INTO delivery_tracking_alerts(device_id,alert_key,kind,detail_json,created_at) VALUES(?,?,'offline',?,?)")->execute([$d['id'],$key,json_encode($detail),$now]);$alert=(int)$this->db->lastInsertId();
                $message=$driver[0]['full_name'].' · '.$d['name'].': location updates have been unavailable for at least '.(int)ceil($settings['offline_seconds']/60).' minutes during scheduled work hours. This may be caused by signal, battery, permissions or stopped tracking; the cause is not confirmed. Open Driver Tracking to check the current status.';
                $this->db->prepare("INSERT INTO notifications(title,message,module,related_type,related_id,priority,deduplication_key,action_link) VALUES('Location updates unavailable',?,'delivery','delivery_tracking_alert',?,'normal',?,'/apps/delivery/tracking.php')")->execute([$message,$alert,'delivery-gps:'.$key]);$notification=(int)$this->db->lastInsertId();
                foreach($owners as $owner)$this->db->prepare('INSERT INTO notification_recipients(notification_id,employee_id) VALUES(?,?)')->execute([$notification,$owner['id']]);
                $this->db->commit();++$sent;
            }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        }
        return $sent;
    }
}
