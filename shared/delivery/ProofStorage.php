<?php
declare(strict_types=1);
namespace Hambelela\Delivery;

/** Private, re-encoded evidence. Only committed delivery events authorize retrieval. */
final class ProofStorage
{
    public const MAX_BYTES=2000000;
    private string $root;
    public function __construct(string $root)
    {
        $real=realpath($root);$web=realpath(defined('BASE_PATH')?BASE_PATH:dirname(__DIR__,2));
        if(!$real||!is_dir($real)||!is_writable($real)||!$web)throw new \DomainException('Private evidence storage is not configured. Contact dispatch.');
        $norm=static fn($v)=>strtolower(str_replace('\\','/',rtrim($v,'/\\'))).'/';
        if(str_starts_with($norm($real),$norm($web)))throw new \LogicException('Evidence storage must be outside the web root.');
        $this->root=$real;
    }
    public static function configured():self {return new self((string)getenv('HO_DRIVER_PROOF_DIR'));}
    public function prepare(array $upload,string $kind,bool $testFile=false):array
    {
        if(!in_array($kind,['photo','signature'],true))throw new \DomainException('Unknown evidence type.');
        if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new \DomainException('Evidence upload failed. Please retry; delivery has not been completed.');
        $path=$upload['tmp_name']??'';
        if(!$testFile&&!is_uploaded_file($path))throw new \DomainException('Valid evidence upload required.');
        if($testFile&&PHP_SAPI!=='cli')throw new \LogicException('Test files are CLI-only.');
        if(!is_file($path)||filesize($path)<32||filesize($path)>self::MAX_BYTES)throw new \DomainException('Use an image smaller than 2 MB.');
        if(!extension_loaded('gd')||!extension_loaded('fileinfo'))throw new \DomainException('Secure image validation is unavailable. Contact dispatch.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)||($kind==='signature'&&$mime!=='image/png'))throw new \DomainException('Use a JPEG, PNG or WebP photo, or a PNG recipient signature.');
        $size=@getimagesize($path);
        if(!$size||$size[0]<20||$size[1]<20||$size[0]>6000||$size[1]>6000||$size[0]*$size[1]>12000000)throw new \DomainException('Image dimensions are invalid or too large.');
        $image=@imagecreatefromstring((string)file_get_contents($path));
        if(!$image)throw new \DomainException('The image is damaged. Take another photo or signature.');
        try{
            if($kind==='signature'){
                if($size[0]>1600||$size[1]>800)throw new \DomainException('Signature canvas is too large.');
                $n=0;$minX=$size[0];$maxX=0;$minY=$size[1];$maxY=0;
                for($y=0;$y<$size[1];$y++)for($x=0;$x<$size[0];$x++){
                    $c=imagecolorsforindex($image,imagecolorat($image,$x,$y));
                    if($c['alpha']<80&&($c['red']+$c['green']+$c['blue'])<540){$n++;$minX=min($x,$minX);$maxX=max($x,$maxX);$minY=min($y,$minY);$maxY=max($y,$maxY);}
                }
                if($n<40||$maxX-$minX<20||$maxY-$minY<8)throw new \DomainException('A blank or incomplete signature cannot be accepted. Ask the recipient to sign.');
            }
            $key=bin2hex(random_bytes(24));$target=$this->path($key);
            // Decode into pixels, then encode afresh: no original EXIF/location/filename retained.
            $flat=imagecreatetruecolor($size[0],$size[1]);imagefill($flat,0,0,imagecolorallocate($flat,255,255,255));imagecopy($flat,$image,0,0,0,0,$size[0],$size[1]);
            $ok=imagepng($flat,$target,6);imagedestroy($flat);
            if(!$ok){if(is_file($target))unlink($target);throw new \DomainException('Evidence could not be stored. Please retry.');}
            @chmod($target,0600);
            return ['id'=>$key,'kind'=>$kind,'mime'=>'image/png','sha256'=>hash_file('sha256',$path),'stored_sha256'=>hash_file('sha256',$target)];
        }finally{imagedestroy($image);}
    }
    public function path(string $key):string
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$key))throw new \DomainException('Evidence unavailable.');
        return $this->root.DIRECTORY_SEPARATOR.$key.'.png';
    }
    public function discard(array $evidence):void {foreach($evidence as $file){$path=$this->path($file['id']);if(is_file($path))unlink($path);}}
}
