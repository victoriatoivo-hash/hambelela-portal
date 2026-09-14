<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_once BASE_PATH.'/shared/database.php';
require_once BASE_PATH.'/shared/cost-workbook.php';
require_once BASE_PATH.'/shared/cost-workbook-size-conversions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function cw_size_json(array $payload,int $status=200): void {http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES);exit;}
function cw_size_body(): array {$raw=file_get_contents('php://input');$body=json_decode((string)$raw,true);return is_array($body)?$body:$_POST;}

try {
    require_role('owner_admin');
    cw_require_admin();
    $pdo=db();cw_install_schema($pdo);
    if($_SERVER['REQUEST_METHOD']==='GET')cw_size_json(['ok'=>true,'conversions'=>cw_size_conversions($pdo)]);
    cw_require_csrf();
    $body=cw_size_body();$action=(string)($body['action']??'');
    if(!in_array($action,['create','update'],true))throw new DomainException('Select a valid conversion action.');
    $record=cw_validate_size_conversion($body);$id=(int)($body['id']??0);$before=null;
    $pdo->beginTransaction();
    if($action==='update'){
        if($id<1)throw new DomainException('Select a valid size conversion.');
        $find=$pdo->prepare('SELECT * FROM cw_size_conversions WHERE id=? AND active=1 FOR UPDATE');$find->execute([$id]);$before=$find->fetch(PDO::FETCH_ASSOC);
        if(!$before)throw new DomainException('This size conversion is no longer available.');
    }
    $duplicate=$pdo->prepare('SELECT id FROM cw_size_conversions WHERE measurement_type=? AND base_value=? AND active=1 AND id<>? LIMIT 1');
    $duplicate->execute([$record['measurement_type'],number_format((float)$record['base_value'],6,'.',''),$id]);
    if($duplicate->fetchColumn())throw new DomainException('This size conversion already exists.');
    $user=cw_user();
    if($action==='create'){
        $save=$pdo->prepare('INSERT INTO cw_size_conversions(label,measurement_type,quantity,unit,base_value,base_unit,created_by,created_by_name,updated_by,updated_by_name) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $save->execute([$record['label'],$record['measurement_type'],$record['quantity'],$record['unit'],$record['base_value'],$record['base_unit'],$user['id'],$user['name'],$user['id'],$user['name']]);$id=(int)$pdo->lastInsertId();
    }else{
        $save=$pdo->prepare('UPDATE cw_size_conversions SET label=?,measurement_type=?,quantity=?,unit=?,base_value=?,base_unit=?,updated_by=?,updated_by_name=? WHERE id=? AND active=1');
        $save->execute([$record['label'],$record['measurement_type'],$record['quantity'],$record['unit'],$record['base_value'],$record['base_unit'],$user['id'],$user['name'],$id]);
    }
    $find=$pdo->prepare('SELECT id,label,measurement_type,quantity,unit,base_value,base_unit,active FROM cw_size_conversions WHERE id=?');$find->execute([$id]);$saved=cw_size_conversion_row((array)$find->fetch(PDO::FETCH_ASSOC));
    $audit=$pdo->prepare('INSERT INTO cw_size_conversion_audit(conversion_id,action_key,before_json,after_json,actor_id,actor_name) VALUES(?,?,?,?,?,?)');
    $audit->execute([$id,$action==='create'?'created':'updated',$before?json_encode($before,JSON_UNESCAPED_SLASHES):null,json_encode($saved,JSON_UNESCAPED_SLASHES),$user['id'],$user['name']]);
    $pdo->commit();cw_size_json(['ok'=>true,'message'=>$action==='create'?'Size conversion added.':'Size conversion updated.','conversion'=>$saved,'conversions'=>cw_size_conversions($pdo)]);
} catch(DomainException $error) {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();cw_size_json(['ok'=>false,'error'=>$error->getMessage()],422);
} catch(Throwable $error) {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('Size conversion API failed: '.get_class($error));cw_size_json(['ok'=>false,'error'=>'The size conversion could not be saved. Please try again.'],500);
}
