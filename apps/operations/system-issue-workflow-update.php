<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode(['success'=>false,'data'=>['code'=>'endpoint_retired','message'=>'This workflow endpoint has been retired. Refresh the System Issues page.']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
