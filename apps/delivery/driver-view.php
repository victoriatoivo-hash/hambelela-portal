<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/delivery/ReleaseFlags.php';
require_once BASE_PATH.'/apps/operations/operations.php';require_login();
require_once BASE_PATH.'/shared/delivery/FrontSession.php';
try{$deliveryActor=\Hambelela\Delivery\FrontSession::actor(db(),(int)(current_user()['id']??0));if($deliveryActor['role']!=='owner_admin')throw new DomainException();}catch(DomainException $e){http_response_code(403);exit('Owner oversight required.');}
if(!DELIVERY_ENABLED){http_response_code(503);exit('Delivery is not enabled.');}
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');http_response_code(405);exit;}
if(isset($_GET['view']) && $_GET['view']==='board'){
    header('Content-Type: application/json');
    try{
        require_once BASE_PATH.'/shared/delivery/DriverErrands.php';
        require_once BASE_PATH.'/shared/delivery/CoreApi.php';
        $errands=new \Hambelela\Delivery\DriverErrands(db());
        $drivers=$errands->drivers($deliveryActor);$id=(int)($_GET['driver_id']??0);
        // Return just the selector until an active driver is selected. Never query an unfiltered board.
        $board=['jobs'=>[],'drivers'=>$drivers,'methods'=>[],'metrics'=>[],'total'=>0,'page'=>1,'has_more'=>false,'preview_errands'=>[]];
        if($id>0&&in_array($id,array_map('intval',array_column($drivers,'id')),true)){
            $params=['driver_id'=>$id,'state'=>'all','page'=>max(1,(int)($_GET['page']??1)),'limit'=>200];
            $board=(new \Hambelela\Delivery\CoreApi(db(),ops_payment_method_map()))->board($deliveryActor,$params);
            if($params['page']===1)$board['preview_errands']=$errands->previewForDriver($deliveryActor,$id);
        }
        echo json_encode($board,JSON_THROW_ON_ERROR);
    }catch(Throwable $e){http_response_code(503);echo '{"error":"Driver preview is temporarily unavailable."}';}
    exit;
}
// Preserve the legacy read API above for existing clients, but retire its duplicate page.
$selected=(int)($_GET['driver_id']??0);
$destination=$selected>0?'view-as-driver.php?driver='.$selected:'driver-operations.php';
header('Location: '.BASE_URL.'/apps/delivery/'.$destination,true,302);exit;
