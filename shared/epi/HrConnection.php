<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use Throwable;

/** Dedicated read connection; never loads HR application bootstrap or runs its migrations. */
final class HrConnection
{
    private static $attempted=false;
    private static $connection=null;
    public static function connect(): ?PDO {
        if(function_exists('ops_hr_db'))return \ops_hr_db();
        if(self::$attempted)return self::$connection;
        self::$attempted=true;
        if(!defined('BASE_PATH'))return null;
        try {
            $local=[];$file=BASE_PATH.'/config.local.php';
            if(is_file($file)){$value=require $file;if(is_array($value))$local=$value;}
            $path=getenv('HAMBELELA_HR_LIVE_CONFIG')?:($local['hr_live_config_path']??dirname(BASE_PATH).'/hr.hambelelaorganic.com/config.php');
            $live=[];
            if(is_file($path)) {
                $source=file_get_contents($path);
                foreach(['HOST','NAME','USER','PASS'] as $key) {
                    // Read literal credentials only, never execute a second application's config.
                    if(preg_match('/define\s*\(\s*[\'\"]DB_'.$key.'[\'\"]\s*,\s*([\'\"])(.*?)\1\s*\)/s',$source,$m))$live[$key]=$m[2];
                }
            }
            $settings=[];
            foreach(['HOST','NAME','USER','PASS'] as $key)$settings[$key]=(string)(getenv('HAMBELELA_HR_DB_'.$key)?:($local['hr_db_'.strtolower($key)]??($live[$key]??'')));
            if($settings['HOST']===''||$settings['NAME']===''||$settings['USER']==='')return null;
            self::$connection=new PDO('mysql:host='.$settings['HOST'].';dbname='.$settings['NAME'].';charset=utf8mb4',$settings['USER'],$settings['PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
            return self::$connection;
        }catch(Throwable $ignored){return null;}
    }
}
