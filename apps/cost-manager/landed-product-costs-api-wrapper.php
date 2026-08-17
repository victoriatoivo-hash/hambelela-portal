<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/auth.php';
require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');
register_shutdown_function(function(){
    $error=error_get_last();
    if(!$error||!in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
    if(!headers_sent())http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Landed Product Costs could not load.','diagnostic'=>$error['message']],JSON_UNESCAPED_SLASHES);
});
require __DIR__.'/landed-product-costs-api.php';
