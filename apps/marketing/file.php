<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/shared/marketing.php';
marketing_require_access();

$id=(int)($_GET['id']??0);
$q=db()->prepare('SELECT v.*,w.assigned_employee_id FROM marketing_item_versions v JOIN marketing_work_items w ON w.id=v.item_id WHERE v.id=? AND w.cancelled_at IS NULL');
$q->execute([$id]);
$file=$q->fetch();
if(!$file||(!marketing_is_owner()&&(int)$file['assigned_employee_id']!==marketing_employee_id())){http_response_code(404);exit('File not found.');}
$path=BASE_PATH.'/uploads/marketing/'.basename((string)$file['stored_name']);
if(!is_file($path)){http_response_code(404);exit('File not found.');}
header('Content-Type: '.((string)$file['mime_type']?:'application/octet-stream'));
header('Content-Length: '.filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode((string)$file['file_name']));
header('X-Content-Type-Options: nosniff');
readfile($path);
