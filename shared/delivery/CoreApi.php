<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryService.php';require_once __DIR__.'/DeliveryArrangement.php';
require_once __DIR__.'/PortalOrderRepository.php';require_once __DIR__.'/OrderPaymentAdapter.php';require_once __DIR__.'/OrderBalanceAdapter.php';
final class CoreApi
{
    private \PDO $db;
    private array $methods;
    private ?\Closure $shippingSource;
    public function __construct(\PDO $db,array $methods,?\Closure $shippingSource=null){$this->db=$db;$this->methods=$methods;$this->shippingSource=$shippingSource;}
    public function command(array $actor,array $body):array
    {
        $id=(int)($body['id']??0);$uuid=(string)($body['uuid']??'');$action=(string)($body['action']??'');
        if($action==='quote'){
            if(!DeliveryPolicy::can($actor,'delivery_arrange'))throw new \DomainException('Arrangement forbidden.');
            if($this->db->inTransaction())throw new \LogicException('Quote owns its transaction.');
            $orderId=(int)($body['order']??0);$evidence=$this->shippingSource?WooShippingSource::fetch($this->db,$orderId,$this->shippingSource):null;
            $this->db->beginTransaction();
            try{$quote=PortalOrderRepository::resolve($this->db,$orderId,$body,$evidence);if(!$quote)throw new \DomainException('Order unavailable.');
                $result=['goods_due'=>$quote['goods_due'],'delivery_fee'=>$quote['fee_cents'],'fee_due'=>$quote['fee_payer']==='already_paid'?0:$quote['fee_cents'],'fee_payer'=>$quote['fee_payer'],'area'=>$quote['area']];
                $this->db->rollBack();return $result;
            }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        }
        if(in_array($action,['note','fail','revise','cancel','receipt_issue','resolve_receipt_issue'],true)){
            $balance=new OrderBalanceAdapter();$service=new DeliveryService($this->db,array_keys($this->methods),static function($db,$job)use($balance){return $balance->read($db,$job);});
            switch($action){
                case 'note':return $service->note($actor,$id,$uuid,(string)($body['note']??''));
                case 'fail':return $service->fail($actor,$id,$uuid,(string)($body['reason']??''),(string)($body['note']??''));
                case 'revise':return $service->revise($actor,$id,$uuid,$body);
                case 'cancel':return $service->cancel($actor,$id,$uuid,(int)($body['version']??0),(string)($body['reason']??''));
                case 'receipt_issue':case 'resolve_receipt_issue':return $service->receiptIssue($actor,$id,$uuid,(int)($body['receipt']??0),(string)($body['reason']??''),$action==='resolve_receipt_issue');
            }
        }
        if($action==='arrange'){
            if(!DeliveryPolicy::can($actor,'delivery_arrange'))throw new \DomainException('Arrangement forbidden.');
            $details=[];foreach(['address','mobile','area','manual_fee','manual_reason','notes'] as $k)$details[$k]=trim((string)($body[$k]??''));$details['zone_id']=(int)($body['zone_id']??0);$details['urgent']=!empty($body['urgent']);
            $details['additional_delivery']=!empty($body['additional_delivery']);$details['additional_reason']=trim((string)($body['additional_reason']??''));
            $driverId=(int)($body['driver']??0);
            $replay=DeliveryArrangement::replay($this->db,$actor,(int)($body['order']??0),$driverId,(string)($body['date']??''),$uuid,$details);
            if($replay!==null)return $replay;
            $shippingEvidence=$this->shippingSource?WooShippingSource::fetch($this->db,(int)($body['order']??0),$this->shippingSource):null;
            $service=new DeliveryArrangement($this->db,static function($db,$id)use($details,$driverId,$shippingEvidence){
                $order=PortalOrderRepository::resolve($db,$id,$details,$shippingEvidence);
                // Verify and lock the current account/role as part of the same assignment transaction.
                $s=$db->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.id=? AND e.status='active' AND r.role_key='delivery_driver' AND p.active=1 FOR UPDATE");$s->execute([$driverId]);if(!$s->fetchColumn())throw new \DomainException('Verified active driver required.');
                return $order;
            });
            return $service->arrange($actor,(int)($body['order']??0),(int)($body['driver']??0),(string)($body['date']??''),$uuid,$details);
        }
        if(!in_array($action,['start','collect','complete','reconcile'],true))throw new \DomainException('Unsupported Delivery action.');
        $evidence=$this->shippingSource?WooShippingSource::forJob($this->db,$actor,$id,$action,$this->shippingSource):null;
        $balance=new OrderBalanceAdapter();$payment=new OrderPaymentAdapter($this->methods);
        $read=static function($db,$job)use($balance,$evidence){if($evidence!==null)WooShippingSource::assertLockedJob($db,$job,$evidence);return $balance->read($db,$job);};
        $apply=static function($db,$job,$receipt,$actor)use($payment,$evidence){if($evidence!==null)WooShippingSource::assertLockedJob($db,$job,$evidence);$payment->apply($db,$job,$receipt,$actor);};
        $service=new DeliveryService($this->db,array_keys($this->methods),$read);
        switch($action){
            case 'start': return $service->start($actor,$id,$uuid);
            case 'collect': return $service->collect($actor,$id,$uuid,['order_goods'=>DeliveryPolicy::cents((string)($body['goods']??'0')),'delivery_fee'=>DeliveryPolicy::cents((string)($body['fee']??'0')),'partner_cod'=>DeliveryPolicy::cents((string)($body['cod']??'0'))],(string)($body['method']??''),(string)($body['reference']??''));
            case 'complete': return $service->complete($actor,$id,$uuid);
            case 'reconcile': return $service->reconcile($actor,$id,$uuid,(int)($body['receipt']??0),$apply);
            default: throw new \DomainException('Unsupported Delivery action.');
        }
    }
    public function board(array $actor,array $filters=[]):array
    {
        if(!DeliveryPolicy::can($actor,'delivery_view')&&!DeliveryPolicy::can($actor,'delivery_view_assigned'))throw new \DomainException('Delivery access denied.');
        $driver=$actor['role']==='delivery_driver';
        $today=(new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
        $where=$driver?'j.driver_employee_id=?':'1=1';$params=$driver?[$actor['id']]:[];
        if((int)($filters['id']??0)>0){$where.=' AND j.id=?';$params[]=(int)$filters['id'];}
        $state=(string)($filters['state']??'all');
        if($state==='active')$where.=" AND j.status NOT IN ('completed','cancelled')";
        elseif($state==='today'){$where.=' AND j.scheduled_date=?';$params[]=$today;}
        elseif($state==='urgent')$where.=" AND j.urgent=1 AND j.status NOT IN ('completed','cancelled')";
        elseif(in_array($state,['completed','out_for_delivery','awaiting_reconciliation'],true)){$where.=' AND j.status=?';$params[]=$state;}
        elseif($state!=='all')throw new \DomainException('Invalid Delivery view.');
        $source=(string)($filters['source']??'');if($source!==''){if(!in_array($source,['hambelela','partner'],true))throw new \DomainException('Invalid source.');$where.=' AND j.source=?';$params[]=$source;}
        $query=trim((string)($filters['q']??''));if(strlen($query)>120)throw new \DomainException('Search text is too long.');
        if($query!==''){$where.=" AND LOCATE(?,CONCAT_WS(' ',j.public_reference,j.customer_name,j.customer_mobile,j.address,j.area,e.full_name))>0";$params[]=$query;}
        $page=max(1,min(100000,(int)($filters['page']??1)));$offset=($page-1)*200;
        $s=$this->db->prepare('SELECT COUNT(*) FROM delivery_jobs j LEFT JOIN ops_employees e ON e.id=j.driver_employee_id WHERE '.$where);$s->execute($params);$total=(int)$s->fetchColumn();
        $s=$this->db->prepare("SELECT j.*,e.full_name driver_name,CASE WHEN source='partner' THEN (SELECT p.name FROM delivery_partners p WHERE p.id=j.partner_id) ELSE 'Hambelela' END source_label FROM delivery_jobs j LEFT JOIN ops_employees e ON e.id=j.driver_employee_id WHERE ".$where." ORDER BY j.status IN ('completed','cancelled'),j.urgent DESC,j.id ASC LIMIT 200 OFFSET ".$offset);$s->execute($params);$jobs=$s->fetchAll(\PDO::FETCH_ASSOC);$balance=new OrderBalanceAdapter();
        $today=(new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
        $start=(new \DateTimeImmutable($today,new \DateTimeZone('Africa/Windhoek')))->setTimezone(new \DateTimeZone('UTC'));
        $s=$this->db->prepare("SELECT COUNT(CASE WHEN scheduled_date=? AND status<>'cancelled' THEN 1 END) today,COUNT(CASE WHEN urgent=1 AND status NOT IN ('completed','cancelled') THEN 1 END) urgent,COUNT(CASE WHEN status='out_for_delivery' THEN 1 END) out_for_delivery,COUNT(CASE WHEN status='awaiting_reconciliation' THEN 1 END) awaiting_reconciliation,COUNT(CASE WHEN status='completed' AND completed_at>=? AND completed_at<? THEN 1 END) completed_today FROM delivery_jobs WHERE 1=1".($driver?' AND driver_employee_id=?':''));
        $args=[$today,$start->format('Y-m-d H:i:s'),$start->modify('+1 day')->format('Y-m-d H:i:s')];if($driver)$args[]=$actor['id'];$s->execute($args);$metrics=array_map('intval',$s->fetch(\PDO::FETCH_ASSOC));
        foreach($jobs as &$j){
            $s=$this->db->prepare('SELECT id,payment_method,order_goods_cents,delivery_fee_cents,partner_cod_cents,status FROM delivery_receipts WHERE delivery_id=? ORDER BY id');$s->execute([$j['id']]);$j['receipts']=$s->fetchAll(\PDO::FETCH_ASSOC);
            $s=$this->db->prepare('SELECT v.event_type,v.created_at,v.metadata_json,COALESCE(e.full_name,p.display_name) actor_name FROM delivery_events v LEFT JOIN ops_employees e ON e.id=v.employee_id LEFT JOIN delivery_partner_users p ON p.id=v.partner_user_id WHERE v.delivery_id=? ORDER BY v.id DESC LIMIT 30');$s->execute([$j['id']]);$j['history']=array_map(static function($e){$m=json_decode($e['metadata_json'],true);return ['type'=>$e['event_type'],'at'=>$e['created_at'],'actor'=>$e['actor_name'],'note'=>$m['note']??'','reason'=>$m['reason']??''];},$s->fetchAll(\PDO::FETCH_ASSOC));
            try{$remaining=$balance->read($this->db,$j);foreach($j['receipts'] as $r)if(in_array($r['status'],['collected','issue'],true)){$remaining['order_goods']-=(int)$r['order_goods_cents'];$remaining['delivery_fee']-=(int)$r['delivery_fee_cents'];$remaining['partner_cod']-=(int)$r['partner_cod_cents'];}if(min($remaining)<0)throw new \DomainException('Payment changed; review required.');$j['remaining']=$remaining;}
            catch(\DomainException $e){$j['remaining']=null;$j['review_message']=$e->getMessage();}
            $states=array_column($j['receipts'],'status');
            $j['collection_state']=in_array('issue',$states,true)?'Shortfall / issue':($j['remaining']===null?'Payment review required':(array_sum($j['remaining'])>0?'Due':(in_array('collected',$states,true)?'Collected by Driver':(in_array('reconciled',$states,true)?'Received / reconciled':'Not required'))));
        }unset($j);
        $drivers=[];if(DeliveryPolicy::can($actor,'delivery_arrange'))$drivers=$this->db->query("SELECT e.id,e.full_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.status='active' AND r.role_key='delivery_driver' AND p.active=1 ORDER BY e.full_name")->fetchAll(\PDO::FETCH_ASSOC);
        $profile=null;if($driver){$s=$this->db->prepare('SELECT e.id,e.full_name,c.mobile FROM ops_employees e LEFT JOIN delivery_driver_credentials c ON c.employee_id=e.id WHERE e.id=?');$s->execute([$actor['id']]);$profile=$s->fetch(\PDO::FETCH_ASSOC)?:null;}
        return ['jobs'=>$jobs,'page'=>$page,'total'=>$total,'has_more'=>$offset+count($jobs)<$total,'metrics'=>$metrics,'today'=>$today,'methods'=>$this->methods,'drivers'=>$drivers,'profile'=>$profile,'can_manage_access'=>$actor['role']==='owner_admin','can_accounting'=>DeliveryPolicy::can($actor,'delivery_accounting_view'),'can_arrange'=>DeliveryPolicy::can($actor,'delivery_arrange'),'can_reconcile'=>DeliveryPolicy::can($actor,'delivery_reconcile'),'driver'=>$driver];
    }
}
