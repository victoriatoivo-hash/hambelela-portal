<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

use PDO;
use DomainException;

/** Mobile is an identifier, never proof of identity. No self-registration. */
final class DriverAuth
{
    private const ERROR = 'Unable to sign in. Check your details or try again later.';
    private PDO $db;
    private \Closure $employeeIsActive;
    public function __construct(PDO $db, \Closure $employeeIsActive) {$this->db=$db;$this->employeeIsActive=$employeeIsActive;}

    public static function mobile(string $input): string
    {
        $value=preg_replace('/[\s()-]/','',trim($input));
        if (strncmp($value,'00',2)===0) $value='+'.substr($value,2);
        if (preg_match('/^0[0-9]{9}$/D',$value)) $value='+264'.substr($value,1);
        if (!preg_match('/^\+[1-9][0-9]{7,14}$/D',$value)) throw new DomainException(self::ERROR);
        return $value;
    }

    public function authenticate(string $mobile,string $secret,string $ip): array
    {
        $mobile=self::mobile($mobile);
        if(strlen($secret)>128 || strlen($ip)>80) throw new DomainException(self::ERROR);
        $now=time();
        $buckets=[hash('sha256','mobile:'.$mobile),hash('sha256','ip:'.$ip)];
        sort($buckets); // Stable lock order for concurrent requests.
        $this->db->beginTransaction();
        try {
            foreach($buckets as $bucket) {
                $this->db->prepare('INSERT IGNORE INTO delivery_login_limits(bucket,window_start) VALUES(?,?)')->execute([$bucket,$now]);
                $s=$this->db->prepare('SELECT * FROM delivery_login_limits WHERE bucket=? FOR UPDATE');$s->execute([$bucket]);$limit=$s->fetch(PDO::FETCH_ASSOC);
                if($now-(int)$limit['window_start']>=900) {
                    $this->db->prepare('UPDATE delivery_login_limits SET failures=0,window_start=? WHERE bucket=?')->execute([$now,$bucket]);
                } elseif((int)$limit['failures']>=5) {
                    $this->db->commit();throw new DomainException(self::ERROR);
                }
            }
            $s=$this->db->prepare('SELECT c.*,p.active profile_active FROM delivery_driver_credentials c JOIN delivery_driver_profiles p ON p.employee_id=c.employee_id WHERE c.mobile=? FOR UPDATE');
            $s->execute([$mobile]);$row=$s->fetch(PDO::FETCH_ASSOC);
            // A real password hash is checked for unknown identities too.
            $valid=password_verify($secret,$row['secret_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            if(!$row || !$valid || !(int)$row['active'] || !(int)$row['profile_active'] || !($this->employeeIsActive)((int)$row['employee_id'])) {
                foreach($buckets as $bucket) $this->db->prepare('UPDATE delivery_login_limits SET failures=failures+1 WHERE bucket=?')->execute([$bucket]);
                $this->db->commit();throw new DomainException(self::ERROR);
            }
            // Do not reset the IP bucket on success: attackers must not clear it with their own account.
            $this->db->prepare('UPDATE delivery_login_limits SET failures=0,window_start=? WHERE bucket=?')->execute([$now,hash('sha256','mobile:'.$mobile)]);
            $this->db->commit();
            return ['id'=>(int)$row['employee_id'],'version'=>(int)$row['session_version'],'issued'=>$now,'seen'=>$now];
        } catch(\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function actor(array &$session): array
    {
        $now=time();
        if(empty($session['id']) || $now-(int)($session['issued']??0)>=28800 || $now-(int)($session['seen']??0)>=1800) throw new DomainException('Please sign in again.');
        $s=$this->db->prepare('SELECT c.session_version FROM delivery_driver_credentials c JOIN delivery_driver_profiles p ON p.employee_id=c.employee_id WHERE c.employee_id=? AND c.active=1 AND p.active=1');
        $s->execute([(int)$session['id']]);$version=$s->fetchColumn();
        if($version===false || (int)$version!==(int)($session['version']??0) || !($this->employeeIsActive)((int)$session['id'])) throw new DomainException('Please sign in again.');
        $session['seen']=$now;
        return ['kind'=>'employee','id'=>(int)$session['id'],'role'=>'delivery_driver','active'=>true,'driver_verified'=>true];
    }
}
