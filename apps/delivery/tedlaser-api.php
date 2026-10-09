<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/FrontSession.php';require_once BASE_PATH.'/shared/delivery/PartnerService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
 if(!DELIVERY_ENABLED)throw new DomainException('Delivery is not enabled.');
 $owner=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));if($owner['role']!=='owner_admin')throw new DomainException('Owner access required.');
 $_SESSION['delivery_front_csrf']??=bin2hex(random_bytes(32));
 if($_SERVER['REQUEST_METHOD']==='POST'){
  \Hambelela\Delivery\FrontSession::csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));$body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($body)||($body['action']??'')!=='arrange')throw new DomainException('Unsupported action.');
  $result=(new \Hambelela\Delivery\PartnerService(db()))->arrangeForOwner($owner,(int)($body['partner_contact']??0),(string)($body['uuid']??''),$body);\Hambelela\Delivery\NotificationOutbox::attempt(db());echo json_encode(['result'=>$result],JSON_THROW_ON_ERROR);exit;
 }
 if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
 $scope="SELECT id FROM delivery_partners WHERE LOWER(TRIM(name)) IN ('tedlaser','tedlaser and engraving') OR LOWER(code)='tedlaser'";
 $jobs=db()->query("SELECT j.* FROM delivery_jobs j WHERE j.source='partner' AND j.partner_id IN ($scope) ORDER BY j.id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
 $contacts=db()->query("SELECT u.id,u.display_name,p.name FROM delivery_partner_users u JOIN delivery_partners p ON p.id=u.partner_id WHERE u.active=1 AND p.active=1 AND p.id IN ($scope) ORDER BY u.display_name")->fetchAll(PDO::FETCH_ASSOC);
 $s=db()->query("SELECT COALESCE(SUM(CASE WHEN account='fee_earned' THEN amount_cents ELSE 0 END),0) earned,COALESCE(SUM(CASE WHEN account='fee_received' THEN amount_cents ELSE 0 END),0) received,COALESCE(SUM(CASE WHEN account='partner_cod_held' THEN amount_cents WHEN account='partner_cod_remitted' THEN -amount_cents ELSE 0 END),0) cod_held FROM delivery_ledger WHERE partner_id IN ($scope)");
 echo json_encode(['partner_name'=>'Tedlaser','jobs'=>$jobs,'contacts'=>$contacts,'balances'=>$s->fetch(PDO::FETCH_ASSOC),'zones'=>db()->query('SELECT id,area,fee_cents FROM delivery_zones WHERE active=1 ORDER BY area')->fetchAll(PDO::FETCH_ASSOC),'csrf'=>$_SESSION['delivery_front_csrf']],JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(403);echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){error_log('Tedlaser Owner view: '.get_class($e));http_response_code(503);echo '{"error":"Tedlaser data is temporarily unavailable."}';}
