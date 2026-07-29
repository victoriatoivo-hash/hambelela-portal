<?php
declare(strict_types=1);
require_once __DIR__ . '/operations.php'; require_login();
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'message'=>'Invalid request method.']);exit;}
$itemId=(int)($_POST['item_id']??0);$employeeId=(int)ops_current_employee_id();
$item=$itemId>0 ? (ops_rows('SELECT id FROM ops_packing_tasks WHERE id=? LIMIT 1',[$itemId])[0]??null) : null;
if(!$item||$employeeId<=0){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Packing item not found.']);exit;}
$csrf=(string)($_POST['csrf_token']??'');if($csrf===''||!hash_equals((string)($_SESSION['packing_attachment_csrf']??''),$csrf)){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Your session token expired.']);exit;}
$types=array_values(array_intersect((array)($_POST['types']??[]),['note_added','file_uploaded']));
$unread=packing_mark_updates_read($itemId,$employeeId,$types);
echo json_encode(['success'=>true,'item_id'=>$itemId,'unread_updates'=>$unread]);
