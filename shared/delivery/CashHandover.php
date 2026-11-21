<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/EmployeeDriverSession.php';
require_once __DIR__.'/FrontSession.php';

/** Custody only. Never edits order payments, VAT, receipts or accounting ledger. */
final class CashHandover
{
    private \PDO $db;
    public function __construct(\PDO $db) {$this->db=$db;}
    private function rows(string $sql,array $v=[]):array {$s=$this->db->prepare($sql);$s->execute($v);return $s->fetchAll(\PDO::FETCH_ASSOC);}
    private function exec(string $sql,array $v=[]):void {$this->db->prepare($sql)->execute($v);}
    public static function ready(\PDO $db):bool {try{$db->query('SELECT id FROM delivery_cash_handovers LIMIT 0');return true;}catch(\PDOException $e){if($e->getCode()==='42S02')return false;throw $e;}}
    public static function actor(\PDO $db,int $id):array {
        try{return EmployeeDriverSession::actor($db,$id);}catch(\DomainException $e){}
        $a=FrontSession::actor($db,$id);if(!DeliveryPolicy::can($a,'delivery_reconcile'))throw new \DomainException('Cash receipt permission required.');return $a;
    }
    public static function money(int $minor):string {return ($minor<0?'-':'').'N$'.number_format(intdiv(abs($minor),100),0,'.',',').'.'.str_pad((string)(abs($minor)%100),2,'0',STR_PAD_LEFT);}
    private static function minor($v,bool $zero=false):int {if(!is_int($v)||$v<($zero?0:1)||$v>9999999999)throw new \DomainException('Use a valid NAD amount in whole cents.');return $v;}
    private static function note($v,bool $required=false):string {if(!is_string($v)||!mb_check_encoding($v,'UTF-8'))throw new \DomainException('Invalid note.');$v=trim($v);if(mb_strlen($v)>2000||($required&&mb_strlen($v)<10)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v))throw new \DomainException('Explain the difference or correction in 10–2,000 characters.');return $v;}
    private function scope(int $employee,int $requested=0):array {
        $a=self::actor($this->db,$employee);$driver=$a['role']==='delivery_driver';
        if($driver&&$requested!==0&&$requested!==$employee)throw new \DomainException('Cash records are private to their driver.');
        return [$a,$driver?$employee:$requested];
    }
    /** Receipts stay attributed to the collector even if an old assignment changed. */
    public function collections(int $driver):array {
        $rows=$this->rows("SELECT r.*,j.order_id,j.public_reference,j.source,j.driver_employee_id,j.status job_status,j.customer_name,j.area,o.order_number FROM delivery_receipts r JOIN delivery_jobs j ON j.id=r.delivery_id LEFT JOIN ops_orders o ON o.id=j.order_id WHERE r.collected_by_employee_id=? AND LOWER(r.payment_method)='cash' ORDER BY r.collected_at DESC,r.id DESC",[$driver]);$out=[];
        foreach($rows as $r){
            $id=(int)$r['id'];$total=(int)$r['order_goods_cents']+(int)$r['delivery_fee_cents']+(int)$r['partner_cod_cents'];$confirmed=0;$pending=0;$history=[];$blocked=null;
            $alloc=self::ready($this->db)?$this->rows('SELECT a.allocated_minor,h.id,h.state,h.declared_minor,h.expected_minor,h.counted_minor,h.receiver_id,h.received_at FROM delivery_cash_allocations a JOIN delivery_cash_handovers h ON h.id=a.handover_id WHERE a.receipt_id=? ORDER BY h.id',[$id]):[];
            $correction=null;
            foreach($alloc as $h){$amount=(int)$h['allocated_minor'];if($h['state']==='confirmed')$confirmed+=$amount;if(in_array($h['state'],['awaiting_front_desk_receipt','disputed'],true))$pending+=$amount;if($h['state']==='rejected')$correction=(int)$h['id'];$history[]=['reference'=>'CH-'.$h['id'],'state'=>$h['state'],'allocated_minor'=>$amount,'received_at'=>$h['received_at']];}
            // Legacy events are independent receiver counts, not driver declarations.
            $legacy=$this->rows("SELECT employee_id,metadata_json FROM delivery_events WHERE delivery_id=? AND event_type='cash_handover' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.receipt_id'))=? ORDER BY id",[$r['delivery_id'],(string)$id]);
            foreach($legacy as $e){$m=json_decode($e['metadata_json'],true,32,JSON_THROW_ON_ERROR);if(!empty($m['cash_phase11']))continue;
                if(count($legacy)===1&&(int)$e['employee_id']!==$driver&&($m['counted_cents']??null)===$total&&($m['expected_cents']??null)===$total&&!$alloc){$confirmed=$total;$history[]=['reference'=>'Legacy shop count','state'=>'confirmed','allocated_minor'=>$total,'received_at'=>null];}
                else $blocked='Earlier cash count needs review.';
            }
            if($r['status']==='reversed'){
                if($r['reversed_at']&&$r['reversed_by_employee_id']&&trim((string)$r['reversal_reason'])!==''&&$confirmed===0&&$pending===0)$total=0;
                else $blocked='Reversal needs custody review.';
            }elseif($r['status']==='issue')$blocked='Collection discrepancy requires review.';
            if($r['status']==='reconciled'&&$confirmed<$total)$blocked='Accounted receipt has no verified shop custody record.';
            if((int)$r['driver_employee_id']!==$driver)$blocked='Assignment changed; dispatch must review custody.';
            if($r['job_status']==='cancelled')$blocked='Cancelled delivery; cash custody needs review.';
            if($r['source']==='hambelela'&&!$r['order_number'])$blocked='Source order is unavailable; cash remains recorded.';
            if($total<0||$confirmed+$pending>$total)throw new \DomainException('Cash allocation balances need review. No amount has been guessed.');
            $name=trim((string)$r['customer_name']);$parts=preg_split('/\s+/u',$name);$name=($parts[0]??'Customer').(count($parts)>1?' '.mb_substr(end($parts),0,1).'.':'');
            $out[]=['id'=>$id,'event_ref'=>$r['collection_uuid'],'delivery_id'=>(int)$r['delivery_id'],'order_id'=>$r['order_id']?(int)$r['order_id']:null,'reference'=>$r['order_number']?:$r['public_reference'],'customer'=>(int)$r['driver_employee_id']===$driver?$name:'Previous assignment','area'=>(int)$r['driver_employee_id']===$driver?$r['area']:'','collected_minor'=>$total,'collected_at'=>$r['collected_at'],'payment_source'=>$r['source'],'status'=>$r['status'],'confirmed_minor'=>$confirmed,'pending_minor'=>$pending,'due_minor'=>$total-$confirmed,'available_minor'=>$total-$confirmed-$pending,'eligible'=>$blocked===null&&$r['status']==='collected','review'=>$blocked,'correction_of'=>$correction,'history'=>$history];
        }return $out;
    }
    public function summary(int $employee,int $requested=0):array {
        [$actor,$driver]=$this->scope($employee,$requested);if($driver<1)throw new \DomainException('Choose a driver.');
        $now=new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek'));$today=$now->format('Y-m-d');$out=['currency'=>'NAD','business_date'=>$today,'timezone'=>'Africa/Windhoek','collected_today_minor'=>0,'today_count'=>0,'due_minor'=>0,'pending_minor'=>0,'unsubmitted_minor'=>0,'review_minor'=>0,'collections'=>[],'handovers'=>[]];
        foreach($this->collections($driver) as $r){$date=(new \DateTimeImmutable($r['collected_at'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Windhoek'))->format('Y-m-d');if($date===$today&&$r['collected_minor']>0){$out['collected_today_minor']+=$r['collected_minor'];$out['today_count']++;}
            $out['due_minor']+=$r['due_minor'];$out['pending_minor']+=$r['pending_minor'];if($r['eligible'])$out['unsubmitted_minor']+=$r['available_minor'];if($r['review'])$out['review_minor']+=$r['due_minor'];if($r['due_minor']>0||$date===$today)$out['collections'][]=$r;
        }
        if(self::ready($this->db))$out['handovers']=array_map(fn($h)=>$this->details($h),$this->rows('SELECT id,state,expected_minor,declared_minor,counted_minor,note,correction_of,created_at,received_at FROM delivery_cash_handovers WHERE driver_id=? ORDER BY id DESC LIMIT 100',[$driver]));
        return $out;
    }
    private function details(array $h):array {
        $h['allocations']=$this->rows("SELECT a.receipt_id,a.allocated_minor,r.delivery_id,r.status collection_status,COALESCE(NULLIF(o.order_number,''),j.public_reference) reference FROM delivery_cash_allocations a JOIN delivery_receipts r ON r.id=a.receipt_id JOIN delivery_jobs j ON j.id=r.delivery_id LEFT JOIN ops_orders o ON o.id=j.order_id WHERE a.handover_id=? ORDER BY a.receipt_id",[$h['id']]);
        $h['audit']=array_map(static function($e){$m=json_decode($e['payload_json'],true,32,JSON_THROW_ON_ERROR);return ['id'=>(int)$e['id'],'action'=>$e['action'],'actor_id'=>(int)$e['actor_id'],'created_at'=>$e['created_at'],'note'=>$m['note']??'','counted_minor'=>$m['counted_minor']??null,'declared_difference_minor'=>$m['declared_difference_minor']??$m['difference_minor']??null,'receiver_difference_minor'=>$m['receiver_difference_minor']??null];},$this->rows('SELECT id,actor_id,action,payload_json,created_at FROM delivery_cash_audit WHERE handover_id=? ORDER BY id',[$h['id']]));return $h;
    }
    public function inbox(int $employee):array {$a=self::actor($this->db,$employee);if($a['role']==='delivery_driver')throw new \DomainException('Front desk permission required.');return array_map(fn($h)=>$this->details($h),$this->rows("SELECT h.*,e.full_name driver_name FROM delivery_cash_handovers h JOIN ops_employees e ON e.id=h.driver_id ORDER BY FIELD(h.state,'awaiting_front_desk_receipt','disputed','rejected','confirmed','reversed'),h.id DESC LIMIT 100"));}
    public function review(int $employee,array $p):array {
        EmployeeDriverSession::actor($this->db,$employee);if(($p['currency']??'')!=='NAD')throw new \DomainException('Only NAD is supported.');
        $list=$p['allocations']??null;if(!is_array($list)||!$list||count($list)>100)throw new \DomainException('Select 1–100 cash collections.');$map=array_column($this->collections($employee),null,'id');$seen=[];$sum=0;$alloc=[];$correction=$p['correction_of']??null;
        if($correction!==null&&(!is_int($correction)||$correction<1))throw new \DomainException('Invalid correction reference.');
        foreach($list as $a){if(!is_array($a)||!is_int($a['receipt_id']??null)||isset($seen[$a['receipt_id']]))throw new \DomainException('Invalid or duplicate collection.');$id=$a['receipt_id'];$seen[$id]=true;$r=$map[$id]??null;$n=self::minor($a['minor']??null);
            if(!$r||!$r['eligible']||$n>$r['available_minor'])throw new \DomainException('A cash collection is unavailable or its balance changed. Refresh and review.');
            if($r['correction_of']!==null&&$correction!==$r['correction_of'])throw new \DomainException('Select the rejected handover reference before resubmitting.');
            $sum+=$n;$alloc[]=['receipt_id'=>$id,'delivery_id'=>$r['delivery_id'],'minor'=>$n,'reference'=>$r['reference']];
        }
        if($correction!==null){$old=$this->rows("SELECT id FROM delivery_cash_handovers WHERE id=? AND driver_id=? AND state='rejected'",[$correction,$employee]);if(!$old)throw new \DomainException('Only a rejected handover can be corrected.');}
        self::minor($sum);$declared=self::minor($p['declared_minor']??null);$note=self::note($p['note']??'',$declared!==$sum||$correction!==null);if(($p['attested']??false)!==true)throw new \DomainException('Confirm the physical handover declaration.');
        return ['expected_minor'=>$sum,'declared_minor'=>$declared,'difference_minor'=>$declared-$sum,'note'=>$note,'correction_of'=>$correction,'allocations'=>$alloc];
    }
    private function audit(int $id,int $actor,string $action,array $data):int {
        $prev=$this->rows('SELECT event_hash FROM delivery_cash_audit WHERE handover_id=? ORDER BY id DESC LIMIT 1',[$id])[0]['event_hash']??'';$stamp=$this->rows('SELECT UTC_TIMESTAMP() t')[0]['t'];$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$hash=hash('sha256',json_encode([$id,$actor,$action,$json,$stamp,$prev],JSON_THROW_ON_ERROR));
        $this->exec('INSERT INTO delivery_cash_audit(handover_id,actor_id,action,payload_json,previous_hash,event_hash,created_at) VALUES(?,?,?,?,?,?,?)',[$id,$actor,$action,$json,$prev,$hash,$stamp]);return (int)$this->db->lastInsertId();
    }
    private function notify(int $id,int $event,int $driver,bool $toFront):void {
        $recipients=[];if(!$toFront)$recipients[]=$driver;else foreach($this->rows("SELECT id FROM ops_employees WHERE status='active'") as $e){try{$a=self::actor($this->db,(int)$e['id']);if($a['role']!=='delivery_driver'&&(int)$e['id']!==$driver)$recipients[]=(int)$e['id'];}catch(\DomainException $e){}}
        if(!$recipients)throw new \DomainException('No authorized shop receiver is available. Contact the Owner.');
        $this->exec("INSERT INTO notifications(title,message,module,related_type,related_id,priority,deduplication_key,action_link,created_at) VALUES('Cash handover update','Open the handover record to review its receipt status.','delivery','delivery_cash_handover',?,'normal',?,?,UTC_TIMESTAMP())",[$id,'cash-handover:'.$event.($toFront?':front':':driver'),'/apps/delivery/index.php?cash=1']);$n=(int)$this->db->lastInsertId();foreach($recipients as $r)$this->exec('INSERT INTO notification_recipients(notification_id,employee_id) VALUES(?,?)',[$n,$r]);
    }
    /** Job locks match canonical collection/reconciliation locking; never lock a receipt first. */
    public function command(int $employee,array $p):array {
        $actor=self::actor($this->db,$employee);$action=$p['action']??'';$key=$p['key']??'';
        if(!is_string($key)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$key)||!in_array($action,['submit','confirm','dispute','reject','reverse'],true))throw new \DomainException('Invalid handover action or retry key.');
        if(($action==='submit')!==($actor['role']==='delivery_driver'))throw new \DomainException('Driver submission and independent receipt permissions are separate.');
        $hash=hash('sha256',json_encode($p,JSON_THROW_ON_ERROR));$id=$p['handover_id']??0;
        if($action!=='submit'&&(!is_int($id)||$id<1))throw new \DomainException('Invalid handover reference.');
        if($this->db->inTransaction())throw new \LogicException('Cash action owns transaction.');
        // Discovery reads precede job locks. READ COMMITTED prevents an old snapshot
        // from ignoring allocations committed while this request waited for that lock.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try{
            if($action==='submit'){$list=$p['allocations']??null;if(!is_array($list)||!$list||count($list)>100)throw new \DomainException('Select 1–100 cash collections.');$ids=[];foreach($list as $a)if(is_array($a)&&is_int($a['receipt_id']??null))$ids[]=$a['receipt_id'];if(!$ids)throw new \DomainException('Select cash collections.');$jobs=$this->rows('SELECT DISTINCT delivery_id FROM delivery_receipts WHERE id IN('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY delivery_id',$ids);}
            else $jobs=$this->rows('SELECT DISTINCT r.delivery_id FROM delivery_cash_allocations a JOIN delivery_receipts r ON r.id=a.receipt_id WHERE a.handover_id=? ORDER BY r.delivery_id',[$id]);
            foreach($jobs as $j)$this->rows('SELECT id FROM delivery_jobs WHERE id=? FOR UPDATE',[$j['delivery_id']]);
            $this->rows('SELECT id FROM ops_employees WHERE id=? FOR UPDATE',[$employee]);$actor=self::actor($this->db,$employee);
            if(($action==='submit')!==($actor['role']==='delivery_driver'))throw new \DomainException('Cash permissions changed. Reload this screen.');
            $old=$this->rows('SELECT request_hash,response_json FROM delivery_requests WHERE actor_key=? AND request_uuid=? FOR UPDATE',['cash:'.$employee,$key])[0]??null;
            if($old){if(!hash_equals($old['request_hash'],$hash))throw new \DomainException('Retry key belongs to different content.');$this->db->commit();return json_decode($old['response_json'],true,32,JSON_THROW_ON_ERROR);}
            if($action==='submit'){
                $r=$this->review($employee,$p);$driver=$employee;
                $this->exec("INSERT INTO delivery_cash_handovers(driver_id,state,expected_minor,declared_minor,note,correction_of,created_at) VALUES(?,'awaiting_front_desk_receipt',?,?,?,?,UTC_TIMESTAMP())",[$driver,$r['expected_minor'],$r['declared_minor'],$r['note'],$r['correction_of']]);$id=(int)$this->db->lastInsertId();
                foreach($r['allocations'] as $a)$this->exec('INSERT INTO delivery_cash_allocations(handover_id,receipt_id,allocated_minor) VALUES(?,?,?)',[$id,$a['receipt_id'],$a['minor']]);$state='awaiting_front_desk_receipt';
            }else{
                $h=$this->rows('SELECT * FROM delivery_cash_handovers WHERE id=? FOR UPDATE',[$id])[0]??null;if(!$h||(int)$h['driver_id']===$employee)throw new \DomainException('Independent shop receiver required.');$driver=(int)$h['driver_id'];$note=self::note($p['note']??'',$action!=='confirm'||$h['state']==='disputed');$counted=self::minor($p['counted_minor']??null,true);$expected=(int)$h['expected_minor'];
                if($action==='reverse'){
                    if($actor['role']!=='owner_admin'||$h['state']!=='confirmed'||$counted!==(int)$h['counted_minor'])throw new \DomainException('Owner must verify the full confirmed cash returned to the driver.');
                    foreach($this->rows('SELECT r.status FROM delivery_cash_allocations a JOIN delivery_receipts r ON r.id=a.receipt_id WHERE a.handover_id=?',[$id]) as $r)if($r['status']!=='collected')throw new \DomainException('Accounted or disputed cash needs an accounting reversal first.');$state='reversed';
                }else{
                    if(!in_array($h['state'],['awaiting_front_desk_receipt','disputed'],true))throw new \DomainException('This handover is no longer awaiting a decision.');
                    if($action==='confirm'&&($counted!==$expected||(int)$h['declared_minor']!==$expected))throw new \DomainException('Amounts differ. Raise a variance; do not confirm or write off the difference.');
                    // Source changes cannot erase or settle a pending physical-cash claim.
                    $map=array_column($this->collections($driver),null,'id');foreach($this->rows('SELECT receipt_id FROM delivery_cash_allocations WHERE handover_id=?',[$id]) as $a)if($action==='confirm'&&(!isset($map[$a['receipt_id']])||!$map[$a['receipt_id']]['eligible']))throw new \DomainException('Source cash changed; review before confirming.');
                    $state=['confirm'=>'confirmed','reject'=>'rejected','dispute'=>'disputed'][$action];
                }
                if(($p['attested']??false)!==true)throw new \DomainException('Confirm the physical cash decision.');
                $r=['expected_minor'=>$expected,'declared_minor'=>(int)$h['declared_minor'],'counted_minor'=>$counted,'declared_difference_minor'=>(int)$h['declared_minor']-$expected,'receiver_difference_minor'=>$counted-(int)$h['declared_minor'],'note'=>$note];
                $this->exec('UPDATE delivery_cash_handovers SET state=?,counted_minor=?,receiver_id=?,received_at=UTC_TIMESTAMP() WHERE id=?',[$state,$counted,$employee,$id]);
            }
            $event=$this->audit($id,$employee,$action,$r+['state'=>$state]);
            // Publish a compatibility custody event ONLY when each entire receipt is received.
            // Reconciliation remains the existing separately authorized Orders/ledger action.
            $lockedReceiptIds=$action==='confirm'?array_map('intval',array_column($this->rows('SELECT receipt_id FROM delivery_cash_allocations WHERE handover_id=?',[$id]),'receipt_id')):[];
            if($action==='confirm')foreach($this->collections($driver) as $c)if(in_array($c['id'],$lockedReceiptIds,true)&&$c['collected_minor']>0&&$c['confirmed_minor']===$c['collected_minor']){
                $exists=$this->rows("SELECT id FROM delivery_events WHERE delivery_id=? AND event_type='cash_handover' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.receipt_id'))=?",[$c['delivery_id'],(string)$c['id']]);
                if(!$exists)$this->exec("INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json,created_at) VALUES(?,'cash_handover',?,?,UTC_TIMESTAMP())",[$c['delivery_id'],$employee,json_encode(['cash_phase11'=>true,'handover_id'=>$id,'receipt_id'=>$c['id'],'expected_cents'=>$c['collected_minor'],'counted_cents'=>$c['confirmed_minor']],JSON_THROW_ON_ERROR)]);
            }
            $this->notify($id,$event,$driver,$action==='submit'||$action==='dispute');if($action==='dispute')$this->notify($id,$event,$driver,false);
            $result=['id'=>$id,'reference'=>'CH-'.$id,'state'=>$state,'audit_event'=>$event];$this->exec('INSERT INTO delivery_requests(actor_key,request_uuid,action,request_hash,response_json) VALUES(?,?,?,?,?)',['cash:'.$employee,$key,'cash_'.$action,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);$this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function hasAllocations(int $receipt):bool {return (bool)$this->rows('SELECT handover_id FROM delivery_cash_allocations WHERE receipt_id=? LIMIT 1',[$receipt]);}
    public function received(int $driver,int $receipt):bool {foreach($this->collections($driver) as $r)if($r['id']===$receipt)return $r['collected_minor']>0&&$r['confirmed_minor']===$r['collected_minor']&&!$r['review'];return false;}
}
