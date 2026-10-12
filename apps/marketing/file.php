<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';require_once BASE_PATH.'/shared/marketing.php';marketing_require_access();
try{$file=marketing_media_get((int)($_GET['id']??0));}catch(Throwable $e){http_response_code(404);exit('File not found.');}
$path=BASE_PATH.'/uploads/marketing/'.basename((string)$file['stored_name']);
if(!is_file($path)){http_response_code(404);exit('File not found.');}
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path)?:'application/octet-stream';
if($file['mime_type']==='audio/mp4'&&$mime==='video/mp4')$mime='audio/mp4';
$safe=['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm','audio/webm','audio/mpeg','audio/mp4','audio/x-m4a','audio/wav','audio/x-wav','audio/vnd.wave'];
$inline=($_GET['inline']??'')==='1'&&in_array($mime,$safe,true);
header('Content-Type: '.($inline?$mime:'application/octet-stream'));header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header("Content-Security-Policy: default-src 'none'; sandbox");header('Content-Disposition: '.($inline?'inline':'attachment')."; filename*=UTF-8''".rawurlencode((string)$file['file_name']));
$size=(int)filesize($path);$start=0;$end=$size-1;header('Accept-Ranges: bytes');
if(isset($_SERVER['HTTP_RANGE'])){
 $range=(string)$_SERVER['HTTP_RANGE'];
 if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m)||($m[1]===''&&$m[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
 if($m[1]===''){$start=max(0,$size-(int)$m[2]);}else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}
 if($start>$end||$start>=$size){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
 http_response_code(206);header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: '.($end-$start+1));session_write_close();
if($_SERVER['REQUEST_METHOD']==='HEAD')exit;
$stream=fopen($path,'rb');fseek($stream,$start);$remaining=$end-$start+1;
while($remaining>0&&!feof($stream)&&!connection_aborted()){$data=fread($stream,min(65536,$remaining));if($data===false||$data==='')break;echo $data;$remaining-=strlen($data);}fclose($stream);
