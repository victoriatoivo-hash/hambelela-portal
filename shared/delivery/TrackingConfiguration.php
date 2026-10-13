<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/TrackingPolicy.php';

final class TrackingConfiguration
{
    private string $directory;
    public function __construct(?string $directory=null){$this->directory=$directory??'/home/hambele1/.hambelela-delivery-tracking';}
    public function read():array
    {
        $path=$this->directory.'/config.json';if(!is_file($path))return ['enabled'=>false,'browser_key'=>'','server_key'=>''];
        $data=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new \RuntimeException('Tracking configuration is unavailable.');return $data+['enabled'=>false,'browser_key'=>'','server_key'=>''];
    }
    public function save(array $actor,array $input):array
    {
        TrackingPolicy::owner($actor);$data=$this->read();
        foreach(['browser_key','server_key'] as $key){$value=trim((string)($input[$key]??''));if($value==='')continue;if(!preg_match('/^AIza[A-Za-z0-9_-]{35}$/D',$value))throw new \DomainException('Enter a valid Google API key.');$data[$key]=$value;}
        $data['enabled']=!empty($input['enabled']);if($data['enabled']&&($data['browser_key']===''||$data['server_key']===''))throw new \DomainException('Configure both restricted Google keys before enabling tracking.');
        if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true))throw new \RuntimeException('Private configuration directory unavailable.');
        $real=realpath($this->directory);$root=realpath((string)($_SERVER['DOCUMENT_ROOT']??''));
        if($real===false||is_link($this->directory)||($root&&($real===$root||strpos($real,$root.DIRECTORY_SEPARATOR)===0)))throw new \RuntimeException('Tracking keys must remain outside the public web directory.');
        $temporary=tempnam($this->directory,'keys-');if($temporary===false)throw new \RuntimeException('Private configuration unavailable.');
        try{chmod($temporary,0600);if(file_put_contents($temporary,json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($temporary,$this->directory.'/config.json'))throw new \RuntimeException('Unable to save tracking configuration.');}
        finally{if(is_file($temporary))unlink($temporary);}
        return $this->publicStatus();
    }
    public function publicStatus():array{$d=$this->read();return ['enabled'=>(bool)$d['enabled'],'browser_configured'=>$d['browser_key']!=='','server_configured'=>$d['server_key']!==''];}
}
