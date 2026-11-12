<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/config.php';require_once BASE_PATH.'/shared/auth.php';require_once BASE_PATH.'/shared/acknowledgments/integration.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; sandbox");
try {
    if(!portal_validate_authenticated_session())throw new DomainException();
    $actor=(int)(current_user()['id']??0);$service=new \Hambelela\Acknowledgments\Service(db());
    $d=$service->detail($actor,(int)($_GET['id']??0));
    if(!$d['image_type']||($d['withdrawn']&&$service->actor($actor)['role_key']!=='owner_admin'))throw new DomainException();
    $s=db()->prepare('SELECT image_type,image_data FROM portal_ack_material WHERE instruction_id=?');$s->execute([$d['instruction_id']]);$r=$s->fetch(PDO::FETCH_ASSOC);
    if(!$r||!in_array($r['image_type'],['image/jpeg','image/png'],true))throw new DomainException();
    header('Content-Type: '.$r['image_type']);echo $r['image_data'];
}catch(Throwable $e){http_response_code(404);}
