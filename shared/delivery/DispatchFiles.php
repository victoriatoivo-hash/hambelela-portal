<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/ProofStorage.php';

final class DispatchFiles
{
    public const MAX_BYTES=2000000; // Existing portal upload ceiling; UI and API agree.
    public static function root():string {
        $root=ProofStorage::directory();new ProofStorage($root);return (string)realpath($root);
    }
    public static function policy():array {
        $p=self::root().'/dispatch-policy.json';return is_file($p)?json_decode((string)file_get_contents($p),true,16,JSON_THROW_ON_ERROR):[];
    }
    public static function auditKey():string {
        $key=@file_get_contents(self::root().'/dispatch-audit.key');if(!is_string($key)||strlen(trim($key))<64)throw new \DomainException('Secure messaging has not been configured. Contact the Owner.');return trim($key);
    }
    public static function phone():?string {
        $p=self::policy();$n=$p['dispatchPhone']??null;return ($p['phoneVerified']??false)===true&&is_string($n)&&preg_match('/^\+[1-9][0-9]{7,14}$/D',$n)?$n:null;
    }
    public static function sourceHash(array $f,bool $test=false):string {
        $p=$f['tmp_name']??'';if(($test&&PHP_SAPI!=='cli')||(!$test&&!is_uploaded_file($p))||($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_file($p)||filesize($p)>self::MAX_BYTES||filesize($p)<16)throw new \DomainException('Attachment upload failed. Use a JPEG, PNG, WebP or PDF up to 2 MB.');return hash_file('sha256',$p);
    }
    /** Process arguments are fixed/array-escaped; paths only come from trusted host policy. */
    private static function check(string $binary,array $args):void {
        if(!is_file($binary)||!is_executable($binary))throw new \DomainException('Attachment validation is unavailable.');
        $pipes=[];$proc=proc_open(array_merge([$binary],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($proc))throw new \DomainException('Attachment validation unavailable.');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$start=microtime(true);$status=null;
        do {stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);$status=proc_get_status($proc);if(!$status['running'])break;if(microtime(true)-$start>5){proc_terminate($proc);break;}usleep(20000);}while(true);
        fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($proc);$exit=$status&&!$status['running']?$status['exitcode']:$closed;if($exit!==0)throw new \DomainException('This attachment could not be validated. Use another file.');
    }
    public static function prepare(array $upload,bool $test=false):array {
        $sourceHash=self::sourceHash($upload,$test);$path=$upload['tmp_name'];$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);$policy=self::policy();
        if(!empty($policy['virusScanner']))self::check($policy['virusScanner'],['--no-summary',$path]);
        if(in_array($mime,['image/jpeg','image/png','image/webp'],true)){$f=(new ProofStorage(self::root()))->prepare($upload,'photo',$test);$mime='image/png';$name='Image.png';$extension='.png';}
        elseif($mime==='application/pdf'){
            $data=file_get_contents($path);$decoded=preg_replace_callback('/#([0-9a-fA-F]{2})/',static fn($m)=>chr(hexdec($m[1])),$data);
            if(strpos($data,'%PDF-')!==0||!preg_match('/%%EOF\s*$/',$data)||preg_match('~/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA|RichMedia|XFA)\b~i',$decoded))throw new \DomainException('Use a standard PDF without scripts, embedded files or automatic actions.');
            if(empty($policy['pdfInfoBinary']))throw new \DomainException('PDF checking is not configured. Please use a photo for now.');self::check($policy['pdfInfoBinary'],[$path]);
            $f=['id'=>bin2hex(random_bytes(24)),'sha256'=>$sourceHash];$target=self::root().'/'.$f['id'].'.pdf';if(!copy($path,$target))throw new \DomainException('Attachment could not be saved.');@chmod($target,0600);$f['stored_sha256']=hash_file('sha256',$target);$name='Document.pdf';$extension='.pdf';
        }else throw new \DomainException('Only JPEG, PNG, WebP images and PDF documents are accepted.');
        $target=self::root().'/'.$f['id'].$extension;$days=$policy['attachmentRetentionDays']??null;
        return ['id'=>$f['id'],'mime'=>$mime,'name'=>$name,'bytes'=>filesize($target),'sha256'=>$sourceHash,'stored_sha256'=>$f['stored_sha256'],'extension'=>$extension,'expires_at'=>is_int($days)&&$days>0?gmdate('Y-m-d H:i:s',time()+$days*86400):null,'scan'=>empty($policy['virusScanner'])?'not_configured':'passed'];
    }
    public static function path(array $f):string {
        if(!preg_match('/^[a-f0-9]{48}$/D',$f['id']??'')||!in_array($f['extension']??'',['.png','.pdf'],true))throw new \DomainException('Attachment unavailable.');
        $path=self::root().'/'.$f['id'].$f['extension'];if(($f['expires_at']&&$f['expires_at']<gmdate('Y-m-d H:i:s'))||!is_file($path)||!hash_equals($f['stored_sha256'],hash_file('sha256',$path)))throw new \DomainException('Attachment is unavailable or has expired.');return $path;
    }
    public static function discard(array $f):void {if(preg_match('/^[a-f0-9]{48}$/D',$f['id']??'')&&in_array($f['extension']??'',['.png','.pdf'],true)){$p=self::root().'/'.$f['id'].$f['extension'];if(is_file($p))unlink($p);}}
}
