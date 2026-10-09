<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Additive partner permissions. No employee identity or employee permissions are changed. */
final class PartnerAccess
{
    private \PDO $db;
    public function __construct(\PDO $db){$this->db=$db;}
    public function install():void
    {
        if($this->db->inTransaction())throw new \LogicException('Partner schema installation requires an independent connection state.');
        try{
            $this->db->query('SELECT user_id FROM delivery_partner_access LIMIT 0');
            $this->db->query('SELECT user_id FROM delivery_partner_reset_tokens LIMIT 0');
            return;
        }catch(\PDOException $e){if($e->getCode()!=='42S02')throw $e;}
        $this->db->exec("CREATE TABLE IF NOT EXISTS delivery_partner_access (user_id BIGINT UNSIGNED PRIMARY KEY,role_key VARCHAR(30) NOT NULL DEFAULT 'partner_staff',can_create TINYINT NOT NULL DEFAULT 1,can_edit TINYINT NOT NULL DEFAULT 0,can_driver TINYINT NOT NULL DEFAULT 0,can_accounting TINYINT NOT NULL DEFAULT 0,last_login_at DATETIME NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("CREATE TABLE IF NOT EXISTS delivery_partner_reset_tokens (user_id BIGINT UNSIGNED PRIMARY KEY,token_hash CHAR(64) NOT NULL UNIQUE,expires_at DATETIME NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function identity(int $id,bool $lock=false):array
    {
        $s=$this->db->prepare("SELECT u.id,u.partner_id,u.display_name,u.email,u.active,u.session_version,p.active partner_active,COALESCE(a.role_key,'partner_staff') role,COALESCE(a.can_create,1) can_create,COALESCE(a.can_edit,0) can_edit,COALESCE(a.can_driver,0) can_driver,COALESCE(a.can_accounting,0) can_accounting FROM delivery_partner_users u JOIN delivery_partners p ON p.id=u.partner_id LEFT JOIN delivery_partner_access a ON a.user_id=u.id WHERE u.id=?".($lock?' FOR UPDATE':''));$s->execute([$id]);$r=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$r||!(int)$r['active']||!(int)$r['partner_active'])throw new \DomainException('Partner account unavailable.');
        $r['kind']='partner';$r['id']=(int)$r['id'];$r['partner_id']=(int)$r['partner_id'];$r['active']=true;
        return $r;
    }
    public static function can(array $a,string $permission):bool
    {
        if(($a['kind']??'')!=='partner'||empty($a['active'])||empty($a['id'])||empty($a['partner_id']))return false;
        if(!in_array($a['role']??'',['partner_staff','partner_admin'],true))return false;
        if(in_array($permission,['driver','accounting'],true)&&$a['role']!=='partner_admin')return false;
        return in_array($permission,['create','edit','driver','accounting'],true)&&!empty($a['can_'.$permission]);
    }
    public static function scope(array $a,string $alias='j'):array
    {
        if(($a['kind']??'')!=='partner'||empty($a['active'])||empty($a['partner_id'])||empty($a['id']))throw new \DomainException('Partner access required.');
        if(!in_array($alias,['j',''],true))throw new \LogicException('Invalid scope alias.');$p=$alias===''?'':$alias.'.';
        $sql=$p."source='partner' AND ".$p.'partner_id=?';$args=[(int)$a['partner_id']];
        if(($a['role']??'partner_staff')!=='partner_admin'){$sql.=' AND '.$p.'partner_user_id=?';$args[]=(int)$a['id'];}
        return [$sql,$args];
    }
    public function owner(array $a):void
    {
        if(($a['kind']??'')!=='employee'||($a['role']??'')!=='owner_admin'||empty($a['active']))throw new \DomainException('Owner access required.');
        $s=$this->db->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='owner_admin'".($this->db->inTransaction()?' FOR UPDATE':''));$s->execute([$a['id']]);if(!$s->fetchColumn())throw new \DomainException('Owner access changed.');
    }
    public function accounts(array $owner):array
    {
        $this->owner($owner);
        return $this->db->query("SELECT u.id,u.partner_id,u.display_name,u.email,u.active,p.name partner_name,COALESCE(a.role_key,'partner_staff') role_key,COALESCE(a.can_create,1) can_create,COALESCE(a.can_edit,0) can_edit,COALESCE(a.can_driver,0) can_driver,COALESCE(a.can_accounting,0) can_accounting,a.last_login_at FROM delivery_partner_users u JOIN delivery_partners p ON p.id=u.partner_id LEFT JOIN delivery_partner_access a ON a.user_id=u.id WHERE LOWER(p.code)='tedlaser' OR LOWER(TRIM(p.name)) IN ('tedlaser','tedlaser and engraving') ORDER BY u.id")->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function manage(array $owner,array $b):array
    {
        $this->owner($owner);$action=(string)($b['action']??'');$id=(int)($b['id']??0);$token=null;
        if(!in_array($action,['create','permissions','activate','deactivate','reset','revoke'],true))throw new \DomainException('Unsupported account action.');
        $role=(string)($b['role']??'partner_staff');if(!in_array($role,['partner_admin','partner_staff'],true))throw new \DomainException('Choose Administrator or Staff.');
        $this->db->beginTransaction();
        try{
            $this->owner($owner);
            if($action==='create'){
                $name=trim((string)($b['name']??''));$email=strtolower(trim((string)($b['email']??'')));
                if($name===''||strlen($name)>190||strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \DomainException('Enter a valid name and email.');
                $p=$this->db->query("SELECT id FROM delivery_partners WHERE active=1 AND (LOWER(code)='tedlaser' OR LOWER(TRIM(name)) IN ('tedlaser','tedlaser and engraving')) FOR UPDATE")->fetchAll(\PDO::FETCH_COLUMN);
                if(count($p)!==1)throw new \DomainException('Exactly one active Tedlaser company must be configured.');
                $s=$this->db->prepare('SELECT id FROM delivery_partner_users WHERE email=? FOR UPDATE');$s->execute([$email]);if($s->fetchColumn())throw new \DomainException('This email already has an account. Use the existing account controls.');
                $s=$this->db->prepare('INSERT INTO delivery_partner_users(partner_id,display_name,email,password_hash,active) VALUES(?,?,?,?,1)');$s->execute([$p[0],$name,$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);$id=(int)$this->db->lastInsertId();
            }else{
                $s=$this->db->prepare("SELECT u.id FROM delivery_partner_users u JOIN delivery_partners p ON p.id=u.partner_id WHERE u.id=? AND (LOWER(p.code)='tedlaser' OR LOWER(TRIM(p.name)) IN ('tedlaser','tedlaser and engraving')) FOR UPDATE");$s->execute([$id]);if(!$s->fetchColumn())throw new \DomainException('Tedlaser account unavailable.');
            }
            if(in_array($action,['create','permissions'],true)){
                $admin=$role==='partner_admin';
                $this->db->prepare('INSERT INTO delivery_partner_access(user_id,role_key,can_create,can_edit,can_driver,can_accounting) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE role_key=VALUES(role_key),can_create=VALUES(can_create),can_edit=VALUES(can_edit),can_driver=VALUES(can_driver),can_accounting=VALUES(can_accounting),updated_at=UTC_TIMESTAMP()')->execute([$id,$role,(int)!empty($b['can_create']),(int)!empty($b['can_edit']),(int)($admin&&!empty($b['can_driver'])),(int)($admin&&!empty($b['can_accounting']))]);
            }
            if($action==='activate'||$action==='deactivate')$this->db->prepare('UPDATE delivery_partner_users SET active=? WHERE id=?')->execute([(int)($action==='activate'),$id]);
            // All management changes invalidate current sessions, including reset issuance.
            $this->db->prepare('UPDATE delivery_partner_users SET session_version=session_version+1 WHERE id=?')->execute([$id]);
            $this->db->prepare('DELETE FROM delivery_partner_reset_tokens WHERE user_id=?')->execute([$id]);
            if($action==='create'||$action==='reset'){
                $token=bin2hex(random_bytes(32));$this->db->prepare('INSERT INTO delivery_partner_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 MINUTE))')->execute([$id,hash('sha256',$token)]);
            }
            $this->db->prepare('INSERT INTO ops_security_events(event_type,employee_id,metadata_json) VALUES(?,?,?)')->execute(['delivery_partner_'.$action,$owner['id'],json_encode(['partner_user_id'=>$id,'sessions_revoked'=>true],JSON_THROW_ON_ERROR)]);
            $this->db->commit();return ['id'=>$id,'token'=>$token];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function password(string $new,string $token,?array $actor=null,string $current=''):void
    {
        if(strlen($new)<16||strlen($new)>72||trim($new)==='')throw new \DomainException('Use a password of 16–72 bytes.');
        $this->db->beginTransaction();
        try{
            if($actor){
                $fresh=$this->identity((int)$actor['id'],true);
                if((int)$fresh['session_version']!==(int)($actor['session_version']??0))throw new \DomainException('Please sign in again.');$id=$fresh['id'];
                $s=$this->db->prepare('SELECT password_hash,failed_attempts,locked_until FROM delivery_partner_users WHERE id=? FOR UPDATE');$s->execute([$id]);$credentials=$s->fetch(\PDO::FETCH_ASSOC);
                if($credentials['locked_until']&&strtotime($credentials['locked_until'].' UTC')>time())throw new \DomainException('Too many attempts. Try again in 15 minutes.');
                if(!password_verify($current,(string)$credentials['password_hash'])){
                    $attempts=$credentials['locked_until']?1:(int)$credentials['failed_attempts']+1;
                    $this->db->prepare('UPDATE delivery_partner_users SET failed_attempts=?,locked_until=CASE WHEN ?>=5 THEN DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) ELSE NULL END WHERE id=?')->execute([$attempts,$attempts,$id]);
                    $this->db->commit();throw new \DomainException('Current password is incorrect.');
                }
            }else{
                if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new \DomainException('Setup link is invalid or expired.');
                $s=$this->db->prepare('SELECT user_id FROM delivery_partner_reset_tokens WHERE token_hash=? AND expires_at>UTC_TIMESTAMP()');$s->execute([hash('sha256',$token)]);$id=(int)$s->fetchColumn();if(!$id)throw new \DomainException('Setup link is invalid or expired.');
                $this->identity($id,true);
                $s=$this->db->prepare('SELECT user_id FROM delivery_partner_reset_tokens WHERE user_id=? AND token_hash=? AND expires_at>UTC_TIMESTAMP() FOR UPDATE');$s->execute([$id,hash('sha256',$token)]);if(!$s->fetchColumn())throw new \DomainException('Setup link is invalid or expired.');
            }
            $this->db->prepare('UPDATE delivery_partner_users SET password_hash=?,session_version=session_version+1,failed_attempts=0,locked_until=NULL WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$id]);
            $this->db->prepare('DELETE FROM delivery_partner_reset_tokens WHERE user_id=?')->execute([$id]);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
