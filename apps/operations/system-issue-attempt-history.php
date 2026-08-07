<?php
declare(strict_types=1);
if(!isset($selected)||!system_issue_is_owner())return;
$attempts=ops_rows('SELECT a.*,e.full_name recorded_by_name FROM system_issue_repair_attempts a LEFT JOIN ops_employees e ON e.id=a.recorded_by_user_id WHERE a.issue_id=? ORDER BY a.attempt_number DESC,a.id DESC',[(int)$selected['id']]);
?>
<section class="system-issue-attempt-history"><h3>Repair attempts</h3>
<?php if(!$attempts):?><p>No repair result has been recorded yet.</p><?php endif;?>
<?php foreach($attempts as$index=>$attempt):$url=filter_var((string)($attempt['pull_request_url']??''),FILTER_VALIDATE_URL);$validPr=$url&&strtolower((string)parse_url($url,PHP_URL_SCHEME))==='https'&&strtolower((string)parse_url($url,PHP_URL_HOST))==='github.com';?>
<article class="system-issue-attempt-card<?=$index===0?' is-latest':''?>"><header><strong>Attempt <?=(int)$attempt['attempt_number']?></strong><?php if($index===0):?><span>Latest</span><?php endif;?><small><?=htmlspecialchars((string)($attempt['status']??'recorded'))?></small></header><dl>
<?php foreach(['repair_summary'=>'Repair summary','branch_name'=>'Branch','commit_hash'=>'Commit','files_changed'=>'Files changed','tests_performed'=>'Tests performed','tests_passed'=>'Tests reported passed','tests_unavailable'=>'Tests unavailable','known_limitations'=>'Known limitations','failure_reason'=>'Failure reason','testing_completed_at'=>'Testing confirmed at','deployment_method'=>'Deployment method','deployment_time'=>'Deployment time','deployed_commit'=>'Deployed commit','deployment_result'=>'Deployment result','deployment_notes'=>'Deployment notes']as$key=>$label):?><div><dt><?=htmlspecialchars($label)?></dt><dd><?=nl2br(htmlspecialchars((string)($attempt[$key]??'—')))?></dd></div><?php endforeach;?>
<div><dt>Deployment required</dt><dd><?=!empty($attempt['deployment_required'])?'Yes':'No'?></dd></div><div><dt>Recorded by</dt><dd><?=htmlspecialchars((string)($attempt['recorded_by_name']??'Owner'))?> · <?=htmlspecialchars((string)$attempt['recorded_at'])?></dd></div><div><dt>Pull request</dt><dd><?php if($validPr):?><a href="<?=htmlspecialchars((string)$url)?>" target="_blank" rel="noopener noreferrer"><?=htmlspecialchars((string)$url)?></a><?php else:?><?=htmlspecialchars((string)($attempt['pull_request_url']??'—'))?><?php endif;?></dd></div></dl></article>
<?php endforeach;?></section>
