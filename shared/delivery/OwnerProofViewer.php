<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/FrontSession.php';
require_once __DIR__.'/ProofStorage.php';
require_once __DIR__.'/OrderBalanceAdapter.php';
require_once __DIR__.'/OrderReference.php';
require_once __DIR__.'/DriverOrderDetailsData.php';
require_once __DIR__.'/NotificationOutbox.php';

/** Owner-only projection and append-only review ledger. No financial/business writes. */
final class OwnerProofViewer
{
    private \PDO $db;
    private int $owner;
    public const REASONS=['evidence'=>'Missing / unclear evidence','recipient'=>'Recipient / signature','items'=>'Wrong / missing items','address'=>'Address / time mismatch','payment'=>'Payment mismatch','other'=>'Other'];
    public function __construct(\PDO $db,int $owner){$this->db=$db;$this->owner=$owner;$this->authorize();}
    public function authorize():array
    {
        try{$actor=FrontSession::actor($this->db,$this->owner);}catch(\DomainException $e){throw new \RuntimeException('Owner access required.',403);}
        if($actor['role']!=='owner_admin')throw new \RuntimeException('Owner access required.',403);
        return $actor;
    }
    private function rows(string $sql,array $args=[]):array{$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(\PDO::FETCH_ASSOC);}
    public function ready():bool
    {
        return (bool)$this->rows("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='delivery_owner_proof_actions'");
    }
    public static function version(array $job,array $events):string
    {
        return hash('sha256',json_encode([$job['id'],$job['order_id'],$job['driver_employee_id'],$job['status'],$job['version'],$events],JSON_THROW_ON_ERROR));
    }
    /** Validate immutable links before exposing any file identifier. Older invalid metadata remains visible as a warning. */
    public static function evidence(array $job,array $events):array
    {
        $files=[];$receipts=[];$warnings=[];
        foreach($events as $event){
            if($event['event_type']!=='delivered')continue;
            $m=json_decode((string)$event['metadata_json'],true);$p=$m['proof']??null;
            if(!is_array($p)){$warnings[]='Handover event '.$event['id'].' has no linked proof.';continue;}
            if(!preg_match('/^[a-f0-9]{32}$/D',(string)($p['receipt']??''))||(int)($p['delivery_id']??0)!==(int)$job['id']||(int)($p['order_id']??0)!==(int)$job['order_id']||(int)($p['driver_id']??0)!==(int)$event['employee_id']){
                $warnings[]='Proof linkage requires review for event '.$event['id'].'.';continue;
            }
            $recipient=is_array($p['recipient']??null)?$p['recipient']:[];
            if(empty($recipient['acknowledged'])||!is_string($recipient['recipient_name']??null))$warnings[]='Recipient acknowledgement needs review for event '.$event['id'].'.';
            foreach(['recipient_name','recipient_type','relationship'] as $field)if(isset($recipient[$field])&&!is_string($recipient[$field]))unset($recipient[$field]);
            $receipts[]=['event_id'=>(int)$event['id'],'receipt'=>$p['receipt'],'driver_id'=>(int)$p['driver_id'],'recorded_at'=>$event['created_at'],'recipient'=>$recipient,'policy'=>$p['policy']??[]];
            foreach((is_array($p['evidence']??null)?$p['evidence']:[]) as $f){
                if(!is_array($f)||!preg_match('/^[a-f0-9]{48}$/D',(string)($f['id']??''))||!preg_match('/^[a-f0-9]{64}$/D',(string)($f['stored_sha256']??''))||($f['mime']??'')!=='image/png'||!in_array($f['kind']??'',['photo','signature'],true)){$warnings[]='Invalid evidence metadata in event '.$event['id'].'.';continue;}
                $f['event_id']=(int)$event['id'];$f['driver_id']=(int)$p['driver_id'];$f['recorded_at']=$event['created_at'];$files[]=$f;
            }
        }
        return ['files'=>$files,'receipts'=>$receipts,'warnings'=>array_values(array_unique($warnings))];
    }
    public function read(int $id):array
    {
        $this->authorize();
        if($id<1)throw new \RuntimeException('Delivery not found.',404);
        $job=$this->rows('SELECT * FROM delivery_jobs WHERE id=?',[$id])[0]??null;
        if(!$job)throw new \RuntimeException('Delivery not found.',404);
        $events=$this->rows('SELECT ev.*,e.full_name actor_name FROM delivery_events ev LEFT JOIN ops_employees e ON e.id=ev.employee_id WHERE ev.delivery_id=? ORDER BY ev.id',[$id]);
        $order=$job['order_id']?($this->rows('SELECT * FROM ops_orders WHERE id=?',[$job['order_id']])[0]??[]):[];
        $employeeColumns=$this->db->query('SHOW COLUMNS FROM ops_employees')->fetchAll(\PDO::FETCH_COLUMN);
        $select='id,full_name';foreach(['employee_code','phone','mobile'] as $column)if(in_array($column,$employeeColumns,true))$select.=','.$column;
        $driver=$this->rows('SELECT '.$select.' FROM ops_employees WHERE id=?',[$job['driver_employee_id']])[0]??[];
        $items=$order?$this->rows('SELECT * FROM ops_order_items WHERE order_id=? ORDER BY id',[$job['order_id']]):[];
        $allocations=$order?$this->rows('SELECT * FROM order_payment_allocations WHERE order_id=? ORDER BY id',[$job['order_id']]):[];
        $collections=$this->rows('SELECT * FROM delivery_receipts WHERE delivery_id=? ORDER BY id',[$id]);
        $payment=['due'=>null,'warning'=>null,'allocations'=>$allocations,'collections'=>$collections];
        try{$payment['due']=array_sum((new OrderBalanceAdapter())->read($this->db,$job));}catch(\DomainException $e){$payment['warning']='Payment balance needs reconciliation in Orders. No amount is assumed verified.';}
        if($order&&(!empty($order['deleted_at'])||!empty($order['archived_at'])||(float)($order['refund_total']??0)>0||in_array(strtolower((string)($order['status']??'')),['cancelled','refunded','failed','deleted','trash'],true)||in_array(strtolower((string)($order['payment_status']??'')),['refunded','reversed'],true))){$payment['warning']='Source order is archived, cancelled, refunded or reversed. Review its payment records.';$payment['due']=null;}
        $proof=self::evidence($job,$events);$version=self::version($job,$events);$ready=$this->ready();
        $actions=$ready?$this->rows('SELECT a.*,e.full_name actor_name FROM delivery_owner_proof_actions a LEFT JOIN ops_employees e ON e.id=a.employee_id WHERE a.delivery_id=? ORDER BY a.id DESC',[$id]):[];
        $review=null;$open=[];$resolved=[];
        foreach($actions as $a){if($a['action_type']==='review'&&!$review)$review=$a;if($a['action_type']==='resolve')$resolved[(int)$a['related_action_id']]=true;}
        foreach($actions as $a)if($a['action_type']==='discrepancy'&&!isset($resolved[(int)$a['id']]))$open[]=$a;
        $phone=DriverOrderDetailsData::phone((string)$job['customer_mobile']);
        $address=trim((string)$job['address']);
        $navigable=mb_strlen($address)>=8&&!preg_match('/^(?:[A-Z]{2}|Namibia)(?:\s*,\s*(?:[A-Z]{2}|Namibia))*$/iD',$address)&&!preg_match('/(?:needs? to give|next order|N\$|no address|not provided|call customer)/i',$address);
        $declarations=[];foreach($events as $e){$m=json_decode((string)$e['metadata_json'],true);if($e['event_type']==='delivered'&&is_array($m)&&($p=DriverOrderDetailsData::recordedPayment($m)))$declarations[]=$p+['created_at'=>$e['created_at']];}
        return compact('job','order','driver','events','items','payment','proof','version','ready','actions','review','open','phone','address','navigable','declarations')+['reference'=>OrderReference::display($order['order_number']??$job['public_reference'])];
    }
    public function file(int $id,string $key):array
    {
        $data=$this->read($id);$files=$data['proof']['files'];
        foreach($data['actions'] as $a){$f=json_decode((string)$a['attachment_json'],true);if(is_array($f))$files[]=$f;}
        foreach($files as $f)if(hash_equals((string)$f['id'],$key)){
            $path=ProofStorage::configured()->path($key);
            if(!is_file($path)||!hash_equals((string)$f['stored_sha256'],(string)hash_file('sha256',$path))||(new \finfo(FILEINFO_MIME_TYPE))->file($path)!=='image/png')throw new \RuntimeException('Evidence unavailable or integrity check failed.',409);
            return ['path'=>$path,'metadata'=>$f];
        }
        throw new \RuntimeException('Evidence not found.',404);
    }
    public function action(int $id,array $input,?array $attachment=null):array
    {
        $this->authorize();$kind=(string)($input['action']??'');$uuid=(string)($input['request_id']??'');$body=trim((string)($input['body']??''));$reason=(string)($input['reason']??'');$related=(int)($input['related_id']??0);$version=(string)($input['version']??'');
        if(!in_array($kind,['review','note','discrepancy','resolve'],true)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$uuid)||!preg_match('/^[a-f0-9]{64}$/D',$version))throw new \RuntimeException('Invalid review request.',422);
        if(mb_strlen($body)>4000||($kind!=='review'&&mb_strlen($body)<3))throw new \RuntimeException('Enter a note of 3 to 4,000 characters.',422);
        if($kind==='discrepancy'&&!isset(self::REASONS[$reason]))throw new \RuntimeException('Choose a discrepancy reason.',422);
        if($kind!=='discrepancy')$reason='';
        if($attachment&&$kind!=='discrepancy')throw new \RuntimeException('Attachments belong to discrepancies only.',422);
        $hash=hash('sha256',json_encode([$id,$kind,$body,$reason,$related,$version,$attachment['sha256']??null],JSON_THROW_ON_ERROR));
        $saved=false;
        $this->db->beginTransaction();
        try{
            // Serialise this owner's retries/rate checks; then lock the canonical delivery.
            $this->rows('SELECT id FROM ops_employees WHERE id=? FOR UPDATE',[$this->owner]);$this->authorize();
            if(!$this->ready())throw new \RuntimeException('Review storage is not installed. No changes saved.',503);
            $old=$this->rows('SELECT * FROM delivery_owner_proof_actions WHERE employee_id=? AND request_id=?',[$this->owner,$uuid])[0]??null;
            if($old){if(!hash_equals($old['payload_hash'],$hash))throw new \RuntimeException('This request was already used with different content.',409);$this->db->commit();return ['id'=>(int)$old['id'],'repeated'=>true];}
            if(count($this->rows('SELECT id FROM delivery_owner_proof_actions WHERE employee_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)',[$this->owner]))>=20)throw new \RuntimeException('Too many changes. Wait a minute before retrying.',429);
            $this->rows('SELECT id FROM delivery_jobs WHERE id=? FOR UPDATE',[$id]);$data=$this->read($id);
            if(!hash_equals($data['version'],$version))throw new \RuntimeException('Delivery evidence changed. Reload and review it before saving.',409);
            if($kind==='review'){
                if(!$data['proof']['files']&&mb_strlen($body)<10)throw new \RuntimeException('No proof is recorded. Document a review exception of at least 10 characters.',422);
                foreach($data['proof']['files'] as $f){try{$this->file($id,$f['id']);}catch(\Throwable $e){if(mb_strlen($body)<10)throw new \RuntimeException('Evidence is unavailable or failed integrity checks. Document the review exception before continuing.',422);}}
                if($data['review']&&hash_equals($data['review']['evidence_version'],$version)){$this->db->commit();return ['id'=>(int)$data['review']['id'],'repeated'=>true];}
            }
            if($kind==='resolve'&&!array_filter($data['open'],static fn($a)=>(int)$a['id']===$related))throw new \RuntimeException('Discrepancy is unavailable or already resolved.',409);
            $q=$this->db->prepare('INSERT INTO delivery_owner_proof_actions(delivery_id,employee_id,request_id,payload_hash,evidence_version,action_type,reason,body,related_action_id,attachment_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            $q->execute([$id,$this->owner,$uuid,$hash,$version,$kind,$reason?:null,$body,$related?:null,$attachment?json_encode($attachment,JSON_THROW_ON_ERROR):null]);$actionId=(int)$this->db->lastInsertId();
            if($kind==='discrepancy')NotificationOutbox::notifyFront($this->db,$id,'owner-proof:'.$actionId,'owner_proof_discrepancy');
            $this->db->commit();$saved=true;return ['id'=>$actionId,'repeated'=>false];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        finally{if($attachment&&!$saved)ProofStorage::configured()->discard([$attachment]);}
    }
    public static function time(?string $value):string
    {
        $iso=DriverOrderDetailsData::timestamp($value);return $iso?(new \DateTimeImmutable($iso))->format('D, j M Y · H:i'):'Time not recorded';
    }
}
