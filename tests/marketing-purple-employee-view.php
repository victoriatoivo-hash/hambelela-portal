<?php
// Actual employee execution markup, synthetic loopback-only data, no production config.
if (!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'], true)) {http_response_code(403);exit;}
function marketing_e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function marketing_csrf(){return 'synthetic-local-only';}
require __DIR__.'/../shared/marketing-execution.php';
$owner=false;$message='';$failed=false;$files=[];
$item=['id'=>999999,'title'=>'Synthetic assigned content','status'=>'in_progress','brief'=>'Prepare independent posts for the selected channels.','caption'=>'','approval_required'=>0,'priority'=>'normal','due_at'=>'2026-10-14 15:00','publish_at'=>'','campaign'=>'','product_reference'=>'','required_dimensions'=>'','cta'=>''];
$rows=[['channel_key'=>'tiktok','prepared'=>1,'completed'=>0,'instructions'=>'Create the short video.','scheduled_at'=>null,'proof_url'=>'','completion_note'=>'']];
$progress=['total'=>1,'complete'=>0,'percent'=>0];
$source=file_get_contents(__DIR__.'/../apps/marketing/execution.php');$start=strpos($source,'<main id="ess-main"');$end=strpos($source,'</main>',$start)+7;
?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/marketing.css"><link rel="stylesheet" href="/assets/css/marketing-extras.css"><link rel="stylesheet" href="/assets/css/marketing-execution.css"></head><body><?php eval('?>'.substr($source,$start,$end-$start));?></body></html>
