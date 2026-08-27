<?php
require_once __DIR__.'/config.php'; require_once __DIR__.'/includes/policy-system.php'; requireLogin();
$db=db(); hrPolicyEnsureSchema($db); $u=currentUser(); $v=hrPolicyVersion($db,(int)($_GET['id']??0));
if(!$v||($u['role']==='employee'&&!in_array($v['status'],array('published','superseded'),true))){http_response_code(404);exit('Policy not found.');}
$file=hrPolicyPrivateDir().DIRECTORY_SEPARATOR.basename($v['file_path']); if(!is_file($file)){http_response_code(404);exit('Policy file not found.');}
header('X-Content-Type-Options: nosniff'); header('Content-Type: '.$v['mime_type']); header('Content-Length: '.filesize($file));
header('Content-Disposition: attachment; filename="'.str_replace('"','',basename($v['original_filename'])).'"'); readfile($file); exit;
