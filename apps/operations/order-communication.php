<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';
require_once BASE_PATH.'/shared/epi/OrderCommunicationCapture.php';
use Hambelela\EPI\OrderCommunicationCapture;
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
try {
    if(!in_array(current_role_key(),['owner_admin','front_desk_admin','marketing_sales','supervisor_manager'],true)){http_response_code(403);throw new RuntimeException('Front Desk / Marketing communication access required');}
    $owner=current_role_key()==='owner_admin';$id=(int)($_POST['order_id']??$_GET['order_id']??0);
    if($id<1)throw new RuntimeException('Valid order required');
    if(!ops_rows('SELECT id FROM ops_orders WHERE id=? AND deleted_at IS NULL',[$id])){http_response_code(404);throw new RuntimeException('Order not found');}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $token=(string)($_SESSION['orders_csrf_token']??'');if($token===''||!hash_equals($token,(string)($_POST['csrf_token']??''))){http_response_code(403);throw new RuntimeException('Refresh Orders before saving communication evidence');}
        if(($_POST['action']??'')==='review_quality'){
            if(!$owner){http_response_code(403);throw new RuntimeException('Owner review required');}
            $m=ops_rows('SELECT m.id FROM ops_whatsapp_messages m JOIN ops_whatsapp_conversations c ON c.id=m.conversation_id WHERE m.id=? AND c.order_id=?',[(int)($_POST['message_id']??0),$id]);if(!$m)throw new RuntimeException('Contact does not belong to this order');
            OrderCommunicationCapture::reviewQuality(db(),(int)$_POST['message_id'],ops_current_employee_id(),(string)($_POST['decision']??''),(string)($_POST['evidence_reference']??''),(string)($_POST['reason']??''));
        }else {
            foreach(['responsible_employee_id','transfer_to'] as $key){$target=(int)($_POST[$key]??0);if($target>0&&!ops_rows("SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=? AND e.status='active' AND r.role_key IN('owner_admin','front_desk_admin','marketing_sales','supervisor_manager')",[$target]))throw new RuntimeException('Choose an active employee with Front Desk / Marketing communication access');}
            OrderCommunicationCapture::save(db(),$id,ops_current_employee_id(),$_POST,$owner);
        }
    }elseif($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);throw new RuntimeException('GET or POST required');}
    $data=OrderCommunicationCapture::read(db(),$id);
    $data['employees']=ops_rows("SELECT e.id,e.full_name FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.status='active' AND r.role_key IN('owner_admin','front_desk_admin','marketing_sales','supervisor_manager') ORDER BY e.full_name");
    echo json_encode(['ok'=>true,'data'=>$data,'actor_id'=>ops_current_employee_id(),'can_review'=>$owner],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){$validation=$e instanceof RuntimeException&&!($e instanceof PDOException);if(http_response_code()<400)http_response_code($validation?422:500);echo json_encode(['ok'=>false,'message'=>$validation?$e->getMessage():'Communication evidence is unavailable; no save was confirmed'],JSON_UNESCAPED_SLASHES);error_log('Communication capture: '.$e->getMessage());}
