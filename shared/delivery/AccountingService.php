<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';

final class AccountingService
{
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    private function rows(string $sql,array $params=[]):array{$s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll(\PDO::FETCH_ASSOC);}
    private function write(string $sql,array $params=[]):void{$this->db->prepare($sql)->execute($params);}
    private function run(array $actor,string $permission,string $uuid,string $action,array $body,callable $apply):array
    {
        if(!DeliveryPolicy::can($actor,$permission))throw new \DomainException('Delivery accounting permission required.');
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uuid))throw new \DomainException('Invalid request ID.');
        if($this->db->inTransaction())throw new \LogicException('Accounting owns its transaction.');
        $this->db->beginTransaction();
        try{
            $employee=$this->rows("SELECT e.id,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' FOR UPDATE",[$actor['id']]);if(!$employee)throw new \DomainException('Active employee required.');
            $grants=$this->rows('SELECT p.permission_key FROM delivery_employee_grants g JOIN ops_permissions p ON p.id=g.permission_id WHERE g.employee_id=?',[$actor['id']]);
            $fresh=['kind'=>'employee','id'=>(int)$employee[0]['id'],'active'=>true,'role'=>$employee[0]['role_key'],'grants'=>array_column($grants,'permission_key')];
            if(!DeliveryPolicy::can($fresh,$permission))throw new \DomainException('Accounting permission changed. Sign in again.');
            $key='employee:'.$actor['id'];$hash=hash('sha256',json_encode([$action,$body],JSON_THROW_ON_ERROR));
            $old=$this->rows('SELECT action,request_hash,response_json FROM delivery_requests WHERE actor_key=? AND request_uuid=? FOR UPDATE',[$key,$uuid]);
            if($old){if($old[0]['action']!==$action||!hash_equals($old[0]['request_hash'],$hash))throw new \DomainException('Request ID already used.');$result=json_decode($old[0]['response_json'],true,32,JSON_THROW_ON_ERROR);$this->db->commit();return $result;}
            $result=$apply();$json=json_encode($result,JSON_THROW_ON_ERROR);
            $this->write('INSERT INTO delivery_requests(actor_key,request_uuid,action,request_hash,response_json) VALUES(?,?,?,?,?)',[$key,$uuid,$action,$hash,$json]);
            $this->write('INSERT INTO ops_security_events(event_type,employee_id,metadata_json) VALUES(?,?,?)',['delivery_'.$action,$actor['id'],$json]);
            $this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public static function date(string $value):string{$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new \DomainException('Valid date required.');return $value;}
    public function saveZone(array $actor,string $uuid,array $body):array
    {
        $id=(int)($body['id']??0);$area=trim((string)($body['area']??''));$fee=DeliveryPolicy::cents((string)($body['fee']??''));$active=!empty($body['active']);
        $aliases=array_values(array_unique(array_filter(array_map('trim',explode(',',(string)($body['aliases']??''))))));
        if($area===''||strlen($area)>190||count($aliases)>20||strlen(implode(',',$aliases))>1000)throw new \DomainException('Area and short comma-separated aliases required.');
        return $this->run($actor,'delivery_rates_manage',$uuid,'rate_saved',[$id,$area,$fee,$active,$aliases],function()use($actor,$body,$id,$area,$fee,$active,$aliases){
            $before=null;if($id){$rows=$this->rows('SELECT * FROM delivery_zones WHERE id=? FOR UPDATE',[$id]);$before=$rows[0]??null;if(!$before||hash('sha256',json_encode($before))!==($body['version']??''))throw new \DomainException('Rate changed. Refresh before editing.');
                $this->write('UPDATE delivery_zones SET area=?,aliases_json=?,fee_cents=?,active=?,updated_by_employee_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$area,json_encode($aliases),$fee,(int)$active,$actor['id'],$id]);
            }else{$this->write('INSERT INTO delivery_zones(area,aliases_json,fee_cents,active,updated_by_employee_id) VALUES(?,?,?,?,?)',[$area,json_encode($aliases),$fee,(int)$active,$actor['id']]);$id=(int)$this->db->lastInsertId();}
            return ['id'=>$id,'area'=>$area,'fee_cents'=>$fee,'active'=>$active,'previous'=>$before];
        });
    }
    public function zones(array $actor):array
    {
        if(!DeliveryPolicy::can($actor,'delivery_rates_manage'))throw new \DomainException('Pricing permission required.');
        $rows=$this->rows('SELECT * FROM delivery_zones ORDER BY area');foreach($rows as &$r)$r['version']=hash('sha256',json_encode($r));return $rows;
    }
    public function expense(array $actor,string $uuid,array $body):array
    {
        $date=self::date((string)($body['date']??''));$category=(string)($body['category']??'');$amount=DeliveryPolicy::cents((string)($body['amount']??''));$description=trim((string)($body['description']??''));$supplier=trim((string)($body['supplier']??''));$paid=!empty($body['paid']);$replace=(int)($body['replace_id']??0);$reason=trim((string)($body['reason']??''));
        if(!in_array($category,['Fuel','Airtime / Data','Bike Maintenance','Repair','Parking','Other'],true)||$amount<=0||$description===''||strlen($description)>2000||strlen($supplier)>190||($replace&&strlen($reason)<5)||strlen($reason)>500)throw new \DomainException('Check expense category, positive amount, description and correction reason.');
        return $this->run($actor,'delivery_accounting_expenses_manage',$uuid,'expense_saved',[$date,$category,$amount,$description,$supplier,$paid,$replace,$reason],function()use($actor,$date,$category,$amount,$description,$supplier,$paid,$replace,$reason){
            if($replace)$this->reverseExpense($actor,$replace,$reason);
            $this->write('INSERT INTO delivery_expenses(expense_date,category,amount_cents,paid,paid_at,supplier,description,created_by_employee_id) VALUES(?,?,?,?,IF(?=1,UTC_TIMESTAMP(),NULL),?,?,?)',[$date,$category,$amount,(int)$paid,(int)$paid,$supplier,$description,$actor['id']]);$id=(int)$this->db->lastInsertId();
            if($paid)$this->write("INSERT INTO delivery_ledger(event_key,expense_id,account,amount_cents,employee_id,reason) VALUES(?,?,'expense_paid',?,?,?)",['expense:'.$id,$id,$amount,$actor['id'],$replace?'Correction of expense '.$replace:null]);
            return ['id'=>$id,'amount_cents'=>$amount,'paid'=>$paid,'replaces'=>$replace,'reason'=>$reason];
        });
    }
    private function reverseExpense(array $actor,int $id,string $reason):void
    {
        $rows=$this->rows('SELECT * FROM delivery_expenses WHERE id=? FOR UPDATE',[$id]);$old=$rows[0]??null;if(!$old||$old['reversed_at']!==null)throw new \DomainException('Expense already corrected or unavailable.');
        $this->write('UPDATE delivery_expenses SET reversed_at=UTC_TIMESTAMP(),reversed_by_employee_id=?,reversal_reason=? WHERE id=?',[$actor['id'],$reason,$id]);
        if((int)$old['paid']){
            $entries=$this->rows("SELECT id,amount_cents FROM delivery_ledger WHERE expense_id=? AND account='expense_paid' AND reversal_of IS NULL FOR UPDATE",[$id]);if(count($entries)!==1)throw new \DomainException('Expense ledger needs review.');
            $this->write("INSERT INTO delivery_ledger(event_key,expense_id,account,amount_cents,employee_id,reversal_of,reason) VALUES(?,?,'expense_paid',?,?,?,?)",['expense:'.$id.':reversal',$id,-(int)$entries[0]['amount_cents'],$actor['id'],$entries[0]['id'],$reason]);
        }
    }
    public function voidExpense(array $actor,string $uuid,int $id,string $reason):array
    {
        $reason=trim($reason);if(strlen($reason)<5||strlen($reason)>500)throw new \DomainException('Correction reason required.');
        return $this->run($actor,'delivery_accounting_expenses_manage',$uuid,'expense_voided',[$id,$reason],function()use($actor,$id,$reason){$this->reverseExpense($actor,$id,$reason);return ['id'=>$id,'reason'=>$reason];});
    }
    public function report(array $actor,string $from,string $to):array
    {
        if(!DeliveryPolicy::can($actor,'delivery_accounting_view'))throw new \DomainException('Accounting access denied.');
        return $this->readReport($from,$to,DeliveryPolicy::can($actor,'delivery_accounting_manage'),DeliveryPolicy::can($actor,'delivery_accounting_expenses_manage'));
    }
    public function partnerReport(array $actor,string $from,string $to):array
    {
        require_once __DIR__.'/PartnerAccess.php';
        if(($actor['kind']??'')!=='partner')throw new \DomainException('Partner reporting access required.');
        $fresh=(new PartnerAccess($this->db))->identity((int)($actor['id']??0));
        if($fresh['partner_id']!==(int)($actor['partner_id']??0)||(int)$fresh['session_version']!==(int)($actor['session_version']??0)||!PartnerAccess::can($fresh,'shared_reporting')||!PartnerAccess::can($fresh,'accounting'))throw new \DomainException('Shared Delivery reporting is not permitted.');
        return $this->readReport($from,$to,false,false);
    }
    private function readReport(string $from,string $to,bool $canSettle,bool $canExpense):array
    {
        self::date($from);self::date($to);if($from>$to)throw new \DomainException('Date range is reversed.');
        $zone=new \DateTimeZone('Africa/Windhoek');$utc=new \DateTimeZone('UTC');
        $start=(new \DateTimeImmutable($from,$zone))->setTimezone($utc)->format('Y-m-d H:i:s');$end=(new \DateTimeImmutable($to,$zone))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $sums=$this->rows('SELECT account,SUM(amount_cents) amount_cents FROM delivery_ledger WHERE created_at>=? AND created_at<? GROUP BY account',[$start,$end]);foreach($sums as &$s)$s['amount_cents']=(int)$s['amount_cents'];unset($s);
        $fund=$this->rows("SELECT COALESCE(SUM(CASE WHEN account='fee_received' THEN amount_cents WHEN account='expense_paid' THEN -amount_cents ELSE 0 END),0) balance FROM delivery_ledger")[0]['balance'];
        $outstanding=$this->rows("SELECT COALESCE(SUM(order_goods_cents+delivery_fee_cents+partner_cod_cents),0) total FROM delivery_receipts WHERE status IN ('collected','issue')")[0]['total'];
        return ['summary'=>DeliveryPolicy::financialSummary($sums),'available_fund_all_time'=>(int)$fund,'collections_awaiting_handover'=>(int)$outstanding,
'deliveries'=>$this->rows("SELECT j.*,(SELECT COALESCE(SUM(l.amount_cents),0) FROM delivery_ledger l WHERE l.delivery_id=j.id AND l.account='fee_received') fee_received_cents,(SELECT COALESCE(SUM(r.order_goods_cents+r.delivery_fee_cents+r.partner_cod_cents),0) FROM delivery_receipts r WHERE r.delivery_id=j.id) collected_cents,(SELECT COUNT(*) FROM delivery_receipts r WHERE r.delivery_id=j.id AND r.status IN ('collected','issue')) pending_receipts,e.full_name driver_name,p.name partner_name FROM delivery_jobs j LEFT JOIN ops_employees e ON e.id=j.driver_employee_id LEFT JOIN delivery_partners p ON p.id=j.partner_id WHERE scheduled_date BETWEEN ? AND ? ORDER BY scheduled_date DESC,j.id DESC LIMIT 500",[$from,$to]),
            'expenses'=>$this->rows('SELECT x.*,e.full_name recorded_by FROM delivery_expenses x JOIN ops_employees e ON e.id=x.created_by_employee_id WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date DESC,x.id DESC LIMIT 500',[$from,$to]),
            'partners'=>$this->rows("SELECT p.id,p.name,COALESCE(SUM(CASE WHEN l.account='fee_earned' THEN l.amount_cents ELSE 0 END),0) fee_earned,COALESCE(SUM(CASE WHEN l.account='fee_received' THEN l.amount_cents ELSE 0 END),0) fee_received,COALESCE(SUM(CASE WHEN l.account='partner_cod_held' THEN l.amount_cents WHEN l.account='partner_cod_remitted' THEN -l.amount_cents ELSE 0 END),0) cod_due,COALESCE(SUM(CASE WHEN j.fee_payer='partner' AND l.account='fee_earned' THEN l.amount_cents WHEN j.fee_payer='partner' AND l.account='fee_received' THEN -l.amount_cents ELSE 0 END),0) fee_due FROM delivery_partners p LEFT JOIN delivery_ledger l ON l.partner_id=p.id LEFT JOIN delivery_jobs j ON j.id=l.delivery_id GROUP BY p.id,p.name ORDER BY p.name"),
            'can_settle'=>$canSettle,'can_expense'=>$canExpense];
    }
    public function partnerSettlement(array $actor,string $uuid,int $partner,string $type,string $amount,string $reference,string $reason):array
    {
        $cents=DeliveryPolicy::cents($amount);$reference=trim($reference);$reason=trim($reason);
        if($cents<1||!in_array($type,['fee_received','partner_cod_remitted'],true)||$reference===''||strlen($reference)>190||strlen($reason)<5||strlen($reason)>500)throw new \DomainException('Valid settlement amount, reference and verification reason required.');
        return $this->run($actor,'delivery_accounting_manage',$uuid,'partner_settled',[$partner,$type,$cents,$reference,$reason],function()use($actor,$uuid,$partner,$type,$cents,$reference,$reason){
            if(!$this->rows('SELECT id FROM delivery_partners WHERE id=? FOR UPDATE',[$partner]))throw new \DomainException('Partner unavailable.');
            $jobs=$this->rows("SELECT id FROM delivery_jobs WHERE partner_id=? AND source='partner'".($type==='fee_received'?" AND fee_payer='partner'":'').' ORDER BY id FOR UPDATE',[$partner]);$remaining=$cents;$parts=[];
            foreach($jobs as $j){$rows=$this->rows('SELECT account,SUM(amount_cents) amount FROM delivery_ledger WHERE delivery_id=? GROUP BY account',[$j['id']]);$totals=array_column($rows,'amount','account');
                $due=$type==='fee_received'?(int)($totals['fee_earned']??0)-(int)($totals['fee_received']??0):(int)($totals['partner_cod_held']??0)-(int)($totals['partner_cod_remitted']??0);
                if($due<0)throw new \DomainException('Partner ledger needs review.');$take=min($remaining,$due);if($take>0){$parts[]=['id'=>$j['id'],'amount'=>$take];$remaining-=$take;}if(!$remaining)break;
            }
            if($remaining)throw new \DomainException('Settlement exceeds the verified amount due.');
            foreach($parts as $part)$this->write('INSERT INTO delivery_ledger(event_key,delivery_id,partner_id,account,amount_cents,reference,reason,employee_id) VALUES(?,?,?,?,?,?,?,?)',['settlement:'.$uuid.':'.$part['id'],$part['id'],$partner,$type,$part['amount'],$reference,$reason,$actor['id']]);
            return ['partner_id'=>$partner,'account'=>$type,'amount_cents'=>$cents,'reference'=>$reference,'reason'=>$reason];
        });
    }
}
