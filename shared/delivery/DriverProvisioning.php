<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DriverAuth.php';

/** Owner-only provisioning. Caller supplies a user-entered secret; never return/log it. */
final class DriverProvisioning
{
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    public function save(int $ownerId,string $name,string $mobile,string $secret,int $existingId=0):int
    {
        $name=trim($name);$mobile=DriverAuth::mobile($mobile);
        if($name===''||strlen($name)>160||preg_match('/[\x00-\x1f]/',$name))throw new \DomainException('Enter the verified employee name.');
        if(strlen($secret)<16||strlen($secret)>72||trim($secret)===''||strpos($secret,$mobile)!==false)throw new \DomainException('Use a private access secret of 16–72 bytes, not the mobile number.');
        if($this->db->inTransaction())throw new \LogicException('Provisioning owns its transaction.');
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin' FOR UPDATE");$s->execute([$ownerId]);
            if(!$s->fetchColumn())throw new \DomainException('Active Owner access required.');
            // Serialize provisioning against the single role; never reuse a broad staff role.
            $this->db->exec("INSERT INTO ops_roles(role_key,name,description) VALUES('delivery_driver','Delivery Driver','Restricted Delivery mobile access only') ON DUPLICATE KEY UPDATE role_key=VALUES(role_key)");
            $role=(int)$this->db->query("SELECT id FROM ops_roles WHERE role_key='delivery_driver' FOR UPDATE")->fetchColumn();
            $s=$this->db->prepare('SELECT COUNT(*) FROM ops_role_permissions WHERE role_id=?');$s->execute([$role]);
            if((int)$s->fetchColumn()!==0)throw new \DomainException('Driver role has staff permissions. Review access before provisioning.');
            $s=$this->db->prepare('SELECT employee_id FROM delivery_driver_credentials WHERE mobile=? FOR UPDATE');$s->execute([$mobile]);$linked=$s->fetchColumn();
            if($linked!==false&&(int)$linked!==$existingId)throw new \DomainException('Mobile already belongs to a Driver account. Verify the existing employee ID.');
            if($existingId>0){
                $s=$this->db->prepare('SELECT id,role_id,status,full_name,phone,password_hash FROM ops_employees WHERE id=? FOR UPDATE');$s->execute([$existingId]);$employee=$s->fetch(\PDO::FETCH_ASSOC);
                if(!$employee||(int)$employee['role_id']!==$role||$employee['status']!=='active'||!empty($employee['password_hash'])||trim($employee['full_name'])!==$name||DriverAuth::mobile((string)$employee['phone'])!==$mobile)
                    throw new \DomainException('Employee ID, name, mobile and restricted Driver role must all match. Existing staff roles are not changed.');
                $s=$this->db->prepare('SELECT mobile FROM delivery_driver_credentials WHERE employee_id=? FOR UPDATE');$s->execute([$existingId]);$credentialMobile=$s->fetchColumn();
                if($credentialMobile!==false&&$credentialMobile!==$mobile)throw new \DomainException('Stored Driver mobile differs. Verify the account before changing credentials.');
                $id=$existingId;
            }else{
                // Matching is used only to block accidental duplicates, never to establish identity.
                foreach($this->db->query('SELECT full_name,phone FROM ops_employees')->fetchAll(\PDO::FETCH_ASSOC) as $employee){
                    $samePhone=false;try{$samePhone=DriverAuth::mobile((string)$employee['phone'])===$mobile;}catch(\DomainException $e){}
                    if($samePhone||strcasecmp(trim($employee['full_name']),$name)===0)throw new \DomainException('A possible employee already exists. Verify its ID; no duplicate was created.');
                }
                $s=$this->db->prepare("INSERT INTO ops_employees(role_id,full_name,phone,email,password_hash,status,packing_assignable,packing_auto_assignable) VALUES(?,?,?,NULL,NULL,'active',0,0)");$s->execute([$role,$name,$mobile]);$id=(int)$this->db->lastInsertId();
            }
            $hash=password_hash($secret,PASSWORD_DEFAULT);if(!$hash)throw new \RuntimeException('Secret hashing failed.');
            $this->db->prepare('INSERT INTO delivery_driver_profiles(employee_id,active,verified_by_employee_id,verified_at) VALUES(?,1,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE active=1,verified_by_employee_id=VALUES(verified_by_employee_id),verified_at=UTC_TIMESTAMP()')->execute([$id,$ownerId]);
            $this->db->prepare('INSERT INTO delivery_driver_credentials(employee_id,mobile,secret_hash,active,session_version) VALUES(?,?,?,1,1) ON DUPLICATE KEY UPDATE secret_hash=VALUES(secret_hash),active=1,session_version=session_version+1')->execute([$id,$mobile,$hash]);
            $metadata=json_encode(['target_employee_id'=>$id,'created'=>$existingId===0,'session_revocation'=>true],JSON_THROW_ON_ERROR);
            $this->db->prepare("INSERT INTO ops_security_events(event_type,employee_id,metadata_json) VALUES('delivery_driver_provisioned',?,?)")->execute([$ownerId,$metadata]);
            $this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
