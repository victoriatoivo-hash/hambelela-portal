<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Prospective stage tracking. Must run inside the durable outbox transaction. */
final class OrdersStageBridge
{
    public static function assertStatusAllowed(PDO $db,array $order,string $status): void {
        if(!self::enabled($db))return;
        $tracked=V2Store::one($db,'SELECT classification FROM epi_v2_orders_tracking WHERE order_id=?',[(int)($order['id']??0)]);
        $mode=$tracked['classification']??OrdersSlaPolicy::classify($order)[0];
        if($mode==='courier' && (int)($order['portal_paid_confirmed']??0)!==1
            && in_array($status,['in_progress','packed','verified','ready_for_courier','complete','completed'],true)) {
            throw new RuntimeException('Tick Paid before packing or progressing a courier order.');
        }
    }
    public static function enabled(PDO $db): bool {
        $flag=V2Store::one($db,"SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='epi_v2_orders_sla_enabled'");
        return $flag && $flag['setting_value']==='1';
    }
    public static function capture(PDO $db,string $action,array $order,array $meta): void {
        $at=Support::timestamp($meta['occurred_at'])->format('Y-m-d H:i:s');$id=(int)$order['id'];$ref='ORDER-'.$id;
        $actor=(int)($meta['employee_id']??0);$engine=new DeadlineEngine($db);
        $state=V2Store::one($db,'SELECT * FROM epi_v2_orders_tracking WHERE order_id=? FOR UPDATE',[$id]);
        if(!$state) {
            // Never start fresh clocks on old records merely because someone edits them.
            if($action!=='order_created')return;
            $version=V2Store::one($db,'SELECT * FROM epi_v2_orders_policy WHERE effective_from<=? ORDER BY effective_from DESC,version DESC LIMIT 1',[$at]);
            if(!$version)return;
            $original=(string)($meta['original_created_at']??$order['created_at']??$at);
            if($original<$version['effective_from'])return; // Historical imports require a separate prospective acceptance.
            $created=Support::timestamp($original)->format('Y-m-d H:i:s');
            if($created>$at)throw new RuntimeException('Order creation is after its capture time; review source timestamp');
            [$mode,$source]=OrdersSlaPolicy::classify($order);
            $db->prepare('INSERT INTO epi_v2_orders_tracking(order_id,object_reference,classification,classification_source,policy_version,created_at,source_snapshot_json) VALUES(?,?,?,?,?,?,?)')->execute([$id,$ref,$mode,$source,$version['version'],$created,Support::json($order)]);
            $db->prepare("INSERT IGNORE INTO epi_v2_object_duties VALUES('Orders',?,'front_desk')")->execute([$ref]);
            $state=V2Store::one($db,'SELECT * FROM epi_v2_orders_tracking WHERE order_id=?',[$id]);
        }
        $version=V2Store::one($db,'SELECT * FROM epi_v2_orders_policy WHERE version=?',[$state['policy_version']]);
        if(!$version)throw new RuntimeException('Original Orders policy missing');
        $policy=json_decode($version['policy_json'],true);$mode=$state['classification'];
        if($mode==='unknown')return; // Missing classification is not employee failure.
        if(in_array($action,['order_moved_to_trash','order_permanently_deleted','order_deleted'],true)||!empty($order['deleted_at'])||in_array($order['status']??'',['cancelled','canceled'],true)) {
            $engine->cancelObject('Orders',$ref,$actor,$at);$engine->cancelObject('Packing',$ref,$actor,$at);
            $db->prepare('UPDATE epi_v2_orders_tracking SET removed_at=COALESCE(removed_at,?) WHERE order_id=?')->execute([$at,$id]);return;
        }
        if($state['removed_at']||$state['completed_at'])return; // Reopening needs a separately reviewed obligation cycle.
        $paid=(int)($order['portal_paid_confirmed']??0)===1;
        $status=strtolower((string)($order['status']??''));
        $progressed=in_array($status,['in_progress','packed','verified','ready_for_collection','ready_for_delivery','ready_for_courier','completed','complete'],true);
        $completed=in_array($status,['completed','complete'],true);
        if($paid&&!$state['paid_at']) {
            $state['paid_at']=$at;$db->prepare('UPDATE epi_v2_orders_tracking SET paid_at=? WHERE order_id=?')->execute([$at,$id]);
            self::finish($engine,'Orders',$ref,$mode==='collection'?'collection_expiry':'courier_payment_wait',$actor,$at);
        }
        // Tick removal cannot erase an already-started employee obligation or reset its clock.
        if($progressed&&!$state['progressed_at']) {
            $state['progressed_at']=$at;$db->prepare('UPDATE epi_v2_orders_tracking SET progressed_at=? WHERE order_id=?')->execute([$at,$id]);
        }
        $schedule=function(string $module,string $obligation,string $event,string $start,$due,string $team)use($engine,$ref,$version,$policy){
            $engine->schedule(['module'=>$module,'object_reference'=>$ref,'obligation_key'=>$obligation,'breach_event_key'=>$event,
                'starts_at'=>$start,'due_at'=>$due,'source_event'=>'orders_stage_policy','cycle_id'=>'initial',
                'responsible_team'=>$team,'stage_policy'=>['version'=>$version['version'],'approved_by'=>$version['approved_by'],'rules'=>$policy]]);
        };
        if($mode==='walk_in') {
            $schedule('Orders','complete_order','order_completion_sla_breached',$state['created_at'],OrdersSlaPolicy::workingDue($state['created_at'],(int)$policy['walk_in_minutes'],$policy),'front_desk');
        } else {
            if(!$state['packing_started_at']&&($mode!=='courier'||$paid)) {
                $start=$mode==='courier'?$state['paid_at']:$state['created_at'];
                $due=OrdersSlaPolicy::workingDue($start,(int)$policy['packing_minutes'][$mode],$policy);
                $schedule('Packing','pack_order','order_packing_sla_breached',$start,$due,'packers');
                $state['packing_started_at']=$start;
                $db->prepare('UPDATE epi_v2_orders_tracking SET packing_started_at=? WHERE order_id=?')->execute([$start,$id]);
                if($mode==='courier') {
                    $dispatch=OrdersSlaPolicy::dispatch($start,$due,$policy);
                    $db->prepare('UPDATE epi_v2_orders_tracking SET dispatch_at=?,rush_candidate=? WHERE order_id=?')->execute([$dispatch['dispatch_at'],(int)$dispatch['rush_candidate'],$id]);
                }
            }
            // Assignment evidence is not employee acceptance. Only authorised assignment events establish it.
            $assignment=in_array($action,['order_created','packer_assigned','packer_automatically_assigned','order_packer_assigned'],true)||($meta['field']??'')==='assigned_packer_id';
            if($assignment) self::assignPacker($db,$ref,(int)($order['assigned_packer_id']??0),$actor,$at);
            if($progressed && ($mode!=='courier'||$paid)) self::finish($engine,'Packing',$ref,'pack_order',$actor,$at);
            if($state['progressed_at'] && ($mode==='delivery'||($paid&&$state['paid_at']))) {
                $start=$mode==='delivery'?$state['progressed_at']:max($state['progressed_at'],$state['paid_at']);
                $schedule('Orders','complete_order','order_completion_sla_breached',$start,OrdersSlaPolicy::workingDue($start,(int)$policy['front_minutes'][$mode],$policy),'front_desk');
            }
            if(!$state['paid_at']) {
                if($mode==='collection')$schedule('Orders','collection_expiry','collection_expired',$state['created_at'],OrdersSlaPolicy::collectionExpiry($state['created_at'],(int)$policy['collection_expiry_hours']),'customer_wait');
                if($mode==='courier')$schedule('Orders','courier_payment_wait','courier_payment_wait_expired',$state['created_at'],OrdersSlaPolicy::workingDue($state['created_at'],(int)$policy['courier_payment_wait_minutes'],$policy),'customer_wait');
            }
        }
        if($completed && ($paid||in_array($mode,['walk_in','delivery'],true))) {
            self::finish($engine,'Orders',$ref,'complete_order',$actor,$at);
            self::finish($engine,'Orders',$ref,'collection_expiry',$actor,$at);
            $db->prepare('UPDATE epi_v2_orders_tracking SET completed_at=? WHERE order_id=?')->execute([$at,$id]);
        }
    }
    private static function finish(DeadlineEngine $engine,string $module,string $ref,string $key,int $actor,string $at): void {
        if($actor>0)$engine->fulfilObject($module,$ref,$key,$actor,$at);
    }
    private static function assignPacker(PDO $db,string $ref,int $employee,int $actor,string $at): void {
        if($employee<=0||$actor<=0)return;
        $person=V2Store::one($db,"SELECT r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active'",[$employee]);
        $assigner=V2Store::one($db,"SELECT r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active'",[$actor]);
        if(!$person||!in_array($person['role_key'],['packer','packer_production_staff'],true))return;
        if(!$assigner||($actor!==$employee&&!in_array($assigner['role_key'],['owner_admin','front_desk_admin','marketing_sales','supervisor_manager'],true)))return;
        V2Store::lock($db,'Packing|'.$ref);
        $owners=new OwnershipPeriodEngine($db);$old=$owners->ownerAt('Packing',$ref,$at);
        if($old&&(int)$old['employee_id']===$employee)return;
        if(V2Store::one($db,"SELECT id FROM epi_v2_ownership_periods WHERE module='Packing' AND object_reference=? AND effective_from>=?",[$ref,$at]))throw new RuntimeException('Ambiguous same-time assignment requires review');
        $owners->closeCurrent('Packing',$ref,Support::timestamp($at),'Approved operational reassignment');
        $db->prepare("INSERT INTO epi_v2_ownership_periods(ownership_uuid,module,object_reference,employee_id,role_key,ownership_reason,effective_from,assigned_by,source) VALUES(?,'Packing',?,?,?,'approved_order_assignment',?,?,'orders_sla_v1')")->execute([Support::uuid(),$ref,$employee,$person['role_key'],$at,$actor]);
        V2Store::audit($db,'Packing|'.$ref,$actor,'Operational assignment; not employee acceptance',$old,['employee_id'=>$employee,'effective_from'=>$at]);
    }
}
