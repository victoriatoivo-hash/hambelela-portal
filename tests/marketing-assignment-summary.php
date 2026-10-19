<?php
declare(strict_types=1);
require dirname(__DIR__).'/shared/marketing-workspace.php';
function marketing_e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$source=file_get_contents(dirname(__DIR__).'/apps/marketing/execution.php');
$start=strpos($source,'<div class="mk-assignment-summary"');$end=strpos($source,'<?=marketing_clock_markup($item)?>',$start);
if($start===false||$end===false)throw new RuntimeException('Summary is missing from the assignment header');
$fragment=substr($source,$start,$end-$start);
function renderSummary($fragment,$item){ob_start();eval('?>'.$fragment);return ob_get_clean();}
function checkSummary($condition,$message){if(!$condition)throw new RuntimeException($message);echo "PASS $message\n";}
date_default_timezone_set('America/Los_Angeles');
$item=['due_at'=>'2026-10-19 17:00:00','publish_at'=>'2026-10-20 09:00:00','media_source'=>'shared_folder','media_reference'=>'October <Content> & Media'];
$html=renderSummary($fragment,$item);
checkSummary(strpos($html,'19 Oct 2026 · 17:00')!==false&&strpos($html,'20 Oct 2026 · 09:00')!==false,'Saved deadline and publishing time render in Namibia, independent of server timezone');
checkSummary(strpos($html,'2026-10-19T17:00:00+02:00')!==false,'Deadline machine-readable value has Namibia offset');
checkSummary(strpos($html,'October &lt;Content&gt; &amp; Media')!==false,'Folder name is escaped and visible without expanding a section');
$item['publish_at']=null;$item['media_reference']='';$html=renderSummary($fragment,$item);
checkSummary(strpos($html,'Not scheduled')!==false&&strpos($html,'mk-assignment-folder')===false,'Unset publishing date and blank folder have honest empty states');
$item['media_reference']='Existing reference';$item['media_source']='adobe_express';
checkSummary(strpos(renderSummary($fragment,$item),'mk-assignment-folder')===false,'Non-folder media does not produce an empty or misleading folder card');
checkSummary(strpos($source,'<?=marketing_clock_markup($item)?>')!==false,'Existing assignment countdown remains in the header');
