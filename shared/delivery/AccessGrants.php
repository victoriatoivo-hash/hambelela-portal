<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Delivery-only explicit grants. Never changes a staff role or its defaults. */
final class AccessGrants
{
    public const ALLOWED = [
        'delivery_view'=>'View deliveries',
        'delivery_arrange'=>'Arrange deliveries',
        'delivery_reconcile'=>'Reconcile Driver collections',
        'delivery_accounting_view'=>'View Delivery accounting',
        'delivery_accounting_manage'=>'Manage partner settlements',
        'delivery_accounting_expenses_manage'=>'Manage Delivery expenses',
        'delivery_rates_manage'=>'Manage Delivery pricing',
    ];
    public static function current(\PDO $db,int $employee):array
    {
        $s=$db->prepare('SELECT p.permission_key FROM delivery_employee_grants g JOIN ops_permissions p ON p.id=g.permission_id WHERE g.employee_id=? ORDER BY p.permission_key');
        $s->execute([$employee]);return $s->fetchAll(\PDO::FETCH_COLUMN);
    }
    public static function version(array $grants):string { sort($grants);return hash('sha256',json_encode($grants)); }
    public static function save(\PDO $db,int $owner,int $employee,array $grants,string $version,string $reason):void
    {
        $grants=array_values(array_unique($grants));sort($grants);$reason=trim($reason);
        foreach($grants as $grant)if(!is_string($grant)||!isset(self::ALLOWED[$grant]))throw new \DomainException('Unsupported Delivery permission.');
        if((in_array('delivery_accounting_manage',$grants,true)||in_array('delivery_accounting_expenses_manage',$grants,true)||in_array('delivery_rates_manage',$grants,true))&&!in_array('delivery_accounting_view',$grants,true))throw new \DomainException('Select View Delivery accounting as well when approving expense, settlement or pricing management.');
        if((in_array('delivery_arrange',$grants,true)||in_array('delivery_reconcile',$grants,true))&&!in_array('delivery_view',$grants,true))throw new \DomainException('Select View deliveries as well when approving arrangement or reconciliation.');
        if(strlen($reason)<5||strlen($reason)>500)throw new \DomainException('Record why this access is approved.');
        if($db->inTransaction())throw new \LogicException('Access update owns its transaction.');
        $db->beginTransaction();
        try{
            $s=$db->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin' FOR UPDATE");$s->execute([$owner]);
            if(!$s->fetchColumn())throw new \DomainException('Active Owner required.');
            $s=$db->prepare("SELECT r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' FOR UPDATE");$s->execute([$employee]);$role=$s->fetchColumn();
            if(!$role||in_array($role,['delivery_driver','owner_admin'],true))throw new \DomainException('Choose an active non-Driver staff member. Owners already have access.');
            $old=self::current($db,$employee);
            if(!hash_equals(self::version($old),$version))throw new \DomainException('Access changed. Reload and review before saving.');
            foreach($grants as $grant){
                // Existing permission catalogue only; no schema or role modifications.
                $s=$db->prepare('SELECT id FROM ops_permissions WHERE permission_key=?');$s->execute([$grant]);$id=$s->fetchColumn();
                if(!$id){$db->prepare('INSERT INTO ops_permissions(permission_key,description) VALUES(?,?)')->execute([$grant,self::ALLOWED[$grant]]);$id=$db->lastInsertId();}
                $db->prepare('INSERT IGNORE INTO delivery_employee_grants(employee_id,permission_id,granted_by_employee_id,granted_at) VALUES(?,?,?,UTC_TIMESTAMP())')->execute([$employee,$id,$owner]);
            }
            foreach(array_diff($old,$grants) as $grant){
                if(!isset(self::ALLOWED[$grant]))continue;
                $db->prepare('DELETE g FROM delivery_employee_grants g JOIN ops_permissions p ON p.id=g.permission_id WHERE g.employee_id=? AND p.permission_key=?')->execute([$employee,$grant]);
            }
            $db->prepare('INSERT INTO ops_security_events(event_type,employee_id,metadata_json) VALUES(?,?,?)')->execute(['delivery_grants_updated',$owner,json_encode(['target_employee_id'=>$employee,'previous'=>$old,'approved'=>$grants,'reason'=>$reason],JSON_THROW_ON_ERROR)]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
