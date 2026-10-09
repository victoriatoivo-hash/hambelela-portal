<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/PartnerAccess.php';
/** Separate restricted partner identity; never shares a staff or Driver session. */
final class PartnerAuth
{
 private \PDO $db;
 private const ERROR='Unable to sign in. Check your details or try again later.';
 public function __construct(\PDO $db){$this->db=$db;(new PartnerAccess($db))->install();}
 public function authenticate(string $email,string $secret,string $ip):array
 {
  $email=strtolower(trim($email));if(strlen($email)>190||strlen($secret)>128||strlen($ip)>80)throw new \DomainException(self::ERROR);
  $now=time();$buckets=[hash('sha256','partner-email:'.$email),hash('sha256','partner-ip:'.$ip)];sort($buckets);$this->db->beginTransaction();
  try{
   foreach($buckets as $key){$this->db->prepare('INSERT IGNORE INTO delivery_login_limits(bucket,window_start) VALUES(?,?)')->execute([$key,$now]);$s=$this->db->prepare('SELECT * FROM delivery_login_limits WHERE bucket=? FOR UPDATE');$s->execute([$key]);$r=$s->fetch(\PDO::FETCH_ASSOC);if($now-(int)$r['window_start']>=900)$this->db->prepare('UPDATE delivery_login_limits SET failures=0,window_start=? WHERE bucket=?')->execute([$now,$key]);elseif((int)$r['failures']>=5){$this->db->commit();throw new \DomainException(self::ERROR);}}
   $s=$this->db->prepare('SELECT u.*,p.active partner_active FROM delivery_partner_users u JOIN delivery_partners p ON p.id=u.partner_id WHERE u.email=? FOR UPDATE');$s->execute([$email]);$r=$s->fetch(\PDO::FETCH_ASSOC);
   $valid=password_verify($secret,$r['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
   if(!$r||!$valid||!(int)$r['active']||!(int)$r['partner_active']){foreach($buckets as $key)$this->db->prepare('UPDATE delivery_login_limits SET failures=failures+1 WHERE bucket=?')->execute([$key]);$this->db->commit();throw new \DomainException(self::ERROR);}
   $this->db->prepare('UPDATE delivery_login_limits SET failures=0,window_start=? WHERE bucket=?')->execute([$now,hash('sha256','partner-email:'.$email)]);$this->db->prepare('INSERT INTO delivery_partner_access(user_id,last_login_at) VALUES(?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_login_at=UTC_TIMESTAMP()')->execute([$r['id']]);$this->db->commit();return ['id'=>(int)$r['id'],'partner_id'=>(int)$r['partner_id'],'version'=>(int)$r['session_version'],'issued'=>$now,'seen'=>$now];
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function actor(array &$session):array
 {
  if(empty($session['id'])||time()-(int)($session['issued']??0)>=28800||time()-(int)($session['seen']??0)>=1800)throw new \DomainException('Please sign in.');
  $s=$this->db->prepare('SELECT u.session_version,u.partner_id FROM delivery_partner_users u JOIN delivery_partners p ON p.id=u.partner_id WHERE u.id=? AND u.active=1 AND p.active=1');$s->execute([$session['id']]);$r=$s->fetch(\PDO::FETCH_ASSOC);
  if(!$r||(int)$r['session_version']!==(int)($session['version']??0)||(int)$r['partner_id']!==(int)($session['partner_id']??0))throw new \DomainException('Please sign in.');$session['seen']=time();return (new PartnerAccess($this->db))->identity((int)$session['id']);
 }
 public static function open():void
 {
  if(empty($_SERVER['HTTPS'])||strtolower((string)$_SERVER['HTTPS'])==='off')throw new \DomainException('Secure connection required.');if(session_status()===PHP_SESSION_ACTIVE)throw new \LogicException('Partner session must be isolated.');
  ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.gc_maxlifetime','28800');session_name('__Host-hambelela_delivery_partner');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();$_SESSION['csrf']??=bin2hex(random_bytes(32));
 }
 public static function csrf(string $token):void{if(empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$token))throw new \DomainException('Session verification failed.');}
}
