<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/FrontSession.php';
require_once __DIR__.'/WooShippingSource.php';
require_once __DIR__.'/PortalOrderRepository.php';

/** Historical recognition only: never creates or changes an Order payment. */
final class BackCapture
{
    private \PDO $db;
    private \Closure $remote;
    public function __construct(\PDO $db,\Closure $remote){$this->db=$db;$this->remote=$remote;}
    public function owner(array $actor):void
    {
        if(($actor['kind']??'')!=='employee'||empty($actor['active'])||($actor['role']??'')!=='owner_admin')throw new \DomainException('Owner access required.');
        $s=$this->db->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin'".($this->db->inTransaction()?' FOR UPDATE':''));$s->execute([$actor['id']]);
        if(!$s->fetchColumn())throw new \DomainException('Owner access changed.');
    }
    public static function time(string $value):\DateTimeImmutable
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('Africa/Windhoek'));
        if(!$d||$d->format('Y-m-d\TH:i')!==$value)throw new \DomainException('Valid Namibia date and time required.');
        return $d;
    }
    private function order(int $id,bool $lock=false):array
    {
        $s=$this->db->prepare('SELECT * FROM ops_orders WHERE id=? AND deleted_at IS NULL'.($lock?' FOR UPDATE':''));$s->execute([$id]);$o=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$o)throw new \DomainException('Order unavailable.');return $o;
    }
    public static function recordedHistory(array $order,array $remote,bool $fullyPaid):array
    {
        $parse=static function($value,string $zone):?\DateTimeImmutable{
            $value=str_replace('T',' ',trim((string)$value));
            if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value))return null;
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone($zone));
            return $date&&$date->format('Y-m-d H:i:s')===$value?$date:null;
        };
        $completion=$parse($remote['date_completed_gmt']??'', 'UTC');$source='Original POS completion';
        $paid=$fullyPaid?$parse($remote['date_paid_gmt']??'', 'UTC'):null;
        if($paid&&$paid>new \DateTimeImmutable('now',new \DateTimeZone('UTC')))throw new \DomainException('Recorded payment date cannot be in the future.');
        return ['recorded_completed_local'=>$completion?$completion->setTimezone(new \DateTimeZone('Africa/Windhoek'))->format('Y-m-d\TH:i'):'',
            'recorded_completed_gmt'=>$completion?$completion->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null,
            'completion_source'=>$completion?$source:'No recorded completion time',
            'recorded_payment_gmt'=>$paid?$paid->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null,
            'payment_date_source'=>$paid?'Original POS payment date':($fullyPaid&&$completion?'Owner-confirmed receipt by recorded completion':'Payment date unavailable'),
            'fully_paid_verified'=>$fullyPaid];
    }
    private function snapshot(array $o,array $remote):array
    {
        $e=WooShippingSource::verify($o,$remote);
        $s=$this->db->prepare('SELECT id FROM delivery_jobs WHERE order_id=? ORDER BY id');$s->execute([$o['id']]);$existing=$s->fetchAll(\PDO::FETCH_COLUMN);
        $s=$this->db->prepare('SELECT amount_cents FROM order_payment_allocations WHERE order_id=?'.($this->db->inTransaction()?' FOR UPDATE':''));$s->execute([$o['id']]);$amounts=$s->fetchAll(\PDO::FETCH_COLUMN);$paid=0;
        foreach($amounts as $a){if((int)$a<0)throw new \DomainException('Payment allocations require review.');$paid+=(int)$a;}
        $total=DeliveryPolicy::cents((string)$o['total_amount']);if($paid>$total)throw new \DomainException('Overpaid Order requires review.');
        // A status label alone is not evidence that shipping money was received.
        $fee=(int)$e['included_cents'];$verifiedFee=max(0,$paid-($total-$fee));
        $address=$e['address']?:PortalOrderRepository::shippingAddress((string)($o['notes']??''));
        $result=['id'=>(int)$o['id'],'number'=>$o['order_number']??(string)$o['id'],'customer'=>$o['customer_name']??'','mobile'=>$o['customer_contact']??$e['mobile'],'address'=>$address,'area'=>$e['area'],'fee_cents'=>$fee,'total_cents'=>$total,'allocated_cents'=>$paid,'verified_fee_cents'=>$verifiedFee,'payment_status'=>$o['payment_status']??'unknown','order_completed_gmt'=>$remote['date_completed_gmt']??null,'existing'=>$existing];
        $result+=self::recordedHistory($o,$remote,$paid===$total&&$total>0&&($o['payment_status']??'')==='paid');
        $result['version']=hash('sha256',json_encode([$result,WooShippingSource::fingerprint($o)],JSON_THROW_ON_ERROR));return $result;
    }
    public function candidates(array $actor,array $q):array
    {
        $this->owner($actor);$from=self::time((string)($q['from']??''));$to=self::time((string)($q['to']??''));
        if($from>$to||$from->diff($to)->days>93)throw new \DomainException('Choose a date range of up to 93 days.');
        $page=max(1,min(100000,(int)($q['page']??1)));$search=trim((string)($q['q']??''));if(strlen($search)>80)throw new \DomainException('Search is too long.');
        // Same Mode expression and order-created date as the Live Orders List.
        // POS completion and later updates never determine historical eligibility.
        $dateExpr=\function_exists('ops_order_display_datetime_expr')?\ops_order_display_datetime_expr('o'):'o.created_at';
        $where="o.deleted_at IS NULL AND o.archived_at IS NULL AND COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type)='delivery' AND {$dateExpr}>=? AND {$dateExpr}<? AND (LOCATE(?,o.order_number)>0 OR LOCATE(?,o.customer_name)>0)";
        $params=[$from->format('Y-m-d H:i:s'),$to->modify('+1 minute')->format('Y-m-d H:i:s'),$search,$search];
        $s=$this->db->prepare("SELECT COUNT(*) FROM ops_orders o WHERE ".$where);$s->execute($params);$total=(int)$s->fetchColumn();
        $s=$this->db->prepare("SELECT o.*, {$dateExpr} AS capture_order_date FROM ops_orders o WHERE ".$where." ORDER BY o.id DESC LIMIT 10 OFFSET ".(($page-1)*10));$s->execute($params);$rows=$s->fetchAll(\PDO::FETCH_ASSOC);$more=$page*10<$total;$out=[];
        foreach($rows as $o){
            $s=$this->db->prepare('SELECT id FROM delivery_jobs WHERE order_id=? ORDER BY id');$s->execute([$o['id']]);$existing=$s->fetchAll(\PDO::FETCH_COLUMN);
            $base=['id'=>(int)$o['id'],'number'=>$o['order_number'],'customer'=>$o['customer_name'],'order_created_at'=>$o['capture_order_date'],'mode'=>'Delivery','existing'=>$existing];
            try{$r=($this->remote)('orders/'.(int)$o['woo_order_id']);$out[]=$this->snapshot($o,$r)+$base;}catch(\Throwable $e){$out[]=$base+['error'=>'Original POS shipping/payment evidence unavailable; review this Order before capture.'];}
        }
        $drivers=$this->db->query("SELECT e.id,e.full_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.status='active' AND r.role_key='delivery_driver' AND p.active=1 ORDER BY e.full_name")->fetchAll(\PDO::FETCH_ASSOC);
        return ['orders'=>$out,'drivers'=>$drivers,'page'=>$page,'has_more'=>$more,'total'=>$total,'date_basis'=>'Order date displayed in Live Orders List','mode'=>'Delivery'];
    }
    public function save(array $actor,array $body):array
    {
        $this->owner($actor);$uuid=(string)($body['uuid']??'');$items=$body['items']??[];$driver=(int)($body['driver']??0);
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uuid)||!is_array($items)||!count($items)||count($items)>10||empty($body['confirmed']))throw new \DomainException('Confirm 1–10 reviewed deliveries.');
        $hash=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));$key='employee:'.$actor['id'];
        $replay=function()use($key,$uuid,$hash){$s=$this->db->prepare('SELECT * FROM delivery_requests WHERE actor_key=? AND request_uuid=?');$s->execute([$key,$uuid]);$r=$s->fetch(\PDO::FETCH_ASSOC);if(!$r)return null;if($r['action']!=='back_captured'||!hash_equals($r['request_hash'],$hash))throw new \DomainException('Request ID already used.');return json_decode($r['response_json'],true);};
        if($old=$replay())return $old;
        $remote=[];$seen=[];foreach($items as $i){$id=(int)($i['id']??0);if(isset($seen[$id]))throw new \DomainException('Duplicate selection.');$seen[$id]=true;$o=$this->order($id);$remote[$id]=($this->remote)('orders/'.(int)$o['woo_order_id']);}
        usort($items,static function($a,$b){return (int)$a['id']<=>(int)$b['id'];});
        $this->db->beginTransaction();
        try{
            $this->owner($actor);if($old=$replay()){$this->db->commit();return $old;}
            $s=$this->db->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.id=? AND e.status='active' AND r.role_key='delivery_driver' AND p.active=1 FOR UPDATE");$s->execute([$driver]);if(!$s->fetchColumn())throw new \DomainException('Select the verified Driver employee who performed these deliveries.');
            $saved=[];
            foreach($items as $i){
                $o=$this->order((int)$i['id'],true);
                if(strtolower((string)(($o['fulfilment_mode']??'')?:($o['order_type']??'')))!=='delivery')throw new \DomainException('Only Delivery-mode Orders can be back-captured.');
                $v=$this->snapshot($o,$remote[(int)$i['id']]);
                if($v['existing'])throw new \DomainException('Order '.$v['number'].' already has a Delivery record. Nothing was saved.');
                if(!hash_equals($v['version'],(string)($i['version']??'')))throw new \DomainException('An Order changed. Refresh and review again.');
                $useRecorded=!empty($i['use_recorded_history']);
                if($useRecorded&&(!$v['fully_paid_verified']||!$v['recorded_completed_local']))throw new \DomainException('Order '.$v['number'].' has no complete recorded completion/payment evidence. Nothing in this batch was saved.');
                $at=$useRecorded?(new \DateTimeImmutable($v['recorded_completed_gmt'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Windhoek')):self::time((string)($i['completed']??''));if($at>new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))throw new \DomainException('Historical completion cannot be in the future.');
                $address=trim((string)($i['address']??$v['address']));if($address===''||strlen($address)>2000)throw new \DomainException('Confirm the historical delivery address.');
                $handover=(string)($i['cash_handover']??'unknown');if(!in_array($handover,['none','held','handed_over','unknown'],true))throw new \DomainException('Confirm cash handover status.');
                $goods=DeliveryPolicy::cents((string)($i['cash_goods']??'0'));$cashFee=DeliveryPolicy::cents((string)($i['cash_fee']??'0'));
                if($goods>$v['total_cents']-$v['fee_cents']||$cashFee>$v['fee_cents']||(($goods+$cashFee)>0&&!in_array($handover,['held','handed_over'],true)))throw new \DomainException('Check separate cash amounts and handover status.');
                $note=trim((string)($i['note']??''));if(strlen($note)>2000||(($goods+$cashFee)>0&&strlen($note)<5))throw new \DomainException('Add a cash handover evidence/reconciliation note.');
                $utc=$at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');$ref='BACK-'.strtoupper(bin2hex(random_bytes(6)));
                $received=$handover==='held'?max(0,$v['verified_fee_cents']-$cashFee):$v['verified_fee_cents'];
                $s=$this->db->prepare("INSERT INTO delivery_jobs(public_reference,source,order_id,customer_name,customer_mobile,address,area,fee_cents,fee_order_component_cents,fee_payer,order_due_snapshot_cents,scheduled_date,driver_employee_id,arranged_by_employee_id,status,delivered_at,completed_at,notes) VALUES(?,'hambelela',?,?,?,?,?,?,? ,?, ?,?,?,?,'completed',?,?,?)");
                $s->execute([$ref,$o['id'],$v['customer'],$v['mobile'],$address,$v['area']?:'Historical address',$v['fee_cents'],$v['fee_cents'],$received===$v['fee_cents']?'already_paid':'customer',max(0,$v['total_cents']-$v['allocated_cents']),$at->format('Y-m-d'),$driver,$actor['id'],$utc,$utc,'Back-Captured. '.$note]);$id=(int)$this->db->lastInsertId();
                $receivedAt=$useRecorded?($v['recorded_payment_gmt']?:$utc):gmdate('Y-m-d H:i:s');
                $meta=['note'=>'Back-Captured: historical completion confirmed by Owner. '.$note,'completed_at'=>$utc,'timezone'=>'Africa/Windhoek','driver_employee_id'=>$driver,'recorded_by_employee_id'=>(int)$actor['id'],'original_order'=>$v,'cash_handover'=>$handover,'cash_product_cents'=>$goods,'cash_fee_cents'=>$cashFee,'cash_declaration_only'=>true,'tracking_evidence'=>false,'use_recorded_history'=>$useRecorded,'received_at'=>$receivedAt,'received_date_basis'=>$useRecorded?$v['payment_date_source']:'Capture audit time'];
                $this->db->prepare("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(?,'back_captured',?,?)")->execute([$id,$actor['id'],json_encode($meta,JSON_THROW_ON_ERROR)]);
                // Historical earned date differs from the audit entry date. No payment allocation writes.
                foreach(['fee_earned'=>$v['fee_cents'],'fee_received'=>$received] as $account=>$amount)if($amount>0)$this->db->prepare('INSERT INTO delivery_ledger(event_key,delivery_id,account,amount_cents,employee_id,created_at,reference,reason) VALUES(?,?,?,?,?,?,?,?)')->execute(['back:'.$id.':'.$account,$id,$account,$amount,$actor['id'],$account==='fee_earned'?$utc:$receivedAt,'order:'.$o['id'],'Back-Captured; original POS allocations only, no new charge'.($useRecorded?'; '.$v['payment_date_source']:'')]);
                $saved[]=['id'=>$id,'reference'=>$ref,'order'=>$v['number']];
            }
            $result=['saved'=>$saved];$this->db->prepare('INSERT INTO delivery_requests(actor_key,request_uuid,action,request_hash,response_json) VALUES(?,?,?,?,?)')->execute([$key,$uuid,'back_captured',$hash,json_encode($result,JSON_THROW_ON_ERROR)]);$this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
