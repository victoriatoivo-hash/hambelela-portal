<?php
declare(strict_types=1);
namespace Hambelela\Accounts;
require_once __DIR__.'/PaymentEvidence.php';
final class Reconciliation
{
    private \PDO $db;
    private array $cache=[];
    public function __construct(\PDO $db){$this->db=$db;}
    public function owner(int $id):void
    {
        $s=$this->db->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin'");$s->execute([$id]);
        if(!$s->fetchColumn())throw new \DomainException('Owner permission is required for independent financial verification.');
    }
    private function query(string $sql,array $args=[]):array{$key=$sql.json_encode($args);if(!$this->db->inTransaction()&&isset($this->cache[$key]))return $this->cache[$key];$s=$this->db->prepare($sql);$s->execute($args);$rows=$s->fetchAll(\PDO::FETCH_ASSOC);if(!$this->db->inTransaction())$this->cache[$key]=$rows;return $rows;}
    public static function cents(string $amount):int
    {
        if(!preg_match('/^-?\d+(?:\.\d{1,2})?$/',$amount))throw new \DomainException('Invalid financial amount.');
        $negative=substr($amount,0,1)==='-';$parts=explode('.',ltrim($amount,'-'));$n=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');return $negative?-$n:$n;
    }
    private static function fingerprint(array $row):string{ksort($row);return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));}
    public function allocations(int $order,bool $lock=false):array{return $this->query('SELECT * FROM order_payment_allocations WHERE order_id=? ORDER BY id'.($lock?' FOR UPDATE':''),[$order]);}
    public function evidence(int $order):array
    {
        $out=[];
        foreach($this->query("SELECT * FROM ops_cash_book_entries WHERE related_order_id=? AND deleted_at IS NULL AND archived_at IS NULL",[$order]) as $r){
            $amount=self::cents((string)$r['cash_in'])-self::cents((string)$r['cash_out']);if($amount<=0)continue;
            $out[]=['key'=>'cash:'.$r['id'],'order_id'=>$order,'amount_cents'=>$amount,'payment_method'=>'cash','transaction_reference'=>$r['related_order_number']??'','receipt_verified'=>$r['actual_count']!==null&&self::cents((string)$r['actual_count'])===$amount,'settlement_state'=>'cash_count','description'=>'Bookkeeping #'.$r['id'],'recorded_by'=>$r['recorded_by'],'fingerprint'=>self::fingerprint($r),'url'=>'../operations/bookkeeping.php','products_cents'=>$amount,'fee_cents'=>0,'partner_cod_cents'=>0];
        }
        $previousFees=[];
        foreach($this->query("SELECT r.*,j.fee_order_component_cents,j.order_id FROM delivery_receipts r JOIN delivery_jobs j ON j.id=r.delivery_id WHERE j.order_id=? AND j.source='hambelela' ORDER BY r.id",[$order]) as $r){
            // Only a receipt explicitly applied to this Order can corroborate its payment.
            $applied=!empty($r['order_allocation_applied_at']);$job=(int)$r['delivery_id'];$included=min((int)$r['delivery_fee_cents'],max(0,(int)$r['fee_order_component_cents']-($previousFees[$job]??0)));
            if($r['status']==='reconciled'&&empty($r['reversed_at']))$previousFees[$job]=($previousFees[$job]??0)+(int)$r['delivery_fee_cents'];
            $out[]=['key'=>'driver:'.$r['id'],'order_id'=>$order,'amount_cents'=>(int)$r['order_goods_cents']+$included,'payment_method'=>$r['payment_method'],'transaction_reference'=>$r['transaction_reference']??'','receipt_verified'=>$r['status']==='reconciled'&&$applied&&(int)$r['partner_cod_cents']===0,'reversed'=>!empty($r['reversed_at']),'settlement_state'=>'driver_handover','description'=>'Driver receipt #'.$r['id'].' · '.$r['status'],'recorded_by'=>$r['collected_by_employee_id'],'handover_by'=>$r['reconciled_by_employee_id'],'fingerprint'=>self::fingerprint($r),'url'=>'../delivery/accounting.php','products_cents'=>(int)$r['order_goods_cents'],'fee_cents'=>(int)$r['delivery_fee_cents'],'partner_cod_cents'=>(int)$r['partner_cod_cents']];
        }
        $groups=[];foreach($out as $e)if(strpos($e['key'],'driver:')===0&&$e['receipt_verified']&&empty($e['reversed']))$groups[$e['payment_method']][]=$e;
        foreach($groups as $method=>$parts)if(count($parts)>1){$group=$parts[0];$group['key']='driver-order:'.$order.':'.$method;$group['description']='Combined verified Driver handovers ('.count($parts).')';foreach(['amount_cents','products_cents','fee_cents','partner_cod_cents'] as $field)$group[$field]=array_sum(array_column($parts,$field));$group['fingerprint']=self::fingerprint($parts);$group['recorded_by']=null;$group['handover_by']=null;$group['receipt_keys']=array_column($parts,'key');$out[]=$group;}
        $amounts=array_map('intval',array_column($this->allocations($order),'amount_cents'));
        foreach($this->query('SELECT * FROM accounts_payment_evidence') as $r){if(!in_array((int)$r['amount_cents'],$amounts,true))continue;$out[]=['key'=>'import:'.$r['id'],'order_id'=>0,'amount_cents'=>(int)$r['amount_cents'],'payment_method'=>$r['method_code'],'transaction_reference'=>$r['source_reference'],'receipt_verified'=>$r['settlement_state']==='settled','settlement_state'=>$r['settlement_state'],'description'=>$r['source_type'].' · '.$r['source_reference'],'recorded_by'=>$r['imported_by'],'fingerprint'=>self::fingerprint($r),'transaction_at'=>$r['transaction_at'],'url'=>null];}
        return $out;
    }
    public function detail(int $id):array
    {
        $orders=$this->query('SELECT id,order_number,order_type,customer_name,customer_contact,total_amount,created_at,created_by,payment_status,payment_method,payment_updated_by_employee_id FROM ops_orders WHERE id=? AND deleted_at IS NULL',[$id]);
        if(!$orders)throw new \DomainException('Order unavailable.');$order=$orders[0];$allocations=$this->allocations($id);$evidence=$this->evidence($id);$components=[];
        foreach($allocations as &$a){
            $m=$this->query('SELECT * FROM accounts_payment_matches WHERE allocation_id=?',[$a['id']]);$m=$m[0]??null;$a['match']=$m;$a['fingerprint']=self::fingerprint($a); // excludes derived fields below
            $original=$a;unset($original['match'],$original['fingerprint']);$a['fingerprint']=self::fingerprint($original);
            $a['suggestions']=[];$current=false;
            foreach($evidence as $e){
                $check=PaymentEvidence::match($a,$e);
                if($check['eligible']||((int)$a['amount_cents']===$e['amount_cents']))$a['suggestions'][]=$e+['eligible'=>$check['eligible'],'reasons'=>$check['reasons']];
                if($m&&$m['evidence_key']===$e['key'])$current=$check['eligible']&&hash_equals($m['allocation_fingerprint'],$a['fingerprint'])&&hash_equals($m['evidence_fingerprint'],$e['fingerprint']);
            }
            $a['verification']=null;
            if($m&&$m['evidence_key']==='manual:'.$a['id']){
                $v=json_decode($m['note']??'',true);
                $current=is_array($v)&&hash_equals($m['allocation_fingerprint'],$a['fingerprint'])&&hash_equals($m['evidence_fingerprint'],self::fingerprint($v));
                if($current&&!empty($v['cashbook_id'])){$source=$this->query('SELECT * FROM ops_cash_book_entries WHERE id=? AND deleted_at IS NULL AND archived_at IS NULL',[(int)$v['cashbook_id']]);$current=$source&&hash_equals($v['cashbook_fingerprint'],self::fingerprint($source[0]));}
                $a['verification']=$v;
            }
            $a['actual_method']=$a['verification']['actual_method']??$a['payment_method'];
            $a['evidence_current']=$current;$a['matched']=$current&&$m['status']==='matched';$a['confirmed']=$current&&$m['status']==='confirmed';if($a['confirmed'])$a['matched']=true;
            $components[]=$a;
        }unset($a);
        $audit=$this->query('SELECT * FROM accounts_payment_review_audit WHERE order_id=? ORDER BY id DESC',[$id]);
        $status=PaymentEvidence::orderStatus(self::cents((string)$order['total_amount']),$components);
        foreach($components as $component)if($component['match']&&$component['match']['status']==='confirmed'&&!$component['evidence_current'])$status='Discrepancy';
        foreach($audit as $event){if($event['action']==='resolve')break;if($event['action']==='flag'){$status='Flagged — Awaiting Investigation';break;}}
        $verified=0;foreach($components as $a)if($a['confirmed'])$verified+=(int)$a['amount_cents'];
        $paymentAudit=$this->query('SELECT * FROM order_payment_allocation_audit WHERE order_id=? ORDER BY id DESC',[$id]);
        foreach($allocations as &$a){$a['recorded_by_employee_id']=null;foreach(array_reverse($paymentAudit) as $event){$before=json_decode($event['previous_allocations_json']??'null',true);$after=json_decode($event['new_allocations_json']??'null',true);if(!is_array($before)||!is_array($after))continue;$old=array_column($before,'payment_method');$new=array_column($after,'payment_method');if(!in_array($a['payment_method'],$old,true)&&in_array($a['payment_method'],$new,true)){$a['recorded_by_employee_id']=$event['changed_by_employee_id'];break;}}}unset($a);
        // Orders board displays and filters order_type, not inferred shipping metadata.
        $modeValue=strtolower(trim((string)($order['order_type']??'collection')));$mode=['mode'=>$modeValue,'label'=>ucfirst(str_replace('_',' ',$modeValue))];$recorded=array_sum(array_map('intval',array_column($allocations,'amount_cents')));
        $reasons=[];if($recorded>self::cents((string)$order['total_amount']))$reasons[]='Recorded payments exceed the order total. Correct the operational allocation, then reopen verification.';
        foreach($allocations as $a){if((int)$a['amount_cents']<=0)$reasons[]='Allocation '.$a['id'].' is zero or negative; correct it in Orders.';if($a['match']&&$a['match']['status']==='confirmed'&&!$a['evidence_current'])$reasons[]='Allocation '.$a['id'].' or its evidence changed after confirmation. Reopen verification with a reason.';}
        return ['order'=>$order,'mode'=>$mode,'allocations'=>$allocations,'evidence'=>$evidence,'status'=>$status,'recorded_cents'=>$recorded,'verified_cents'=>$verified,'difference_cents'=>self::cents((string)$order['total_amount'])-$verified,'confirmation_blocks'=>$reasons,'audit'=>$audit,'payment_audit'=>$paymentAudit];
    }
    public function act(int $employee,string $action,array $body):void
    {
        $this->cache=[];$this->owner($employee);$this->db->beginTransaction();
        try{
            $this->owner($employee);
            $order=(int)($body['order']??0);if(!$this->query('SELECT id FROM ops_orders WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$order]))throw new \DomainException('Order unavailable.');$allocations=$this->allocations($order,true);
            // Hold source rows stable while checking evidence. These locks do not change source records.
            $this->query('SELECT id FROM ops_cash_book_entries WHERE related_order_id=? FOR UPDATE',[$order]);
            $jobs=$this->query("SELECT id FROM delivery_jobs WHERE order_id=? AND source='hambelela' ORDER BY id FOR UPDATE",[$order]);
            foreach($jobs as $job)$this->query('SELECT id FROM delivery_receipts WHERE delivery_id=? ORDER BY id FOR UPDATE',[$job['id']]);
            if($action==='flag'||$action==='resolve'){
                $note=trim((string)($body['note']??''));if($note===''||strlen($note)>2000)throw new \DomainException('Enter an issue or resolution description.');
                $choice=(string)($body['choice']??'');$allowed=$action==='flag'?['Payment not received','Amount differs','Wrong payment method','Missing Bookkeeping entry','Driver collection not handed over','Duplicate payment','Other']:['Payment confirmed','Payment corrected','Awaiting payment','Incorrect flag removed','Other resolution'];
                if(!in_array($choice,$allowed,true))throw new \DomainException('Choose an issue reason or resolution outcome.');
                if($action==='resolve'&&$choice==='Payment confirmed'){
                    $d=$this->detail($order);if(!$d['allocations'])throw new \DomainException('No recorded payment allocations to verify.');
                    foreach($d['allocations'] as $a)if(!$a['confirmed'])throw new \DomainException('Explicitly verify each payment portion first, then resolve as Payment confirmed.');
                    if($d['confirmation_blocks'])throw new \DomainException(implode(' ',$d['confirmation_blocks']));
                }
                if($action==='resolve'&&$choice==='Awaiting payment'){
                    foreach($allocations as $a){$prior=$this->query('SELECT * FROM accounts_payment_matches WHERE allocation_id=? FOR UPDATE',[$a['id']]);if($prior){$this->audit($order,(int)$a['id'],'reopened',$employee,$prior[0]['evidence_key'],['note'=>$note,'previous_match'=>$prior[0]]);$this->db->prepare('DELETE FROM accounts_payment_matches WHERE allocation_id=?')->execute([$a['id']]);}}
                }
                $this->audit($order,null,$action,$employee,null,['choice'=>$choice,'note'=>$note]);
            }
            elseif($action==='manual-confirm'){$this->manualConfirm($employee,$order,$allocations,$body);}
            elseif($action==='reopen'){
                $note=trim((string)($body['note']??''));if($note===''||strlen($note)>2000)throw new \DomainException('A reason is required to reopen verification.');
                foreach($allocations as $a){$matches=$this->query('SELECT * FROM accounts_payment_matches WHERE allocation_id=? FOR UPDATE',[$a['id']]);foreach($matches as $match){$this->audit($order,(int)$a['id'],'reopened',$employee,$match['evidence_key'],['note'=>$note,'previous_match'=>$match]);$this->db->prepare('DELETE FROM accounts_payment_matches WHERE allocation_id=?')->execute([$a['id']]);}}
            }
            elseif($action==='reject'){
                $allocation=null;foreach($allocations as $a)if((int)$a['id']===(int)($body['allocation']??0))$allocation=$a;
                if(!$allocation)throw new \DomainException('Payment component unavailable.');
                $key=(string)($body['evidence']??'');$note=trim((string)($body['note']??''));if($key===''||$note===''||strlen($note)>2000)throw new \DomainException('Evidence and a rejection reason are required.');
                $match=$this->query('SELECT * FROM accounts_payment_matches WHERE allocation_id=? FOR UPDATE',[$allocation['id']]);
                if($match&&$match[0]['status']==='confirmed')throw new \DomainException('Confirmed evidence is immutable. Flag a discrepancy instead.');
                $this->db->prepare("DELETE FROM accounts_payment_matches WHERE allocation_id=? AND evidence_key=? AND status='matched'")->execute([$allocation['id'],$key]);
                $this->audit($order,(int)$allocation['id'],'rejected',$employee,$key,['note'=>$note]);
            }
            elseif($action==='match'){
                $allocation=null;foreach($allocations as $a)if((int)$a['id']===(int)($body['allocation']??0))$allocation=$a;
                if(!$allocation)throw new \DomainException('Payment component unavailable.');$evidence=null;
                $this->lockImported((string)($body['evidence']??''));
                foreach($this->evidence($order) as $e)if($e['key']===($body['evidence']??''))$evidence=$e;
                if(!$evidence)throw new \DomainException('Evidence unavailable.');$check=PaymentEvidence::match($allocation,$evidence);
                if(!$check['eligible'])throw new \DomainException(implode(' ',$check['reasons']));
                if(strpos($evidence['key'],'cash:')===0){$cash=(int)substr($evidence['key'],5);foreach($this->query('SELECT note FROM accounts_payment_matches FOR UPDATE') as $m){$v=json_decode($m['note']??'',true);if((int)($v['cashbook_id']??0)===$cash)throw new \DomainException('This cashbook receipt already supports grouped delivery verification. It cannot also be used as a whole receipt.');}}
                $current=$this->query('SELECT * FROM accounts_payment_matches WHERE allocation_id=? FOR UPDATE',[$allocation['id']]);
                if($current&&$current[0]['status']==='confirmed')throw new \DomainException('Confirmed evidence cannot be replaced. Flag it for review.');
                if($current&&$current[0]['evidence_key']===$evidence['key']&&$current[0]['allocation_fingerprint']===self::fingerprint($allocation)&&$current[0]['evidence_fingerprint']===$evidence['fingerprint']){$this->db->commit();return;}
                if($current)throw new \DomainException('Reject the previous match before replacing it.');
                $this->db->prepare("INSERT INTO accounts_payment_matches(allocation_id,evidence_key,allocation_fingerprint,evidence_fingerprint,status,matched_by) VALUES(?,?,?,?,'matched',?) ON DUPLICATE KEY UPDATE allocation_id=allocation_id")->execute([$allocation['id'],$evidence['key'],self::fingerprint($allocation),$evidence['fingerprint'],$employee]);
                $saved=$this->query('SELECT * FROM accounts_payment_matches WHERE allocation_id=?',[$allocation['id']]);
                if(!$saved||$saved[0]['evidence_key']!==$evidence['key']||$saved[0]['allocation_fingerprint']!==self::fingerprint($allocation))throw new \DomainException('Evidence already used or payment changed. Review before matching.');
                $this->audit($order,(int)$allocation['id'],'matched',$employee,$evidence['key'],$check);
            }elseif($action==='confirm'){
                foreach($allocations as $a){$m=$this->query('SELECT evidence_key FROM accounts_payment_matches WHERE allocation_id=? FOR UPDATE',[$a['id']]);if($m)$this->lockImported($m[0]['evidence_key']);}
                $d=$this->detail($order);if($d['status']==='Confirmed'){$this->db->commit();return;}
                if($d['status']!=='Matched')throw new \DomainException('Every payment component requires current independent evidence before confirmation.');
                foreach($d['allocations'] as $a){$this->db->prepare("UPDATE accounts_payment_matches SET status='confirmed',confirmed_by=?,confirmed_at=CURRENT_TIMESTAMP() WHERE allocation_id=?")->execute([$employee,$a['id']]);$this->audit($order,(int)$a['id'],'confirmed',$employee,$a['match']['evidence_key'],['amount_cents'=>(int)$a['amount_cents']]);}
            }else throw new \DomainException('Unsupported reconciliation action.');
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function lockImported(string $key):void{if(preg_match('/^import:(\d+)$/',$key,$m))$this->query('SELECT id FROM accounts_payment_evidence WHERE id=? FOR UPDATE',[(int)$m[1]]);}
    public function listing(string $from,string $to):array
    {
        foreach([$from,$to] as $date){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new \DomainException('Choose valid dates.');}
        if($from>$to||(new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days>93)throw new \DomainException('Choose a range of up to 93 days.');
        $end=(new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
        $rows=$this->query('SELECT id FROM ops_orders WHERE created_at>=? AND created_at<? AND deleted_at IS NULL AND archived_at IS NULL ORDER BY created_at DESC,id DESC',[$from,$end]);$out=[];
        $ids=array_map('intval',array_column($rows,'id'));
        $this->prime('SELECT id,order_number,order_type,customer_name,customer_contact,total_amount,created_at,created_by,payment_status,payment_method,payment_updated_by_employee_id FROM ops_orders WHERE id=? AND deleted_at IS NULL','id',$ids);
        $allocationRows=$this->prime('SELECT * FROM order_payment_allocations WHERE order_id=? ORDER BY id','order_id',$ids);
        $this->prime('SELECT * FROM accounts_payment_matches WHERE allocation_id=?','allocation_id',array_map('intval',array_column($allocationRows,'id')));
        $this->prime('SELECT * FROM accounts_payment_review_audit WHERE order_id=? ORDER BY id DESC','order_id',$ids);
        $this->prime('SELECT * FROM order_payment_allocation_audit WHERE order_id=? ORDER BY id DESC','order_id',$ids);
        $this->prime('SELECT * FROM ops_cash_book_entries WHERE related_order_id=? AND deleted_at IS NULL AND archived_at IS NULL','related_order_id',$ids);
        $this->prime("SELECT r.*,j.fee_order_component_cents,j.order_id FROM delivery_receipts r JOIN delivery_jobs j ON j.id=r.delivery_id WHERE j.order_id=? AND j.source='hambelela' ORDER BY r.id",'order_id',$ids);
        foreach($rows as $r){$d=$this->detail((int)$r['id']);$o=$d['order'];$methods=[];$employees=[];$matched=0;$driver=false;$book=false;$refs=[];
            foreach($d['allocations'] as $a){$methods[]=$a['payment_method'];$methods[]=$a['actual_method'];if($a['recorded_by_employee_id'])$employees[]=(int)$a['recorded_by_employee_id'];if($a['matched'])$matched+=(int)$a['amount_cents'];}
            foreach($d['allocations'] as $a)if($a['transaction_reference']!=='')$refs[]=$a['transaction_reference'];
            foreach($d['evidence'] as $e){if(strpos($e['key'],'driver:')===0)$driver=true;if(strpos($e['key'],'cash:')===0)$book=true;}
            $out[]=['id'=>(int)$o['id'],'number'=>$o['order_number'],'customer'=>$o['customer_name'],'date'=>$o['created_at'],'total_cents'=>self::cents((string)$o['total_amount']),'references'=>$refs,'methods'=>array_values(array_unique($methods)),'employees'=>array_values(array_unique($employees)),'matched_cents'=>$matched,'recorded_cents'=>$d['recorded_cents'],'outstanding_cents'=>max(0,self::cents((string)$o['total_amount'])-$d['recorded_cents']),'verified_cents'=>$d['verified_cents'],'status'=>$d['status'],'mode'=>$d['mode']['mode'],'driver'=>$driver||$d['mode']['mode']==='delivery','bookkeeping'=>$book];
        }
        $unmatched=$this->query("SELECT e.id,e.source_type,e.source_reference,e.transaction_at,e.amount_cents,e.method_code,e.settlement_state FROM accounts_payment_evidence e LEFT JOIN accounts_payment_matches m ON m.evidence_key=CONCAT('import:',e.id) WHERE m.allocation_id IS NULL AND e.transaction_at>=? AND e.transaction_at<? ORDER BY e.transaction_at DESC",[$from,$end]);
        $handovers=$this->query("SELECT COUNT(*) total FROM delivery_receipts r JOIN delivery_jobs j ON j.id=r.delivery_id JOIN ops_orders o ON o.id=j.order_id WHERE j.source='hambelela' AND r.status='reconciled' AND r.reversed_at IS NULL AND o.created_at>=? AND o.created_at<? AND o.deleted_at IS NULL AND o.archived_at IS NULL",[$from,$end]);
        return ['orders'=>$out,'unmatched'=>$unmatched,'handovers'=>(int)$handovers[0]['total'],'employees'=>$this->query('SELECT id,full_name FROM ops_employees ORDER BY full_name')];
    }
    private function prime(string $sql,string $field,array $ids):array
    {
        $all=[];foreach(array_chunk(array_unique($ids),500) as $chunk){$groups=array_fill_keys($chunk,[]);$batch=preg_replace('/=\?/', ' IN ('.implode(',',array_fill(0,count($chunk),'?')).')',$sql,1);foreach($this->query($batch,$chunk) as $row){$groups[(int)$row[$field]][]=$row;$all[]=$row;}foreach($groups as $id=>$group)$this->cache[$sql.json_encode([(int)$id])]=$group;}return $all;
    }
    private function manualConfirm(int $employee,int $order,array $allocations,array $body):void
    {
        $a=null;foreach($allocations as $row)if((int)$row['id']===(int)($body['allocation']??0))$a=$row;
        if(!$a)throw new \DomainException('No recorded payment allocation selected. Record operational payments in Orders first.');
        if(!hash_equals(self::fingerprint($a),(string)($body['fingerprint']??'')))throw new \DomainException('The payment changed. Reload and review its current amount before confirming.');
        $amount=self::cents((string)($body['amount']??''));if($amount!==(int)$a['amount_cents']||$amount<=0)throw new \DomainException('Verified amount must equal this recorded payment portion. Flag Amount differs and correct the operational payment before confirmation.');
        $method=(string)($body['actual_method']??'');if(!array_key_exists($method,\ops_payment_method_map()))throw new \DomainException('Choose a configured payment method.');
        $how=(string)($body['verification_method']??'');if(!in_array($how,['phone_wallet','bank_statement','card_receipt','cash_handover','other'],true))throw new \DomainException('Choose how you independently verified receipt.');
        if(($body['attested']??false)!==true)throw new \DomainException('Explicitly confirm that you checked receipt of this money.');
        $note=trim((string)($body['note']??''));$reference=trim((string)($body['reference']??''));if(strlen($note)>2000||strlen($reference)>190||($how==='other'&&$note===''))throw new \DomainException('Provide a supporting note for Other evidence (maximum 2,000 characters).');
        $d=$this->detail($order);if($d['confirmation_blocks'])throw new \DomainException(implode(' ',$d['confirmation_blocks']));
        $v=['amount_cents'=>$amount,'original_method'=>$a['payment_method'],'actual_method'=>$method,'verification_method'=>$how,'reference'=>$reference,'note'=>$note,'evidence_source'=>'Owner independent verification','order_number'=>$d['order']['order_number']];
        $existing=$this->query('SELECT * FROM accounts_payment_matches WHERE allocation_id=? FOR UPDATE',[$a['id']]);
        if($existing&&$existing[0]['status']==='confirmed'){
            $previous=json_decode($existing[0]['note']??'',true);
            if($existing[0]['evidence_key']==='manual:'.$a['id']&&$previous&&$previous['actual_method']===$method&&$previous['amount_cents']===$amount)return;
            throw new \DomainException('This allocation is already confirmed. Reopen it with a reason before changing verification.');
        }
        // Linking a grouped handover reserves only an evidence portion, never creates cash or an allocation.
        $cash=(int)($body['cashbook_id']??0);
        if($cash){
            if($d['mode']['mode']!=='delivery'||$method!=='cash'||$how!=='cash_handover')throw new \DomainException('Grouped cash handovers apply only to Delivery cash verification.');
            $sources=$this->query('SELECT * FROM ops_cash_book_entries WHERE id=? AND deleted_at IS NULL AND archived_at IS NULL FOR UPDATE',[$cash]);
            if(!$sources)throw new \DomainException('Existing handover cashbook entry not found.');$source=$sources[0];$available=self::cents((string)$source['cash_in'])-self::cents((string)$source['cash_out']);
            if($source['actual_count']===null||self::cents((string)$source['actual_count'])!==$available)throw new \DomainException('The selected cashbook entry does not have a matching actual cash count.');
            $reserved=0;foreach($this->query('SELECT * FROM accounts_payment_matches FOR UPDATE') as $m){if((int)$m['allocation_id']===(int)$a['id'])continue;if($m['evidence_key']==='cash:'.$cash)throw new \DomainException('This cashbook receipt is already matched as a whole receipt.');$meta=json_decode($m['note']??'',true);if((int)($meta['cashbook_id']??0)===$cash)$reserved+=(int)$meta['amount_cents'];}
            if($amount+$reserved>$available)throw new \DomainException('Grouped handover remaining amount is N$'.number_format(($available-$reserved)/100,2).'; this portion would reuse received cash.');
            $v['cashbook_id']=$cash;$v['cashbook_fingerprint']=self::fingerprint($source);$v['evidence_source']='Existing grouped cash handover #'.$cash;
        }
        if($existing)$this->audit($order,(int)$a['id'],'match_replaced_by_owner',$employee,$existing[0]['evidence_key'],['previous_match'=>$existing[0]]);
        $this->db->prepare("INSERT INTO accounts_payment_matches(allocation_id,evidence_key,allocation_fingerprint,evidence_fingerprint,status,note,matched_by,confirmed_by,confirmed_at) VALUES(?,?,?,?,'confirmed',?,?,?,CURRENT_TIMESTAMP()) ON DUPLICATE KEY UPDATE evidence_key=VALUES(evidence_key),allocation_fingerprint=VALUES(allocation_fingerprint),evidence_fingerprint=VALUES(evidence_fingerprint),status='confirmed',note=VALUES(note),matched_by=VALUES(matched_by),confirmed_by=VALUES(confirmed_by),confirmed_at=CURRENT_TIMESTAMP()")->execute([$a['id'],'manual:'.$a['id'],self::fingerprint($a),self::fingerprint($v),json_encode($v,JSON_THROW_ON_ERROR),$employee,$employee]);
        $this->audit($order,(int)$a['id'],'owner_confirmed',$employee,'manual:'.$a['id'],$v);
    }
    public function importTerminal(int $employee,array $report):array
    {
        $this->owner($employee);$inserted=0;$this->db->beginTransaction();
        try{foreach($report['transactions'] as $r){$s=$this->db->prepare("INSERT IGNORE INTO accounts_payment_evidence(source_key,source_type,source_reference,transaction_at,amount_cents,method_code,settlement_state,metadata_json,imported_by) VALUES(?,'fnb_terminal',?,?,?,'card_swipe','terminal_approved',?,?)");$s->execute([$r['source_key'],$r['rrn'],$r['transaction_at'],$r['amount_cents'],json_encode($r,JSON_THROW_ON_ERROR),$employee]);$inserted+=$s->rowCount();}$this->db->commit();return ['imported'=>$inserted,'duplicates'=>count($report['transactions'])-$inserted];}catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function importBank(int $employee,array $report):array
    {
        $this->owner($employee);$inserted=0;$this->db->beginTransaction();
        try{foreach($report['transactions'] as $r){$s=$this->db->prepare("INSERT IGNORE INTO accounts_payment_evidence(source_key,source_type,source_reference,transaction_at,amount_cents,method_code,settlement_state,metadata_json,imported_by) VALUES(?,'bank_processor_csv',?,?,?,?,'settled',?,?)");$s->execute([$r['source_key'],$r['reference'],$r['transaction_at'],$r['amount_cents'],$r['method_code'],json_encode($r,JSON_THROW_ON_ERROR),$employee]);$inserted+=$s->rowCount();}$this->db->commit();return ['imported'=>$inserted,'duplicates'=>count($report['transactions'])-$inserted];}catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    private function audit(int $order,?int $allocation,string $action,int $employee,?string $evidence,array $details):void
    {
        $this->db->prepare('INSERT INTO accounts_payment_review_audit(order_id,allocation_id,action,employee_id,evidence_key,details_json) VALUES(?,?,?,?,?,?)')->execute([$order,$allocation,$action,$employee,$evidence,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
}
