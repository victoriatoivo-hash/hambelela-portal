<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/DriverAuth.php';

/** Dedicated mobile session; never sets the portal's employee session user. */
final class DriverSession
{
    public static function open(bool $enabled):void
    {
        if(!$enabled)throw new \DomainException('Delivery is not enabled.');
        // Trust server TLS only, not a client-supplied forwarded header.
        if(empty($_SERVER['HTTPS'])||strtolower((string)$_SERVER['HTTPS'])==='off')throw new \DomainException('Secure connection required.');
        if(session_status()===PHP_SESSION_ACTIVE)throw new \LogicException('Driver session must be opened before any portal session.');
        ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
        ini_set('session.gc_maxlifetime','28800');
        session_name('__Host-hambelela_delivery_driver');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
        session_start();$_SESSION['csrf']??=bin2hex(random_bytes(32));
    }
    public static function auth(\PDO $db):DriverAuth
    {
        return new DriverAuth($db,static function(int $id)use($db):bool{
            $s=$db->prepare("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key='delivery_driver'");
            $s->execute([$id]);return (bool)$s->fetchColumn();
        });
    }
    public static function csrf(string $token):void
    {
        if(!isset($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$token))throw new \DomainException('Session verification failed.');
    }
    public static function login(DriverAuth $auth,string $mobile,string $secret):void
    {
        $identity=$auth->authenticate($mobile,$secret,(string)($_SERVER['REMOTE_ADDR']??''));
        session_regenerate_id(true);$_SESSION=['driver'=>$identity,'csrf'=>bin2hex(random_bytes(32))];
    }
    public static function actor(DriverAuth $auth):array
    {
        if(empty($_SESSION['driver'])||!is_array($_SESSION['driver']))throw new \DomainException('Please sign in.');
        return $auth->actor($_SESSION['driver']);
    }
    public static function logout():void
    {
        $_SESSION=[];
        setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
        session_destroy();
    }
}
