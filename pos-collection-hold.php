<?php
declare(strict_types=1);
// A reversible packing pause. Never cancel an order or change financial/stock fields.
require_once __DIR__.'/config.php';
require_once __DIR__.'/shared/database.php';
require_once __DIR__.'/shared/pos-identity.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);exit;}
    $p=json_decode((string)file_get_contents('php://input',false,null,0,4097),true);
    $db=db();$actor=pos_identity_session($db,(string)($p['token']??''),time());
    $role=pos_identity_employee($db,(int)$actor['employee_id']);
    if(!$role||!in_array($role['role_key'],['owner_admin','front_desk_admin','front_desk_admin_employee','marketing_sales'],true))throw new RuntimeException('Unavailable.');
    if(($p['operation']??'')==='probe'){
        $db->query('SELECT id,status,deleted_at,archived_at,archive_reason,archived_by,updated_at,woo_order_id,fulfilment_mode,order_type FROM ops_orders LIMIT 0');
        echo json_encode(['ok'=>true,'capability'=>'postpone_collection_v1']);exit;
    }
    $id=filter_var($p['order_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $op=$p['operation']??'';$tag=(string)($p['tag']??'');
    if(!$id||!in_array($op,['hold','release'],true)||!preg_match('/^[a-f0-9]{32}$/D',$tag))throw new DomainException('Invalid collection request.');
    $marker='POS collection hold '.$tag;
    $db->beginTransaction();
    $q=$db->prepare("SELECT id,status,deleted_at,archived_at,archive_reason,COALESCE(NULLIF(fulfilment_mode,''),order_type) AS mode FROM ops_orders WHERE woo_order_id=? FOR UPDATE");$q->execute([$id]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1||$rows[0]['deleted_at'])throw new DomainException('No single active Live Orders record. Sync the order before continuing.');
    $row=$rows[0];
    if(!preg_match('/collect|pick.?up/i',(string)$row['mode']))throw new DomainException('Only collection orders can be postponed.');
    if($op==='hold'){
        if($row['archived_at']&&$row['archive_reason']!==$marker)throw new DomainException('Already archived for another reason.');
        if(!$row['archived_at']){
            if(!in_array($row['status'],['new_order','in_progress','packed','verified'],true))throw new DomainException('This order is no longer awaiting collection.');
            $db->prepare('UPDATE ops_orders SET archived_at=NOW(),archived_by=?,archive_reason=?,updated_at=NOW() WHERE id=?')->execute([$actor['employee_id'],$marker,$row['id']]);
        }
    }elseif($row['archived_at']){
        if($row['archive_reason']!==$marker)throw new DomainException('This archive belongs to another action. Ask the owner to review.');
        $db->prepare('UPDATE ops_orders SET archived_at=NULL,archived_by=NULL,archive_reason=NULL,updated_at=NOW() WHERE id=?')->execute([$row['id']]);
    }
    $db->commit();echo json_encode(['ok'=>true,'status'=>$row['status']]);
}catch(Throwable $e){
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    http_response_code($e instanceof DomainException?409:403);
    echo json_encode(['ok'=>false,'message'=>$e instanceof DomainException?$e->getMessage():'Could not authorise the packing change.']);
}
