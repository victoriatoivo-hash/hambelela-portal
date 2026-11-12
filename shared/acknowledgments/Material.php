<?php
declare(strict_types=1);
namespace Hambelela\Acknowledgments;

final class Material {
    public static function readImage(string $encoded):array {
        if(strlen($encoded)>2796204) throw new \DomainException('Choose a JPG or PNG under 2 MB.');
        $bytes=base64_decode($encoded,true);
        if(!$bytes || strlen($bytes)>2097152) throw new \DomainException('Invalid image or image too large.');
        $info=@getimagesizefromstring($bytes);
        if(!$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true) || $info[0]*$info[1]>12000000)
            throw new \DomainException('Choose a JPG or PNG up to 12 megapixels.');
        if(!function_exists('imagecreatefromstring'))throw new \DomainException('Image processing is unavailable. The instruction was not sent.');
        $image=@imagecreatefromstring($bytes);
        if(!$image)throw new \DomainException('The image could not be opened.');
        // Decode and re-encode: no original filename, EXIF, appended payload or public file URL.
        ob_start();
        try {
            if($info[2]===IMAGETYPE_PNG){imagesavealpha($image,true);$ok=imagepng($image,null,6);$type='image/png';}
            else {$ok=imagejpeg($image,null,88);$type='image/jpeg';}
            $clean=ob_get_contents();
        }finally{ob_end_clean();imagedestroy($image);}
        if(!$ok||!$clean||strlen($clean)>8388608)throw new \DomainException('Please resize the image before attaching it.');
        return [$type,$clean];
    }
    // Called inside the instruction send/edit transaction. Revisions retain their own attachment.
    public static function save(\PDO $db,int $id,array $body,int $source=0):void {
        if($source && $source!==$id){
            $s=$db->prepare('INSERT INTO portal_ack_material(instruction_id,question,image_type,image_data) SELECT ?,question,image_type,image_data FROM portal_ack_material WHERE instruction_id=?');
            $s->execute([$id,$source]);
        }
        if(!array_key_exists('question',$body)&&empty($body['image'])&&empty($body['remove_image']))return;
        $s=$db->prepare("INSERT INTO portal_ack_material(instruction_id,question) VALUES(?,'') ON DUPLICATE KEY UPDATE instruction_id=VALUES(instruction_id)");$s->execute([$id]);
        if(array_key_exists('question',$body)){
            $q=trim((string)$body['question']);if(strlen($q)>1200)throw new \DomainException('Keep the question under 1,200 bytes.');
            $db->prepare('UPDATE portal_ack_material SET question=? WHERE instruction_id=?')->execute([$q,$id]);
        }
        if(!empty($body['image'])){
            [$type,$bytes]=self::readImage((string)$body['image']);
            $db->prepare('UPDATE portal_ack_material SET image_type=?,image_data=? WHERE instruction_id=?')->execute([$type,$bytes,$id]);
        }elseif(!empty($body['remove_image']))$db->prepare('UPDATE portal_ack_material SET image_type=NULL,image_data=NULL WHERE instruction_id=?')->execute([$id]);
    }
    public static function meta(\PDO $db,int $id):array{
        $s=$db->prepare('SELECT question,image_type FROM portal_ack_material WHERE instruction_id=?');$s->execute([$id]);
        return $s->fetch(\PDO::FETCH_ASSOC)?:['question'=>'','image_type'=>null];
    }
}
