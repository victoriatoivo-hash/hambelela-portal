<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';
try{$deliveryActor=$partnerAuth->actor($_SESSION['partner']);if(($deliveryActor['role']??'')!=='partner_admin')throw new DomainException();}catch(Throwable $e){http_response_code(403);exit('Administrator access required.');}
require_once BASE_PATH.'/shared/delivery/PartnerShell.php';\Hambelela\Delivery\PartnerShell::begin($deliveryActor);
require BASE_PATH.'/shared/delivery/WorkspaceView.php';
