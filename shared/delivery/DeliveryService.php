<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';
require_once __DIR__.'/NotificationOutbox.php';
require_once __DIR__.'/DeliveryDeadline.php';

/** Internal service; not an HTTP endpoint. Adapters must verify session and CSRF. */
final class DeliveryService
{
    private \PDO $db;
    private array $methods;
    private \Closure $liveBalances;
    public function __construct(\PDO $db, array $methods, \Closure $liveBalances) {$this->db=$db;$this->methods=$methods;$this->liveBalances=$liveBalances;}

    private function row(string $sql,array $params): ?array
    {
        $s=$this->db->prepare($sql);$s->execute($params);return $s->fetch(\PDO::FETCH_ASSOC)?:null;
    }
    private function exec(string $sql,array $params): void {$this->db->prepare($sql)->execute($params);}

    /** Lock scope is the job; UUID replay checks occur after current authorization. */
    private function command(array $actor,int $id,string $uuid,string $action,array $body,callable $authorize,callable $apply): array
    {
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uuid))throw new \DomainException('Invalid request ID.');
        if($this->db->inTransaction())throw new \LogicException('Service must own transaction.');
        $this->db->beginTransaction();
        try {
            $job=$this->row('SELECT * FROM delivery_jobs WHERE id=? FOR UPDATE',[$id]);
            if(!$job)throw new \DomainException('Delivery unavailable.');
            $authorize($job);
            $actorKey=($actor['kind']??'').':'.(int)($actor['id']??0);
            $hash=hash('sha256',json_encode([$id,$action,$body],JSON_THROW_ON_ERROR));
            $old=$this->row('SELECT * FROM delivery_requests WHERE actor_key=? AND request_uuid=? FOR UPDATE',[$actorKey,$uuid]);
            if($old) {
                if($old['request_hash']!==$hash||$old['action']!==$action)throw new \DomainException('Request ID already used with different content.');
                $result=json_decode($old['response_json'],true,512,JSON_THROW_ON_ERROR);
                $this->db->commit();return $result;
            }
            $result=$apply($job);
            $this->exec('INSERT INTO delivery_events(delivery_id,event_type,employee_id,metadata_json) VALUES(?,?,?,?)',
                [$id,$action,$actor['id'],json_encode($result,JSON_THROW_ON_ERROR)]);
            $event=(int)$this->db->lastInsertId();
            if($action==='failed'||($action==='delivered'&&($result['status']??'')==='awaiting_reconciliation'))NotificationOutbox::notifyFront($this->db,$id,'event:'.$event,$action==='failed'?'failed':'reconciliation');
            if($action==='revised')NotificationOutbox::queue($this->db,$id,(int)$result['driver'],'event:'.$event.':driver',['kind'=>'assignment']);
            $this->exec('INSERT INTO delivery_requests(actor_key,request_uuid,action,request_hash,response_json) VALUES(?,?,?,?,?)',
                [$actorKey,$uuid,$action,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            $this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function assigned(array $actor,array $job): void
    {
        DeliveryPolicy::requireAssignedDriver($actor,$job);
        $driver=$this->row('SELECT active FROM delivery_driver_profiles WHERE employee_id=? FOR UPDATE',[$actor['id']]);
        if(!$driver||(int)$driver['active']!==1)throw new \DomainException('Driver profile inactive.');
    }

    public function start(array $actor,int $id,string $uuid): array
    {
        return $this->command($actor,$id,$uuid,'started',[],fn($j)=>$this->assigned($actor,$j),function($j)use($id){
            if($j['status']!=='ready')throw new \DomainException('Delivery is not ready.');
            $this->remaining($j);
            $this->exec("UPDATE delivery_jobs SET status='out_for_delivery',started_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?",[$id]);
            return ['id'=>$id,'status'=>'out_for_delivery'];
        });
    }

    public function note(array $actor,int $id,string $uuid,string $note):array
    {
        $note=trim($note);if($note===''||strlen($note)>2000)throw new \DomainException('Enter a note of at most 2000 bytes.');
        return $this->command($actor,$id,$uuid,'note_added',[$note],function($j)use($actor){
            if(!DeliveryPolicy::canView($actor,$j))throw new \DomainException('Delivery access denied.');
            if(($actor['role']??'')==='delivery_driver')$this->assigned($actor,$j);
        },function($j)use($id,$note,$actor){if(($actor['role']??'')==='delivery_driver'&&in_array($j['status'],['completed','awaiting_reconciliation','cancelled'],true))throw new \DomainException('Completed deliveries are read-only. Ask Front for an approved correction.');return ['id'=>$id,'note'=>$note];});
    }

    public function fail(array $actor,int $id,string $uuid,string $reason,string $note):array
    {
        if(!in_array($reason,['Customer unavailable','Wrong address','Customer cancelled','Payment issue','Vehicle issue','Other'],true))throw new \DomainException('Choose a failure reason.');
        $note=trim($note);if(strlen($note)>2000||($reason==='Other'&&$note===''))throw new \DomainException('Provide a short explanation.');
        return $this->command($actor,$id,$uuid,'failed',[$reason,$note],fn($j)=>$this->assigned($actor,$j),function($j)use($id,$reason,$note){
            if(!in_array($j['status'],['ready','out_for_delivery'],true))throw new \DomainException('This delivery cannot be marked failed.');
            $this->exec("UPDATE delivery_jobs SET status='failed',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?",[$id]);
            return ['id'=>$id,'status'=>'failed','reason'=>$reason,'note'=>$note];
        });
    }

    public function revise(array $actor,int $id,string $uuid,array $details):array
    {
        $version=(int)($details['version']??0);$driver=(int)($details['driver']??0);$date=(string)($details['date']??'');
        $address=trim((string)($details['address']??''));$mobile=trim((string)($details['mobile']??''));$reason=trim((string)($details['reason']??''));$urgent=!empty($details['urgent']);
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Africa/Windhoek'));
        if(!$day||$day->format('Y-m-d')!==$date||$day<new \DateTimeImmutable('today',new \DateTimeZone('Africa/Windhoek')))throw new \DomainException('Choose today or a future date.');
        if($address===''||strlen($address)>2000||!preg_match('/^\+?[0-9 ()-]{7,30}$/D',$mobile)||strlen($reason)<5||strlen($reason)>500)throw new \DomainException('Valid address, mobile and change reason required.');
        return $this->command($actor,$id,$uuid,'revised',[$version,$driver,$date,$address,$mobile,$reason,$urgent],function($j)use($actor){
            if(!DeliveryPolicy::can($actor,'delivery_arrange')||!DeliveryPolicy::canView($actor,$j))throw new \DomainException('Arrangement permission required.');
        },function($j)use($id,$version,$driver,$date,$address,$mobile,$reason,$urgent){
            if((int)$j['version']!==$version||!in_array($j['status'],['ready','failed'],true))throw new \DomainException('Delivery changed or is already in progress. Refresh first.');
            $s=$this->row("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.id=? AND e.status='active' AND r.role_key='delivery_driver' AND p.active=1 FOR UPDATE",[$driver]);
            if(!$s)throw new \DomainException('Choose an active verified Driver.');
            $held=$this->row("SELECT COUNT(*) n FROM delivery_receipts WHERE delivery_id=? AND status IN ('collected','issue')",[$id]);
            if((int)$held['n']>0)throw new \DomainException('Resolve collected money before rescheduling or reassigning.');
            $this->exec("UPDATE delivery_jobs SET driver_employee_id=?,scheduled_date=?,address=?,customer_mobile=?,urgent=?,status='ready',started_at=NULL,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?",[$driver,$date,$address,$mobile,(int)$urgent,$id]);
            return ['id'=>$id,'status'=>'ready','reason'=>$reason,'previous'=>['driver'=>$j['driver_employee_id'],'date'=>$j['scheduled_date'],'status'=>$j['status'],'urgent'=>$j['urgent'],'address'=>$j['address'],'mobile'=>$j['customer_mobile']],'driver'=>$driver,'date'=>$date,'urgent'=>$urgent];
        });
    }

    public function deadline(array $actor,int $id,string $uuid,array $body):array
    {
        $d=DeliveryDeadline::normalize($body);$version=(int)($body['version']??0);
        return $this->command($actor,$id,$uuid,'deadline_updated',[$d,$version],function($j)use($actor){
            if(!DeliveryPolicy::can($actor,'delivery_arrange')||!DeliveryPolicy::canView($actor,$j))throw new \DomainException('Arrangement permission required.');
        },function($j)use($id,$d,$version){
            if((int)$j['version']!==$version||!in_array($j['status'],['ready','failed','out_for_delivery'],true))throw new \DomainException('Delivery changed or completed. Refresh before editing its deadline.');
            $previous=DeliveryDeadline::latest($this->db,$id);
            $this->exec('UPDATE delivery_jobs SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
            NotificationOutbox::queue($this->db,$id,(int)$j['driver_employee_id'],'deadline-change:'.$id.':'.($version+1),['kind'=>'deadline','deadline'=>DeliveryDeadline::label($d)]);
            return ['id'=>$id,'deadline'=>$d,'previous'=>$previous,'note'=>DeliveryDeadline::label($d)];
        });
    }

    public function inlineUpdate(array $actor,int $id,string $uuid,array $body):array
    {
        $field=(string)($body['field']??'');if(!in_array($field,['urgent','driver'],true))throw new \DomainException('Unsupported inline field.');
        $value=$field==='urgent'?(!empty($body['value'])?1:0):(int)($body['value']??0);$version=(int)($body['version']??0);
        return $this->command($actor,$id,$uuid,'dispatch_updated',[$field,$value,$version],function($j)use($actor){
            if(!DeliveryPolicy::can($actor,'delivery_arrange')||!DeliveryPolicy::canView($actor,$j))throw new \DomainException('Arrangement permission required.');
        },function($j)use($id,$field,$value,$version){
            if((int)$j['version']!==$version||!in_array($j['status'],['ready','failed','out_for_delivery'],true))throw new \DomainException('Delivery changed. Refresh before updating.');
            if($field==='driver'){
                if($j['status']==='out_for_delivery')throw new \DomainException('Cannot reassign a delivery in progress.');
                $active=$this->row("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id JOIN delivery_driver_profiles p ON p.employee_id=e.id WHERE e.id=? AND e.status='active' AND r.role_key='delivery_driver' AND p.active=1 FOR UPDATE",[$value]);
                if(!$active)throw new \DomainException('Choose an active verified Driver.');
                $held=$this->row("SELECT COUNT(*) n FROM delivery_receipts WHERE delivery_id=? AND status IN ('collected','issue')",[$id]);if((int)$held['n'])throw new \DomainException('Resolve collected money before reassigning.');
                $this->exec('UPDATE delivery_jobs SET driver_employee_id=?,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?',[$value,$id]);
                NotificationOutbox::queue($this->db,$id,$value,'assigned:'.$id.':'.($version+1),['kind'=>'assignment']);
            }else $this->exec('UPDATE delivery_jobs SET urgent=?,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?',[$value,$id]);
            return ['id'=>$id,'field'=>$field,'previous'=>$field==='driver'?$j['driver_employee_id']:$j['urgent'],'value'=>$value];
        });
    }

    public function cancel(array $actor,int $id,string $uuid,int $version,string $reason):array
    {
        $reason=trim($reason);if(strlen($reason)<5||strlen($reason)>500)throw new \DomainException('Cancellation reason required.');
        return $this->command($actor,$id,$uuid,'cancelled',[$version,$reason],function($j)use($actor){if(!DeliveryPolicy::can($actor,'delivery_arrange')||!DeliveryPolicy::canView($actor,$j))throw new \DomainException('Arrangement permission required.');},function($j)use($id,$version,$reason){
            if((int)$j['version']!==$version||!in_array($j['status'],['ready','failed'],true))throw new \DomainException('Only unchanged Ready or Failed deliveries can be cancelled.');
            $receipts=$this->row("SELECT COUNT(*) n FROM delivery_receipts WHERE delivery_id=? AND status<>'reversed'",[$id]);if((int)$receipts['n'])throw new \DomainException('Resolve recorded collections before cancellation.');
            $this->exec("UPDATE delivery_jobs SET status='cancelled',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?",[$id]);return ['id'=>$id,'status'=>'cancelled','reason'=>$reason];
        });
    }

    /** Hold a disputed receipt without pretending funds were received or reversing money. */
    public function receiptIssue(array $actor,int $id,string $uuid,int $receiptId,string $reason,bool $resolve=false):array
    {
        $reason=trim($reason);if(strlen($reason)<10||strlen($reason)>500)throw new \DomainException('Explain the collection issue or its verified resolution (10–500 characters).');
        return $this->command($actor,$id,$uuid,$resolve?'receipt_issue_resolved':'receipt_issue',[$receiptId,$reason],function($j)use($actor){
            if(!DeliveryPolicy::can($actor,'delivery_reconcile')||!DeliveryPolicy::canView($actor,$j))throw new \DomainException('Reconciliation permission required.');
        },function($j)use($id,$receiptId,$reason,$resolve){
            $receipt=$this->row('SELECT * FROM delivery_receipts WHERE id=? AND delivery_id=? FOR UPDATE',[$receiptId,$id]);
            if(!$receipt||$receipt['status']!==($resolve?'issue':'collected'))throw new \DomainException('Receipt changed. Refresh before reviewing.');
            $status=$resolve?'collected':'issue';
            $this->exec('UPDATE delivery_receipts SET status=?,issue_reason=? WHERE id=?',[$status,$reason,$receiptId]);
            $this->exec('UPDATE delivery_jobs SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
            return ['id'=>$id,'receipt_id'=>$receiptId,'receipt_status'=>$status,'reason'=>$reason];
        });
    }

    private function remaining(array $job): array
    {
        // Adapter must lock/read current Order allocations, not browser-supplied values.
        // Returns TOTAL still collectible by this delivery before its own receipts.
        $due=($this->liveBalances)($this->db,$job);
        $paid=$this->row("SELECT COALESCE(SUM(order_goods_cents),0) goods,COALESCE(SUM(delivery_fee_cents),0) fee,COALESCE(SUM(partner_cod_cents),0) cod FROM delivery_receipts WHERE delivery_id=? AND status IN ('collected','issue')",[$job['id']]);
        // Reconciled receipts must already be accounted for by the balance adapter.
        $remaining=[];
        foreach(['order_goods'=>'goods','delivery_fee'=>'fee','partner_cod'=>'cod'] as $key=>$column){
            if(!is_int($due[$key]??null)||$due[$key]<0)throw new \DomainException('Balance needs review.');
            $remaining[$key]=$due[$key]-(int)$paid[$column];
            if($remaining[$key]<0)throw new \DomainException('Collection conflicts with a newer payment; review required.');
        }
        return $remaining;
    }

    public function collect(array $actor,int $id,string $uuid,array $amounts,string $method,string $reference=''): array
    {
        if(strlen($reference)>190)throw new \DomainException('Reference too long.');
        // Normalize key order before hashing so equivalent requests replay safely.
        $amounts=['order_goods'=>$amounts['order_goods']??0,'delivery_fee'=>$amounts['delivery_fee']??0,'partner_cod'=>$amounts['partner_cod']??0];
        return $this->command($actor,$id,$uuid,'collected',[$amounts,$method,$reference],function($j)use($actor){
            $this->assigned($actor,$j);if(!DeliveryPolicy::can($actor,'delivery_collect'))throw new \DomainException('Collection forbidden.');
        },function($j)use($actor,$id,$uuid,$amounts,$method,$reference){
            DeliveryPolicy::validateCollection($j,$amounts,$this->remaining($j),$method,$this->methods);
            $this->exec('INSERT INTO delivery_receipts(delivery_id,collection_uuid,payment_method,transaction_reference,order_goods_cents,delivery_fee_cents,partner_cod_cents,collected_by_employee_id) VALUES(?,?,?,?,?,?,?,?)',
                [$id,$uuid,$method,$reference,$amounts['order_goods'],$amounts['delivery_fee'],$amounts['partner_cod'],$actor['id']]);
            $receipt=(int)$this->db->lastInsertId();
            $this->exec('UPDATE delivery_jobs SET updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?',[$id]);
            return ['id'=>$id,'receipt_id'=>$receipt,'amount_cents'=>array_sum($amounts),'status'=>'collected'];
        });
    }

    private function ledger(string $key,array $job,string $account,int $amount,int $employee,?int $receipt=null): void
    {
        if($amount===0)return;
        $this->exec('INSERT INTO delivery_ledger(event_key,delivery_id,receipt_id,partner_id,account,amount_cents,employee_id) VALUES(?,?,?,?,?,?,?)',
            [$key,$job['id'],$receipt,$job['partner_id'],$account,$amount,$employee]);
    }

    public function complete(array $actor,int $id,string $uuid): array
    {
        return $this->command($actor,$id,$uuid,'delivered',[],fn($j)=>$this->assigned($actor,$j),function($j)use($actor,$id){
            $held=$this->row("SELECT COALESCE(SUM(order_goods_cents+delivery_fee_cents+partner_cod_cents),0) amount FROM delivery_receipts WHERE delivery_id=? AND status IN ('collected','issue')",[$id]);
            $status=DeliveryPolicy::completionStatus($actor,$j,array_sum($this->remaining($j)),(int)$held['amount']);
            // Prepaid receipts need a verified settlement reference, not a UI flag.
            if($j['fee_payer']==='already_paid'&&(int)$j['fee_cents']>0&&trim((string)$j['prepaid_reference'])==='')throw new \DomainException('Prepaid fee evidence required.');
            $this->exec('UPDATE delivery_jobs SET status=?,delivered_at=UTC_TIMESTAMP(),completed_at=IF(?="completed",UTC_TIMESTAMP(),NULL),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?',[$status,$status,$id]);
            $this->ledger('earned:'.$id,$j,'fee_earned',(int)$j['fee_cents'],$actor['id']);
            $event=$this->row("SELECT metadata_json FROM delivery_events WHERE delivery_id=? AND event_type='arranged' ORDER BY id LIMIT 1",[$id]);$metadata=json_decode((string)($event['metadata_json']??''),true);
            if(!empty($metadata['order_dispatch'])){
                $collected=$this->row("SELECT COALESCE(SUM(delivery_fee_cents),0) fee FROM delivery_receipts WHERE delivery_id=? AND status IN ('collected','issue','reconciled')",[$id]);
                $this->ledger('prepaid:'.$id,$j,'fee_received',max(0,(int)$j['fee_cents']-(int)$collected['fee']),$actor['id']);
            }elseif($j['fee_payer']==='already_paid')$this->ledger('prepaid:'.$id,$j,'fee_received',(int)$j['fee_cents'],$actor['id']);
            return ['id'=>$id,'status'=>$status];
        });
    }

    /** Adapter merges existing Order payments within this SAME transaction; no DDL or network. */
    public function reconcile(array $actor,int $id,string $uuid,int $receiptId,\Closure $applyOrderPayment): array
    {
        return $this->command($actor,$id,$uuid,'reconciled',[$receiptId],function($j)use($actor){
            if(!DeliveryPolicy::can($actor,'delivery_reconcile')||!DeliveryPolicy::canView($actor,$j))throw new \DomainException('Reconciliation forbidden.');
        },function($j)use($actor,$id,$receiptId,$applyOrderPayment){
            $r=$this->row('SELECT * FROM delivery_receipts WHERE id=? AND delivery_id=? FOR UPDATE',[$receiptId,$id]);
            if(!$r||$r['status']!=='collected')throw new \DomainException('Receipt is not awaiting reconciliation.');
            if($j['source']==='hambelela')$applyOrderPayment($this->db,$j,$r,$actor);
            $this->exec("UPDATE delivery_receipts SET status='reconciled',reconciled_by_employee_id=?,reconciled_at=UTC_TIMESTAMP(),order_allocation_applied_at=IF(?='hambelela',UTC_TIMESTAMP(),NULL) WHERE id=?",[$actor['id'],$j['source'],$receiptId]);
            foreach(['order_goods_cents'=>'order_funds_received','delivery_fee_cents'=>'fee_received','partner_cod_cents'=>'partner_cod_held'] as $column=>$account)
                $this->ledger('receipt:'.$receiptId.':'.$account,$j,$account,(int)$r[$column],$actor['id'],$receiptId);
            $pending=$this->row("SELECT COUNT(*) n FROM delivery_receipts WHERE delivery_id=? AND status IN ('collected','issue')",[$id]);
            $status=$j['status'];
            if($status==='awaiting_reconciliation'&&(int)$pending['n']===0){
                $status='completed';$this->exec("UPDATE delivery_jobs SET status='completed',completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?",[$id]);
            }
            return ['id'=>$id,'receipt_id'=>$receiptId,'status'=>$status];
        });
    }
}
