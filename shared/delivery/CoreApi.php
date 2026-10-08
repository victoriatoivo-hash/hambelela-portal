<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryDeadline.php';
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
            $body['preview']=true;
            try{$quote=PortalOrderRepository::resolve($this->db,$orderId,$body,$evidence);if(!$quote)throw new \DomainException('Order unavailable.');
                $result=['goods_due'=>$quote['goods_due'],'delivery_fee'=>$quote['fee_cents'],'fee_due'=>$quote['fee_payer']==='already_paid'?0:$quote['fee_cents'],'fee_payer'=>$quote['fee_payer'],'area'=>$quote['area']];
                if(!empty($quote['source_order']))$result=array_merge($result,$quote,['delivery_fee'=>$quote['fee_cents'],'fee_due'=>$quote['fee_due'],'verification'=>hash('sha256',json_encode([$orderId,$quote['total_cents'],$quote['paid_cents'],$quote['fee_cents'],$quote['customer_mobile']],JSON_THROW_ON_ERROR))]);
                unset($result['shipping_evidence']);$this->db->rollBack();return $result;
            }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        }
        if(in_array($action,['deadline','inline','note','fail','revise','cancel','receipt_issue','resolve_receipt_issue'],true)){
            $balance=new OrderBalanceAdapter();$service=new DeliveryService($this->db,array_keys($this->methods),static function($db,$job)use($balance){return $balance->read($db,$job);});
            switch($action){
                case 'deadline':return $service->deadline($actor,$id,$uuid,$body);
                case 'inline':return $service->inlineUpdate($actor,$id,$uuid,$body);
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
            if(!empty($body['source_order'])){$details['source_order']=true;$details['verification']=(string)($body['verification']??'');}
            $details['deadline']=DeliveryDeadline::normalize($body);
            $driverId=(int)($body['driver']??0);
            $replay=DeliveryArrangement::replay($this->db,$actor,(int)($body['order']??0),$driverId,(string)($body['date']??''),$uuid,$details);
            if($replay!==null)return $replay;
            $shippingEvidence=$this->shippingSource?WooShippingSource::fetch($this->db,(int)($body['order']??0),$this->shippingSource):null;
            $service=new DeliveryArrangement($this->db,static function($db,$id)use($details,$driverId,$shippingEvidence){
                $order=PortalOrderRepository::resolve($db,$id,$details,$shippingEvidence);
                if(!empty($details['source_order'])){
                    $fingerprint=hash('sha256',json_encode([$id,$order['total_cents'],$order['paid_cents'],$order['fee_cents'],$order['customer_mobile']],JSON_THROW_ON_ERROR));
                    if(!hash_equals($fingerprint,$details['verification']))throw new \DomainException('Order changed. Review the refreshed confirmation before adding it.');
                }
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
        // Filter against verified live balances BEFORE pagination, not just the loaded page.
        $payment=(string)($filters['payment']??'');
        if($payment!==''){
            if(!in_array($payment,['collect','paid','review'],true))throw new \DomainException('Invalid payment filter.');
            $wanted=max(1,min(100000,(int)($filters['page']??1)));$size=max(1,min(200,(int)($filters['limit']??200)));$skip=($wanted-1)*$size;
            unset($filters['payment']);$filters['limit']=200;$filters['page']=1;$matches=0;$selected=[];$result=null;
            do{$batch=$this->board($actor,$filters);if($result===null)$result=$batch;
                foreach($batch['jobs'] as $job){$due=$job['remaining']===null?null:array_sum($job['remaining']);$review=$due===null||$job['collection_state']==='Shortfall / issue';
                    $accept=$payment==='review'?$review:($payment==='collect'?$due>0:($due===0&&!$review));
                    if($accept){if($matches>=$skip&&count($selected)<$size)$selected[]=$job;$matches++;}}
                $filters['page']++;
            }while($batch['has_more']);
            $result['jobs']=$selected;$result['total']=$matches;$result['page']=$wanted;$result['has_more']=$skip+count($selected)<$matches;return $result;
        }
        $driver=$actor['role']==='delivery_driver';
        $today=(new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
        $where=$driver?'j.driver_employee_id=?':'1=1';$params=$driver?[$actor['id']]:[];
        // Admin oversight filters records without impersonating a Driver or widening Driver access.
        if(!$driver&&(int)($filters['driver_id']??0)>0){$where.=' AND j.driver_employee_id=?';$params[]=(int)$filters['driver_id'];}
        if((int)($filters['id']??0)>0){$where.=' AND j.id=?';$params[]=(int)$filters['id'];}
        $state=(string)($filters['state']??'all');
        if($state==='active')$where.=" AND j.status NOT IN ('completed','cancelled')";
        elseif($state==='for_delivery')$where.=" AND j.status IN ('ready','out_for_delivery','failed')";
        elseif($state==='driver_completed')$where.=" AND j.status IN ('completed','awaiting_reconciliation')";
        elseif($state==='today'){$where.=' AND j.scheduled_date=?';$params[]=$today;}
        elseif($state==='urgent')$where.=" AND j.urgent=1 AND j.status NOT IN ('completed','cancelled')";
        elseif(in_array($state,['ready','completed','failed','out_for_delivery','awaiting_reconciliation'],true)){$where.=' AND j.status=?';$params[]=$state;}
        elseif($state!=='all')throw new \DomainException('Invalid Delivery view.');
        $source=(string)($filters['source']??'');if($source!==''){if(!in_array($source,['hambelela','partner'],true))throw new \DomainException('Invalid source.');$where.=' AND j.source=?';$params[]=$source;}
        $query=trim((string)($filters['q']??''));if(strlen($query)>120)throw new \DomainException('Search text is too long.');
        if($query!==''){$where.=" AND (LOCATE(?,CONCAT_WS(' ',j.public_reference,j.customer_name,j.customer_mobile,j.address,j.area,e.full_name))>0 OR EXISTS(SELECT 1 FROM ops_orders search_order WHERE search_order.id=j.order_id AND CAST(search_order.woo_order_id AS CHAR)=?))";$params[]=$query;$params[]=ltrim($query,'#');}
        $area=trim((string)($filters['area']??''));if(strlen($area)>190)throw new \DomainException('Area is too long.');if($area!==''){$where.=' AND j.area=?';$params[]=$area;}
        if(!empty($filters['urgent']))$where.=' AND j.urgent=1';
        $page=max(1,min(100000,(int)($filters['page']??1)));$limit=max(1,min(200,(int)($filters['limit']??200)));$offset=($page-1)*$limit;
        $deadline=DeliveryDeadline::sortExpression();
        $priority="CASE WHEN $deadline IS NOT NULL AND $deadline<>'null' AND $deadline<UTC_TIMESTAMP() THEN 0 WHEN $deadline IS NOT NULL AND $deadline<>'null' THEN 1 WHEN j.urgent=1 THEN 2 ELSE 3 END, $deadline ASC,j.urgent DESC";
        $order=in_array($state,['completed','driver_completed'],true)?'COALESCE(j.delivered_at,j.completed_at,j.updated_at) DESC,j.id DESC':"j.status IN ('completed','cancelled'),$priority,j.scheduled_date ASC,j.id ASC";
        $sorts=['oldest'=>'j.id ASC','newest'=>'j.id DESC','customer'=>'j.customer_name ASC,j.id ASC','updated'=>'j.updated_at DESC,j.id DESC'];
        if(isset($sorts[$filters['sort']??'']))$order="j.status IN ('completed','cancelled'),$priority,".$sorts[$filters['sort']];
        $s=$this->db->prepare('SELECT COUNT(*) FROM delivery_jobs j LEFT JOIN ops_employees e ON e.id=j.driver_employee_id WHERE '.$where);$s->execute($params);$total=(int)$s->fetchColumn();
        $s=$this->db->prepare("SELECT j.*,e.full_name driver_name,CASE WHEN source='partner' THEN (SELECT p.name FROM delivery_partners p WHERE p.id=j.partner_id) ELSE 'Hambelela' END source_label FROM delivery_jobs j LEFT JOIN ops_employees e ON e.id=j.driver_employee_id WHERE ".$where.' ORDER BY '.$order.' LIMIT '.$limit.' OFFSET '.$offset);$s->execute($params);$jobs=$s->fetchAll(\PDO::FETCH_ASSOC);$balance=new OrderBalanceAdapter();
        $today=(new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
        $start=(new \DateTimeImmutable($today,new \DateTimeZone('Africa/Windhoek')))->setTimezone(new \DateTimeZone('UTC'));
        $s=$this->db->prepare("SELECT COUNT(CASE WHEN status='ready' THEN 1 END) ready,COUNT(CASE WHEN scheduled_date=? AND status<>'cancelled' THEN 1 END) today,COUNT(CASE WHEN urgent=1 AND status NOT IN ('completed','cancelled') THEN 1 END) urgent,COUNT(CASE WHEN status='out_for_delivery' THEN 1 END) out_for_delivery,COUNT(CASE WHEN status='awaiting_reconciliation' THEN 1 END) awaiting_reconciliation,COUNT(CASE WHEN status='completed' AND completed_at>=? AND completed_at<? THEN 1 END) completed_today FROM delivery_jobs WHERE 1=1".($driver?' AND driver_employee_id=?':''));
        $args=[$today,$start->format('Y-m-d H:i:s'),$start->modify('+1 day')->format('Y-m-d H:i:s')];if($driver)$args[]=$actor['id'];$s->execute($args);$metrics=array_map('intval',$s->fetch(\PDO::FETCH_ASSOC));
        $scope=$driver?(int)$actor['id']:(int)($filters['driver_id']??0);
        if($scope>0){
            $s=$this->db->prepare("SELECT COUNT(CASE WHEN status IN ('ready','out_for_delivery','failed') THEN 1 END) for_delivery,COUNT(CASE WHEN status IN ('completed','awaiting_reconciliation') THEN 1 END) driver_completed,COUNT(CASE WHEN urgent=1 AND status IN ('ready','out_for_delivery','failed') THEN 1 END) driver_urgent FROM delivery_jobs WHERE driver_employee_id=?");$s->execute([$scope]);$metrics=array_merge($metrics,array_map('intval',$s->fetch(\PDO::FETCH_ASSOC)));
        }
        foreach($jobs as &$j){
            $j['deadline']=DeliveryDeadline::present(DeliveryDeadline::latest($this->db,(int)$j['id']),$j);
            if($j['source']==='hambelela'){$s=$this->db->prepare('SELECT * FROM ops_orders WHERE id=?');$s->execute([$j['order_id']]);$o=$s->fetch(\PDO::FETCH_ASSOC);$j['order_number']=$o['order_number']??('#'.($o['woo_order_id']??$j['order_id']));$j['order_payment_status']=$o['payment_status']??'';$j['order_payment_method']=$o['payment_method']??'';}
            $s=$this->db->prepare('SELECT r.id,r.payment_method,r.transaction_reference,r.order_goods_cents,r.delivery_fee_cents,r.partner_cod_cents,r.status,r.reconciled_at,c.full_name collected_by,n.full_name reconciled_by FROM delivery_receipts r LEFT JOIN ops_employees c ON c.id=r.collected_by_employee_id LEFT JOIN ops_employees n ON n.id=r.reconciled_by_employee_id WHERE r.delivery_id=? ORDER BY r.id');$s->execute([$j['id']]);$j['receipts']=$s->fetchAll(\PDO::FETCH_ASSOC);
            $s=$this->db->prepare('SELECT v.event_type,v.created_at,v.metadata_json,COALESCE(e.full_name,p.display_name) actor_name FROM delivery_events v LEFT JOIN ops_employees e ON e.id=v.employee_id LEFT JOIN delivery_partner_users p ON p.id=v.partner_user_id WHERE v.delivery_id=? ORDER BY v.id DESC LIMIT 30');$s->execute([$j['id']]);$j['history']=array_map(static function($e){$m=json_decode($e['metadata_json'],true);return ['type'=>$e['event_type'],'at'=>$e['created_at'],'actor'=>$e['actor_name'],'note'=>$m['note']??'','reason'=>$m['reason']??'','deadline'=>is_array($m['deadline']??null)?DeliveryDeadline::label($m['deadline']):($m['deadline']??null)];},$s->fetchAll(\PDO::FETCH_ASSOC));
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
