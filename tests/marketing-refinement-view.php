<?php
// Loopback-only visual harness: real form template, no live records or writes.
if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(403);exit;}
function marketing_e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function marketing_csrf(){return 'synthetic-local-only';}
require __DIR__.'/../shared/marketing-execution.php';
$owner=true;$formOpen=true;$formFailed=false;$editItem=[];$editChannels=[];$employees=[['id'=>999,'full_name'=>'Synthetic Marketing Employee']];
$source=file_get_contents(__DIR__.'/../apps/marketing/index.php');
$start=strpos($source,'<?php if($owner): ?>'."\n".'<dialog');$end=strpos($source,'</dialog>',$start)+strlen('</dialog>');
$template=substr($source,$start,$end-$start).'<?php endif;?>';
?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/marketing.css"><link rel="stylesheet" href="/assets/css/marketing-extras.css"><link rel="stylesheet" href="/assets/css/marketing-execution.css"></head><body><main class="marketing-workspace ess-marketing-page"><h1>Marketing form verification</h1><?php eval('?>'.$template);?></main><script src="/assets/js/marketing.js"></script></body></html>
