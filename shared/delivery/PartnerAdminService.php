<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/PartnerAccess.php';

final class PartnerAdminService
{
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    private function rows(string $sql,array $args=[]):array{$s=$this->db->prepare($sql);$s->execute($args);return $s->fetchAll(\PDO::FETCH_ASSOC);}
    private function actor(array $actor,string $permission,bool $lock=false):array
    {
        if(($actor['kind']??'')!=='partner'||empty($actor['active']))throw new \DomainException('Partner access required.');
        $fresh=(new PartnerAccess($this->db))->identity((int)$actor['id'],$lock);
        if((int)($actor['session_version']??0)!==(int)$fresh['session_version'])throw new \DomainException('Please sign in again.');
        if($fresh['partner_id']!==(int)($actor['partner_id']??0)||!PartnerAccess::can($fresh,$permission))throw new \DomainException('This application is not permitted.');return $fresh;
    }
    public function driver(array $actor,array $q):array
    {
        $a=$this->actor($actor,'driver');[$scope,$args]=PartnerAccess::can($a,'shared_reporting')?['1=1',[]]:PartnerAccess::scope($a);
        $view=(string)($q['view']??'active');
        if($view==='completed')$scope.=" AND j.status='completed'";
        elseif($view!=='history')$scope.=" AND j.status NOT IN ('completed','cancelled')";
        $search=trim((string)($q['q']??''));if(strlen($search)>190)throw new \DomainException('Search is too long.');
        if($search!==''){$scope.=' AND (j.public_reference LIKE ? OR j.partner_reference LIKE ? OR j.customer_name LIKE ?)';array_push($args,'%'.$search.'%','%'.$search.'%','%'.$search.'%');}
        return ['jobs'=>$this->rows("SELECT j.id,j.public_reference,j.partner_reference,j.customer_name,j.address,j.area,j.status,j.urgent,j.scheduled_date,j.started_at,j.delivered_at,j.completed_at,e.full_name driver_name FROM delivery_jobs j LEFT JOIN ops_employees e ON e.id=j.driver_employee_id WHERE $scope ORDER BY j.id DESC LIMIT 200",$args)];
    }
    public function accounting(array $actor,array $q=[]):array
    {
        $a=$this->actor($actor,'accounting');$id=$a['partner_id'];
        if(PartnerAccess::can($a,'shared_reporting')){
            require_once __DIR__.'/AccountingService.php';
            return (new AccountingService($this->db))->partnerReport($a,(string)($q['from']??date('Y-m-01')),(string)($q['to']??date('Y-m-d')));
        }
        // Only partner-tagged entries and this company's jobs. Global fund/expenses never enter this query.
        $ledger=$this->rows("SELECT l.account,l.amount_cents,l.created_at,j.public_reference,CASE WHEN j.id IS NULL THEN NULL ELSE j.fee_payer END fee_payer FROM delivery_ledger l LEFT JOIN delivery_jobs j ON j.id=l.delivery_id WHERE l.partner_id=? AND (l.delivery_id IS NULL OR (j.source='partner' AND j.partner_id=?)) AND l.account IN ('fee_earned','fee_received','partner_cod_held','partner_cod_remitted') ORDER BY l.created_at DESC,l.id DESC",[$id,$id]);
        $totals=['fee_earned'=>0,'fee_received'=>0,'partner_cod_held'=>0,'partner_cod_remitted'=>0,'fee_due'=>0,'cod_due'=>0];
        foreach($ledger as $row){$amount=(int)$row['amount_cents'];$totals[$row['account']]+=$amount;if($row['account']==='fee_earned'&&$row['fee_payer']==='partner')$totals['fee_due']+=$amount;if($row['account']==='fee_received'&&$row['fee_payer']==='partner')$totals['fee_due']-=$amount;}
        $totals['cod_due']=$totals['partner_cod_held']-$totals['partner_cod_remitted'];
        return ['totals'=>$totals,'entries'=>array_slice($ledger,0,500),'expenses'=>[],'expenses_note'=>'Only explicitly attributed Tedlaser transactions are shown. No company-wide expenses are included.'];
    }
    public function pricing(array $actor):array
    {
        $this->actor($actor,'shared_reporting');
        return ['zones'=>$this->rows('SELECT id,area,aliases_json,fee_cents,active FROM delivery_zones ORDER BY area')];
    }
    public function edit(array $actor,array $b):array
    {
        $this->db->beginTransaction();
        try{
            $a=$this->actor($actor,'edit',true);[$scope,$args]=PartnerAccess::scope($a);$id=(int)($b['id']??0);$args[]=$id;
            $rows=$this->rows("SELECT j.id,j.version,j.status FROM delivery_jobs j WHERE $scope AND j.id=? FOR UPDATE",$args);$j=$rows[0]??null;
            if(!$j||$j['status']!=='ready')throw new \DomainException('Only your authorised, not-yet-started deliveries can be edited.');
            if((int)($b['version']??0)!==(int)$j['version'])throw new \DomainException('Delivery changed. Refresh before editing.');
            $notes=trim((string)($b['notes']??''));if(strlen($notes)>4000)throw new \DomainException('Instructions are too long.');$urgent=(int)!empty($b['urgent']);
            $this->db->prepare('UPDATE delivery_jobs SET notes=?,urgent=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$notes,$urgent,$id]);
            $this->db->prepare("INSERT INTO delivery_events(delivery_id,event_type,partner_user_id,metadata_json) VALUES(?,'partner_updated',?,?)")->execute([$id,$a['id'],json_encode(['notes'=>$notes,'urgent'=>$urgent],JSON_THROW_ON_ERROR)]);
            $this->db->commit();return ['id'=>$id];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
