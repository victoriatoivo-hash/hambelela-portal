<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryCustody.php';

/** Read model only. Never mutates Orders, payment allocations or historical events. */
final class OrderDeliveryTracking
{
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    private function rows(string $sql,array $p=[]):array{$s=$this->db->prepare($sql);$s->execute($p);return $s->fetchAll(\PDO::FETCH_ASSOC);}
    public function packing(int $order):array
    {
        $o=$this->rows('SELECT * FROM ops_orders WHERE id=?',[$order])[0]??[];
        $out=['state'=>'Not Yet Packed','name'=>null,'at'=>null,'source'=>null];
        // A Packed By assignment is not proof of packing. Use the recorded packing-stage transition.
        try{$events=$this->rows("SELECT s.*,e.full_name,r.role_key FROM kpi_status_events s LEFT JOIN ops_employees e ON e.id=s.changed_by LEFT JOIN ops_roles r ON r.id=e.role_id WHERE s.module='order' AND s.record_id=? AND s.new_status IN ('new','new_order','in_progress','packed') ORDER BY s.changed_at DESC,s.id DESC LIMIT 1",[$order]);}
        catch(\PDOException $e){if(!in_array($e->getCode(),['42S02','42S22'],true))throw $e;$events=[];}
        $e=$events[0]??null;
        if(!in_array(strtolower((string)($o['status']??'')),['new','new_order',''],true)&&$e&&in_array($e['new_status'],['in_progress','packed'],true)){
            $selfPacked=(int)($o['assigned_packer_id']??0)===(int)$e['changed_by']||in_array($e['role_key']??'',['packer','packer_production_staff'],true);
            $out=['state'=>$selfPacked?'Packing recorded':'Packing recorded · packer attribution needs review','name'=>$selfPacked?($e['full_name']?:null):null,'at'=>$e['changed_at'],'recorded_by'=>$e['full_name']?:null,'source'=>'Packing-stage transition'];
            // An explicit, reviewed Packed By correction can replace the subject, never the original packing time.
            try{$correction=$this->rows("SELECT metadata FROM ops_order_stage_events WHERE order_id=? AND stage_key='packer_attribution_corrected' ORDER BY id DESC LIMIT 1",[$order])[0]??null;}
            catch(\PDOException $x){if(!in_array($x->getCode(),['42S02','42S22'],true))throw $x;$correction=null;}
            $m=$correction?json_decode((string)$correction['metadata'],true):null;
            if(!empty($m['assigned_packer_id'])&&!empty($m['correction_reason'])&&(int)$m['assigned_packer_id']===(int)($o['assigned_packer_id']??0)){
                $employee=$this->rows('SELECT full_name FROM ops_employees WHERE id=?',[(int)$m['assigned_packer_id']])[0]??null;
                $out['name']=$employee['full_name']??null;$out['source']='Reviewed Packed By correction; original packing-stage timestamp';$out['state']=$out['name']?'Packing recorded':'Packer attribution needs review';
            }
        }elseif(!in_array(strtolower((string)($o['status']??'')),['new','new_order',''],true))$out['state']='Packing evidence not recorded';
        return $out;
    }
    public function enrich(array $job):array
    {
        $id=(int)$job['id'];
        $events=$this->rows('SELECT v.*,e.full_name actor FROM delivery_events v LEFT JOIN ops_employees e ON e.id=v.employee_id WHERE v.delivery_id=? ORDER BY v.id',[$id]);
        $first=[];$last=[];$assignment=0;
        foreach($events as $event){$type=$event['event_type'];$event['data']=json_decode($event['metadata_json'],true)?:[];if(!empty($event['data']['system_generated']))$event['actor']='Scheduled follow-up';unset($event['metadata_json']);$first[$type]=$first[$type]??$event;$last[$type]=$event;
            if($type==='arranged'||$type==='revised'||($type==='dispatch_updated'&&($event['data']['field']??'')==='driver'))$assignment=(int)$event['id'];
        }
        $job['arrangement']=$first['arranged']??null;
        $job['requested_time']=$last['deadline_updated']['data']['deadline']??null;
        $job['payment_arrangement']=$first['arranged']['data']['payment_arrangement']??'Not recorded';
        $custody=DeliveryCustody::read($this->db,$job);
        $job['custody']=$custody;
        $job['handover']=$custody['handover']?($last['parcel_collected']??null):null;
        $job['picked_up']=$job['handover']?1:0;
        $job['delivery_confirmation']=$last['delivered']??null;
        $job['acceptance']=$last['driver_accepted']??null;
        if($job['acceptance']&&((int)$job['acceptance']['id']<$assignment||(int)$job['acceptance']['employee_id']!==(int)$job['driver_employee_id']))$job['acceptance']=null;
        $job['driver_collection']=$custody['collection']?($last['driver_collection_confirmed']??null):null;
        $notice=$this->rows("SELECT o.delivered_at sent_at,n.created_at notification_at,nr.read_at seen_at FROM delivery_outbox o LEFT JOIN notifications n ON n.deduplication_key=CONCAT('delivery:',o.event_key) LEFT JOIN notification_recipients nr ON nr.notification_id=n.id AND nr.employee_id=o.recipient_employee_id WHERE o.delivery_id=? AND o.recipient_employee_id=? AND (o.event_key LIKE 'assigned:%' OR JSON_UNQUOTE(JSON_EXTRACT(o.payload_json,'$.kind'))='assignment') ORDER BY o.id DESC LIMIT 1",[$id,$job['driver_employee_id']]);
        $job['notification']=$notice[0]??null;
        $job['response_status']=$job['acceptance']?'Accepted by Driver':(!empty($job['notification']['seen_at'])?'Seen by Driver':(!empty($job['notification']['notification_at'])?'Sent to Driver':'Notification Queued'));
        $job['acceptance_status']=$job['acceptance']?'Accepted by Driver':($custody['tracking_version']?'Awaiting Driver Acceptance':'Acceptance not recorded (legacy delivery)');
        $job['tracking_version']=$first['arranged']['data']['tracking_version']??0;
        $job['cod_followup']=$job['handover']['data']['front_cod_due_utc']??$last['started']['data']['front_cod_due_utc']??null;
        $job['cod_followup_breached']=$last['cod_followup_overdue']??null;
        $job['cod_followup_resolved']=$last['cod_followup_resolved']??null;
        return $job;
    }
}
