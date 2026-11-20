<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/ProofContext.php';
require_once __DIR__.'/ProofStorage.php';

final class ProofSubmission
{
    private array $fields;private array $files;private array $policy;private int $version;
    public function __construct(array $fields,array $files,int $version,?array $policy=null)
    {
        $this->policy=$policy??ProofPolicy::configured();ProofPolicy::validate($this->policy,$fields,$files);
        $this->fields=['recipient_name'=>trim($fields['recipient_name']),'recipient_type'=>$fields['recipient_type'],
            'relationship'=>$fields['recipient_type']==='representative'?trim($fields['relationship']):'','acknowledged'=>true];
        $this->files=$files;$this->version=$version;
    }
    public function fingerprint():array
    {
        $hashes=[];foreach(['photo','signature'] as $kind)if(isset($this->files[$kind]))$hashes[$kind]=$this->files[$kind]['sha256'];
        return [$this->fields,$hashes,$this->version,$this->policy];
    }
    public function record(\PDO $db,array $job,array $actor):array
    {
        ProofContext::assertLocked($db,$job,$actor,$this->version);
        $store=ProofStorage::configured();
        foreach($this->files as $file){$path=$store->path($file['id']);if(!is_file($path)||!hash_equals($file['stored_sha256'],hash_file('sha256',$path)))throw new \DomainException('Uploaded evidence is unavailable. Retry without completing the delivery.');}
        $id=bin2hex(random_bytes(16));$at=new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek'));
        return ['receipt'=>$id,'delivery_id'=>(int)$job['id'],'order_id'=>$job['order_id']?(int)$job['order_id']:null,
            'driver_id'=>(int)$actor['id'],'recorded_at'=>$at->format(DATE_ATOM),'timezone'=>'Africa/Windhoek',
            'recipient'=>$this->fields,'evidence'=>array_values($this->files),'policy'=>$this->policy];
    }
}
